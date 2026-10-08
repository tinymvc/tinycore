<?php

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;
use Spark\Database\Relation\HasOne;

class ScenarioUser extends Model
{
    protected string $table = 'scenario_users';

    protected const USE_TIMESTAMPS = false;

    public function posts(): HasMany
    {
        return $this->hasMany(ScenarioPost::class, foreignKey: 'user_id');
    }

    public function comments(): \Spark\Database\Relation\HasManyThrough
    {
        return $this->hasManyThrough(
            ScenarioComment::class,
            ScenarioPost::class,
            firstKey: 'user_id',
            secondKey: 'post_id',
        );
    }

    public function roles(): \Spark\Database\Relation\BelongsToMany
    {
        return $this->belongsToMany(
            ScenarioRole::class,
            table: 'scenario_role_user',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'role_id',
        );
    }

    public function firstPost(): HasOne
    {
        return $this->hasOne(ScenarioPost::class, foreignKey: 'user_id');
    }
}

class ScenarioPost extends Model
{
    protected string $table = 'scenario_posts';

    protected const USE_TIMESTAMPS = false;

    protected const USE_SOFT_DELETES = true;

    protected array $casts = ['score' => 'integer', 'metadata' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ScenarioUser::class, foreignKey: 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ScenarioComment::class, foreignKey: 'post_id');
    }
}

class ScenarioComment extends Model
{
    protected string $table = 'scenario_comments';

    protected const USE_TIMESTAMPS = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(ScenarioPost::class, foreignKey: 'post_id');
    }
}

class ScenarioRole extends Model
{
    protected string $table = 'scenario_roles';

    protected const USE_TIMESTAMPS = false;
}

class ScenarioCustomKey extends Model
{
    protected string $table = 'scenario_schema';

    protected string $primaryKey = 'code';

    protected const USE_TIMESTAMPS = false;
}

class ScenarioActivity extends Model
{
    protected string $table = 'scenario_schema';

    protected const USE_TIMESTAMPS = false;
}
