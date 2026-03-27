<?php

namespace Spark\Database\Relation;

/**
 * Class HasOne
 * 
 * Represents a "has one" relationship in a database model.
 * 
 * This class is used to define a one-to-one relationship
 * between two models, where a model can have
 * exactly one instance of another model associated with it.
 * 
 * @package Spark\Database\Relation
 */
class HasOne extends HasMany
{
    // No additional functionality is needed for HasOne, as it inherits
    // all necessary behavior from HasMany. The only difference is that
    // HasOne will return a single instance of the related model instead of a collection.
}