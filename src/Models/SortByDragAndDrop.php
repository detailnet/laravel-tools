<?php

namespace Detail\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use MongoDB\Laravel\Relations\EmbedsMany;
use RuntimeException;
use function array_filter;
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
trait SortByDragAndDrop
{
    use SortUtils;

    private function onSortByDragDropChange(?callable $isWithinDescendants = null): void
    {
        if (($parentProperty = $this->adjacentSortModelsParentProperty()) !== null) {
            if ($isWithinDescendants === null) {
                throw new RuntimeException('Descendants check function must be provided when used as tree');
            }

            if (!$this->originalIsEquivalent($parentProperty)) { // Only if changed
                if ($isWithinDescendants($this->{$parentProperty})) { // Check if new parent is within descendants
                    throw new RuntimeException('Can\'t change parent within own descendants');
                }

                // Change sort-index, place as first within the parent
                $first = $this->getAdjacentModels()
                        ->orderBy('sort_index')
                        ->first([$this->primaryKey])
                        ?->getAttributeValue($this->primaryKey) ?? null;

                $this->sort_index = $first === null ? self::SORT_INDEX_DEFAULT_DELTA : ('before:' . $first);
            }
        }

        if (is_string($this->sort_index)) { // If is already an integer is applied without additional tests or reindex
            $this->sort_index = $this->extractSortIndexFromAdjacent();
        }
    }

    /**
     * Property used to detect a parent change `onSortByDragDropChange` method.
     * For a tree structure normally the 'parent_id', if not a tree then null.
     */
    protected function adjacentSortModelsParentProperty(): ?string
    {
        return null;
    }

    private function getAdjacentModels(): Builder|HasMany|BelongsToMany|EmbedsMany
    {
        $adjacentModels = $this->getAdjacentModelsQuery();

        if ($adjacentModels instanceof Relation) {  // Can't be written in one single if, otherwise phpstan does not pick it correctly
            if (!$adjacentModels instanceof HasMany
                && !$adjacentModels instanceof BelongsToMany
                && !$adjacentModels instanceof EmbedsMany
            ) {
                throw new RuntimeException('Provided Relation has to be a valid to-Many one');
            }
        }

        foreach ($this->adjacentSortModelsCompareProperties() as $pairing) {
            $adjacentModels->where($pairing, '=', $this->{$pairing});
        }

        return $adjacentModels;
    }

    /**
     * The model builder, on which the sorting is applied.
     * To override when the information about the compare property is not int the own model (e.g.: when sorting an embedded collection)
     */
    protected function getAdjacentModelsQuery(): Builder|Relation
    {
        return $this->newQuery();
    }

    /**
     * Properties used to define the group within the sorting has to be made against.
     * For a tree structure normally the 'parent_id', when client is involved 'client_id' too. When standalone collection empty array.
     * @return string[]
     */
    protected function adjacentSortModelsCompareProperties(): array
    {
        return [];
    }

    private function extractSortIndexFromAdjacent(): int
    {
        if (!is_string($this->sort_index)) {
            throw new RuntimeException('Wrong call of extractSortIndexFromAdjacent method');
        }

        $newIndex = $this->extractSortIndex(
            $this->sort_index,
            $this->fetchIndexes()
        );

        if ($newIndex === null) {
            $this->fullReindex();

            return $this->extractSortIndexFromAdjacent();
        }

        return $newIndex;
    }

    /**
     * @return array<string, int>
     */
    private function fetchIndexes(): array
    {
        $indexes = [];
        $key = $this->primaryKey;

        $this->getAdjacentModels()->orderBy('sort_index')->each(
            function (self $model) use (&$indexes, $key): void {
                if ($model->{$key} !== $this->{$key}) { // Exclude the own model (it might be the item to reposition)
                    if (!is_int($model->sort_index)) {
                        throw new RuntimeException('Some sort_index are not numeric');
                    }

                    $indexes[(string) $model->{$key}] = $model->sort_index;
                }
            }
        );

        return $indexes;
    }

    private function fullReindex(): void
    {
        static $reindexAlreadyPerformed = false;

        if ($reindexAlreadyPerformed) {
            throw new RuntimeException('Full reindex asked twice in same request');
        }

        $reindexAlreadyPerformed = true;
        $index = 0;

        // We need to retrieve filed sorted by sort_index and update that sort index, the operation is done by chunks of results
        // therefore needs to be aware of what has been already updated. The solution is use 'chunkById';
        // Ref: https://laravel.com/docs/8.x/queries#chunking-results

        // But we have decided to go the safer way, that might perform a few queries more (less performant)
        foreach (array_keys($this->fetchIndexes()) as $key) { // Note: the current model is not re-indexed
            $index += Model::SORT_INDEX_DEFAULT_DELTA;

            $model = self::query()->find($key);

            if ($model === null) {
                continue;
            }

            // $model->updateQuietly(['sort_index' => $index]); // Does not works .. don't know why
            $model->sort_index = $index;
            $model->saveQuietly();
        }
    }

    private function getNextSortIndex(): int
    {
        // Get higher value within the pairing
        $latest = $this->getAdjacentModels()->orderByDesc('sort_index')->first(['sort_index'])?->getAttributeValue('sort_index') ?? 0;

        if ($latest > (PHP_INT_MAX - self::SORT_INDEX_DEFAULT_DELTA)) {
            $this->fullReindex();

            return $this->getNextSortIndex();
        }

        return $latest + Model::SORT_INDEX_DEFAULT_DELTA;
    }
}
