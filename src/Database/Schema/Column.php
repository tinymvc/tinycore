<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\ColumnContract;

/**
 * Class representing a database column.
 *
 * This class implements the ColumnContract and provides methods
 * for defining column properties and generating SQL.
 * 
 * @package Spark\Database\Schema
 */
class Column implements ColumnContract
{
    /**
     * @var array List of modifiers for the column
     */
    protected $modifiers = [];

    /**
     * Column constructor.
     *
     * @param string $name The name of the column
     * @param string $type The type of the column
     * @param array $parameters Additional parameters for the column
     */
    public function __construct(private string $name, private string $type, private array $parameters = [])
    {
    }

    /**
     * Marks the column as nullable.
     *
     * @return self
     */
    public function nullable(): self
    {
        $this->modifiers[] = 'nullable';
        return $this;
    }

    /**
     * Marks the column as unique.
     *
     * This method adds a "unique" modifier to the column, which
     * will cause the column to be created with a unique index.
     *
     * @return self
     */
    public function unique(): self
    {
        $this->modifiers[] = 'unique';
        return $this;
    }

    /**
     * Marks the column as required.
     *
     * @return self
     */
    public function required(): self
    {
        $this->modifiers[] = 'required';
        return $this;
    }

    /**
     * Sets a default value for the column.
     *
     * @param mixed $value The default value
     * @return self
     */
    public function default($value): self
    {
        $this->modifiers[] = ['default' => $value];
        return $this;
    }

    /**
     * Marks the column as unsigned.
     *
     * @return self
     */
    public function unsigned(): self
    {
        $this->modifiers[] = 'unsigned';
        return $this;
    }

    /**
     * Marks the column as auto-incrementing.
     *
     * @return self
     */
    public function autoIncrement(): self
    {
        $this->modifiers[] = 'auto_increment';
        return $this;
    }

    /**
     * Specifies the column after which this column should be placed.
     *
     * @param string $column The column name
     * @return self
     */
    public function after(string $column)
    {
        $this->modifiers[] = ['after' => $column];
        return $this;
    }

    /**
     * Sets the character set for the column.
     *
     * @param string $charset The character set
     * @return self
     */
    public function charset(string $charset)
    {
        $this->modifiers[] = ['charset' => $charset];
        return $this;
    }

    /**
     * Sets the collation for the column.
     *
     * @param string $collation The collation
     * @return self
     */
    public function collation(string $collation)
    {
        $this->modifiers[] = ['collation' => $collation];
        return $this;
    }

    /**
     * Adds a comment for the column.
     *
     * @param string $comment The comment text
     * @return self
     */
    public function comment(string $comment)
    {
        $this->modifiers[] = ['comment' => $comment];
        return $this;
    }

    /**
     * Sets the default value of the column to CURRENT_TIMESTAMP.
     *
     * @return self
     */
    public function useCurrent()
    {
        $this->modifiers[] = 'default_current_timestamp';

        return $this;
    }

    /**
     * Marks the column to use CURRENT_TIMESTAMP on update.
     *
     * @return self
     */
    public function useCurrentOnUpdate()
    {
        $this->modifiers[] = 'on_update_current_timestamp';
        return $this;
    }

    /**
     * Converts the column definition to an SQL string.
     *
     * @return string The SQL representation of the column
     */
    public function toSql(): string
    {
        $grammar = Schema::getGrammar();
        $type = $grammar->mapColumnType($this->type, $this->parameters);

        $sql = [
            $grammar->wrapColumn($this->name),
            $type
        ];

        foreach ($this->modifiers as $modifier) {
            if (is_array($modifier)) {
                $key = key($modifier);
                $sql[] = $grammar->mapModifier($key, $modifier[$key]);
            } else {
                $sql[] = $grammar->mapModifier($modifier);
            }
        }

        return implode(' ', $sql);
    }
}
