<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Spark\Cache\Cache;
use Spark\Carbon;
use Spark\Exceptions\Utils\UploaderException;
use Spark\Storage\Storage;
use Spark\Storage\Uploader;

final class InteropUtilitiesTest extends FrameworkTestCase
{
    public function test_cache_expirations_accept_durations_and_date_objects(): void
    {
        $cache = Cache::make('expiry-types');
        $deadline = Carbon::now()->addMinutes(5);
        foreach ([300, '+5 minutes', $deadline, $deadline->toDateTime(), $deadline->toDateTimeImmutable(), new DateInterval('PT5M')] as $expiry) {
            $cache->set('value', 'stored', $expiry);
            $this->assertSame('stored', $cache->get('value'));
            $this->assertTrue($cache->ttl('value') >= 298 && $cache->ttl('value') <= 300);
            $cache->storeMany(['bulk' => 'value'], $expiry);
            $this->assertSame('value', $cache->get('bulk'));
            $cache->delete('added');
            $this->assertTrue($cache->add('added', 'value', $expiry));
        }
        $this->assertSame($deadline->getTimestamp(), Carbon::parse($deadline)->getTimestamp());
        $cache->storeManyWithExpiry(['a' => ['value' => 1, 'expire' => 60], 'b' => ['value' => 2, 'expire' => $deadline]]);
        $this->assertSame(1, $cache->get('a'));
        $this->assertSame(2, $cache->get('b'));
        $this->assertSame('remembered', $cache->remember('remembered', fn() => 'remembered', $deadline));
        $this->assertSame('loaded', $cache->load('loaded', fn() => 'loaded', 60));
        foreach ([0, -1, new DateTimeImmutable('@0'), Carbon::now()->subMinutes(1)] as $expiry) {
            $cache->put('expired', 'gone', $expiry);
            $this->assertFalse($cache->has('expired'));
        }
        $this->assertThrows(Exception::class, fn() => $cache->put('invalid', 1, 'not a valid date'));
    }

    public function test_restored_lock_handles_can_extend_and_release_the_same_owner(): void
    {
        $cache = Cache::make('leases');
        $lock = $cache->lock('lease', 60);
        $this->assertTrue($lock->get());
        $expiresAt = $lock->info()['expire_at'];
        $restored = $cache->restoreLock('lease', $lock->owner());
        $this->assertTrue($restored->extend(30));
        $this->assertSame($expiresAt + 30, $lock->info()['expire_at']);
        $this->assertTrue($restored->release());
        $this->assertFalse($lock->isLocked());
    }

    public function test_carbon_locales_translate_relative_time_and_names_without_leaking(): void
    {
        $directory = $this->storagePath . '/languages';
        mkdir($directory);
        app()->setConfig('app.locale_dir', $directory);
        file_put_contents($directory . '/bn.php', '<?php return ' . var_export([
            '%d minutes ago' => '%d মিনিট আগে',
            'in %d minute' => '%d মিনিট পরে',
            '%dm ago' => '%dমি আগে',
            '%dm' => '%dমি',
            'now' => 'এখন',
            'a few seconds ago' => 'কয়েক সেকেন্ড আগে',
            'Monday' => 'সোমবার',
            'January' => 'জানুয়ারি',
        ], true) . ';');
        $now = Carbon::parse('2026-01-05 12:00:00', 'UTC');
        $past = $now->subMinutes(2);
        $translated = $past->locale('bn');
        try {
            $this->assertSame('2 মিনিট আগে', $translated->diffForHumans($now));
            $this->assertSame('2মি আগে', $translated->diffForHumansShort($now));
            $this->assertSame('2মি', $translated->diffForHumansShort($now, false));
            $this->assertSame('1 মিনিট পরে', $now->addMinutes(1)->locale('bn')->diffForHumans($now));
            $this->assertSame('2 minutes ago', $past->diffForHumans($now));
            $this->assertSame('1 minute ago', $now->subMinutes(1)->diffForHumans($now));
            $this->assertSame('সোমবার জানুয়ারি 2026', $translated->translatedFormat('l F Y'));
            $this->assertSame('Monday', $translated->format('l'));
            $this->assertSame('সোমবার', Carbon::parse($translated)->getDayName());
            $this->assertSame('জানুয়ারি', $translated->getMonthName());
            Carbon::setLocale('bn');
            $this->assertSame('এখন', $now->diffForHumansShort($now));
            $this->assertSame('কয়েক সেকেন্ড আগে', $now->diffForHumans($now));
            $this->assertSame('now', $now->locale('en')->diffForHumansShort($now));
            $this->assertSame('2 minutes ago', $past->locale('fr')->diffForHumans($now));
            $this->assertThrows(InvalidArgumentException::class, fn() => Carbon::setLocale('../private'));
        } finally {
            Carbon::setLocale(null);
        }
    }

    private function uploadFile(string $name = 'sample.txt', string $contents = 'hello', ?int $size = null): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), $size ?? strlen($contents), UPLOAD_ERR_OK, $name, 'text/plain');
    }

    public function test_psr_uploads_use_native_and_disk_storage_and_nested_lists(): void
    {
        $directory = $this->storagePath . '/uploads';
        $uploader = new Uploader(uploadDir: $directory, extensions: ['txt']);
        $file = $this->uploadFile();
        $path = $uploader->upload($file);
        $this->assertSame('hello', file_get_contents($directory . '/' . $path));
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload($file));
        $paths = $uploader->upload(['documents' => [$this->uploadFile('a.txt'), $this->uploadFile('b.txt')]]);
        $this->assertCount(2, $paths);
        $disk = Storage::build(['driver' => 'local', 'root' => $this->storagePath . '/disk']);
        $path = $disk->uploader('documents', extensions: ['txt'])->upload($this->uploadFile());
        $this->assertSame('hello', $disk->get($path));
    }

    public function test_psr_upload_validation_checks_real_size_and_rolls_back_batches(): void
    {
        $directory = $this->storagePath . '/uploads';
        $uploader = new Uploader(uploadDir: $directory, extensions: ['txt'], maxSize: 1);
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload($this->uploadFile('large.txt', str_repeat('x', 2048), 1)));
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload([
            $this->uploadFile(),
            $this->uploadFile('bad.php'),
        ]));
        $this->assertSame([], glob($directory . '/*'));
        $error = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_PARTIAL, 'partial.txt');
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload($error));
        $local = $this->storagePath . '/local.txt';
        file_put_contents($local, 'private file');
        $this->assertThrows(UploaderException::class, fn() => $uploader->upload([
            'name' => 'local.txt',
            'tmp_name' => $local,
            'error' => UPLOAD_ERR_OK,
        ]));
        $this->assertSame('private file', file_get_contents($local));
    }
}
