<?php

namespace Detail\Laravel\Http;

use Detail\Laravel\Http\Traits\CollectionQuery;
use Detail\Laravel\Http\Traits\ErrorResponse;
use Detail\Laravel\Http\Traits\ModelLogging;
use Detail\Laravel\Models\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function array_diff_key;
use function array_flip;
use function array_merge;
use function array_pop;
use function array_unique;
use function assert;
use function call_user_func;
use function class_basename;
use function count;
use function explode;
use function in_array;
use function is_a;
use function preg_match;
use function request;
use function response;
use function sprintf;

abstract class RestController extends Controller
{
    use CollectionQuery, ErrorResponse, ModelLogging;

    protected const ID_PATTERN = Model::UUID_V4_PATTERN;
    protected const ALLOW_DROP = false; // Additional security: has to be defined as route, but the controller has to explicit allow too
    protected const ALLOW_MULTI = false;
    protected const MULTI_SEPARATOR = ',';
    protected const MULTI_MAX_COUNT = 50;
    protected const LIST_TREE = false;
    protected const LISTING_DEFAULT_FILTERS = [];
    protected const LISTING_DEFAULT_SORTERS = [];
    protected const LISTING_DEFAULT_PAGE_SIZE = null;
    protected const LISTING_MAX_PAGE_SIZE = null;
    protected const LISTING_EXCLUDED_FIELDS = [];
    protected const LOG_PERSISTENCE = true; //Sometimes there is no need to log (typically for comments or downloads that are as logs in DB)

    /** @var callable(Model):Model|null */
    protected $preSerialize = null;

    public function index(): JsonResponse
    {
        $collection = $this->getCollection();
        $excludedFields = static::LISTING_EXCLUDED_FIELDS;

        if (static::LIST_TREE) {
            if (in_array(request()->query('tree') ?? '1', ['1', 'yes', 'true'], true)) {
                $collection?->where('parent_id', '=', null)?->orderBy('sort_index');
            } else {
                $excludedFields[] = 'children'; // When on tree-listing but tree is disabled, do not show children
            }
        }

        return response()->json(
            $this->getCollectionFromRequest(
                $collection,
                $this->getCollectionName(),
                static::LISTING_DEFAULT_PAGE_SIZE,
                static::LISTING_MAX_PAGE_SIZE,
                $excludedFields,
                static::LISTING_DEFAULT_FILTERS,
                static::LISTING_DEFAULT_SORTERS
            )
        );
    }

    protected function getCollection(): Builder|HasMany|BelongsToMany|EmbedsMany|null
    {
        $collection = $this->collection();

        if ($collection instanceof Relation) {  // Can't be written in one single if, otherwise phpstan does not pick it correctly
            if (!$collection instanceof HasMany
                && !$collection instanceof BelongsToMany
                && !$collection instanceof EmbedsMany
            ) {
                throw new RuntimeException('Provided Relation has to be a valid to-Many one');
            }
        }

        return $collection;
    }

    protected function collection(): Builder|Relation|null
    {
        $model = $this->modelClass();

        return $model::query();
    }

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    protected function getCollectionName(): string
    {
        return Str::snake(Str::plural(class_basename($this->modelClass())));
    }

    public function show(string ...$id): JsonResponse
    {
        $id = array_pop($id) ?? ''; // The last argument, needed when route has other parameters

        if (!$this->isIdValid($id)) {
            return $this->errorResponse(sprintf('Invalid ID "%s" format for %s', $id, class_basename($this->modelClass())));
        }

        if ($this->isMulti($id)) {
            return $this->errorResponse('Multi ID is not supported on GET requests');
        }

        $model = $this->getCollection()?->find($id);

        if (!$model instanceof Model) {
            return $this->errorResponse(
                sprintf('%s with ID "%s" not found', class_basename($this->modelClass()), $id),
                Response::HTTP_NOT_FOUND
            );
        }

        if (isset($this->preSerialize)) {
            call_user_func($this->preSerialize, $model);
        }

        return response()->json($model);
    }

    protected function isIdValid(string $id): bool
    {
        $pattern = static::ID_PATTERN;

        if (static::ALLOW_MULTI) {
            if (str_contains($pattern, static::MULTI_SEPARATOR)) {
                return false; // Should throw an exception
            }

            $pattern = '(' . static::ID_PATTERN . static::MULTI_SEPARATOR . '){0,' . (static::MULTI_MAX_COUNT - 1) . '}' . $pattern;
        }

        return preg_match('/^' . $pattern . '$/', $id) === 1;
    }

    protected function isMulti(string $id): bool
    {
        return static::ALLOW_MULTI && str_contains($id, static::MULTI_SEPARATOR);
    }

    public function store(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();
        assert(is_a($modelClass, Model::class, true));
        $params = $request->all();

        if (count($params) === 0) {
            return $this->errorResponse('Empty or corrupt request body');
        }

        $validator = $modelClass::createValidator($params);

        if ($validator->fails()) {
            return $this->errorResponse($validator);
        }

        $validated = $validator->validated();

        try {
            /** @var Model $model */
            $model = new $modelClass($validated);

            //dd($validated, $model->getFillable(), array_diff_key($validated, array_flip($model->getFillable())));

            foreach (array_diff_key($validated, array_flip($model->getFillable())) as $property => $value) {
                $model->{$property} = $value;
            }

            $this->createOnStore($model);

            if (static::LOG_PERSISTENCE) {
                $this->logPersisted('create', $model, $validated);
            }
        } catch (Throwable $e) {
            $this->logError('create', $e, $model ?? $modelClass, $validated);

            return $this->errorResponse($e);
        }

        if (isset($this->preSerialize)) {
            call_user_func($this->preSerialize, $model);
        }

        return response()->json($model, Response::HTTP_CREATED);
    }

    /**
     * Permit override to create embedded models when using sub-routes
     */
    protected function createOnStore(Model $model): void
    {
        $model->save(); // As default cave the model (the DB creates it)
    }

    public function update(Request $request, string ...$id): JsonResponse
    {
        $id = array_pop($id) ?? ''; // The last argument, needed when route has other parameters

        if (!$this->isIdValid($id)) {
            return $this->errorResponse(sprintf('Invalid ID "%s" format for %s', $id, class_basename($this->modelClass())));
        }

        $params = $request->all();

        if (count($params) === 0) {
            return $this->errorResponse('Empty or corrupt request body');
        }

        // Differentiate multi requests for
        //  - validation: on multi is done before the check if $model exists
        //  - response: on multi we return a "collection"

        if (!$this->isMulti($id)) {
            $model = $this->getCollection()?->find($id);

            if ($model === null) {
                return $this->errorResponse(
                    sprintf('%s with ID "%s" not found', class_basename($this->modelClass()), $id),
                    Response::HTTP_NOT_FOUND
                );
            }

            assert($model instanceof Model);

            $validator = $model::updateValidator(
                $params,
                $this->updateValidatorOptions($model)
            );

            if ($validator->fails()) {
                return $this->errorResponse($validator);
            }

            $validated = $validator->validated();

            try {
                foreach ($validated as $property => $value) {
                    $model->{$property} = $value;
                }

                $this->saveOnUpdate($model);

                if (static::LOG_PERSISTENCE) {
                    $this->logPersisted('update', $model, $validated);
                }
            } catch (Throwable $e) {
                $this->logError('update', $e, $model, $validated);

                return $this->errorResponse($e);
            }

            if (isset($this->preSerialize)) {
                call_user_func($this->preSerialize, $model);
            }

            return response()->json($model);
        }

        /** @var Model[] $result */
        $result = [];

        $validator = ($this->modelClass())::updateValidator(
            $params,
            array_merge([Model::RULE_OPTION_MULTI => true], $this->updateValidatorOptions())
        );

        if ($validator->fails()) {
            return $this->errorResponse($validator);
        }

        /** @todo Should start transaction, to rollback all when one fails */
        foreach ($this->getIds($id) as $key) {
            $model = $this->getCollection()?->find($key);

            if ($model === null) {
                continue; // Skip and do not fail on multi
            }

            assert($model instanceof Model);

            $validated = $validator->validated();

            try {
                foreach ($validated as $property => $value) {
                    $model->{$property} = $value;
                }

                $this->saveOnUpdate($model);

                if (static::LOG_PERSISTENCE) {
                    $this->logPersisted('multi-update', $model, $validated);
                }
            } catch (Throwable $e) {
                $this->logError('multi-update', $e, $model, $validated);

                continue;
            }

            if (isset($this->preSerialize)) {
                call_user_func($this->preSerialize, $model);
            }

            $result[] = $model;
        }

        if (count($result) === 0) {
            return $this->errorResponse(
                'Errors occurred on multi PATCH update for all entities, submit single entity for insight'
            );
        }

        return response()->json($result);
    }

    /**
     * Permit override to update embedded models when using sub-routes
     */
    protected function saveOnUpdate(Model $model): void
    {
        $model->save(); // As default save the model
    }

    /**
     * @return string[]
     */
    protected function getIds(string $id): array
    {
        if ($this->isMulti($id)) {
            return array_unique(explode(static::MULTI_SEPARATOR, $id)); // Make sure ID are not repeated
        }

        return [$id];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateValidatorOptions(?Model $model = null): array
    {
        return [];
    }

    public function destroy(string ...$id): Response
    {
        $id = array_pop($id) ?? ''; // The last argument, needed when route has other parameters

        if (!$this->isIdValid($id)) {
            return $this->errorResponse(sprintf('Invalid ID "%s" format for %s', $id, class_basename($this->modelClass())));
        }

        // Do not throw return HTTP_NOT_FOUND when the entity is not found

        if (!$this->isMulti($id)) {
            try {
                $model = $this->getCollection()?->find($id);

                if ($model instanceof Model) { // Checks that is not null
                    $this->deleteOnDestroy($model);
                }

                if (static::LOG_PERSISTENCE) {
                    $this->logPersisted('delete', $this->modelClass(), ['id' => $id]);
                }
            } catch (Throwable $e) {
                $this->logError('delete', $e, $this->modelClass(), ['id' => $id]);

                return $this->errorResponse($e);
            }
        } else {
            //$this->getCollection()?->whereIn('_id', $this->getIds($id))?->delete();

            foreach ($this->getCollection()?->whereIn('_id', $this->getIds($id))?->getModels() ?? [] as $model) {
                if ($model instanceof Model) { // Checks that is not null
                    $this->deleteOnDestroy($model);
                }
            }

            if (static::LOG_PERSISTENCE) {
                $this->logPersisted('multi-delete', $this->modelClass(), ['id' => $id]);
            }
        }

        return response()->noContent();
    }

    /**
     * Permit override to delete embedded models when using sub-routes
     */
    protected function deleteOnDestroy(Model $model): void
    {
        $model->delete(); // As default delete the model
    }

    //public function drop(): Response
    //{
    //    if (!static::ALLOW_DROP
    //        // || auth()?->user()?->getRole() !== 'superadmin'
    //    ) {
    //        return $this->errorResponse('Not allowed to drop the whole collection');
    //    }
    //
    //    $modelClass = $this->modelClass();
    //    assert(is_a($modelClass, Model::class, true));
    //
    //    $modelClass::truncate();
    //
    //    return response()->noContent();
    //}
}
