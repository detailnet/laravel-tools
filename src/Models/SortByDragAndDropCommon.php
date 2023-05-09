<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Http\RestController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use function array_key_exists;
use function current;
use function floor;
use function is_int;
use function is_string;
use function key;
use function next;
use function preg_match;
use function prev;
use function reset;
use function sprintf;

/**
 * @property string $_id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
trait SortByDragAndDropCommon
{
    /**
     * @return string[]
     */
    protected static function updateSortIndexRule(): array
    {
        return ['string', 'regex:/^(?:after|before):' . RestController::UUID_V4_PATTERN . '$/'];
    }
}
