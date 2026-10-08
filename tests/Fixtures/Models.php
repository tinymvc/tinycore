<?php

use Spark\Database\Schema\Schema;
use Spark\Database\Schema\Blueprint;
use Spark\Database\Model;
use Spark\Facades\DB;

class DatabaseFixturePost extends Model
{
    protected string $table = 'posts';
    protected const USE_SOFT_DELETES = true;
    protected array $fillable = ['title', 'user_id', 'views'];
    protected array $casts = ['deleted_at' => 'datetime'];
}

class DatabaseFixtureUser extends Model
{
    protected string $table = 'users';
    protected const USE_TIMESTAMPS = false;
    public function posts(): \Spark\Database\Relation\HasMany
    {
        return $this->hasMany(DatabaseFixturePost::class, foreignKey: 'user_id');
    }
}

class DatabaseFixtureCustomArchive extends Model
{
    protected string $table = 'archives';
    protected const USE_SOFT_DELETES = true;
    protected const USE_TIMESTAMPS = false;
    protected const SOFT_DELETE_COLUMN = 'archived_at';
    protected array $casts = ['archived_at' => 'datetime'];
}

// Separate fixtures exercise parent, related, nested, and joined scopes independently.
class DatabaseFixtureRelationUser extends Model
{
    protected string $table = 'scope_users';
    protected const USE_SOFT_DELETES = true;
    protected const USE_TIMESTAMPS = false;
    public function posts(): \Spark\Database\Relation\HasMany
    {
        return $this->hasMany(DatabaseFixtureRelationPost::class, foreignKey: 'user_id');
    }
    public function firstPost(): \Spark\Database\Relation\HasOne
    {
        return $this->hasOne(DatabaseFixtureRelationPost::class, foreignKey: 'user_id');
    }
    public function roles(): \Spark\Database\Relation\BelongsToMany
    {
        return $this->belongsToMany(DatabaseFixtureRelationRole::class, table: 'scope_role_user', foreignPivotKey: 'user_id', relatedPivotKey: 'role_id');
    }
}

class DatabaseFixtureRelationPost extends Model
{
    protected string $table = 'scope_posts';
    protected const USE_SOFT_DELETES = true;
    protected const USE_TIMESTAMPS = false;
    protected const SOFT_DELETE_COLUMN = 'archived_at';
    public function user(): \Spark\Database\Relation\BelongsTo
    {
        return $this->belongsTo(DatabaseFixtureRelationUser::class, foreignKey: 'user_id');
    }
    public function comments(): \Spark\Database\Relation\HasMany
    {
        return $this->hasMany(DatabaseFixtureRelationComment::class, foreignKey: 'post_id');
    }
}

class DatabaseFixtureRelationComment extends Model
{
    protected string $table = 'scope_comments';
    protected const USE_SOFT_DELETES = true;
    protected const USE_TIMESTAMPS = false;
}

class DatabaseFixtureRelationRole extends Model
{
    protected string $table = 'scope_roles';
    protected const USE_SOFT_DELETES = true;
    protected const USE_TIMESTAMPS = false;
}

class DatabaseFixtureRelationCountry extends Model
{
    protected string $table = 'scope_countries';
    protected const USE_TIMESTAMPS = false;
    public function posts(): \Spark\Database\Relation\HasManyThrough
    {
        return $this->hasManyThrough(DatabaseFixtureRelationPost::class, DatabaseFixtureRelationUser::class, firstKey: 'country_id', secondKey: 'user_id');
    }
}

class DatabaseFixtureQueryKey extends Model
{
    protected string $table = 'query_keys';
    protected string $primaryKey = 'code';
    protected const USE_TIMESTAMPS = false;
    protected const USE_SOFT_DELETES = true;
}

class DatabaseFixtureQueryOwner extends Model
{
    protected string $table = 'query_owners';
    protected const USE_TIMESTAMPS = false;
    public function records(): \Spark\Database\Relation\HasMany
    {
        return $this->hasMany(DatabaseFixtureQueryKey::class, foreignKey: 'owner_id');
    }
}
