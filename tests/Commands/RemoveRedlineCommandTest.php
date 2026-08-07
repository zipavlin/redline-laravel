<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;
use Zipavlin\Redline\Commands\RemoveCommand;

/**
 * Bind a command instance that redirects the .env path to a temp file and never
 * spawns a real composer process, keeping tests deterministic and fast.
 */
function fakeRemoveRedlineCommand(string $envPath): RemoveCommand
{
    $command = new class($envPath) extends RemoveCommand
    {
        public function __construct(private string $envPath)
        {
            parent::__construct();
        }

        protected function envPath(): string
        {
            return $this->envPath;
        }

        protected function removePackageProcess(): Process
        {
            throw new RuntimeException('composer unavailable in tests');
        }
    };

    app()->instance(RemoveCommand::class, $command);

    // The Artisan console app is built during app boot with real command
    // instances; rebuild it so the fake above is the one registered by name.
    app(Kernel::class)->setArtisan(null);

    return $command;
}

function removeTestEnvPath(string $suffix): string
{
    return storage_path('app/remove-redline-test-'.$suffix.'.env');
}

afterEach(function () {
    foreach (glob(storage_path('app/remove-redline-test-*.env')) ?: [] as $file) {
        @unlink($file);
    }

    // Remove any scan fixture a test may have left behind.
    app('files')->delete(base_path('app/Http/Middleware/RedlineRemovalScanFixture.php'));
});

it('aborts when the app name confirmation does not match', function () {
    $fs = app('files');

    $markers = [
        storage_path('app/redline/database/redline.db'),
        public_path('vendor/redline/redline.js'),
        config_path('redline.php'),
        resource_path('views/vendor/redline/mail/new-report.blade.php'),
    ];

    foreach ($markers as $marker) {
        $fs->ensureDirectoryExists(dirname($marker));
        $fs->put($marker, 'test');
    }

    $appName = (new class extends RemoveCommand
    {
        public function token(): string
        {
            return $this->appName();
        }
    })->token();

    $this->artisan('redline:remove')
        ->expectsQuestion("Please write '$appName' to continue", 'wrong-app-name')
        ->assertExitCode(0);

    foreach ($markers as $marker) {
        expect(file_exists($marker))->toBeTrue();
    }

    // The command refused, so clean the markers up manually.
    $fs->deleteDirectory(storage_path('app/redline'));
    $fs->deleteDirectory(public_path('vendor/redline'));
    $fs->delete(config_path('redline.php'));
    $fs->deleteDirectory(resource_path('views/vendor/redline'));
});

it('falls back to a safe confirmation token when app.url is unset', function () {
    config()->set('app.url', null);

    $this->artisan('redline:remove')
        ->expectsQuestion("Please write 'redline' to continue", 'wrong-answer')
        ->assertExitCode(0);
});

it('removes storage, published files and REDLINE_* env entries with --force', function () {
    $fs = app('files');
    $envPath = removeTestEnvPath('force');
    fakeRemoveRedlineCommand($envPath);

    // Storage tree (SQLite db + media).
    $fs->ensureDirectoryExists(storage_path('app/redline/database'));
    $fs->ensureDirectoryExists(storage_path('app/redline/screenshots'));
    $fs->put(storage_path('app/redline/database/redline.db'), 'sqlite-bytes');
    $fs->put(storage_path('app/redline/screenshots/shot.png'), 'png');

    // Published assets.
    $fs->ensureDirectoryExists(public_path('vendor/redline'));
    $fs->put(public_path('vendor/redline/redline.js'), 'js');

    // Published config + mail views.
    $fs->put(config_path('redline.php'), "<?php\n\nreturn [];\n");
    $fs->ensureDirectoryExists(resource_path('views/vendor/redline/mail'));
    $fs->put(resource_path('views/vendor/redline/mail/new-report.blade.php'), 'blade');

    // Fake .env mixing REDLINE_* keys with unrelated ones.
    $fs->put($envPath, implode("\n", [
        'APP_NAME=Laravel',
        'REDLINE_STORAGE_PROVIDER=Zipavlin\\Redline\\Providers\\Storage\\DatabaseStorageProvider',
        'MAIL_MAILER=resend',
        'REDLINE_AUTH_PIN_REPORTER=1234',
        'RESEND_API_KEY=re_abc',
        'REDLINE_DISABLED=videos,files',
    ]));

    $this->artisan('redline:remove', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Redline storage removed')
        ->expectsOutputToContain('Published assets removed')
        ->expectsOutputToContain('Published config removed')
        ->expectsOutputToContain('Published mail views removed')
        ->expectsOutputToContain('REDLINE_* entries removed')
        ->expectsOutputToContain('composer remove zipavlin/redline');

    expect(storage_path('app/redline'))->not->toBeDirectory();
    expect(public_path('vendor/redline'))->not->toBeDirectory();
    expect(config_path('redline.php'))->not->toBeFile();
    expect(resource_path('views/vendor/redline'))->not->toBeDirectory();

    $remaining = (string) file_get_contents($envPath);
    expect($remaining)->toContain('APP_NAME=Laravel');
    expect($remaining)->toContain('MAIL_MAILER=resend');
    expect($remaining)->toContain('RESEND_API_KEY=re_abc');
    expect($remaining)->not->toContain('REDLINE_');
    expect($remaining)->toContain("MAIL_MAILER=resend\nRESEND_API_KEY=re_abc");
});

it('refuses to delete storage when the base path escapes storage/app', function () {
    $fs = app('files');
    $envPath = removeTestEnvPath('traversal');
    fakeRemoveRedlineCommand($envPath);

    config()->set('redline.storage.path.base', '../..');

    // Marker at the resolved traversal target - it must survive the command.
    $marker = base_path('redline-remove-guard-marker.txt');
    $fs->put($marker, 'keep me');

    $this->artisan('redline:remove', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Refusing to delete');

    expect(file_exists($marker))->toBeTrue();

    $fs->delete($marker);
});

it('reports manually-added Redline references for manual removal', function () {
    $fs = app('files');
    $envPath = removeTestEnvPath('scan');
    fakeRemoveRedlineCommand($envPath);

    $fixture = base_path('app/Http/Middleware/RedlineRemovalScanFixture.php');
    $fs->ensureDirectoryExists(dirname($fixture));
    $fs->put($fixture, implode("\n", [
        '<?php',
        '// fixture for the redline:remove scan',
        'use Zipavlin\\Redline\\Http\\Middleware\\ShowRedlineWidget;',
        'use Zipavlin\\Redline\\RedlineServiceProvider;',
    ]));

    $this->artisan('redline:remove', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('app/Http/Middleware/RedlineRemovalScanFixture.php:3')
        ->expectsOutputToContain('app/Http/Middleware/RedlineRemovalScanFixture.php:4');

    $fs->delete($fixture);
});

it('skips gracefully when nothing is published', function () {
    $envPath = removeTestEnvPath('nothing');
    fakeRemoveRedlineCommand($envPath);

    // The storage provider boot creates storage/app/redline during setUp;
    // delete it to simulate an app that never installed Redline.
    app('files')->deleteDirectory(storage_path('app/redline'));

    $this->artisan('redline:remove', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No Redline storage directory found')
        ->expectsOutputToContain('No published assets found')
        ->expectsOutputToContain('No published config found')
        ->expectsOutputToContain('No published mail views found')
        ->expectsOutputToContain('No .env file found')
        ->expectsOutputToContain('No manual clean-ups required')
        ->expectsOutputToContain('composer remove zipavlin/redline');
});

it('strips REDLINE_* lines and preserves surrounding formatting', function () {
    $command = new class extends RemoveCommand
    {
        public function strip(string $content): string
        {
            return $this->stripRedlineEnvLines($content);
        }
    };

    $cleaned = $command->strip(implode("\n", [
        'APP_NAME=Laravel',
        'REDLINE_STORAGE_PROVIDER=Zipavlin\\Redline\\Providers\\Storage\\DatabaseStorageProvider',
        'MAIL_MAILER=resend',
        'REDLINE_AUTH_PIN_REPORTER=1234',
        'RESEND_API_KEY=re_abc',
        'REDLINE_DISABLED=videos,files',
        '',
    ]));

    expect($cleaned)->toBe(implode("\n", [
        'APP_NAME=Laravel',
        'MAIL_MAILER=resend',
        'RESEND_API_KEY=re_abc',
        '',
    ]));
});

it('strips REDLINE_* lines from CRLF files', function () {
    $command = new class extends RemoveCommand
    {
        public function strip(string $content): string
        {
            return $this->stripRedlineEnvLines($content);
        }
    };

    expect($command->strip("APP_NAME=Laravel\r\nREDLINE_STORAGE_PROVIDER=X\r\nMAIL_MAILER=resend\r\n"))
        ->toBe("APP_NAME=Laravel\r\nMAIL_MAILER=resend\r\n");
});
