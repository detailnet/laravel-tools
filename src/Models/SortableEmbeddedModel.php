<?php

namespace Detail\Laravel\Models;

/**
 * @property string $id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
abstract class SortableEmbeddedModel extends Model // Should be named SortableByDragAndDropModel
{
    use SortByDragAndDropCommon;

    protected $primaryKey = 'id';

    public function getIdAttribute($value = null)
    {
        return $this->attributes['id'] ?? null;
    }
}
