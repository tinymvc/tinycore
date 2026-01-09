<?php

namespace Spark\Database\Contracts;

/**
 * Interface EventsContract
 *
 * This interface defines the contract for database event handling.
 *
 * @package Spark\Database\Contracts
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
interface EventsContract
{
    /**
     * Triggered when a new model is created.
     *
     * @return void
     */
    public function created(): void;

    /**
     * Triggered when a model is updated.
     *
     * @return void
     */
    public function updated(): void;

    /**
     * Triggered when a model is deleted.
     *
     * @return void
     */
    public function deleted(): void;
}