<?php

namespace Detail\Laravel\Api;

use DateTime;
use Detail\Laravel\Models\Model;
use function explode;
use function fnmatch;
use function in_array;
use function str_replace;
use function strlen;

/**
 * @property string $name
 * @property bool $active
 * @property string $key
 * @property string $description
 * @property Datetime $created_on
 * @property string[] $resources // Pattern '<method>:<path>'; '*' as wildcard, for path '?' too; '*:*' permits all
 */
class UserModel extends Model
{
    public const ALLOWED_METHODS = [
        'GET',
        // 'HEAD',
        'POST',
        'PUT',
        'DELETE',
        // 'CONNECT',
        'OPTIONS',
        'PATCH',
        // 'PURGE',
        // 'TRACE',
    ];

    public const FORBIDDEN_PATH_CHARS = [
        '*',
        '?',
        ' ',
    ];

    protected $collection = 'api-users';
    protected $primaryKey = 'name';

    protected $hidden = [ // This model should never be serialized, but in case make suer the key is not in the output
        'key',
    ];

    final public function allowsResource(string $method, string $path): bool
    {
        if (!$this->active
            || !in_array($method, self::ALLOWED_METHODS, true)
            || strlen($path) !== strlen(str_replace(self::FORBIDDEN_PATH_CHARS,'', $path))
        ) {
            return false;
        }

        foreach ($this->resources as $resource) {
            [$allowedMethod, $allowedPath] = explode(':', $resource, 2);

            if ($allowedMethod !== '*' && $allowedMethod !== $method) {
                continue;
            }

            if (fnmatch($allowedPath, $path, FNM_NOESCAPE | FNM_PATHNAME | FNM_PERIOD)
                || $allowedPath === '*' // Allowing '*' to pass sub-routes too (excluded by fnmatch with FNM_PATHNAME)
            ) {
                return true;
            }
        }

        return false;
    }

    protected function indexes(): array
    {
        return [
            ['active', 'key'],
        ];
    }
}
