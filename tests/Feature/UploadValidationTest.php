<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Nyholm\Psr7\{ServerRequest, Stream, UploadedFile};
use Spark\Facades\Route;
use Spark\Http\{Request, Validator};

final class UploadValidationTest extends FrameworkTestCase
{
    public function test_psr_upload_validation_uses_actual_bytes_and_preserves_the_stream(): void
    {
        $stream = Stream::create(str_repeat('x', 1024));
        $stream->seek(23);
        // Client metadata must not decide size or MIME checks.
        $file = new UploadedFile($stream, 1, UPLOAD_ERR_OK, 'fake.png', 'image/png');
        $rules = 'required|file|min:1|max:1|size:1|between:1,1|mimes:text/plain';
        $this->assertTrue(Validator::make(['file' => $rules], ['file' => $file])->passes());
        $this->assertSame(23, $stream->tell());
        foreach (['max:0.5', 'min:2', 'size:0.5', 'between:2,3', 'image', 'mimes:png'] as $rule) {
            $this->assertFalse(Validator::make(['file' => "file|$rule"], ['file' => $file])->passes());
            $this->assertSame(23, $stream->tell());
        }
        $larger = new UploadedFile(Stream::create(str_repeat('a', 2048)), 0, UPLOAD_ERR_OK);
        $this->assertTrue(Validator::make(['file' => 'file|gt:other'], ['file' => $larger, 'other' => $file])->passes());
    }

    public function test_psr_image_validation_does_not_move_the_upload(): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aL1kAAAAASUVORK5CYII=');
        $stream = Stream::create($contents);
        $stream->seek(5);
        $file = new UploadedFile($stream, strlen($contents), UPLOAD_ERR_OK, 'image.txt', 'text/plain');
        $this->assertTrue(Validator::make(['file' => 'required|image|mimes:png|max:1'], ['file' => $file])->passes());
        $this->assertSame(5, $stream->tell());
        $target = $this->storagePath . '/validated.png';
        $file->moveTo($target);
        $this->assertSame($contents, file_get_contents($target));
        $this->assertFalse(Validator::make(['file' => 'file'], ['file' => $file])->passes());
    }

    public function test_upload_errors_and_forged_native_uploads_are_rejected(): void
    {
        foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE] as $error) {
            $file = new UploadedFile(Stream::create(''), 0, $error);
            $this->assertFalse(Validator::make(['file' => 'required|file'], ['file' => $file])->passes());
        }
        $local = $this->storagePath . '/private.txt';
        file_put_contents($local, 'not uploaded');
        foreach ([0, '0', null] as $error) {
            $file = ['tmp_name' => $local, 'name' => 'fake.txt', 'size' => 0, 'error' => $error];
            $this->assertFalse(Validator::make(['file' => 'required|file'], ['file' => $file])->passes());
        }
    }

    public function test_regular_arrays_with_upload_like_keys_use_element_counts(): void
    {
        foreach ([0, 'invalid', []] as $size) {
            $items = ['size' => $size, 'a' => 1, 'b' => 2, 'c' => 3];
            $this->assertFalse(Validator::make(['items' => 'array|max:3'], ['items' => $items])->passes());
            $this->assertTrue(Validator::make(['items' => 'array|min:4|size:4|between:4,4'], ['items' => $items])->passes());
        }
    }

    public function test_real_request_validation_accepts_psr_uploads_and_rejects_forged_body_arrays(): void
    {
        Route::post('/validated-upload', function (Request $request) {
            $request->validate(['file' => 'required|file|max:1|mimes:text/plain']);
            return 'valid';
        });
        $file = new UploadedFile(Stream::create('hello'), 5, UPLOAD_ERR_OK, 'hello.txt');
        $request = new ServerRequest('POST', 'http://localhost/validated-upload', ['Accept' => 'application/json']);
        $response = $this->app->handlePsr($request->withUploadedFiles(['file' => $file]));
        $this->assertSame(200, $response->getStatusCode());
        $local = $this->storagePath . '/local.txt';
        file_put_contents($local, 'private');
        $response = $this->app->handlePsr($request->withParsedBody([
            'file' => ['tmp_name' => $local, 'name' => 'fake.txt', 'error' => 0, 'size' => 0],
        ]));
        $this->assertSame(422, $response->getStatusCode());
    }
}
