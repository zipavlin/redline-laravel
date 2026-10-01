<?php

namespace Zipavlin\Redline\Models\Concerns;

use Illuminate\Http\Request;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;

/**
 * Opts a model into the Redline query grammar. The model declares its
 * whitelist via querySchema(); resourceQuery() is the entry point controllers
 * use to parse/apply/execute it - e.g.
 *
 *   Report::resourceQuery($request)->use(QueryCapability::INCLUDE)->parse();
 */
trait HasQuerySchema
{
    abstract public static function querySchema(): QuerySchema;

    public static function resourceQuery(Request $request): ResourceQuery
    {
        return new ResourceQuery(static::querySchema(), $request, static::class);
    }
}
