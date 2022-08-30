<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Http\RestController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use function array_flip;
use function array_keys;
use function floor;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * @property string $_id
 * @property int|string $sort_index // String is possible only on assignment, never on persist
 */
trait SortByDragAndDrop
{
    /**
     * @return string[]
     */
    private static function updateSortIndexRule(): array
    {
        return ['string', 'regex:/^(?:after|before):' . RestController::UUID_V4_PATTERN . '$/'];
    }

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

        if (is_string($this->sort_index)) {
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

        if (preg_match('/^(?<position>after|before):(?<uuid>' . RestController::UUID_V4_PATTERN . ')$/', $this->sort_index,
                $reference) === false) {
            throw new RuntimeException('Wrong sorting string');
        }

        $indexes = $this->fetchIndexes();
        /** @var array<int, string> $indexMap */
        $indexMap = array_flip(array_keys($indexes));
        $currentIndex = null;

        foreach ($indexMap as $index => $id) {
            if ($id === $reference['uuid']) {
                $currentIndex = $index;
            }
        }

        if ($currentIndex === null) {
            throw new RuntimeException(
                sprintf('Failed to apply sort_index: reference model "%s" not found within adjacent models', $reference['uuid'])
            );
        }

        switch ($reference['position']) {
            case 'before':
                $maxIndex = $currentIndex;
                $minIndex = $currentIndex - 1;
                break;
            case 'after':
                $minIndex = $currentIndex;
                $maxIndex = $currentIndex + 1;
                break;
            default:
                throw new RuntimeException(
                    sprintf('Failed to apply sort_index: position to reference "%s" not supported', $reference['position'])
                );
        }

        // Check boundaries
        $min = $minIndex >= 0 ? $indexes[$indexMap[$minIndex]] : 0;
        $max = $maxIndex < count($indexes) ? $indexes[$indexMap[$maxIndex]] : ($min + 2 * self::SORT_INDEX_DEFAULT_DELTA);

        // Mean value between min and max
        $newIndex = $min + (integer) floor(($max - $min) / 2);

        if ($newIndex === $min || $newIndex === $max) {
            $this->fullReindex();

            return $this->extractSortIndexFromAdjacent();
        }

        return $newIndex;
    }

    /**
     * @return array<string|int, int>
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

                    $indexes[$model->{$key}] = $model->sort_index;
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
        // there fore need sot be aware of what has been already updated. The solution is use 'chunkById';
        // Ref: https://laravel.com/docs/8.x/queries#chunking-results

        // But we have decided to go the better way, that might perform a few queries more (less performant), but is safer
        foreach (array_keys($this->fetchIndexes()) as $key) {
            $index += self::SORT_INDEX_DEFAULT_DELTA;

            $model = self::query()->find($key);

            if ($model === null) {
                continue;
            }

            // $model->updateQuietly(['sort_index' => $index]); // Doe not works .. don't know why
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

        return $latest + self::SORT_INDEX_DEFAULT_DELTA;
    }
}
