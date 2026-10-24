<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Spark\Exceptions\Utils\UploaderException;
use Spark\Http\Input;
use Spark\Storage\Contracts\UploaderDriverInterface;
use Spark\Storage\Storage;
use Spark\Storage\Uploader;

final class UploaderTest extends FrameworkTestCase
{
    private function file(string $contents = 'hello', string $name = 'sample.txt'): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), strlen($contents), UPLOAD_ERR_OK, $name);
    }

    private function uploader(?bool $multiple = null): Uploader
    {
        return new Uploader(uploadDir: $this->storagePath . '/uploads', extensions: ['txt'], multiple: $multiple);
    }

    private function contents(array $paths): array
    {
        return array_map(fn(string $path) => file_get_contents($this->storagePath . '/uploads/' . $path), $paths);
    }

    public function test_nested_psr_field_names_and_reusing_an_uploader_preserve_return_shapes(): void
    {
        $uploader = $this->uploader();
        $paths = $uploader->upload(new Input([
            'name' => ['avatar' => $this->file('first')],
            'tmp_name' => ['nested' => [$this->file('second')]],
            'error' => $this->file('third'),
            'empty' => [],
        ]));
        $this->assertSame(['first', 'second', 'third'], $this->contents($paths));
        $path = $uploader->upload($this->file('single'));
        $this->assertTrue(is_string($path));
        $this->assertSame(['single'], $this->contents([$path]));
        $this->assertCount(1, $uploader->upload([$this->file()]));
        $this->assertSame(['hello'], $this->contents($uploader->upload([
            'name' => [],
            'tmp_name' => [],
            'error' => $this->file(),
        ])));
        $this->assertSame([], glob(temp_dir('uploads/*')));
    }

    public function test_explicit_upload_modes_reject_mismatches_without_consuming_the_file(): void
    {
        $file = $this->file();
        $this->assertThrows(UploaderException::class, fn() => $this->uploader(false)->upload([$file]));
        $this->assertThrows(UploaderException::class, fn() => $this->uploader(true)->upload($file));
        $this->assertSame('hello', (string) $file->getStream());
        $this->assertTrue(is_string($this->uploader(false)->upload($file)));
    }

    public function test_column_metadata_is_matched_by_key_and_empty_slots_are_skipped(): void
    {
        $paths = $this->uploader()->upload([
            'name' => ['second' => 'second.txt', 'empty' => '', 'first' => 'first.txt'],
            'tmp_name' => ['first' => $this->file('first'), 'second' => $this->file('second'), 'empty' => ''],
            'error' => ['empty' => UPLOAD_ERR_NO_FILE, 'first' => UPLOAD_ERR_OK, 'second' => UPLOAD_ERR_OK],
        ]);
        $this->assertSame(['second', 'first'], $this->contents($paths));
        $empty = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE);
        $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload([$empty]), 'No files');
        $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload([]), 'No files');
    }

    public function test_malformed_batches_fail_before_any_file_is_consumed(): void
    {
        foreach ([
            ['name' => 'missing.txt'],
            ['name' => ['x' => 'a.txt'], 'tmp_name' => []],
            ['name' => ['x' => 'a.txt'], 'tmp_name' => ['y' => 'missing']],
            ['name' => ['x' => 'a.txt'], 'tmp_name' => ['x' => 'missing'], 'error' => ['y' => 0]],
            ['name' => ['a.txt'], 'tmp_name' => ['missing'], 'type' => 'text/plain'],
            ['name' => 'a.txt', 'tmp_name' => 'missing', 'error' => []],
            ['name' => 'a.txt', 'tmp_name' => 'missing', 'size' => []],
            ['not a file'],
        ] as $invalid) {
            $first = $this->file();
            $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload([$first, $invalid]));
            $this->assertSame('hello', (string) $first->getStream());
            $this->assertSame([], glob($this->storagePath . '/uploads/*'));
        }
    }

    public function test_configured_temporary_directory_is_used_and_partial_moves_are_cleaned_up(): void
    {
        app()->setConfig('app.temp_dir', $this->storagePath . '/custom-temp');
        $file = new class (Stream::create('hello'), 5, UPLOAD_ERR_OK, 'sample.txt') extends UploadedFile {
            public string $target;

            public function moveTo($targetPath): void
            {
                $this->target = $targetPath;
                parent::moveTo($targetPath);
            }
        };
        $this->uploader()->upload($file);
        $this->assertSame(realpath(temp_dir('uploads')), dirname($file->target));
        $this->assertFalse(file_exists($file->target));

        $partial = new class (Stream::create('hello'), 5, UPLOAD_ERR_OK, 'sample.txt') extends UploadedFile {
            public function moveTo($targetPath): void
            {
                file_put_contents($targetPath, 'partial');
                throw new RuntimeException('Interrupted move');
            }
        };
        $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload($partial), 'Interrupted move');
        $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload($this->file('bad', 'bad.php')));
        $this->assertSame([], glob(temp_dir('uploads/*')));
    }

    public function test_unavailable_staging_directory_does_not_consume_the_upload(): void
    {
        $blocked = $this->storagePath . '/blocked';
        file_put_contents($blocked, 'not a directory');
        app()->setConfig('app.temp_dir', $blocked);
        $file = $this->file();
        $this->assertThrows(UploaderException::class, fn() => $this->uploader()->upload($file), 'temporary directory');
        $this->assertSame('hello', (string) $file->getStream());
    }

    public function test_driver_failure_cleans_staging_and_attempts_every_batch_rollback(): void
    {
        $driver = new class implements UploaderDriverInterface {
            public array $sources = [];
            public array $deleted = [];

            public function upload(string $filepath, string $destination): bool
            {
                $this->sources[] = $filepath;
                return count($this->sources) < 3;
            }

            public function delete(string $destination): bool
            {
                $this->deleted[] = $destination;
                throw new RuntimeException('Cleanup unavailable');
            }
        };
        $uploader = new Uploader(uploadDir: $this->storagePath . '/uploads', driver: $driver);
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload([
            $this->file(),
            $this->file(),
            $this->file(),
        ]), 'Failed to move');
        $this->assertCount(2, $driver->deleted);
        foreach ($driver->sources as $source) {
            $this->assertFalse(file_exists($source));
        }
        $this->assertSame([], glob(temp_dir('uploads/*')));
    }

    public function test_psr_image_variants_reach_the_disk_and_leave_no_staged_files(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for image transformations.');
        }

        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        $disk = Storage::build(['driver' => 'local', 'root' => $this->storagePath . '/disk']);
        $uploader = $disk->uploader('photos', extensions: ['png'], resizes: [[10, 5], [4, 2]], compress: 80);
        $paths = $uploader->upload($this->file($contents, 'photo.png'));
        $this->assertCount(3, $paths);
        foreach ($paths as $index => $path) {
            $size = getimagesizefromstring($disk->get($path));
            $this->assertSame([[20, 10], [10, 5], [4, 2]][$index], array_slice($size, 0, 2));
        }
        $this->assertSame([], glob($uploader->uploadDir . '/*'));
        $this->assertSame([], glob(temp_dir('uploads/*')));
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload($this->file('invalid image', 'bad.png')));
        $this->assertSame([], glob($uploader->uploadDir . '/*'));
        $this->assertSame([], glob(temp_dir('uploads/*')));
    }
}
