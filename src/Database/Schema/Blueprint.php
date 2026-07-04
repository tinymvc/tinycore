<?php

namespace Spark\Database\Schema;

use Spark\Database\Schema\Contracts\BlueprintContract;
use Spark\Database\Schema\Exceptions\InvalidBlueprintArgumentException;
use Spark\Support\Traits\Macroable;
use function func_get_args;
use function is_array;
use function sprintf;

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
    use Macroable;

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
     * @var string|null The storage engine for MySQL tables.
     */
    private ?string $engine = null;

    /**
     * @var string The character set for the blueprint.
     */
    private string $charset;

    /**
     * @var string The collation for the blueprint.
     */
    private string $collation;

    /**
     * Blueprint constructor.
     *
     * @param string $table The name of the table.
     */
    public function __construct(private string $table)
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
        return $this->bigIncrements($name);
    }

    /**
     * Add an auto-incrementing integer primary key.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function increments(string $name): Column
    {
        return $this->addColumn('increments', $name);
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
     * Add an unsigned integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedInteger(string $name): Column
    {
        return $this->integer($name)->unsigned();
    }

    /**
     * Add a small integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function smallInteger(string $name): Column
    {
        return $this->addColumn('smallInteger', $name);
    }

    /**
     * Add an unsigned small integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedSmallInteger(string $name): Column
    {
        return $this->smallInteger($name)->unsigned();
    }

    /**
     * Add a medium integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function mediumInteger(string $name): Column
    {
        return $this->addColumn('mediumInteger', $name);
    }

    /**
     * Add an unsigned medium integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedMediumInteger(string $name): Column
    {
        return $this->mediumInteger($name)->unsigned();
    }

    /**
     * Add a 'tinyInteger' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function tinyInteger(string $name): Column
    {
        return $this->addColumn('tinyInteger', $name);
    }

    /**
     * Add an unsigned tiny integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedTinyInteger(string $name): Column
    {
        return $this->tinyInteger($name)->unsigned();
    }

    /**
     * Add an auto-incrementing tiny integer primary key.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function tinyIncrements(string $name): Column
    {
        return $this->addColumn('tinyIncrements', $name);
    }

    /**
     * Add an auto-incrementing small integer primary key.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function smallIncrements(string $name): Column
    {
        return $this->addColumn('smallIncrements', $name);
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
    public function timestamp(string $name, int $precision = 0): Column
    {
        return $this->addColumn('timestamp', $name, compact('precision'));
    }

    /**
     * Add a nullable timestamp column for soft deletes.
     *
     * @param string $name The name of the column.
     * @param int $precision The precision of the timestamp.
     * @return Column
     */
    public function nullableTimestamp(string $name, int $precision = 0): Column
    {
        return $this->timestamp($name, $precision)->nullable();
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
     * @param bool $nullable Whether the column is nullable.
     * @return ForeignKeyConstraint
     */
    public function foreignId(string $name, bool $nullable = false): ForeignKeyConstraint
    {
        $this->unsignedBigInteger($name)->nullable($nullable);

        return $this->foreign($name);
    }

    /**
     * Add a 'foreign' constraint to the blueprint.
     *
     * @param array|string $columns The column(s) to constrain.
     * @param string|null $name The name of the table.
     * @return ForeignKeyConstraint
     */
    public function foreign(array|string $columns, ?string $name = null): ForeignKeyConstraint
    {
        $constraint = new ForeignKeyConstraint($columns, $name);

        $this->foreignKeys[] = $constraint;
        return $constraint;
    }

    /**
     * Add a 'constrained' foreign key to the blueprint.
     *
     * @param string $column The name of the column.
     * @param string|null $table The name of the table.
     * @return ForeignKeyConstraint
     */
    public function constrained(string $column, ?string $table = null): ForeignKeyConstraint
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
        return $this->addColumn('bigIncrements', $name);
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
     * Add an unsigned big integer column.
     *
     * @param string $name The name of the column.
     * @return Column
     */
    public function unsignedBigInteger(string $name): Column
    {
        return $this->bigInteger($name)->unsigned();
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
     * @param ?int $precision The precision of the double.
     * @param ?int $scale The scale of the double.
     * @return Column
     */
    public function double(string $name, ?int $precision = null, ?int $scale = null): Column
    {
        return $this->addColumn('double', $name, compact('precision', 'scale'));
    }

    /**
     * Add a 'float' column to the blueprint.
     *
     * @param string $name The name of the column.
     * @param ?int $precision The precision of the float.
     * @param ?int $scale The scale of the float.
     * @return Column
     */
    public function float(string $name, ?int $precision = null, ?int $scale = null): Column
    {
        return $this->addColumn('float', $name, compact('precision', 'scale'));
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
    public function primary($columns, ?string $name = null): void
    {
        $this->primaryKeys[] = ['columns' => (array) $columns, 'name' => $name];
    }

    /**
     * Add a unique index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as unique index.
     * @return void
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $name = is_array($columns) ? $name : ($columns[1] ?? null);
        $this->indexes[] = ['type' => 'unique', 'columns' => $this->normalizeColumns($columns), 'name' => $name];
    }

    /**
     * Add an index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as index.
     * @return void
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $name = is_array($columns) ? $name : ($columns[1] ?? null);
        $this->indexes[] = ['type' => 'index', 'columns' => $this->normalizeColumns($columns), 'name' => $name];
    }

    /**
     * Add a full text index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as full text index.
     * @return void
     */
    public function fullText(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $name = is_array($columns) ? $name : ($columns[1] ?? null);
        $this->indexes[] = ['type' => 'fulltext', 'columns' => $this->normalizeColumns($columns), 'name' => $name];
    }

    /**
     * Add a spatial index to the blueprint.
     *
     * @param string|array $columns The column(s) to set as spatial index.
     * @return void
     */
    public function spatialIndex(string|array $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $name = is_array($columns) ? $name : ($columns[1] ?? null);
        $this->indexes[] = ['type' => 'spatial', 'columns' => $this->normalizeColumns($columns), 'name' => $name];
    }

    /**
     * Set the storage engine for MySQL tables.
     *
     * @param string $engine The storage engine to use.
     * @return self
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set the character set for the blueprint.
     *
     * @param string $charset The character set to use.
     * @return self
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set the collation for the blueprint.
     *
     * @param string $collation The collation to use.
     * @return self
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Compile the blueprint into a SQL statement.
     *
     * @return string
     */
    public function compileCreate(): string
    {
        return implode("\n", $this->compileCreateStatements());
    }

    /**
     * Compile the blueprint into SQL statements.
     *
     * @return array
     */
    public function compileCreateStatements(): array
    {
        $grammar = Schema::getGrammar();
        $elements = [];

        // Add columns
        foreach ($this->columns as $column) {
            $elements[] = $column->toSql();
        }

        // Add primary keys
        foreach ($this->primaryKeys as $primaryKey) {
            $elements[] = $grammar->compilePrimaryKey($this->table, $primaryKey);
        }

        // Add foreign keys
        foreach ($this->foreignKeys as $foreignKey) {
            $elements[] = $grammar->compileForeignKey($foreignKey, $this->table);
        }

        $options = '';
        if ($grammar->isMySQL()) {
            $options = sprintf(
                "%s DEFAULT CHARSET=%s COLLATE=%s",
                $this->engine ? " ENGINE={$this->engine}" : '',
                $this->charset ?? config('database.charset', 'utf8mb4'),
                $this->collation ?? config('database.collation', 'utf8mb4_general_ci')
            );
        }

        $statements = [
            "CREATE TABLE " . $grammar->getWrapper()->wrapTable($this->table) . " (\n" . implode(",\n", $elements) . "\n)$options"
        ];

        // Add secondary indexes
        foreach ($this->indexes as $index) {
            $statements[] = $grammar->compileIndex($this->table, $index);
        }

        return $statements;
    }

    /**
     * Drop a column from the blueprint.
     *
     * @param string|array $columns The column(s) to drop.
     * @return self
     */
    public function dropColumn(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->drops[] = ['type' => 'column', 'names' => $columns];
        return $this;
    }

    /**
     * Drop an index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @param string|null $type The type of index to drop.
     * @return self
     */
    public function dropIndex($index, ?string $type = null): self
    {
        $type ??= 'index';

        $this->drops[] = ['type' => $type, ...$this->normalizeDropIndex($index)];
        return $this;
    }

    /**
     * Drop a unique index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropUnique($index): self
    {
        return $this->dropIndex($index, 'unique');
    }

    /**
     * Drop a primary key from the blueprint.
     *
     * @param string|array|null $index The primary key name or columns.
     * @return self
     */
    public function dropPrimary(string|array|null $index = null): self
    {
        $this->drops[] = ['type' => 'primary', ...($index === null ? ['columns' => [], 'name' => null] : $this->normalizeDropIndex($index))];
        return $this;
    }

    /**
     * Drop a full text index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropFullText(string|array $index): self
    {
        return $this->dropIndex($index, 'fulltext');
    }

    /**
     * Drop a spatial index from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropSpatialIndex(string|array $index): self
    {
        return $this->dropIndex($index, 'spatial');
    }

    /**
     * Drop a foreign key constraint from the blueprint.
     *
     * @param string|array $index The index name or columns.
     * @return self
     */
    public function dropForeign($index): self
    {
        $this->drops[] = ['type' => 'foreign', ...$this->normalizeDropIndex($index)];
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
        return implode(";\n", $this->compileAlterStatements());
    }

    /**
     * Compile the blueprint into ALTER TABLE SQL statements.
     *
     * @return array
     */
    public function compileAlterStatements(): array
    {
        $grammar = Schema::getGrammar();
        $statements = [];

        foreach ($this->columns as $column) {
            $statements[] = $grammar->compileAddColumn($this->table, $column);
        }

        // Add indexes
        foreach ($this->indexes as $index) {
            $statements[] = $grammar->compileIndex($this->table, $index);
        }

        foreach ($this->primaryKeys as $primaryKey) {
            $statements[] = $grammar->compileAddPrimaryKey($this->table, $primaryKey);
        }

        // Add foreign keys
        foreach ($this->foreignKeys as $foreignKey) {
            $statements[] = $grammar->compileAddForeignKey($this->table, $foreignKey);
        }

        foreach ($this->drops as $drop) {
            $statements[] = $grammar->compileDrop($this->table, $drop);
        }

        foreach ($this->renames as $rename) {
            $statements[] = $grammar->compileRenameColumn($this->table, $rename['from'], $rename['to']);
        }

        // Remove trailing semicolons from statements to ensure proper concatenation
        return array_filter(array_map(
            fn($stmt) => rtrim($stmt, ';'),
            $statements
        ));
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

        return $column;
    }

    /**
     * Normalize columns from Laravel-style variadic calls.
     *
     * @param array $columns
     * @return array
     */
    private function normalizeColumns(array $columns): array
    {
        if (isset($columns[1]) && is_string($columns[1])) {
            return [$columns[0]];
        }

        return $columns;
    }

    /**
     * Normalize drop index input into columns or an explicit name.
     *
     * @param string|array $index
     * @return array
     */
    private function normalizeDropIndex(string|array $index): array
    {
        if (is_array($index)) {
            return ['columns' => $index, 'name' => null];
        }

        return ['columns' => [], 'name' => $index];
    }
}
