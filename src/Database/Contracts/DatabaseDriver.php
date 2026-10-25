<?php

namespace Spark\Database\Contracts;

/**
 * Interface Pdo Common + Supported Database Drivers
 *
 * This interface defines constants for supported database drivers.
 */
interface DatabaseDriver
{
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_PGSQL = 'pgsql';
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_SQLSRV = 'sqlsrv';
    public const DRIVER_OCI = 'oci';
    public const DRIVER_ODBC = 'odbc';
    public const DRIVER_FIREBIRD = 'firebird';
    public const DRIVER_IBM = 'ibm';
    public const DRIVER_INFORMIX = 'informix';
    public const DRIVER_DBLIB = 'dblib';
    public const DRIVER_CUBRID = 'cubrid';

    /**
     * An array of supported database drivers by tinycore/spark.
     */
    public const SUPPORTED_DRIVERS = [
        self::DRIVER_MYSQL,
        self::DRIVER_PGSQL,
        self::DRIVER_SQLITE,
    ];
}