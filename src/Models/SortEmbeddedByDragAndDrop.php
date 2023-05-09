<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Http\RestController;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use function array_key_exists;
use function array_keys;
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
    private function getEmbeddedRelation(string $embeddedProperty): EmbedsMany
    {
        assert($this instanceof SortableEmbeddedModel);

        $relationGetter = [$this, $embeddedProperty];

        if (!is_callable($relationGetter)) {
            throw new RuntimeException(sprintf('Relation "%s" is not callable', $embeddedProperty));
        }

        $relation = call_user_func($relationGetter);

        if (!$relation instanceof EmbedsMany) {
            throw new RuntimeException(sprintf('Relation "%s" is an "%s" relation', $embeddedProperty, EmbedsMany::class));
        }

        return $relation;
    }

    public function getEmbeddedModel(string $embeddedProperty, SortableEmbeddedModel|string $modelOrId): ?SortableEmbeddedModel
    {
        assert(property_exists($this, $embeddedProperty));

        $id = ($modelOrId instanceof SortableEmbeddedModel) ? $modelOrId->id : $modelOrId;

        foreach ($this->$embeddedProperty as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function getEmbeddedSortIndexes(string $embeddedProperty): array
    {
        $indexes = [];

        $this->getEmbeddedRelation($embeddedProperty)->orderBy('sort_index')->each(
            function (SortableEmbeddedModel $model) use (&$indexes): void {
                if (!is_int($model->sort_index)) {
                    throw new RuntimeException('Some sort_index are not numeric');
                }

                $indexes[$model->id] = $model->sort_index;
            }
        );

        return $indexes; // @phpstan-ignore-line Index is always string
    }

    public function sortEmbeddedModel(string $embeddedProperty, SortableEmbeddedModel $model): void
    {


        if (!is_string($model->sort_index)) {
            throw new RuntimeException('Wrong call of sortEmbeddedModel method');
        }

        if (preg_match('/^(?<position>after|before):(?<uuid>' . RestController::UUID_V4_PATTERN . ')$/', $model->sort_index, $reference) === false) {
            throw new RuntimeException('Wrong sorting string');
        }

        $indexes = $this->getEmbeddedSortIndexes($embeddedProperty);

        if (!array_key_exists($reference['uuid'], $indexes)) {
            throw new RuntimeException(
                sprintf('Failed to apply sort_index: reference model "%s" not found within adjacent models', $reference['uuid'])
            );
        }

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

    public function reindexEmbeddedModels(string $embeddedProperty): void
    {
        static $reindexAlreadyPerformed = false;

        if ($reindexAlreadyPerformed) {
            throw new RuntimeException('Full reindex asked twice in same request');
        }

        $reindexAlreadyPerformed = true;
        $relation = $this->getEmbeddedRelation($embeddedProperty);
        $index = 0;

        foreach (array_keys($this->getEmbeddedSortIndexes($embeddedProperty)) as $key) {
            $model = $this->getEmbeddedModel($embeddedProperty, $key);

            if ($model === null) {
                continue;
            }

            $index += Model::SORT_INDEX_DEFAULT_DELTA;

            $model->sort_index = $index;
            $relation->save($model);
        }
    }

    public function getEmbeddedModelNextSortIndex(string $embeddedProperty): int
    {
        // Get higher value within the pairing
        $latest = $this->getEmbeddedRelation($embeddedProperty)->orderByDesc('sort_index')->first(['sort_index'])?->getAttributeValue('sort_index') ?? 0;

        if ($latest > (PHP_INT_MAX - Model::SORT_INDEX_DEFAULT_DELTA)) {
            $this->reindexEmbeddedModels($embeddedProperty);

            return $this->getEmbeddedModelNextSortIndex($embeddedProperty);
        }

        return $latest + Model::SORT_INDEX_DEFAULT_DELTA;
    }
}

