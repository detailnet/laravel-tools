<?php

namespace Detail\Laravel\Models;

use App\Models\Production\Layer;
use Illuminate\Support\Str;
use MongoDB\Laravel\Relations\EmbedsOneOrMany;
use RuntimeException;
use function assert;
use function call_user_func;
use function class_basename;
use function class_uses;
use function get_class;
use function in_array;
use function is_callable;
use function sprintf;

/**
 * @template TDeclaringModel of Model
 *
 * @todo Would this be better as trait instead of class?
 */
abstract class EmbeddedModel extends Model
{
    protected $table = null; // Do not save own collection

    /**
     * Get the parent model of the relation.
     *
     * // Following generic return can't be set, because produces error
     * // "Type Detail\Laravel\Models\Model is not always the same as TDeclaringModel.
     * //  It breaks the contract for some argument types, typically subtypes."
     * // @return TDeclaringModel
     */
    public function getParent(): Model
    {
        if (($relation = $this->getParentRelation()) === null) {
            throw new RuntimeException(
                sprintf(
                    'Model "%s" is not embedded (has no parent releation), invalid use of "%s" class.',
                    static::class,
                    EmbeddedModel::class,
                )
            );
        }

        if (!$relation instanceof EmbedsOneOrMany) {
            throw new RuntimeException(
                sprintf(
                    'Relation "%s" is not valid for use of "%s" class.',
                    get_class($relation),
                    EmbeddedModel::class,
                )
            );
        }

        $parent = $relation->getParent();

        assert($parent instanceof Model);

        return $parent;
    }

    /**
     * The attribute name to get the embedded collection from parent.
     *
     * This is a limitation: this model can't be integrated more than once in same parent with different names
     * Could get rid of this using Reflection into $this->getParentRelation(): gathering the
     * protected parameter @ref \MongoDB\Laravel\Relations\EmbedsOneOrMany::$localKey value.
     */
    protected static function getParentRelationAttributeName(): string
    {
        return Str::snake(Str::plural(class_basename(static::class)));
    }
}
