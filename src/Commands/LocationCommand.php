<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Zipavlin\Redline\Models\Report;

class LocationCommand extends Command
{
    protected $signature = 'redline:location
        {from : Current location to replace}
        {to : New location}
        {--dry-run : will report how many reports would move without writing}
        {--F|force : will skip confirmation}';

    protected $description = 'Move existing reports from one page location to another';

    public function handle(): int
    {
        $from = trim((string) $this->argument('from'));
        $to = trim((string) $this->argument('to'));

        if ($from === '' || $to === '') {
            $this->error('  Both the current and the new location are required.');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('  The current and the new location must differ.');

            return self::FAILURE;
        }

        $count = Report::query()->where('location', $from)->count();

        if ($count === 0) {
            $this->error("  Nothing is stored at {$from} - no reports to move.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line("  <fg=yellow>→</> Dry run: {$count} report(s) would move from {$from} to {$to}.");
            $this->line('');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Move {$count} report(s) from {$from} to {$to}?")) {
            $this->line('');
            $this->line('  <fg=yellow>→</> Aborted - nothing was changed.');
            $this->line('');

            return self::SUCCESS;
        }

        $moved = Report::query()->where('location', $from)->update(['location' => $to]);

        $this->line('');
        $this->line("  <fg=green>✓</> Moved {$moved} report(s) from {$from} to {$to}.");
        $this->line('');

        return self::SUCCESS;
    }
}
