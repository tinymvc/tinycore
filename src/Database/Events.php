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
}