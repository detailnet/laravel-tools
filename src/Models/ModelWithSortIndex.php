<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Http\RestController;

/**
 * @property string $id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
abstract class ModelWithSortIndex extends Model
{
    /**
     * @return string[]
     */
    protected static function updateSortIndexRule(): array
    {
        return ['string', 'regex:/^(?:after|before):' . RestController::UUID_V4_PATTERN . '$/'];
    }
}
