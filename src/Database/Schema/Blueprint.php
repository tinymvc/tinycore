<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\BlueprintContract;
use Spark\Database\Schema\Exceptions\InvalidBlueprintArgumentException;

/**
 * Class Blueprint
 *
 * This class represents a database table blueprint.
 * It provides methods for creating and modifying database tables.
 * 
 * @package Spark\Database\Schema
 */
class Blueprint implements BlueprintContract
{
    /**
     * @var array List of columns in the blueprint.
     */
    private array $columns = [];

    /**
     * @var array List of indexes in the blueprint.
     */
    private array $indexes = [];

    /**
     * @var array List of primary keys in the blueprint.
     */
    private array $primaryKeys = [];

    /**
     * @var array List of foreign keys in the blueprint.
     */
    private array $foreignKeys = [];

    /**
     * @var array List of columns to drop in the blueprint.
     */
    private array $drops = [];

    /**
     * @var array List of columns to rename in the blueprint.
     */
    private array $renames = [];

    /**
     * Blueprint constructor.
     *
     * @param string $table The name of the table.
     */
    public function __construct(private string $table, private bool $isAlter = false)
    {
    }

    /**
     * Add an 'id' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function id(string $name = 'id'): Column
    {
        return $this->addColumn('id', $name);
    }

    /**
     * Add a 'string' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $length The length of the string.
     * @return Column
     */
    public function string(string $name, int $length = 255): Column
    {
        return $this->addColumn('string', $name, compact('length'));
    }

    /**
     * Add an 'integer' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function integer(string $name): Column
    {
        return $this->addColumn('integer', $name);
    }

    /**
     * Add a 'text' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function text(string $name): Column
    {
        return $this->addColumn('text', $name);
    }

    /**
     * Add 'created_at' and 'updated_at' timestamp columns to the blueprint.
     *
     * @return void
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->useCurrent();
        $this->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    }

    /**
     * Add a 'timestamp' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function timestamp(string $name): Column
    {
        return $this->addColumn('timestamp', $name);
    }

    /**
     * Add a 'boolean' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function boolean(string $name): Column
    {
        return $this->addColumn('boolean', $name);
    }

    /**
     * Add a 'foreignId' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return ForeignKeyConstraint
     */
    public function foreignId(string $name): ForeignKeyConstraint
    {
        $this->integer($name)->unsigned();
        return $this->foreign($name);
    }

    /**
     * Add a 'foreign' constraint to the blueprint.
     *
     * @param array|string $columns The column(s) to constrain.
     * @return ForeignKeyConstraint
     */
    public function foreign(array|string $columns, string $table = null): ForeignKeyConstraint
    {
        $constraint = new ForeignKeyConstraint($columns);

        if ($table) {
            $constraint->on($table);
        }

        $this->foreignKeys[] = $constraint;
        return $constraint;
    }

    /**
     * Add a 'constrained' foreign key to the blueprint.
     *
     * @param string $column The name of the column.
     * @param string $table The name of the table.
     * @return ForeignKeyConstraint
     */
    public function constrained(string $column, string $table = null): ForeignKeyConstraint
    {
        return $this->foreign($column)->constrained($table);
    }

    /**
     * Add a 'bigIncrements' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function bigIncrements(string $name = 'id'): Column
    {
        $column = $this->addColumn('bigIncrements', $name);
        $this->primary($name);

        return $column;
    }

    /**
     * Add a 'bigInteger' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function bigInteger(string $name): Column
    {
        return $this->addColumn('bigInteger', $name);
    }

    /**
     * Add a 'decimal' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the decimal.
     * @param int $scale The scale of the decimal.
     * @return Column
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): Column
    {
        return $this->addColumn('decimal', $name, compact('precision', 'scale'));
    }

    /**
     * Add a 'double' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the double.
     * @param int $scale The scale of the double.
     * @return Column
     */
    public function double(string $name, int $precision = null, int $scale = null): Column
    {
        return $this->addColumn('double', $name, compact('precision', 'scale'));
    }

    /**
     * Add a 'float' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the float.
     * @param int $scale The scale of the float.
     * @return Column
     */
    public function float(string $name, int $precision = null, int $scale = null): Column
    {
        return $this->addColumn('float', $name, compact('precision', 'scale'));
    }

    /**
     * Add an 'unsignedBigInteger' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedBigInteger(string $name): Column
    {
        return $this->bigInteger($name)->unsigned();
    }

    /**
     * Add a 'char' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $length The length of the char.
     * @return Column
     */
    public function char(string $name, int $length = 255): Column
    {
        return $this->addColumn('char', $name, compact('length'));
    }

    /**
     * Add an 'enum' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param array $allowed The allowed values for the enum.
     * @return Column
     */
    public function enum(string $name, array $allowed): Column
    {
        if (empty($allowed)) {
            throw new InvalidBlueprintArgumentException('Enum values cannot be empty');
        }

        return $this->addColumn('enum', $name, compact('name', 'allowed'));
    }

    /**
     * Add a 'longText' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function longText(string $name): Column
    {
        return $this->addColumn('longText', $name);
    }

    /**
     * Add a 'json' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function json(string $name): Column
    {
        return $this->addColumn('json', $name);
    }

    /**
     * Add a 'date' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function date(string $name): Column
    {
        return $this->addColumn('date', $name);
    }

    /**
     * Add a 'dateTime' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the dateTime.
     * @return Column
     */
    public function dateTime(string $name, int $precision = 0): Column
    {
        return $this->addColumn('dateTime', $name, compact('precision'));
    }

    /**
     * Add a 'time' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the time.
     * @return Column
     */
    public function time(string $name, int $precision = 0): Column
    {
        return $this->addColumn('time', $name, compact('precision'));
    }

    /**
     * Add a 'binary' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function binary(string $name): Column
    {
        return $this->addColumn('binary', $name);
    }

    /**
     * Add a 'uuid' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function uuid(string $name): Column
    {
        return $this->addColumn('uuid', $name);
    }

    /**
     * Add a 'ipAddress' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function ipAddress(string $name): Column
    {
        return $this->string($name, 45);
    }

    /**
     * Add a 'macAddress' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function macAddress(string $name): Column
    {
        return $this->string($name, 17);
    }

    /**
     * Add a 'rememberToken' column to the blueprint.
     */
    public function rememberToken(): void
    {
        $this->string('remember_token', 100)->nullable();
    }

    /**
     * Add 'deleted_at' column to the blueprint.
     */
    public function softDeletes(): void
    {
        $this->timestamp('deleted_at')->nullable();
    }

    /**
     * Add nullable timestamps to the blueprint.
     */
    public function nullableTimestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * Add a primary key to the blueprint.
     *
     * @param string|array $columns The column(s) to set as primary key.
     * @return void
     */
    public function primary($columns): void
    {
        $this->primaryKeys[] = (array) $columns;
    }

    /**
     * Add a unique index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as unique index.
     * @return void
     */
    public function unique($columns): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => (array) $columns];
    }

    /**
     * Add an index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as index.
     * @return void
     */
    public function index($columns): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => (array) $columns];
    }

    /**
     * Compile the blueprint into a SQL statement.
     *
     * @return string
     */
    public function compileCreate(): string
    {
        $grammar = Schema::getGrammar();
        $elements = [];

        // Add columns
        foreach ($this->columns as $column) {
            $elements[] = $column->toSql();
        }

        // Add primary keys
        foreach ($this->primaryKeys as $primaryKey) {
            $elements[] = 'PRIMARY KEY (' . $grammar->columnize($primaryKey) . ')';
        }

        // Add foreign keys
        foreach ($this->foreignKeys as $foreignKey) {
            $elements[] = $grammar->compileForeignKey($foreignKey);
        }

        $statements = [
            "CREATE TABLE " . $grammar->wrapTable($this->table) . " (\n" . implode(",\n", $elements) . "\n);"
        ];

        // Add secondary indexes
        foreach ($this->indexes as $index) {
            $statements[] = $grammar->compileIndex($this->table, $index);
        }

        return implode("\n", $statements);
    }

    /**
     * Drop a column from the blueprint.
     *
     * @param string|array $columns The column(s) to drop.
     * @return self
     */
    public function dropColumn($columns): self
    {
        $this->drops[] = ['type' => 'column', 'names' => (array) $columns];
        return $this;
    }

    /**
     * Drop an index from the blueprint.
     *
     * @param string|array $columns The name(s) of the index(es) to drop.
     * @param string $type The type of index to drop.
     * @return self
     */
    public function dropIndex($columns, $type = null): self
    {
        $type ??= 'index';

        $this->drops[] = ['type' => $type, 'columns' => (array) $columns];
        return $this;
    }

    /**
     * Drop a foreign key constraint from the blueprint.
     *
     * @param string|array $columns The name(s) of the foreign key constraint(s) to drop.
     * @param string $onTable The name of the table that has the foreign key constraint.
     * @return self
     */
    public function dropForeign($columns, $onTable): self
    {
        $this->drops[] = ['type' => 'foreign', 'table' => $onTable, 'columns' => (array) $columns];
        return $this;
    }

    /**
     * Rename a column in the blueprint.
     *
     * @param string $from The original name of the column.
     * @param string $to The new name of the column.
     * @return self
     */
    public function renameColumn(string $from, string $to): self
    {
        $this->renames[] = compact('from', 'to');
        return $this;
    }

    /**
     * Compile the blueprint into an ALTER TABLE statement.
     *
     * @return string
     */
    public function compileAlter(): string
    {
        $grammar = Schema::getGrammar();
        $statements = [];

        foreach ($this->drops as $drop) {
            $statements[] = $grammar->compileDrop($this->table, $drop);
        }

        foreach ($this->renames as $rename) {
            $statements[] = $grammar->compileRenameColumn($this->table, $rename['from'], $rename['to']);
        }

        return implode(";\n", $statements);
    }

    /**
     * Add a column to the blueprint.
     *
     * @param string $type The type of the column.
     * @param string $name The name of the column.
     * @param array $parameters The parameters of the column.
     * @return Column
     */
    private function addColumn(string $type, string $name, array $parameters = []): Column
    {
        $column = new Column($name, $type, $parameters);
        $this->columns[] = $column;

        if ($type === 'id') {
            $this->primary($name);
        }

        return $column;
    }
}
