<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Database\Expression;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;
use Spark\Facades\DB;

final class DatabaseExpressionTest extends FrameworkTestCase
{
    public function test_raw_never_executes_and_run_preserves_bound_execution(): void
    {
        $expression = DB::raw('DROP TABLE missing_table');
        $this->assertInstanceOf(Expression::class, $expression);
        $this->assertSame('DROP TABLE missing_table', $expression->getValue());
        $this->assertInstanceOf(Expression::class, query('unused')->raw('1 + 1'));
        $this->assertSame([['answer' => 42]], DB::run('SELECT ? AS answer', [42]));
        $this->assertSame([['answer' => "quote'value"]], DB::run('SELECT :value AS answer', ['value' => "quote'value"]));
    }

    public function test_expressions_compose_with_bound_reads_writes_and_schema_defaults(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->integer('points');
            $table->string('label');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
        query('scores')->insert([
            ['points' => DB::raw('2 + 3'), 'label' => 'first'],
            ['points' => 10, 'label' => 'second'],
        ]);
        $row = query('scores')->select(['id', DB::raw("printf('%s,%s', label, points) AS summary")])->where('points', DB::raw('5'))->first();
        $this->assertSame('first,5', $row->summary);
        $this->assertSame(1, query('scores')->where(DB::raw('points + 1'), 6)->count());
        $this->assertSame(2, query('scores')->where(['points' => DB::raw('points')])->count());
        $this->assertSame(2, query('scores')->where(DB::raw('created_at'), '!=', null)->count());
        $this->assertSame(1, query('scores')->where('id', 1)->update(['points' => DB::raw('points + 2'), 'label' => 'changed']));
        $this->assertSame(7, (int) query('scores')->where('id', 1)->value('points'));
        $this->assertSame(1, query('scores')->whereRaw('id = ?', [1])->update(['points' => DB::raw('points + 1'), 'label' => 'positional']));
        query('scores')->upsert(['id' => 1, 'points' => 100, 'label' => 'upsert'], ['id'], ['points' => DB::raw('points + 1')]);
        $this->assertSame(9, (int) query('scores')->where('id', 1)->value('points'));
        $this->assertSame(10, (int) query('scores')->orderBy(DB::raw('points * -1'))->value('points'));
        $this->assertSame(2, count(query('scores')->select(DB::raw('points % 2 AS parity'))->groupBy(DB::raw('points % 2'))->all()));
        $this->assertSame(2, query('scores')->where(DB::raw('points > 0'))->count());
    }
}
