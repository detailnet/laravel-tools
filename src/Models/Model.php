<?php

namespace Detail\Laravel\Models;

use GoldSpecDigital\LaravelEloquentUUID\Database\Eloquent\Uuid;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Jenssegers\Mongodb\Eloquent\Model as OdmModel;
use Jenssegers\Mongodb\Schema\Blueprint;
use RuntimeException;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_merge;
use function is_array;
use function json_encode;
use function sprintf;
use function strcmp;
use function uksort;

/**
 * @mixin Builder
 * @method static Builder query()
 */
abstract class Model extends OdmModel
{
    use Uuid;

    const CREATED_AT = 'created_on';
    const UPDATED_AT = 'updated_on';
    const DELETED_AT = 'deleted_on';

    public const SORT_INDEX_DEFAULT_DELTA = 10000;

    public const RULE_OPTION_MULTI = 'multi';

    protected const EMBEDDED_RELATIONS = []; // Method names of relations to be loaded on serialization (toArray)
    protected const SERIALIZATION_ORDER = ['id']; // Sorting of properties on serialization (toArray), properties might not exist (no check)

    private const MAX_INDEXES_PER_COLLECTION = 64 - 1; // 64: ref: https://docs.mongodb.com/manual/reference/limits/ ; the -1 because '_id' is always indexed automatically

    protected $hidden = ['_id']; // Do not serialize '_id', use 'id' instead
    /** @var string[] */
    protected $appends = ['id']; // Serialize 'id'
    protected $connection = 'mongodb';

    /**
     * Validation system for REST client
     *
     * Define own Validator rules by extending `createRules` and `updateRules` methods.
     * The public methods `createValidator` and `updateValidator` are not extendable on purpose (finals).
     */

    /**
     * @param mixed[] $data
     * @param array<string, mixed> $options
     */
    public final static function createValidator(array $data, array $options = []): ValidatorContract
    {
        return Validator::make($data, array_filter(static::createRules($options)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected static function createRules(array $options = []): array
    {
        return []; // Empty stub, each model should define the own ones
    }

    /**
     * @param mixed[] $data
     * @param array<string, mixed> $options
     */
    public final static function updateValidator(array $data, array $options = []): ValidatorContract
    {
        return Validator::make($data, array_filter(static::updateRules($options)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected static function updateRules(array $options = []): array
    {
        return []; // Empty stub, each model should define the own ones
    }

    /**
     * @return array<string, mixed>
     */
    public function onlySortedFields(): array
    {
        return array_intersect_key($this->toArray(), array_flip(static::SERIALIZATION_ORDER));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // Embedded relations are not loaded by default, this causes on serialization:
        // - hidden properties to be displayed
        // - date conversion is not applied
        // Therefore we load the embedded relations recursively.
        // We have to work with a clone, because as soon as an embedded relation is loaded it can't be persisted anymore.
        // This has minimal performance effects, because embedded objects are already in memory (no DB operation needed).
        $clone = clone $this; // Not $this->clone()
        $clone->recursiveLoadEmbeddedRelations();

        // Same as parent::toArray(), but on the clone, otherwise we would have an infinite recursion
        $values = array_merge($clone->attributesToArray(), $clone->relationsToArray());
        $sortPositions = array_flip(static::SERIALIZATION_ORDER);

        uksort(
            $values,
            static function (string $aKey, string $bKey) use ($sortPositions): int {
                $value = ($sortPositions[$aKey] ?? 1000) <=> ($sortPositions[$bKey] ?? 1000);

                return $value !== 0 ? $value : strcmp($aKey, $bKey);
            }
        );

        return $values;
    }

    private function recursiveLoadEmbeddedRelations(): void
    {
        // This should be fixed in \Jenssegers\Mongodb\Eloquent\Model,
        // as the data is embedded, all the job is done in memory.

        // To get embedded relations could use Reflection, searching for methods that return
        // EmbedsMany or EmbedsOne, but performance degradation should be investigated.
        $relations = static::EMBEDDED_RELATIONS;

        if (count($relations) > 0) {
            $this->load($relations);

            foreach ($relations as $relationName) {
                if ($this->{$relationName} instanceof Collection) {
                    foreach ($this->{$relationName}->getIterator() as $model) {
                        if ($model instanceof Model) {
                            $model->recursiveLoadEmbeddedRelations();
                        }
                    }
                }

                if ($this->{$relationName} instanceof Model) {
                    $this->{$relationName}->recursiveLoadEmbeddedRelations();
                }
            }
        }
    }

    /**
     * @internal This should not be used on normal runtime
     */
    public function ensureIndexes(): void
    {
        $indexes = $this->indexes();

        if (count($indexes) >= self::MAX_INDEXES_PER_COLLECTION) {
            throw new RuntimeException('Max limit of indexes exceeded for ' . $this->getTable());
        }

        foreach ($indexes as $indexFields) {
            $this->createIndex($indexFields);

            Log::debug(sprintf('Created index for "%s" collection with keys %s', $this->getTable(), json_encode($indexFields)));
        }
    }

    /**
     * Get fields that need an index, for whole collection and not a client
     *
     * @return array<int, string|string[]>
     */
    protected function indexes(): array
    {
        return []; // Empty stub, each model should define the own ones
    }

    /**
     * @param string|string[] $columns
     * @param array<string, bool>|null $options
     */
    private function createIndex($columns, ?string $name = null, ?array $options = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];

        Schema::connection('mongodb')->table(
            $this->getTable(),
            static function (Blueprint $collection) use ($columns, $name, $options): void {
                $collection->index(
                    $columns,
                    $name,
                    null,
                    $options ?? ['background' => true] // When no options defined use 'background' as default
                );
            }
        );
    }
}
