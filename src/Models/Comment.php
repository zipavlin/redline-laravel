<?php

namespace Zipavlin\Redline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Zipavlin\Redline\Enums\QueryOperator;
use Zipavlin\Redline\Http\Query\FilterField;
use Zipavlin\Redline\Http\Query\IncludeField;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Models\Concerns\HasQuerySchema;

class Comment extends Model
{
    use HasQuerySchema;
    use HasUuids;

    protected $connection = 'redline';

    protected $table = 'redline_comments';

    protected $fillable = [
        'id',
        'report_id',
        'author_id',
        'body',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Query grammar surface for comments. CommentController::index() is a
     * nested, always-scoped-to-one-report list and is not (yet) grammar-driven,
     * so this exists mainly for consistency and to let a future
     * `filter[comments.body:like]` on report - or a top-level comment endpoint -
     * delegate here. `author` mirrors Report's: name-only delegation, so it can
     * never become an email oracle. See FilterField::$relatedFilters.
     */
    public static function querySchema(): QuerySchema
    {
        return new QuerySchema(
            filters: [
                'body' => new FilterField(operators: QueryOperator::string(), column: 'body'),
                'author' => new FilterField(
                    operators: QueryOperator::relation(),
                    relation: 'author',
                    relationColumn: 'id',
                    relatedSchema: fn () => User::querySchema(),
                    relatedFilters: ['name'],
                ),
            ],
            sortable: ['created_at'],
            includable: [
                'body' => new IncludeField(column: 'body'),
                'author' => new IncludeField(
                    relation: 'author',
                    attributes: ['id', 'name', 'initials'],
                    default: ['id', 'name'],
                    needs: ['initials' => ['name']],
                ),
            ],
            defaultSort: ['created_at' => 'asc', 'id' => 'asc'],
        );
    }
}
