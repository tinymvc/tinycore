<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Storage\Storage;

final class StorageBoundaryTest extends FrameworkTestCase
{
    private function disk(): Storage
    {
        return Storage::build(['driver' => 'local', 'root' => $this->storagePath . '/disk', 'url' => 'https://cdn.example.test/files']);
    }

    public function test_empty_and_binary_files_roundtrip(): void
    {
        $disk = $this->disk();
        foreach (['empty' => '', 'binary' => "a\0\xffb"] as $key => $value) {
            $this->assertTrue($disk->put($key, $value));
            $this->assertSame($value, $disk->get($key));
            $this->assertSame(strlen($value), $disk->size($key));
        }
    }

    public function test_copy_preserves_source_and_move_removes_it(): void
    {
        $disk = $this->disk();
        $disk->put('source.txt', 'contents');
        $this->assertTrue($disk->copy('source.txt', 'nested/copied.txt'));
        $this->assertSame('contents', $disk->get('source.txt'));
        $this->assertTrue($disk->move('nested/copied.txt', 'moved.txt'));
        $this->assertFalse($disk->exists('nested/copied.txt'));
        $this->assertSame('contents', $disk->get('moved.txt'));
    }

    public function test_batch_delete_preserves_unselected_files(): void
    {
        $disk = $this->disk();
        foreach (['a', 'b', 'c'] as $key) {
            $disk->put($key, $key);
        }
        $this->assertTrue($disk->delete(['a', 'b']));
        $this->assertFalse($disk->exists('a'));
        $this->assertFalse($disk->exists('b'));
        $this->assertSame('c', $disk->get('c'));
    }

    public function test_public_url_encodes_each_path_segment(): void
    {
        $this->assertSame('https://cdn.example.test/files/reports/a%20b%23c.txt', $this->disk()->url('reports/a b#c.txt'));
    }
}
