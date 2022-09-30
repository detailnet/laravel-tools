<?php

namespace Detail\Laravel\Http\Traits;

use Detail\Laravel\Models\Model;
use Dflydev\DotAccessData\Data;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use IteratorAggregate;
use Jenssegers\Mongodb\Relations\EmbedsMany;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use function abort;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function ctype_digit;
use function implode;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function json_last_error_msg;
use function request;

/**
 * // Types need to be defined in phpstan/extension.neon too (maybe because this is a trait?)
 * @phpstan-type FilterItem array{property: string, operator?: string, value: string|int|float|bool|null}
 * @phpstan-type SortItem array{property: string, direction?: 'asc'|'desc'|1|-1}
 */
trait CollectionQuery
{
    /** @var array<string, string> */
    private array $operators = [
        'eq' => '=',
        'gt' => '>',
        'lt' => '<',
        'gte' => '>=',
        'lte' => '<=',
        'not' => '!=',
        '≃' => 'like',
        'exists' => 'exists',
        'notexists' => 'notexists',
        'in' => 'in',
        'not in' => 'notin',
    ];

    /**
     * @param string[] $excludedFields
     * @param FilterItem[] $defaultFilters
     * @param SortItem[] $defaultSorters
     *
     * @return array<string, mixed>
     */
    public function getCollectionFromRequest(
        Builder|Relation|null $model,
        string $collectionName = 'data',
        ?int $defaultPageSize = null,
        ?int $maxPageSize = null,
        array $excludedFields = [],
        array $defaultFilters = [],
        array $defaultSorters = []
    ): array {
        if ($model === null) {
            return [
                $collectionName => [],
                'total_items' => 0,
            ];
        }

        if ($model instanceof Relation) {  // Can't be written in one single if, otherwise phpstan does not pick it correctly
            if (!$model instanceof HasMany
                && !$model instanceof BelongsToMany
                && !$model instanceof EmbedsMany
            ) {
                throw new RuntimeException('Provided Relation has to be a valid to-Many one');
            }
        }

        foreach ($this->getFilters($defaultFilters) as $filter) {
            $operator = $this->operators[$filter['operator'] ?? ''] ?? $filter['operator'] ?? '=';

            $model = match ($operator) {
                'in' => $model->whereIn($filter['property'], $filter['value']),
                'notin' => $model->whereNotIn($filter['property'], $filter['value']),
                'exists' => $model->whereNotNull($filter['property']),
                'notexists' => $model->whereNull($filter['property']),
                'like' => $model->where($filter['property'], 'like', '%' . $filter['value'] . '%'),
                default => $model->where($filter['property'], $operator, $filter['value']),
            };
        }

        // For EmbedsMany get collection, because sorting and paginating would not work
        // ref: https://github.com/jenssegers/laravel-mongodb/issues/178
        if ($model instanceof EmbedsMany) {
            $model = $model->getResults();
        }

        foreach ($this->getSorters($defaultSorters) as $sort) {
            $model = match ($sort['direction'] ?? '') {
                'desc', 1 => $model instanceof Collection ? $model->sortByDesc($sort['property']) : $model->orderByDesc($sort['property']),
                default => $model instanceof Collection ? $model->sortBy($sort['property']) : $model->orderBy($sort['property']),
            };
        }

        return $this->getPaginatedData(
            $model,
            $collectionName,
            $defaultPageSize,
            $maxPageSize,
            $excludedFields
        );
    }

    /**
     * @param string[] $excludedFields
     *
     * @return array<string, mixed>
     */
    public function getPaginatedData(
        Builder|Relation|Collection $model,
        string $collectionName = 'data',
        ?int $defaultPageSize = null,
        ?int $maxPageSize = null,
        array $excludedFields = [],
    ): array {
        if (($pageSize = $this->getPageSize($defaultPageSize, $maxPageSize)) !== null) {
            $page = $this->getPageNumber();

            if ($model instanceof Collection) {
                $data = new LengthAwarePaginator($model->forPage($page, $pageSize), $model->count(), $pageSize, $page);
            } else {
                /** @var LengthAwarePaginator $data */
                $data = $model->paginate($pageSize, ['*'], 'page', $page);
            }

            return [
                $collectionName => $this->getCollectionData($data, $excludedFields),
                'page_count' => $data->lastPage(),
                'page_size' => $pageSize,
                'total_items' => $data->total(),
            ];
        }

        $data = $model instanceof Builder ? $model->get() : $model;
        $data = $data instanceof Collection ? $data : $data->getResults();

        return [
            $collectionName => $this->getCollectionData($data, $excludedFields),
            'total_items' => count($data),
        ];
    }

    /**
     * @param FilterItem[] $defaultFilters
     *
     * @return FilterItem[]
     */
    protected function getFilters(array $defaultFilters = []): array
    {
        /** @todo Should accept array too e.g.: filter[0]['property'], filter[0]['operation'], ... Test it */
        $filters = request()->query('filter', $defaultFilters);

        if (is_string($filters)) {
            $filters = json_decode($filters, true);

            if ($filters === false) {
                abort(Response::HTTP_BAD_REQUEST, 'Malformed JSON provided for filter: ' . json_last_error_msg());
            }
        }

        $validator = Validator::make(
            $filters,
            [
                '*.property' => ['required', 'regex:/^[a-z][0-9a-z_.]*[a-z]$/i'],
                '*.operator' => ['nullable', Rule::in(array_merge(array_keys($this->operators), array_values($this->operators)))],
                '*.value' => ['nullable'],
            ]
        );

        if ($validator->fails()) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid filter: ' . implode(' - ', $validator->errors()->all()));
        }

        return $filters;
    }

    /**
     * @param SortItem[] $defaultSorters
     *
     * @return SortItem[]
     */
    protected function getSorters(array $defaultSorters = []): array
    {
        /** @todo Should accept array too e.g.: sort[0]['property'], sort[0]['direction'], ... Test it */
        $sorters = request()->query('sort', $defaultSorters);

        if (is_string($sorters)) {
            $sorters = json_decode($sorters, true);

            if ($sorters === false) {
                abort(Response::HTTP_BAD_REQUEST, 'Malformed JSON provided for sorters: ' . json_last_error_msg());
            }
        }

        $validator = Validator::make(
            $sorters,
            [
                '*.property' => ['required', 'regex:/^[a-z][0-9a-z_.]*[a-z]$/i'],
                '*.direction' => ['nullable', Rule::in(['asc', 'desc', -1, 1])],
            ]
        );

        if ($validator->fails()) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid sorter: ' . implode(' - ', $validator->errors()->all()));
        }

        return $sorters;
    }

    protected function getPageSize(?int $defaultPageSize, ?int $maxPageSize): ?int
    {
        $pageSize = request()->query('page_size');

        if ($pageSize === null) {
            return $defaultPageSize ?? $maxPageSize;
        }

        if (!ctype_digit($pageSize)) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid page_size: must be a positive integer number');
        }

        $pageSize = (int) $pageSize;

        if ($pageSize < 1) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid page_size: must be higher or equal to 1');
        }

        if ($maxPageSize !== null && $pageSize > $maxPageSize) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid page_size: must be lower or equal to ' . $maxPageSize);
        }

        return $pageSize;
    }

    protected function getPageNumber(): int
    {
        $pageNumber = request()->query('page');

        if ($pageNumber === null) {
            return 1;
        }

        if (!ctype_digit($pageNumber)) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid page: must be a positive integer number');
        }

        $pageNumber = (int) $pageNumber;

        if ($pageNumber < 1) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid page: must be higher or equal to 1');
        }

        return $pageNumber;
    }

    /**
     * @param string[] $excludes
     *
     * @return mixed[]
     */
    private function getCollectionData(?IteratorAggregate $data, array $excludes = []): array
    {
        if ($data === null) {
            return [];
        }

        foreach ($data->getIterator() as &$item) {
            if ($item instanceof Model) {
                if (count($excludes) > 0) {
                    $item->makeHidden($excludes);
                }
            } else {
                $data = new Data($item);

                foreach ($excludes as $key) {
                    $data->remove($key);
                }

                $item = $data->export();
            }
        }

        return array_values(iterator_to_array($data->getIterator()));
    }
}
