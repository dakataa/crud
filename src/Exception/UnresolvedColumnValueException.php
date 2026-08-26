<?php

namespace Dakataa\Crud\Exception;

use RuntimeException;

/**
 * Thrown by columnValueDetermination() to signal that it does not handle the given
 * column, so the caller should fall through to the getter/query-selected/property
 * resolvers. This lets a determination return any real value, including null or
 * false, without it being mistaken for "not handled".
 */
class UnresolvedColumnValueException extends RuntimeException
{
}
