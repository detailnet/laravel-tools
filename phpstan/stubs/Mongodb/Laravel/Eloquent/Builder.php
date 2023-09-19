<?php

namespace MongoDB\Laravel\Eloquent;

/**
 * @ref: https://github.com/nunomaduro/larastan/blob/19866e06d5846f8c17460ce1c1808da8166d3747/UPGRADE.md#upgrading-to-051-from-050
 *
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 * @extends \Illuminate\Database\Eloquent\Builder<TModelClass>
 */
class Builder extends \Illuminate\Database\Eloquent\Builder
{
}
