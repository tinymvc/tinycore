<?php

namespace Spark\View;

use Spark\Support\HtmlString;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function trim;

/**
 * Class ComponentSlot
 *
 * Represents an anonymous component slot with content and attributes.
 *
 * @package Spark\View
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class ComponentSlot extends HtmlString
{
    use Macroable, Conditionable;

    public Attributes $attributes;

    public function __construct(string $html = '', array $attributes = [])
    {
        parent::__construct($html);
        $this->attributes = new Attributes($attributes);
    }

    /**
     * Determine if the slot has content.
     */
    public function isNotEmpty(): bool
    {
        return trim($this->html ?? '') !== '';
    }

    /**
     * Determine if the slot has content other than whitespace.
     */
    public function hasActualContent(): bool
    {
        return $this->isNotEmpty();
    }

    /**
     * Determine if the slot is empty.
     */
    public function isEmpty(): bool
    {
        return !$this->isNotEmpty();
    }
}
