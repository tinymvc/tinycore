<?php

require_once dirname(__DIR__) . '/Support/StorageDriverContract.php';

final class DatabaseStorageContractTest extends StorageDriverContract
{
    protected string $driver = 'database';
}
