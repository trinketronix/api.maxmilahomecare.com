<?php

declare(strict_types=1);

namespace Api\Services;

/**
 * Thrown by BaseController's query-string helpers when a list filter is malformed.
 * Controllers turn it into a 400 with the exception message (which is safe to show).
 */
class InvalidQueryException extends \InvalidArgumentException {
}
