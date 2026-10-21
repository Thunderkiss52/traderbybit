<?php

declare(strict_types=1);

namespace App\Research\Exceptions;

use RuntimeException;

final class IdempotencyConflictException extends RuntimeException {}
