<?php

namespace Spark\Database;

use Closure;
use Spark\Database\Contracts\EventsContract;

/**
 * Class Events
 * 
 * Handles model events such as creation, update, and deletion.
 * 
 * @package Spark\Database
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Events implements EventsContract
{
    /**
     * Events constructor.
     * 
     * Initializes the event handlers for model events.
     * 
     * @param Closure|null $created The closure to be executed when a model is created.
     * @param Closure|null $updated The closure to be executed when a model is updated.
     * @param Closure|null $deleted The closure to be executed when a model is deleted.
     * @param Closure|null $changed The closure to be executed when a model is changed.
     */
    public function __construct(
        private null|Closure $created = null,
        private null|Closure $updated = null,
        private null|Closure $deleted = null,
        private null|Closure $changed = null,
    ) {
    }

    /**
     * Create a new Events instance.
     * 
     * @param Closure|null $created The closure to be executed when a model is created.
     * @param Closure|null $updated The closure to be executed when a model is updated.
     * @param Closure|null $deleted The closure to be executed when a model is deleted.
     * @param Closure|null $changed The closure to be executed when a model is changed.
     */
    public static function make(
        null|Closure $created = null,
        null|Closure $updated = null,
        null|Closure $deleted = null,
        null|Closure $changed = null,
    ): static {
        return new static($created, $updated, $deleted, $changed);
    }

    /**
     * Create a new Events instance with no event handlers.
     * 
     * @return static A new Events instance with no event handlers.
     */
    public static function none(): static
    {
        return new static();
    }

    /**
     * Triggered when a new model is created.
     *
     * @return void
     */
    public function created(): void
    {
        $this->created && ($this->created)();
    }

    /**
     * Triggered when a model is updated.
     *
     * @return void
     */
    public function updated(): void
    {
        $this->updated && ($this->updated)();
    }

    /**
     * Triggered when a model is deleted.
     *
     * @return void
     */
    public function deleted(): void
    {
        $this->deleted && ($this->deleted)();
    }

    /**
     * Triggered when a model is changed.
     *
     * @return void
     */
    public function changed(): void
    {
        $this->changed && ($this->changed)();
    }

    /**
     * Check if any event handlers are set.
     *
     * @return bool True if any event handlers are set, false otherwise.
     */
    public function hasEvents(): bool
    {
        return $this->created !== null ||
            $this->updated !== null ||
            $this->deleted !== null ||
            $this->changed !== null;
    }

    /**
     * Check if a specific event handler is set.
     *
     * @param string $event The event name to check ('created', 'updated', 'deleted', 'changed').
     * @return bool True if the specified event handler is set, false otherwise.
     */
    public function hasEvent(string $event): bool
    {
        return match ($event) {
            'created' => $this->created !== null,
            'updated' => $this->updated !== null,
            'deleted' => $this->deleted !== null,
            'changed' => $this->changed !== null,
            default => false,
        };
    }
}