<?php

namespace Detail\Laravel\Models;

use Illuminate\Support\Str;
use RuntimeException;
use function call_user_func;
use function class_basename;
use function class_uses;
use function in_array;
use function is_callable;
use function sprintf;

/**
 * @template TDeclaringModel of Model
 * @extends EmbeddedModel<TDeclaringModel>
 *
 * @property string $id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
abstract class EmbeddedModelSortableByDragAndDrop extends EmbeddedModel
{
    use SortUtils;

    protected $primaryKey = 'id';

    public function getIdAttribute($value = null)
    {
        return $this->attributes['id'] ?? null;
    }

    protected function updateSortIndex(): void
    {
        $parent = $this->getParent();
        $parentTraits = class_uses($parent); // Should use laravel class_uses_recursive()
        $sorter = [$parent, 'sortEmbeddedModel'];

        if (!in_array(SortEmbeddedByDragAndDrop::class, $parentTraits !== false ? $parentTraits : [], true)
            || !is_callable($sorter)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Parent model "%s" does not contains does not uses "%s" trait',
                    $parent::class,
                    SortEmbeddedByDragAndDrop::class
                )
            );
        }

        call_user_func($sorter, $this::getParentRelationAttributeName(), $this);
    }
}
