<?php

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

return new class {
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('caches', function (Blueprint $table) {
            $table->string('key')->collation('BINARY')->primary();
            $table->string('group')->nullable()->index();
            $table->longText('data');
            $table->bigInteger('expiration')->default(0)->index();
        });

        Schema::create('locks', function (Blueprint $table) {
            $table->string('key')->collation('BINARY')->primary()->unique();
            $table->string('owner')->index();
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->text('payload');
            $table->dateTime('scheduled_time');
            $table->dateTime('reserved_at')->nullable()->index();
            $table->string('repeat')->nullable();
            $table->string('status');
            $table->integer('attempts')->default(0);
            $table->index(['status', 'scheduled_time'], 'idx_jobs_status_scheduled');
            $table->index(['queue', 'status'], 'idx_jobs_queue_status');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->dateTime('failed_at')->index();
            $table->text('exception');
            $table->integer('attempts')->default(0);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('locks');
        Schema::dropIfExists('caches');
        Schema::dropIfExists('sessions');
    }
};