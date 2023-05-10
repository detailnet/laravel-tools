<?php

namespace Detail\Laravel\Models;

use Illuminate\Support\Str;
use RuntimeException;
use function call_user_func;
use function class_basename;
use function class_uses;
use function is_callable;

/**
 * @property string $id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
abstract class EmbeddedModelSortableByDragAndDrop extends Model
{
    use SortByDragAndDropCommon;

    protected $primaryKey = 'id';

    public function getIdAttribute($value = null)
    {
        return $this->attributes['id'] ?? null;
    }

    public function updateSortIndex(): void
    {
        $parent = $this->getParentRelation()->getParent();
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

    /**
     * The attribute name to get the embedded collection from parent.
     *
     * This is a limitation: this model can't be integrated more than once in same parent with different names
     * Could get rid of this using Reflection into $this->getParentRelation(): gathering the
     * protected parameter Jenssegers\Mongodb\Relations\EmbedsOneOrMany::$localKey value.
     */
    protected static function getParentRelationAttributeName(): string
    {
        return Str::snake(Str::plural(class_basename(static::class)));
    }
}
