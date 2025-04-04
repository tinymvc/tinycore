<?php

namespace Spark\Console\Contracts;

/**
 * Interface ConsoleContract
 */
interface ConsoleContract
{
    /**
     * Runs the console application.
     *
     * This method is called when the console application is started. It is
     * used to initialize the console application and execute the commands.
     *
     * @return void
     */
    public function run(): void;
}
