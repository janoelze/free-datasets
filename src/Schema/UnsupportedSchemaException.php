<?php

declare(strict_types=1);

namespace Datasets\Schema;

/** Thrown when a schema uses a keyword (or keyword shape) the Validator does not implement. */
final class UnsupportedSchemaException extends \InvalidArgumentException
{
}
