<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Http\RestController;
use Illuminate\Database\Eloquent\Collection;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use function array_filter;
use function asort;
use function assert;
use function call_user_func;
use function current;
use function floor;
use function is_callable;
use function is_int;
use function is_string;
use function key;
use function next;
use function preg_match;
use function prev;
use function reset;
use function sprintf;

/**
 * Current limitations:
 *  - Embedded model key is always 'id'
 *  - Embedded model sort key is always 'sort_index'
 *  - Delta is always Model::SORT_INDEX_DEFAULT_DELTA (cannot be overridden in the embedded ModelWithSortIndex)
 *  - Tree not supported (makes no sense in embedded anyway)
 */
trait SortEmbeddedByDragAndDrop
{
    public function sortEmbeddedModel(string $embeddedProperty, EmbeddedModelSortableByDragAndDrop $model): void
    {
        if (!is_string($model->sort_index)) {
            return; // Nothing to do
        }

        if (preg_match('/^(?<position>after|before):(?<uuid>' . RestController::UUID_V4_PATTERN . ')$/', $model->sort_index, $reference) === false) {
            throw new RuntimeException('Wrong sorting string');
        }

        $indexes = array_filter(
            $this->getEmbeddedModelCollection($embeddedProperty)->mapWithKeys(
                static fn(EmbeddedModelSortableByDragAndDrop $item): array => [$item->id => ($item->id !== $model->id) ? $item->sort_index : null]
            )->toArray()
        );

        asort($indexes);

        // Check that there is a space before or after the referenced model
        reset($indexes); // Set internal pointer to first element

        // Move internal pointer to referenced model
        while (key($indexes) !== $reference['uuid']) {
            next($indexes);
        }

        switch ($reference['position']) {
            case 'before':
                $max = current($indexes);
                $min = prev($indexes) ?: 0;
                break;
            case 'after':
                $min = current($indexes);
                $max = next($indexes) ?: ($min + 2 * Model::SORT_INDEX_DEFAULT_DELTA);
                break;
            default:
                throw new RuntimeException(
                    sprintf('Failed to apply sort_index: position to reference "%s" not supported', $reference['position'])
                );
        }

        // Mean value between min and max
        $newIndex = $min + (integer) floor(($max - $min) / 2);

        if ($newIndex === $min || $newIndex === $max) {
            $this->reindexEmbeddedModels($embeddedProperty);

            $this->sortEmbeddedModel($embeddedProperty, $model);
        }

        $model->sort_index = $newIndex;
    }

    protected function getEmbeddedModelNextSortIndex(string $embeddedProperty): int
    {
        /** @var int $latest */
        $latest = $this->getEmbeddedModelCollection($embeddedProperty)->max(
            static fn(EmbeddedModelSortableByDragAndDrop $model): int => is_int($model->sort_index) ? $model->sort_index : 0
        );

        if ($latest > (PHP_INT_MAX - Model::SORT_INDEX_DEFAULT_DELTA)) {
            $this->reindexEmbeddedModels($embeddedProperty);

            return $this->getEmbeddedModelNextSortIndex($embeddedProperty);
        }

        return $latest + Model::SORT_INDEX_DEFAULT_DELTA;
    }

    private function reindexEmbeddedModels(string $embeddedProperty): void
    {
        static $reindexAlreadyPerformed = false;

        if ($reindexAlreadyPerformed) {
            throw new RuntimeException('Full reindex asked twice in same request');
        }

        $reindexAlreadyPerformed = true;

        assert($this instanceof Model);

        $relationGetter = [$this, $embeddedProperty];

        if (!is_callable($relationGetter)) {
            throw new RuntimeException(sprintf('Relation "%s" is not callable', $embeddedProperty));
        }

        $relation = call_user_func($relationGetter);

        if (!$relation instanceof EmbedsMany) {
            throw new RuntimeException(sprintf('Relation "%s" is an "%s" relation', $embeddedProperty, EmbedsMany::class));
        }

        $index = 0;

        $this->getEmbeddedModelCollection($embeddedProperty)->each(
            static function (EmbeddedModelSortableByDragAndDrop $model) use (&$index, $relation): void {
                $index += Model::SORT_INDEX_DEFAULT_DELTA;

                $model->sort_index = $index;

                $relation->save($model);
            }
        );
    }

    /**
     * @return Collection<int, EmbeddedModelSortableByDragAndDrop>
     */
    private function getEmbeddedModelCollection(string $embeddedProperty): Collection
    {
        if (!isset($this->$embeddedProperty)) {
            throw new RuntimeException(sprintf('Property "%s" not found', $embeddedProperty));
        }

        if (!$this->$embeddedProperty instanceof Collection) {
            throw new RuntimeException(sprintf('Property "%s" is not a "%s"', $embeddedProperty, Collection::class));
        }

        return $this->$embeddedProperty;
    }
}
