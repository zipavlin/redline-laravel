<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'redline';

    public function up(): void
    {
        // A report's author is never a member (they are the `author` relation),
        // so drop any historical pair linking a report to its own author. The
        // correlated subquery naturally skips reports with a NULL author.
        DB::connection('redline')->table('redline_members')
            ->whereIn('report_id', function (Builder $reports): void {
                $reports->select('redline_reports.id')
                    ->from('redline_reports')
                    ->whereColumn('redline_reports.author_id', 'redline_members.user_id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Deleted pairs cannot be reconstructed; the cleanup is irreversible.
    }
};
