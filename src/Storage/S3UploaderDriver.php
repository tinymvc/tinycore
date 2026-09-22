<?php

namespace Spark\Storage;

use Spark\Contracts\Utils\UploaderUtilDriverInterface;

/** Reuses the uploader's existing driver contract for S3-compatible storage. */
class S3UploaderDriver implements UploaderUtilDriverInterface
{
    public function __construct(private S3Storage $storage, private ?string $acl = null)
    {
        if ($acl !== null && !\in_array($acl, ['private', 'public-read'], true)) {
            throw new \InvalidArgumentException('S3 ACL must be null, private, or public-read.');
        }
    }

    public function upload(string $filepath, string $destination): bool
    {
        return $this->storage->uploadFile($filepath, StoragePath::normalize($destination), acl: $this->acl)['success'];
    }

    public function delete(string $destination): bool
    {
        return $this->storage->deleteFile(StoragePath::normalize($destination));
    }

    public function copy(string $from, string $to): bool
    {
        return $this->storage->copyFile(StoragePath::normalize($from), StoragePath::normalize($to), acl: $this->acl);
    }

    public function getPublicUrl(string $path): string
    {
        return $this->storage->getPublicUrl(StoragePath::normalize($path));
    }
}
