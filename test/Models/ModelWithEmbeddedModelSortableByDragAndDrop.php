<?php

namespace DetailTest\Laravel\Models;

use Detail\Laravel\Models\Model;
use Detail\Laravel\Models\SortEmbeddedByDragAndDrop;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use Ramsey\Uuid\Uuid;
use function sprintf;

/**
 * This model exists only to let phpstan analyse the SortEmbeddedByDragAndDrop trait.
 *
 * This class is a general example on the methods an embedded property should always have in the main class
 *
 * @property Collection<int, Item> $items
 */
class ModelWithEmbeddedModelSortableByDragAndDrop extends Model
{
    use SortEmbeddedByDragAndDrop;

    protected const EMBEDDED_RELATIONS = [
        'items',
    ];

    public function items(): EmbedsMany
    {
        return $this->embedsMany(Item::class);
    }

    public function addItem(Item $item): void
    {
        if (!isset($item->id)) {
            $item->id = Uuid::uuid4()->toString();
        }

        // Verify same item is not already present
        if ($this->getItem($item) !== null) {
            throw new InvalidArgumentException(sprintf('Invalid item provided; item with id "%s" already exists', $item->id));
        }

        $item->sort_index = $this->getEmbeddedModelNextSortIndex('items');

        $this->items()->associate($item);
    }

    public function removeItem(Item $item): void
    {
        if ($this->getItem($item) === null) {
            throw new \RuntimeException(sprintf('Item %d not found', $item->id));
        }

        if ($this->items()->count() <= 1) {
            throw new \RuntimeException('Can\'t delete last item');
        }

        $this->items()->dissociate($item);
    }

    public function getItem(Item|string $itemOrId): ?Item
    {
        $id = ($itemOrId instanceof Item) ? $itemOrId->id : $itemOrId;

        return $this->items->first(static fn(Item $item): bool => $item->id === $id);
    }
}
