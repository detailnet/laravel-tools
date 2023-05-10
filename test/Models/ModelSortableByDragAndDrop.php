<?php

namespace DetailTest\Laravel\Models;

use Detail\Laravel\Models\Model;
use Detail\Laravel\Models\SortByDragAndDrop;

/**
 * This model exists only to let phpstan analyse the SortByDragAndDrop trait.
 *
 * @property string $_id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
class ModelSortableByDragAndDrop extends Model
{
    use SortByDragAndDrop;

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_index' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (ModelSortableByDragAndDrop $model) {
            $model->sort_index = $model->getNextSortIndex();
        });

        static::updating(function (ModelSortableByDragAndDrop $model) {
            $model->onSortByDragDropChange();
        });
    }

    /**
     * {@inheritdoc}
     */
    protected static function updateRules(array $options = []): array
    {
        return [
            'sort_index' => Model::SORT_INDEX_BY_DRAG_AND_DROP_RULE,
        ];
    }

    protected function indexes(): array
    {
        return [
            'sort_index',
        ];
    }
}

