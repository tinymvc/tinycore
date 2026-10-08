<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Storage\LocalStorage;

final class LocalStorageTest extends FrameworkTestCase
{
    public function test_write_upload_list_metadata_and_delete(): void
    {
        $disk = new LocalStorage($this->storagePath . '/disk', 'https://example.test/files');
        $this->assertTrue($disk->put('reports/one.txt', 'first'));
        $this->assertTrue($disk->put('reports/one.txt', 'replacement'));
        $this->assertSame('replacement', $disk->get('reports/one.txt'));
        $this->assertSame(11, $disk->metadata('reports/one.txt')['size']);
        $source = $this->storagePath . '/upload.txt';
        file_put_contents($source, 'uploaded');
        $this->assertTrue($disk->upload($source, 'reports/sub/two.txt'));
        $this->assertSame('uploaded', $disk->get('reports/sub/two.txt'));
        $this->assertCount(1, $disk->files('reports'));
        $this->assertCount(2, $disk->files('reports', true));
        $this->assertTrue($disk->delete('reports/one.txt'));
        $this->assertTrue($disk->delete('reports/one.txt'));
        $this->assertFalse($disk->exists('reports/one.txt'));
        $this->assertThrows(\RuntimeException::class, fn () => $disk->get('missing'));
    }

    public function test_paths_cannot_escape_the_disk_or_follow_symlinks(): void
    {
        $disk = new LocalStorage($this->storagePath . '/disk');

        foreach (['../escape', '/absolute', 'a/../../escape', "a\0b"] as $key) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => $disk->put($key, 'bad'));
        }

        $disk->put('safe.txt', 'safe');
        mkdir($this->storagePath . '/outside');
        file_put_contents($this->storagePath . '/outside/keep.txt', 'keep');
        symlink($this->storagePath . '/outside', $this->storagePath . '/disk/link');
        $this->assertThrows(\RuntimeException::class, fn () => $disk->get('link/keep.txt'));
        $this->assertThrows(\RuntimeException::class, fn () => $disk->put('link/keep.txt', 'bad'));
        $this->assertThrows(\RuntimeException::class, fn () => $disk->delete('link/keep.txt'));
        $this->assertSame('keep', file_get_contents($this->storagePath . '/outside/keep.txt'));
    }
}
