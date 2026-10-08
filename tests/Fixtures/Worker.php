<?php

class DriverFixtureJob
{
    public function __construct(private string $filename)
    {
    }

    public function handle(int $number = 7): void
    {
        file_put_contents($this->filename, (string) $number);
    }
}

