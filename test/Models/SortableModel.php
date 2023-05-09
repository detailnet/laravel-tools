<?php

namespace DetailTest\Laravel\Models;

use Detail\Laravel\Models\ModelWithSortIndex;
use Detail\Laravel\Models\SortByDragAndDrop;

/**
 * This model exists only to let phpstan analyse the SortByDragAndDrop trait.
 *
 * @property string $_id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
class SortableModel extends ModelWithSortIndex
{
    use SortByDragAndDrop;

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_index' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (SortableModel $model) {
            $model->sort_index = $model->getNextSortIndex();
        });

        static::updating(function (SortableModel $model) {
            $model->onSortByDragDropChange();
        });
    }

    /**
     * {@inheritdoc}
     */
    protected static function updateRules(array $options = []): array
    {
        return [
            'sort_index' => self::updateSortIndexRule(),
        ];
    }

    protected function indexes(): array
    {
        return [
            'sort_index',
        ];
    }
}

