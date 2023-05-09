<?php

namespace DetailTest\Laravel\Models;

use Detail\Laravel\Models\Model;
use Detail\Laravel\Models\ModelWithSortIndex;
use Detail\Laravel\Models\SortEmbeddedByDragAndDrop;
use InvalidArgumentException;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use Ramsey\Uuid\Uuid;
use function sprintf;

/**
 * This model exists only to let phpstan analyse the SortEmbeddedByDragAndDrop trait.
 *
 * This class is a general example on the methods an embedded property should always have in the main class
 *
 * @property ModelWithSortIndex[] $items
 */
class EmbeddedSortableModel extends Model
{
    use SortEmbeddedByDragAndDrop;

    protected const EMBEDDED_RELATIONS = [
        'items',
    ];

    public function items(): EmbedsMany
    {
        return $this->embedsMany(ModelWithSortIndex::class);
    }

    public function addItem(ModelWithSortIndex $item): void
    {
        if (!isset($item->id)) {
            $item->id = Uuid::uuid4()->toString();
        }

        // Verify same item is not already present
        if ($this->getItem($item) !== null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid item provided; item with id "%s" already exists',
                    $item->id
                )
            );
        }

        $this->getEmbeddedModelNextSortIndex('items');

        $this->items()->associate($item);
    }

    public function removeItem(ModelWithSortIndex $item): void
    {
        if ($this->getItem($item) === null) {
            throw new \RuntimeException(sprintf('Item %d not found', $item->id));
        }

        if ($this->items()->count() <= 1) {
            throw new \RuntimeException('Can\'t delete last item');
        }

        $this->items()->dissociate($item);
    }

    public function getItem(ModelWithSortIndex|string $itemOrId): ?ModelWithSortIndex
    {
        $id = ($itemOrId instanceof ModelWithSortIndex) ? $itemOrId->id : $itemOrId;

        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }
}
