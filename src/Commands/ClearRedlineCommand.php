<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class ClearRedlineCommand extends Command
{
    public $signature = 'redline:clear';

    public $description = 'Delete all Redline reports and associated media files';

    public function handle(StorageProvider $storage): int
    {
        if (! $this->confirm('This will permanently delete ALL Redline reports and media. Continue?')) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $storage->deleteMedia();
        app(Report::class)->query()->delete();

        $this->info('All Redline reports and media files have been deleted.');

        return self::SUCCESS;
    }
}
