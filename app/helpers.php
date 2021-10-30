<?php

declare(strict_types=1);

namespace Core\Functions;

use Closure;

/**
 * Retrieves environment variables or returns a default value
 *
 * @param string $key
 * @param mixed $default
 * @return bool|string|null
 */
function env(string $key, mixed $default = null): bool|string|null {
    $value = $_ENV[$key] ?? null;

    if ($value === null) {
        return ($default instanceof Closure ? $default() : $default);
    }

    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }

    if (strlen($value) > 1 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
        return substr($value, 1, -1);
    }

    return $value;
}
