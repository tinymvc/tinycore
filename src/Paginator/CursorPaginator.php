<?php

namespace Spark\Paginator;

use InvalidArgumentException;
use function array_slice;
use function count;
use function is_array;
use function is_scalar;

/** A page of ordered results with next/previous cursors and no total-count query. */
class CursorPaginator extends AbstractPaginator
{
    private ?Cursor $nextCursor = null;
    private ?Cursor $previousCursor = null;
    private string $cursorName;

    public function __construct(array $items, private int $perPage = 15, private ?Cursor $cursor = null, array $options = [])
    {
        $this->perPage = max(1, $perPage);
        $this->initialize([], $options);

        $this->cursorName = $options['cursorName'] ?? 'cursor';

        $parameters = $options['parameters'] ?? [];
        $hasMore = count($items) > $this->perPage;
        $items = array_slice($items, 0, $this->perPage);
        $backwards = $cursor?->pointsToPreviousItems() ?? false;

        if ($backwards) {
            $items = array_reverse($items);
        }

        $boundaries = [];
        foreach ($items as $item) {
            $values = [];
            foreach ($parameters as $column => $name) {
                $value = is_array($item) ? ($item[$name] ?? null) : ($item->$name ?? null);
                if (!is_scalar($value)) {
                    throw new InvalidArgumentException("Cursor column [$column] must be selected and non-null.");
                }
                $values[$column] = $value;
            }
            $boundaries[] = $values;
        }

        $this->setData($items);
        if ($items !== []) {
            if ($backwards || $hasMore) {
                $this->nextCursor = new Cursor($boundaries[array_key_last($boundaries)]);
            }
            if ($backwards ? $hasMore : $cursor !== null) {
                $this->previousCursor = new Cursor($boundaries[0], false);
            }
        }
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function cursor(): ?Cursor
    {
        return $this->cursor;
    }

    public function nextCursor(): ?Cursor
    {
        return $this->nextCursor;
    }

    public function previousCursor(): ?Cursor
    {
        return $this->previousCursor;
    }

    public function getCursorName(): string
    {
        return $this->cursorName;
    }

    public function url(Cursor|string|null $cursor): string
    {
        return $this->urlWithParameter($this->cursorName, $cursor instanceof Cursor ? $cursor->encode() : $cursor);
    }

    public function nextPageUrl(): ?string
    {
        return $this->nextCursor === null ? null : $this->url($this->nextCursor);
    }

    public function previousPageUrl(): ?string
    {
        return $this->previousCursor === null ? null : $this->url($this->previousCursor);
    }

    public function hasMorePages(): bool
    {
        return $this->nextCursor !== null;
    }

    public function paginationMeta(): array
    {
        return [
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'next_cursor' => $this->nextCursor?->encode(),
            'prev_cursor' => $this->previousCursor?->encode(),
        ];
    }

    public function toArray(): array
    {
        return $this->paginationMeta() + [
            'data' => $this->collection->toArray(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }
}
