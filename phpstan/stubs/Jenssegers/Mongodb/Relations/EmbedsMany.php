<?php

namespace Jenssegers\Mongodb\Relations;

use Illuminate\Database\Eloquent\Collection;

/**
 * @ref https://github.com/nunomaduro/larastan/blob/19866e06d5846f8c17460ce1c1808da8166d3747/UPGRADE.md#upgrading-to-056
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TKey of array-key
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends EmbedsOneOrMany<TRelatedModel>
 */
class EmbedsMany extends EmbedsOneOrMany
{
    /**
     * @return void
     */
    public function addConstraints()
    {
    }

    /**
     * @param TRelatedModel[] $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
    }

    /**
     * @param TRelatedModel[] $models
     * @param string $relation
     *
     * @return TRelatedModel[]
     */
    public function initRelation(array $models, $relation)
    {
    }

    /**
     * @param TRelatedModel[] $models
     * @param \Illuminate\Database\Eloquent\Collection<TKey, TModel> $results
     * @param string $relation
     *
     * @return TRelatedModel[]
     */
    public function match(array $models, Collection $results, $relation)
    {
    }

    /**
     * @return mixed
     */
    public function getResults()
    {
    }
}
