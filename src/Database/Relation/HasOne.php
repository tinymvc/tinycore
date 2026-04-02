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
    // No additional properties or methods are needed for HasOne,
    // as it inherits all necessary functionality from HasMany.
}