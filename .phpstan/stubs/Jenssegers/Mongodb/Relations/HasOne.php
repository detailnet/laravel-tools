<?php

namespace Jenssegers\Mongodb\Relations;

/**
 * @ref https://github.com/nunomaduro/larastan/blob/19866e06d5846f8c17460ce1c1808da8166d3747/UPGRADE.md#upgrading-to-056
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @extends \Illuminate\Database\Eloquent\Relations\HasOne<TRelatedModel>
 */
class HasOne extends \Illuminate\Database\Eloquent\Relations\HasOne
{
}
