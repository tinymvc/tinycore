<?php

namespace Spark\Database\Concerns;

use function array_key_exists;
use function array_keys;
use function array_shift;
use function explode;
use function implode;
use function in_array;
use function md5;
use function serialize;
use function str_contains;
use function str_starts_with;

/**
 * Trait InteractsWithTracking
 *
 * Handles change tracking, dirty state detection, original attributes preservation,
 * and lifecycle event tracking for models.
 *
 * @package Spark\Database\Concerns
 */
trait InteractsWithTracking
{
    /**
     * Track vars for internal usage.
     *
     * @var array
     */
    protected array $tracking = [];

    /**
     * Checks if the model was newly created (i.e., it has a primary key but no original primary key).
     *
     * @return bool True if the model was newly created, false otherwise.
     */
    public function wasNewlyCreated(): bool
    {
        return $this->exists() && !$this->hasOriginal() && ($this->tracking['was_created'] ?? false);
    }

    /**
     * Checks if the model was updated (i.e., it has an original primary key and changes were made).
     *
     * @return bool True if the model was updated, false otherwise.
     */
    public function wasUpdated(): bool
    {
        return $this->exists() && $this->hasOriginal() && ($this->tracking['was_updated'] ?? false);
    }

    /**
     * Checks if the model was created (i.e., it has a primary key and was marked as created).
     *
     * @return bool True if the model was created, false otherwise.
     */
    public function wasCreated(): bool
    {
        return $this->exists() && ($this->tracking['was_created'] ?? false);
    }

    /**
     * Checks if the model was either newly created or updated.
     *
     * @return bool True if the model was changed, false otherwise.
     */
    public function wasChanged(): bool
    {
        return $this->wasNewlyCreated() || $this->wasCreated() || $this->wasUpdated();
    }

    /**
     * Checks if the model was deleted.
     *
     * @return bool True if the model was deleted, false otherwise.
     */
    public function wasDeleted(): bool
    {
        return $this->tracking['was_deleted'] ?? false;
    }

    /**
     * Get the original value of a specific attribute before any changes were made.
     *
     * @param string $name The name of the attribute to get.
     * @param mixed $default The default value to return if the attribute is not set.
     * @return mixed The original value of the attribute or the default value if not set.
     */
    public function getOriginal(string $name, $default = null): mixed
    {
        return $this->tracking['original_attributes'][$name] ?? $this->attributes[$name] ?? $default;
    }

    /**
     * Get the original value of a nested attribute before any changes.
     *
     * @param string $path The dot-notation path to the attribute.
     * @param mixed $default The default value if no original exists.
     * @return mixed The original value or the default.
     */
    public function getNestedOriginal(string $path, mixed $default = null): mixed
    {
        // Check if we have a direct record of this path's original value
        if (isset($this->tracking['changed_paths'][$path])) {
            return $this->tracking['changed_paths'][$path]['original'];
        }

        // Fall back to getting from the original root attribute
        $segments = explode('.', $path);
        $rootKey = array_shift($segments);

        if (isset($this->tracking['original_attributes'][$rootKey])) {
            $originalRoot = $this->tracking['original_attributes'][$rootKey];
            return data_get($originalRoot, implode('.', $segments), $default);
        }

        return data_get($this->attributes, $path, $default);
    }

    /**
     * Get the changes made to the model compared to its original state.
     *
     * @return array An associative array of changed attributes and their new values.
     */
    public function getChanges(): array
    {
        if (!$this->hasOriginal()) {
            return []; // No original data to compare with.
        }

        return $this->tracking['original_attributes'];
    }

    /**
     * Get all nested paths that have been changed.
     *
     * @return array An associative array of changed paths with their original and new values.
     */
    public function getNestedChanges(): array
    {
        return $this->tracking['changed_paths'] ?? [];
    }

    /**
     * Check if the model has any changes compared to its original state.
     *
     * @return bool True if there are changes, false otherwise.
     */
    public function hasChanges(): bool
    {
        return !empty($this->getChanges());
    }

    /**
     * Clear the original attributes of the model.
     * 
     * @return void
     */
    public function clearOriginal(): void
    {
        unset($this->tracking['original_attributes'], $this->tracking['changed_paths']);
    }

    /**
     * Check if the model has original attributes stored.
     * 
     * @return bool
     */
    public function hasOriginal(): bool
    {
        return isset($this->tracking['original_attributes']);
    }

    /**
     * Check if the model or a specific field is dirty (has changes).
     *
     * @param string|null $field The specific field to check for changes. If null, checks the entire model.
     * @return bool True if the model or the specified field has changes, false otherwise.
     */
    public function isDirty(null|string $field = null): bool
    {
        if (($originalHash = ($this->tracking['hash'] ?? null)) === null) {
            return false; // No original hash means we can't determine if it's dirty.
        }

        $currentHash = $this->makeHash();

        $dirty = $field === null ? $this->hasChanges()
            : in_array($field, array_keys($this->getChanges()));

        // If the model is dirty, it must have changes and the current 
        // hash should differ from the original hash.
        return $dirty && $currentHash !== $originalHash;
    }

    /**
     * Check if the model or a specific field has no unsaved changes.
     *
     * @param string|null $field
     * @return bool
     */
    public function isClean(null|string $field = null): bool
    {
        return !$this->isDirty($field);
    }

    /**
     * Check if a specific nested path has been changed.
     *
     * @param string|null $path The dot-notation path to check, or null to check if any nested path changed.
     * @return bool True if the path (or any path) has been changed.
     */
    public function isNestedDirty(null|string $path = null): bool
    {
        $changedPaths = $this->tracking['changed_paths'] ?? [];

        if ($path === null) {
            return !empty($changedPaths);
        }

        // Check exact path match
        if (isset($changedPaths[$path])) {
            return true;
        }

        // Check if any child paths of the given path have changed
        $prefix = "$path.";
        foreach (array_keys($changedPaths) as $changedPath) {
            if (str_starts_with($changedPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restore the model's attributes to their original state.
     * 
     * This method reverts any changes made to the model since the last save.
     * 
     * @return void
     */
    public function restoreOriginal(): void
    {
        if ($this->hasOriginal()) {
            $this->attributes = [...$this->attributes, ...$this->tracking['original_attributes']];
            $this->clearOriginal();
        }
    }

    /**
     * Mark the model as updated.
     * 
     * @return void
     */
    public function trackUpdated(): void
    {
        $this->tracking['was_updated'] = true;
        $this->tracking['hash'] = $this->makeHash();
        $this->triggerEvent('updated'); // Trigger the 'updated' event.
    }

    /**
     * Mark the model as created.
     * 
     * @return void
     */
    public function trackCreated(): void
    {
        $this->syncPersistenceKey($this->primaryValue());
        $this->tracking['was_created'] = true;
        $this->tracking['hash'] = $this->makeHash();
        $this->triggerEvent('created'); // Trigger the 'created' event.
    }

    /**
     * Mark the model as deleted.
     * 
     * @return void
     */
    public function trackDeleted(): void
    {
        $this->tracking['was_deleted'] = true;
        $this->triggerEvent('deleted'); // Trigger the 'deleted' event.
    }

    /**
     * Trigger a model event if the events method exists.
     *
     * @param string $event The name of the event to trigger.
     * @return void
     */
    public function triggerEvent(string $event): void
    {
        \Spark\Telemetry::emit('model.action', fn() => [
            'action' => $event,
            'model' => static::class,
            'key' => $this->primaryValue(),
            'table' => $this->getTable(),
            'attributes' => $this->getAttributes(),
            'changes' => $this->getChanges(),
        ]);

        /** @var \Spark\Database\Events $events */
        $events = $this->events();

        if (!$events->hasEvents()) {
            return; // No events to trigger.
        }

        if (in_array($event, ['created', 'updated', 'deleted'])) {
            $events->changed();
        }

        $events->$event(); // Call the event method dynamically.
    }

    /**
     * Clear all tracking information for the model.
     * 
     * @return void
     */
    public function clearTracks(): void
    {
        $this->tracking = [];
    }

    /**
     * Generate a hash of the model's fillable data for change tracking.
     *
     * @return string The generated hash.
     */
    protected function makeHash(): string
    {
        // Storage casts can be nondeterministic (encryption/hashing) or have side effects.
        return md5(serialize($this->getFillableData()));
    }

    /**
     * Update the value of a specific attribute and track changes if necessary.
     *
     * @param string $key The name of the attribute to update.
     * @param mixed $value The new value for the attribute.
     * @return void
     */
    protected function updateAttributeValue(string $key, mixed $value): void
    {
        if (isset($this->tracking['hash']) && $this->tracking['hash'] === $this->makeHash()) {
            $this->clearOriginal();
        }

        // Only track changes if the model has a primary key and an original hash exists (i.e., it exists in the database).
        if (
            $this->hasPrimaryValue() && isset($this->tracking['hash']) &&
            !array_key_exists($key, $this->tracking['original_attributes'] ?? []) &&
            ($this->attributes[$key] ?? null) !== $value
        ) {
            $this->tracking['original_attributes'][$key] = ($this->attributes[$key] ?? null);
        }

        $this->attributes[$key] = $value; // Set the new value for the attribute.
    }

    /**
     * Update a nested attribute value using dot notation and track changes.
     *
     * This method handles both simple attributes (e.g., 'name') and nested paths
     * (e.g., 'meta.country'). For nested paths, it tracks the root attribute's original
     * value and records which specific nested paths were modified.
     *
     * @param string $path The dot-notation path to the attribute (e.g., 'meta.country').
     * @param mixed $value The new value for the attribute.
     * @return void
     */
    protected function updateNestedAttributeValue(string $path, mixed $value): void
    {
        // If no dot notation, delegate to simple attribute update
        if (!str_contains($path, '.')) {
            $this->updateAttributeValue($path, $value);
            return;
        }

        // Only track if model exists in database
        if ($this->hasPrimaryValue() && isset($this->tracking['hash'])) {
            // Track the specific nested path that was changed
            $currentValue = data_get($this->attributes, $path);
            if ($currentValue === $value) {
                return; // No change, so no need to track or write.
            }

            // Extract the root key (e.g., 'meta' from 'meta.country')
            $segments = explode('.', $path);
            $rootKey = $segments[0];

            // Track the original value of the root attribute if not already tracked
            if (!array_key_exists($rootKey, $this->tracking['original_attributes'] ?? [])) {
                $this->tracking['original_attributes'][$rootKey] = $this->attributes[$rootKey] ?? null;
            }

            // Track the specific nested path change with original and new values
            $this->tracking['changed_paths'][$path] = ['original' => $currentValue, 'new' => $value];
        }

        // Set the nested value
        data_set($this->attributes, $path, $value);
    }

    /**
     * Track the removal of a nested attribute before it's forgotten.
     *
     * @param string $path The dot-notation path to the attribute being removed.
     * @return void
     */
    protected function trackNestedAttributeRemoval(string $path): void
    {
        // Only track if model exists in database
        if (!$this->hasPrimaryValue() || !isset($this->tracking['hash'])) {
            return;
        }

        if (!str_contains($path, '.')) {
            // Simple attribute removal - track the original value
            if (
                !array_key_exists($path, $this->tracking['original_attributes'] ?? []) &&
                array_key_exists($path, $this->attributes)
            ) {
                $this->tracking['original_attributes'][$path] = $this->attributes[$path];
            }
            return;
        }

        // For nested paths, track the root attribute's original value
        $segments = explode('.', $path);
        $rootKey = $segments[0];

        if (!array_key_exists($rootKey, $this->tracking['original_attributes'] ?? [])) {
            $this->tracking['original_attributes'][$rootKey] = $this->attributes[$rootKey] ?? null;
        }

        // Track the specific path removal
        $currentValue = data_get($this->attributes, $path);
        if ($currentValue !== null) {
            $this->tracking['changed_paths'][$path] = [
                'original' => $currentValue,
                'new' => null,
                'removed' => true,
            ];
        }
    }
}
