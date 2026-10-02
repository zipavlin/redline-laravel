<?php

use Illuminate\Contracts\Console\Kernel;
use Zipavlin\Redline\Commands\InstallCommand;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Guard\EnvironmentGuardProvider;

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
});

/**
 * Bind a command instance that records .env writes and never spawns a real
 * composer process, keeping the install-flow tests deterministic and fast.
 *
 * @param  bool  $composerSucceeds  Whether the faked composer install reports success.
 */
function fakeInstallCommand(bool $composerSucceeds = true): InstallCommand
{
    $command = new class extends InstallCommand
    {
        public array $envWrites = [];

        public array $composerInstalls = [];

        public bool $composerSucceeds = true;

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
    };

    $command->composerSucceeds = $composerSucceeds;

    app()->instance(InstallCommand::class, $command);

    // The Artisan console app is built during app boot with real command
    // instances; rebuild it so the fake above is the one registered by name.
    app(Kernel::class)->setArtisan(null);

    return $command;
}

it('accepts an empty features answer ("disable none") and completes the install', function () {
    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
        ->expectsQuestion('How would you like to set up email?', 'Skip - I\'ll configure a mailer later')
        ->expectsConfirmation('Publish Redline config? This is not needed - you can set Redline in .env', 'no')
        ->expectsConfirmation('Publish Redline assets to public/vendor/redline/?', 'no')
        ->expectsConfirmation('Would you like to create the first admin user?', 'no')
        ->expectsOutputToContain('Redline installed successfully!')
        ->assertExitCode(0);
});

it('skips creating a user when LaravelAuthProvider is selected during install', function () {
    fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', LaravelAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
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
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
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
    ]);
});

it('installs resend/resend-php and writes the API key when Resend is chosen', function () {
    $command = fakeInstallCommand();

    $this->artisan('redline:install')
        ->expectsQuestion('Which auth provider would you like to use?', OpenAuthProvider::title())
        ->expectsQuestion('Which guard provider would you like to use?', EnvironmentGuardProvider::title())
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
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
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
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
        ->expectsQuestion('Which features should be disabled? (separate multiple with commas, or press Enter for none)', '')
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
