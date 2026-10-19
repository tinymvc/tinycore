# Files, Storage, and Image Utilities

Read this reference for filesystem storage, S3 integration, disk uploaders, and the native `File`, `Image`, and `JWT` utilities in TinyMVC / TinyCore (Spark). All paths refer to the application root.

---

## Filesystem Storage (`Spark\Facades\Storage`)

Use **`Spark\Facades\Storage`** for filesystem operations across local, public, and S3-compatible disks. Configuration is located in `config/storage.php`; `FILESYSTEM_DISK` chooses the default.

### Common Operations

```php
use Spark\Facades\Storage;

// Default disk operations
Storage::put('reports/annual.txt', 'Summary data');
$contents = Storage::get('reports/annual.txt');
$exists = Storage::exists('reports/annual.txt');
$missing = Storage::missing('reports/annual.txt');
Storage::delete('reports/annual.txt');

// Explicit disk operations ('public', 'local', 's3')
Storage::disk('public')->put('avatars/user-1.jpg', $imageContent);
$publicUrl = Storage::disk('public')->url('avatars/user-1.jpg');

// Copy and Move
Storage::disk('public')->copy('avatars/old.jpg', 'avatars/new.jpg');
Storage::disk('public')->move('temp/file.pdf', 'documents/file.pdf');

// Metadata
$size = Storage::disk('public')->size('avatars/user-1.jpg');
$mime = Storage::disk('public')->mimeType('avatars/user-1.jpg');
$lastModified = Storage::disk('public')->lastModified('avatars/user-1.jpg');

// Listing files
$files = Storage::disk('public')->files('avatars');
$allFiles = Storage::disk('public')->allFiles('documents');
```

- **`local`**: Defaults to private storage at `storage/app/private`.
- **`public`**: Uses `storage/app/public` exposed via the public symlink (`php spark storage:link`).
- Keys are always relative paths within the disk (e.g. `avatars/1.jpg`), never leading slashes or absolute paths.

---

## Disk Uploaders

Use disk-backed uploaders for automatic file validation, resizing, and variant generation:

```php
use Spark\Facades\Storage;

$path = Storage::disk('public')
    ->uploader('avatars', extensions: ['jpg', 'png', 'webp'], maxSize: 2048)
    ->upload($request->file('avatar'));

// Fluent uploader with image compression and resizing
$path = Storage::disk('public')
    ->uploader('photos', compress: 85, resize: [800 => 600])
    ->upload('photo');
```

---

## S3 Direct Operations and Signed URLs

When using the `s3` storage driver:

```php
use Spark\Facades\Storage;

// 1. Temporary Download URL (signed S3 URL with expiry in seconds)
$downloadUrl = Storage::disk('s3')->temporaryUrl('invoices/inv-101.pdf', seconds: 3600);

// 2. Direct Browser Upload Signed URL
$signedPutUrl = Storage::disk('s3')->temporaryUploadUrl(
    'uploads/raw-video.mp4',
    seconds: 300,
    contentType: 'video/mp4',
);
```

---

## Local Filesystem Utility (`Spark\Utils\File` and `fm()`)

For direct local server filesystem manipulation, use `Spark\Utils\File` (or the global `fm()` helper):

```php
use Spark\Utils\File;

// 1. Existence and Type Checks
File::exists($path);
File::isFile($path);
File::isDirectory($path);
File::isReadable($path);
File::isWritable($path);

// 2. Reading and Writing Content
$contents = File::get($path);
File::put($path, 'Content to save');
File::append($path, "\nNew log line");
File::prepend($path, "Header line\n");

// 3. Moving, Copying & Deleting
File::copy($source, $destination);
File::move($from, $to);
File::delete($path);
File::delete([$path1, $path2]);

// 4. Directory Management
File::ensureDirectoryExists(storage_dir('exports'));
File::makeDirectory(storage_dir('reports'), mode: 0755, recursive: true);

// 5. File Properties and Names
$ext = File::extension($path);         // 'jpg'
$name = File::name($path);             // 'avatar' (without extension)
$basename = File::basename($path);     // 'avatar.jpg'
$dirname = File::dirname($path);       // '/path/to'
$size = File::size($path);             // bytes
$mime = File::mimeType($path);         // 'image/jpeg'
```

---

## Image Manipulation (`Spark\Utils\Image` and `image()`)

TinyCore includes native image manipulation powered by GD via `Spark\Utils\Image`:

```php
use Spark\Utils\Image;

// Resize and compress an image
Image::from(storage_dir('uploads/original.jpg'))
    ->resize(800, 600)
    ->compress(80, destination: storage_dir('uploads/thumb.jpg'));

// Bulk resizing to multiple formats
$image = Image::from($sourcePath);
$image->bulkResize([
    'small' => [150, 150],
    'medium' => [400, 400],
    'large' => [1200, 800],
]);

// Rotate image
$image->rotate(90);
```

---

## JWT Utilities (`Spark\Utils\JWT`)

For low-level JSON Web Token encoding and decoding:

```php
use Spark\Utils\JWT;

// Encode payload
$payload = [
    'sub' => $user->id,
    'role' => $user->role,
    'exp' => now()->addHours(2)->timestamp,
];

$jwt = JWT::encode($payload, key: config('app.key'), algo: 'HS256');

// Decode and verify
try {
    $decoded = JWT::decode($jwt, key: config('app.key'), verify: true);
    $userId = $decoded['sub'];
} catch (\Throwable $e) {
    abort(401, 'Invalid or expired token.');
}
```

> [!TIP]
> For standard API authentication, prefer **`Auth::createToken()`** and **`Auth::revokeToken()`**, which automatically integrate with TinyCore's authentication guards and token revocation tables.
