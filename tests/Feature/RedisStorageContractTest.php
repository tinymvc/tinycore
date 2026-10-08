<?php

require_once dirname(__DIR__) . '/Support/StorageDriverContract.php';

final class RedisStorageContractTest extends StorageDriverContract
{
    protected string $driver = 'redis';
}
