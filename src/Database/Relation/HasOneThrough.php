<?php

namespace Spark\Database\Relation;

/**
 * Class HasOneThrough
 * 
 * Represents a "has one through" relationship in a database model.
 * 
 * This class is used to define a one-to-one relationship
 * between two models through an intermediate model.
 * It encapsulates the related model, the through model,
 * 
 * the keys used to establish the relationship,
 * and other parameters necessary to establish the relationship.
 * 
 * @package Spark\Database\Relation
 */
class HasOneThrough extends HasManyThrough
{
}