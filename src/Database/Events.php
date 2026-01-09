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
     */
    public function __construct(private null|Closure $created, private null|Closure $updated, private null|Closure $deleted)
    {
    }

    /**
     * Create a new Events instance.
     * 
     * @param Closure|null $created The closure to be executed when a model is created.
     * @param Closure|null $updated The closure to be executed when a model is updated.
     * @param Closure|null $deleted The closure to be executed when a model is deleted.
     */
    public static function make(null|Closure $created, null|Closure $updated, null|Closure $deleted): static
    {
        return new static($created, $updated, $deleted);
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
}