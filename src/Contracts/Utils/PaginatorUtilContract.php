<?php

namespace Spark\Contracts\Utils;

interface PaginatorUtilContract
{

    public function getData(bool $lazy = false): array;

    public function hasData(): bool;

    public function hasLinks(): bool;

    public function getLinks(int $links = 2, array $classes = [], array $entity = []): string;

}