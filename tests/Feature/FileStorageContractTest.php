<?php

require_once dirname(__DIR__) . '/Support/StorageDriverContract.php';

final class FileStorageContractTest extends StorageDriverContract
{
    protected string $driver = 'file';
}
