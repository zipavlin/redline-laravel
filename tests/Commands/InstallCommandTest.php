<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Zipavlin\Redline\Commands\InstallCommand;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Guard\DebugGuardProvider;
use Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider;
use Zipavlin\Redline\Support\ComposerScripts;

/**
 * redline:install walks the user through interactive setup. In testbench there
 * is no .env and no published config, so every run follows the same skeleton:
 * storage backend, auth provider, guard provider, features (empty), email setup
 * (declined), asset publishing (declined), then the first-admin-user decision.
 */
afterEach(function () {
    // The install command publishes config/redline.php unconditionally when the
    // file is missing; remove it so the tests stay order-independent.
    if (file_exists(config_path('redline.php'))) {
        app('files')->delete(config_path('redline.php'));
    }

    cleanComposerJsonFixtures();
});

/**
 * Bind a command instance that records .env writes, never spawns a real
 * composer process, and redirects the composer.json hook to a temp fixture -
 * keeping the install-flow tests deterministic and fast.
 *
 * @param  bool  $composerSucceeds  Whether the faked composer install reports success.
 */
function fakeInstallCommand(bool $composerSucceeds = true): InstallCommand
{
    $command = new class(composerJsonFixture('{}')) extends InstallCommand
    {
        public array $envWrites = [];

        public array $composerInstalls = [];

        public bool $composerSucceeds = true;

        /** @var list<array{string, array}> Commands run through the --silent seam. */
        public array $quietCalls = [];

        /** @var array<string, int> Exit code per command for the --silent seam (default 0). */
        public array $quietExitCodes = [];

        public function __construct(public string $composerJsonPath)
        {
            parent::__construct();
        }

        protected function composerJsonPath(): string
        {
            return $this->composerJsonPath;
        }

        protected function composerInstall(string $package): bool
        {
            $this->composerInstalls[] = $package;

            if (! $this->composerSucceeds) {
                $this->line('  <fg=yellow>⚠</> Could not run composer automatically. Run this manually then restart this command:');
                $this->line("     <options=bold>composer require $package</>");

                return false;
            }

            return true;
        }

        protected function writeEnvValue(string $key, string $value): void
        {
            $this->envWrites[$key] = $value;
        }

        /** @var list<array> Arguments of every `migrate` call, interactive (call) or silent (callSilent). */
        public array $migrateCalls = [];

        public function call($command, array $arguments = []): int
        {
            if ($command === 'migrate') {
                $this->migrateCalls[] = $arguments;
            }

            return parent::call($command, $arguments);
        }

        public function callSilent($command, array $arguments = []): int
        {
            $this->quietCalls[] = [$command, $arguments];

            if ($command === 'migrate') {
                $this->migrateCalls[] = $arguments;
            }

            return $this->quietExitCodes[$command] ?? 0;
        }
    };

    $command->composerSucceeds = $composerSucceeds;

    app()->instance(InstallCommand::class, $command);

    // The Artisan console app is built during app boot with real command
    // instances; rebuild it so the fake above is the one registered by name.
    app(Kernel::class)->setArtisan(null);

    return $command;
}

it('accepts an empty features answer ("disable none") and completes the install', function () {
    // Binds a fake pointing the composer.json hook at a throwaway fixture.
    // Without it this test would run the real installer, whose composerJsonPath()
    // resolves to testbench's own composer.json, and would rewrite that file.
    fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Redline installed successfully!')
        ->assertExitCode(0);
});

it('writes REDLINE_FEATURES when features are selected during install', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', 'screenshots,comments')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('REDLINE_FEATURES=screenshots,comments')
        ->assertExitCode(0);

    expect($command->envWrites['REDLINE_FEATURES'])->toBe('screenshots,comments');
});

/**
 * Selecting the mcp feature surfaces the setup hints the flag alone cannot
 * give: where to mint a token, which endpoint to point the MCP client at
 * (derived from config, not a hardcoded host), and the HTTP Basic Auth
 * pointer when the host has edge Basic Auth turned on.
 */
it('mcp selection prints the token + endpoint hints during install', function () {
    // config-only: putenv() here would leak APP_URL process-wide and flake
    // later browser-URL assertions in randomized order (SecurityTest S1).
    fakeInstallCommand();
    config(['app.url' => 'https://staging.example.com']);

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', 'mcp')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('redline:mcp')
        ->expectsOutputToContain('staging.example.com/redline/api/mcp')
        ->expectsOutputToContain('docs/12-mcp.md')
        ->assertExitCode(0);
});

it('an install without mcp stays quiet about tokens and endpoints', function () {
    fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', 'screenshots')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->doesntExpectOutputToContain('redline:mcp')
        ->doesntExpectOutputToContain('redline/api/mcp')
        ->doesntExpectOutputToContain('docs/12-mcp.md')
        ->assertExitCode(0);
});

it('skips creating a user when LaravelAuthProvider is selected during install', function () {
    fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', LaravelAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsOutputToContain('provisioned automatically on first host-application login')
        ->doesntExpectOutputToContain('Would you like to create the first admin user?')
        ->assertExitCode(0);

    expect(app(User::class)->count())->toBe(0);
});

it('writes the SMTP mailer values when the no-DNS SMTP option is chosen', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'SMTP without DNS (recommended for staging - e.g. Mailjet)')
        ->expectsQuestion('SMTP host', 'in-v3.mailjet.com')
        ->expectsQuestion('SMTP port', '587')
        ->expectsQuestion('SMTP username (Mailjet API key)', 'apikey123')
        ->expectsQuestion('SMTP password (Mailjet Secret key)', 'secretkey123')
        ->expectsQuestion('From address (the inbox that receives the sender-verification link)', 'staging@example.com')
        ->expectsQuestion('From name', 'Redline')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Set MAIL_FROM_ADDRESS')
        ->expectsOutputToContain('simply skipped')
        ->assertExitCode(0);

    expect($command->envWrites)->toMatchArray([
        'MAIL_MAILER' => 'smtp',
        'MAIL_HOST' => 'in-v3.mailjet.com',
        'MAIL_PORT' => '587',
        'MAIL_USERNAME' => 'apikey123',
        'MAIL_PASSWORD' => 'secretkey123',
        'MAIL_FROM_ADDRESS' => 'staging@example.com',
        'MAIL_FROM_NAME' => 'Redline',
        'REDLINE_GUARD_PROVIDER' => EnvironmentGuardProvider::class,
        'REDLINE_FEATURES' => '',
    ]);
});

it('installs resend/resend-php and writes the API key when Resend is chosen', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Resend (API) - requires verifying a sending domain (DNS)')
        ->expectsQuestion('Resend API key (re_… - create one at https://resend.com/api-keys)', 're_abc123')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->assertExitCode(0);

    expect($command->composerInstalls)->toContain('resend/resend-php');
    expect($command->envWrites)->toMatchArray([
        'MAIL_MAILER' => 'resend',
        'RESEND_API_KEY' => 're_abc123',
    ]);
});

it('warns that a sending domain must be DNS-verified before the user commits to Resend', function () {
    fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Resend (API) - requires verifying a sending domain (DNS)')
        ->expectsQuestion('Resend API key (re_… - create one at https://resend.com/api-keys)', 're_abc123')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('requires a sending domain verified via DNS')
        ->expectsOutputToContain('@resend.dev')
        ->assertExitCode(0);
});

it('prints the composer warning and continues when the package install fails', function () {
    $command = fakeInstallCommand(composerSucceeds: false);

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Resend (API) - requires verifying a sending domain (DNS)')
        ->expectsQuestion('Resend API key (re_… - create one at https://resend.com/api-keys)', 're_abc123')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Could not run composer automatically. Run this manually then restart this command:')
        ->expectsOutputToContain('composer require resend/resend-php')
        ->assertExitCode(0);

    expect($command->composerInstalls)->toContain('resend/resend-php');
});

it('adds the composer post-autoload-dump hook to composer.json', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Added the composer post-autoload-dump hook to composer.json')
        ->assertExitCode(0);

    $decoded = json_decode((string) file_get_contents($command->composerJsonPath), true);

    expect($decoded['scripts']['post-autoload-dump'])->toBe([ComposerScripts::AUTOLOAD_HOOK]);
});

it('reports the composer post-autoload-dump hook as already present', function () {
    $command = fakeInstallCommand();

    file_put_contents($command->composerJsonPath, (string) json_encode([
        'scripts' => ['post-autoload-dump' => [ComposerScripts::AUTOLOAD_HOOK]],
    ]));

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Composer post-autoload-dump hook already present in composer.json.')
        ->assertExitCode(0);
});

it('migrates a legacy composer post-package-update entry to post-autoload-dump', function () {
    $command = fakeInstallCommand();

    file_put_contents($command->composerJsonPath, (string) json_encode([
        'name' => 'acme/app',
        'scripts' => ['post-package-update' => ['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK]],
    ], JSON_PRETTY_PRINT));

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Migrated the composer hook to post-autoload-dump (was post-package-update).')
        ->assertExitCode(0);

    $decoded = json_decode((string) file_get_contents($command->composerJsonPath), true);

    expect($decoded['scripts']['post-autoload-dump'])->toBe([ComposerScripts::AUTOLOAD_HOOK]);
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
    expect($decoded['name'])->toBe('acme/app');
});

/**
 * --silent: no prompts, fixed defaults (OpenAuthProvider, DebugGuardProvider, no
 * features). callSilent() is faked, so no real migration or publish runs.
 */
it('installs non-interactively with --silent using the fixed defaults', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install', ['--silent' => true])
        ->doesntExpectOutputToContain('Redline installed successfully!')
        ->assertExitCode(0);

    expect($command->envWrites)->toBe([
        'REDLINE_AUTH_PROVIDER' => OpenAuthProvider::class,
        'REDLINE_GUARD_PROVIDER' => DebugGuardProvider::class,
        'REDLINE_FEATURES' => 'screenshots,videos,comments',
    ]);

    expect(collect($command->quietCalls)->pluck(0)->all())->toBe(['migrate', 'vendor:publish', 'vendor:publish']);
    expect($command->quietCalls[0][1])->toBe([
        '--path' => 'vendor/zipavlin/redline/database/redline',
        '--database' => 'redline',
        '--force' => true,
    ]);
    expect($command->quietCalls[1][1])->toBe(['--tag' => 'redline-config', '--force' => false]);
    expect($command->quietCalls[2][1])->toBe(['--tag' => 'redline-assets', '--force' => false]);
    expect(config('redline.auth.provider'))->toBe(OpenAuthProvider::class);
});

it('--silent skips email setup, first-user creation and composer installs', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install', ['--silent' => true])
        ->doesntExpectOutputToContain('Email')
        ->doesntExpectOutputToContain('First admin user')
        ->assertExitCode(0);

    expect($command->composerInstalls)->toBe([]);
    expect(array_keys($command->envWrites))->not->toContain('MAIL_MAILER');
});

it('--silent adds the composer hook and is idempotent on re-run', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install', ['--silent' => true])->assertExitCode(0);

    $first = (string) file_get_contents($command->composerJsonPath);
    expect(json_decode($first, true)['scripts']['post-autoload-dump'])->toBe([ComposerScripts::AUTOLOAD_HOOK]);

    $this->artisan('redline:install', ['--silent' => true])->assertExitCode(0);

    expect((string) file_get_contents($command->composerJsonPath))->toBe($first);
});

it('--silent returns FAILURE and writes nothing when migrations fail', function () {
    $command = fakeInstallCommand();
    $command->quietExitCodes['migrate'] = 1;

    $this->artisan('redline:install', ['--silent' => true])->assertExitCode(1);

    expect($command->envWrites)->toBe([]);
    expect(collect($command->quietCalls)->pluck(0)->all())->toBe(['migrate']);
});

it('--silent returns FAILURE when publishing fails', function () {
    $command = fakeInstallCommand();
    $command->quietExitCodes['vendor:publish'] = 1;

    $this->artisan('redline:install', ['--silent' => true])->assertExitCode(1);
});

it('rejects the removed --auth, --guard and --features options as unknown options', function (string $option) {
    fakeInstallCommand();

    // Symfony throws "The "--x" option does not exist."; the console kernel
    // renders it as a normal command failure (exit 1).
    expect(fn () => $this->artisan('redline:install', [$option => 'x', '--silent' => true])->run())
        ->toThrow(InvalidOptionException::class, 'does not exist');

    expect(fn () => $this->artisan('redline:install', [$option => 'x'])->run())
        ->toThrow(InvalidOptionException::class, 'does not exist');
})->with(['--auth', '--guard', '--features']);

/**
 * Regression: on a fresh host MigrateCommand asks "create the SQLite database?"
 * through Laravel Prompts. Under callSilent nothing renders, so the process
 * blocked on stdin forever (and under --no-interaction it threw). Redline owns
 * the dedicated file, so a silent install migrates with --force (touch, no
 * prompt); interactively the user can answer the prompt, so --force is off.
 */
it('migrates with --force under --silent so a fresh host never prompts to create the SQLite file', function (bool $silent) {
    $command = fakeInstallCommand();

    if ($silent) {
        $this->artisan('redline:install', ['--silent' => true])->assertExitCode(0);
    } else {
        $this->artisan('redline:install')
            ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
            ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
            ->expectsQuestion('Which features should you enable? (all off by default - separate multiple with commas, or press Enter for none)', '')
            ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
            ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
            ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
            ->expectsConfirmation('Would you like to create the first admin user?', 'no')
            ->assertExitCode(0);
    }

    expect($command->migrateCalls)->toHaveCount(1);
    expect($command->migrateCalls[0])->toMatchArray([
        '--path' => 'vendor/zipavlin/redline/database/redline',
        '--database' => 'redline',
        '--force' => $silent,
    ]);
})->with([
    'silent' => true,
    'interactive' => false,
]);
