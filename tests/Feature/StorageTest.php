<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class StorageTest extends FrameworkTestCase
{
    public function test_disk_selection_and_signed_download_examples(): void
    {
        config([
            'storage' => [
                'default' => 'not-configured',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => $this->storagePath . '/private'],
                    'public' => ['driver' => 'local', 'root' => $this->storagePath . '/public', 'url' => 'https://example.com/uploads'],
                    's3' => [
                        'driver' => 's3',
                        'key' => 'docs-key',
                        'secret' => 'docs-secret',
                        'region' => 'us-east-1',
                        'bucket' => 'docs-bucket',
                        'url' => 'https://cdn.example.com'
                    ],
                ]
            ]
        ]);
        // Named selection must work even when the default disk cannot be constructed.
        $temporary = \Spark\Facades\Storage::build(['driver' => 'local', 'root' => $this->storagePath . '/explicit']);
        $this->assertTrue($temporary->put('example.txt', 'on-demand'));
        $this->assertSame('on-demand', $temporary->get('example.txt'));
        $s3 = \Spark\Facades\Storage::disk('s3');
        $this->assertTrue($s3 instanceof \Spark\Storage\Contracts\StorageContract);
        $this->assertSame(
            'https://cdn.example.com/reports/private%20file.pdf',
            $s3->url('reports/private file.pdf')
        );
        $signed = $s3->temporaryUrl('reports/private file.pdf', 300);
        parse_str(parse_url($signed, PHP_URL_QUERY), $query);
        $this->assertSame('attachment; filename="private file.pdf"', $query['response-content-disposition']);
        $this->assertSame('300', $query['X-Amz-Expires']);
        $this->assertSame('docs-bucket.s3.us-east-1.amazonaws.com', parse_url($signed, PHP_URL_HOST));
        $this->assertSame(64, strlen($query['X-Amz-Signature']));
        $this->assertSame($s3->url('report.pdf'), storage('s3')->url('report.pdf'));

        config(['storage.default' => 'local']);
        $this->app->bind(\Spark\Storage\Storage::class, fn () => \Spark\Storage\Storage::disk('public'));
        \Spark\Facades\Storage::put('facade.txt', 'public');
        storage()->put('helper.txt', 'private');
        $this->assertSame('public', storage('public')->get('facade.txt'));
        $this->assertTrue(storage('local')->missing('facade.txt'));
        $this->assertSame('private', storage('local')->get('helper.txt'));
        $this->assertTrue(storage('public')->missing('helper.txt'));
        $this->assertFalse($this->app->bound(\Spark\Storage\Contracts\StorageContract::class));
        $this->app->bind(
            \Spark\Storage\Contracts\StorageContract::class,
            fn () => $this->app->make(\Spark\Storage\Storage::class)
        );
        $this->assertSame('public', $this->app->make(\Spark\Storage\Contracts\StorageContract::class)->get('facade.txt'));
    }
}
