<?php

namespace Spark\Http\Resources;

/** @internal Marks an omitted resource field without conflating it with null. */
enum MissingValue
{
    case Missing;
}
