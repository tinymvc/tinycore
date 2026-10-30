# Files, Storage, and Image Utilities

> **Read when:** working with filesystem storage, S3, disk uploaders, or the native `File`, `Image`, and `JWT` utilities.
> **Related:** [Foundation](foundation.md#cache-lock-queue-and-session-config) (storage paths) · [Helpers](helpers.md) · [API discovery](api-discovery.md#check-optional-capabilities) (required extensions)
> Paths are relative to the application root. Config lives in `config/storage.php`; `FILESYSTEM_DISK` selects the default disk.

Contents:

- [Storage facade](#storage-facade)
- [Disk uploaders](#disk-uploaders)
- [PSR-7 uploaded files](#psr-7-uploaded-files)
- [S3 and signed URLs](#s3-and-signed-urls)
- [File utility](#file-utility)
- [Image utility](#image-utility)
- [JWT utility](#jwt-utility)

## Storage facade

Use `Spark\Facades\Storage` for local, public, and S3-compatible disks.

| Disk | Location / behavior |
| --- | --- |
| `local` | Private: `storage/app/private` |
| `public` | `storage/app/public`, exposed through the public symlink (`php spark storage:link`) |
| `s3` | S3-compatible driver |

Keys are **relative paths within the disk** (`avatars/1.jpg`): no leading slash, no absolute paths.

```php
use Spark\Facades\Storage;

// Default disk
Storage::put('reports/annual.txt', 'Summary data');
$contents = Storage::get('reports/annual.txt');
$exists = Storage::exists('reports/annual.txt');
$missing = Storage::missing('reports/annual.txt');
Storage::delete('reports/annual.txt');

// Explicit disk
Storage::disk('public')->put('avatars/user-1.jpg', $imageContent);
$publicUrl = Storage::disk('public')->url('avatars/user-1.jpg');

// Copy / move
Storage::disk('public')->copy('avatars/old.jpg', 'avatars/new.jpg');
Storage::disk('public')->move('temp/file.pdf', 'documents/file.pdf');

// Metadata
$size = Storage::disk('public')->size('avatars/user-1.jpg');
$mime = Storage::disk('public')->mimeType('avatars/user-1.jpg');
$lastModified = Storage::disk('public')->lastModified('avatars/user-1.jpg');

// Listing
$files = Storage::disk('public')->files('avatars');
$allFiles = Storage::disk('public')->allFiles('documents');
```

To store an existing local path, use `Storage::putFile()`.

## Disk uploaders

Disk-backed uploaders handle validation, resizing, and variant generation:

```php
$path = Storage::disk('public')
    ->uploader('avatars', extensions: ['jpg', 'png', 'webp'], maxSize: 2048)
    ->upload($request->file('avatar'));

// Fluent: image compression and resizing
$path = Storage::disk('public')
    ->uploader('photos', compress: 85, resize: [800 => 600])
    ->upload('photo');
```

## PSR-7 uploaded files

`Uploader::upload()` accepts `Psr\Http\Message\UploadedFileInterface`, native PHP upload arrays, field names, and lists/trees of uploads. The same extension, actual-file-size, image-processing, disk-driver, and batch-rollback rules apply.

```php
$uploadedFile = $request->toPsrRequest()->getUploadedFiles()['avatar'];
$path = Storage::disk('public')->uploader('avatars', extensions: ['jpg', 'png'])
    ->upload($uploadedFile);
```

- **Return values:** a single upload returns a path (or an array when image variants are generated); lists and nested trees return a flat list of paths.
- **Batches:** native PHP columns match by key; empty slots (`UPLOAD_ERR_NO_FILE`) are skipped; invalid structures are rejected before any upload is consumed.
- **One-shot:** PSR uploads are consumed via `moveTo()` and cannot be reused, even after a validation failure.
- **Staging:** uses `temp_dir('uploads')` under `app.temp_dir` (usually `storage/framework/temp`), created on demand and cleaned up on success and failure. Keep it outside the public web root.
- Raw array inputs still require genuine HTTP uploads.

## S3 and signed URLs

```php
// Temporary download URL (signed, expiry in seconds)
$downloadUrl = Storage::disk('s3')->temporaryUrl('invoices/inv-101.pdf', expires: 3600);

// Direct browser upload (signed PUT)
$signedPutUrl = Storage::disk('s3')->temporaryUploadUrl(
    'uploads/raw-video.mp4',
    expires: 300,
    contentType: 'video/mp4',
);
```

S3 needs `ext-curl`; listing/copying also needs `ext-simplexml`.

## File utility

`Spark\Utils\File` (global helper `fm()`) operates on the local server filesystem.

```php
use Spark\Utils\File;

// Checks
File::exists($path);
File::isFile($path);
File::isDirectory($path);
File::isReadable($path);
File::isWritable($path);

// Read / write
$contents = File::get($path);
File::put($path, 'Content to save');
File::append($path, "\nNew log line");
File::prepend($path, "Header line\n");

// Move / copy / delete
File::copy($source, $destination);
File::move($from, $to);
File::delete($path);
File::delete([$path1, $path2]);

// Directories
File::ensureDirectoryExists(storage_dir('exports'));
File::makeDirectory(storage_dir('reports'), mode: 0755, recursive: true);

// Properties
$ext = File::extension($path);      // 'jpg'
$name = File::name($path);          // 'avatar' (no extension)
$basename = File::basename($path);  // 'avatar.jpg'
$dirname = File::dirname($path);    // '/path/to'
$size = File::size($path);          // bytes
$mime = File::mimeType($path);      // 'image/jpeg'
```

`File::putAtomic($path, $contents)` writes through a unique temp file in the destination directory. A failed or partial string write preserves the existing destination, and temp files are cleaned up on failure or exception. Environment and config caches use this helper.

## Image utility

`Spark\Utils\Image` (helper `image()`) uses GD (`ext-gd`; MIME detection needs `ext-fileinfo`).

```php
use Spark\Utils\Image;

// Resize and compress
Image::from(storage_dir('uploads/original.jpg'))
    ->resize(800, 600)
    ->compress(80, destination: storage_dir('uploads/thumb.jpg'));

// Bulk resize to several sizes
$image = Image::from($sourcePath);
$image->bulkResize([
    'small' => [150, 150],
    'medium' => [400, 400],
    'large' => [1200, 800],
]);

// Rotate
$image->rotate(90);
```

## JWT utility

Low-level JSON Web Token encode/decode via `Spark\Utils\JWT`:

```php
use Spark\Utils\JWT;

$payload = [
    'sub' => $user->id,
    'role' => $user->role,
    'exp' => now()->addHours(2)->timestamp,
];

$jwt = JWT::encode($payload, key: config('app.key'), algo: 'HS256');

try {
    $decoded = JWT::decode($jwt, key: config('app.key'), verify: true);
    $userId = $decoded['sub'];
} catch (\Throwable $e) {
    abort(\Spark\Http\Response::STATUS_UNAUTHORIZED, 'Invalid or expired token.');
}
```

> [!TIP]
> For standard API authentication prefer `Auth::createToken()` and `Auth::revokeToken()`: they integrate with the authentication guards and token revocation tables.