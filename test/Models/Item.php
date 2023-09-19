<?php

namespace DetailTest\Laravel\Models;

use Detail\Laravel\Models\EmbeddedModelSortableByDragAndDrop;

class Item extends EmbeddedModelSortableByDragAndDrop
{
    protected static function boot()
    {
        parent::boot();

        // static::creating never called on embedded model creation
        // Use $attributes instead, and for dynamic properties use the override of constructor,
        // using the isset on property, to not interfere with creation from the DB

        static::updating(function (Item $item) {
            $item->updateSortIndex();
        });
    }
}
