<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;
use Zipavlin\Redline\Providers\Auth\OpenAuthProvider;
use Zipavlin\Redline\Providers\Auth\PinAuthProvider;
use Zipavlin\Redline\Providers\Storage\CloudStorageProvider;
use Zipavlin\Redline\Providers\Storage\DatabaseStorageProvider;
use Zipavlin\Redline\Providers\Storage\FileStorageProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class InstallCommand extends Command
{
    public $signature = 'redline:install';

    public $description = 'Interactive setup for the Redline bug-reporting widget';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=red>●</> <options=bold>Redline</> — interactive setup');
        $this->line('');

        // ----------------------------------------------------------------
        // 1. Storage provider
        // ----------------------------------------------------------------
        $this->line('  <options=bold>Storage backend</>');

        $storageClass = $this->promptProvider(
            baseClass: StorageProvider::class,
            directory: __DIR__.'/../Providers/Storage',
            namespace: 'Zipavlin\\Redline\\Providers\\Storage',
            question: 'Which storage backend would you like to use?',
        );

        $this->handleStorageFollowUp($storageClass);

        $this->line('');

        // ----------------------------------------------------------------
        // 2. Auth provider
        // ----------------------------------------------------------------
        $this->line('  <options=bold>Auth provider</>');
        $this->line('  Controls how users register and which roles they can claim.');
        $this->line('');

        $authClass = $this->promptAuthProvider($storageClass);

        $this->line('');

        // ----------------------------------------------------------------
        // 2b. Email (mailer) — some actions need it
        // ----------------------------------------------------------------
        if ($storageClass !== CloudStorageProvider::class) {
            $this->handleEmailSetup($authClass);
        }

        // ----------------------------------------------------------------
        // 3. Features
        // ----------------------------------------------------------------
        $this->line('  <options=bold>Features</>');
        $this->line('  All features are enabled by default. Select any you want to disable.');
        $this->line('');

        $allFeatures = array_column(Feature::cases(), 'value');
        $toDisable = $this->choice(
            'Which features should be disabled? (separate multiple with commas, or press Enter for none)',
            $allFeatures,
            null,
            null,
            true,
        );
        $toDisable = array_filter($toDisable);

        $this->line('');

        // ----------------------------------------------------------------
        // 4. Write .env
        // ----------------------------------------------------------------
        $this->line('  <options=bold>Writing .env …</>');

        $this->writeEnvValue('REDLINE_STORAGE_PROVIDER', $storageClass);
        $this->line("  <fg=green>✓</> REDLINE_STORAGE_PROVIDER=$storageClass");

        if ($authClass !== OpenAuthProvider::class) {
            $this->writeEnvValue('REDLINE_AUTH_PROVIDER', $authClass);
            $this->line("  <fg=green>✓</> REDLINE_AUTH_PROVIDER=$authClass");
        }

        if (! empty($toDisable)) {
            $disabled = implode(',', $toDisable);
            $this->writeEnvValue('REDLINE_DISABLED', $disabled);
            $this->line("  <fg=green>✓</> REDLINE_DISABLED=$disabled");
        }

        $this->line('');

        // ----------------------------------------------------------------
        // 5. Publish config + assets
        // ----------------------------------------------------------------
        $configPath = config_path('redline.php');
        $configExists = file_exists($configPath);

        if (! $configExists || $this->confirm('config/redline.php already exists. Overwrite?')) {
            $this->call('vendor:publish', [
                '--tag' => 'redline-config',
                '--force' => $configExists,
            ]);
        }

        if ($this->confirm('Publish Redline assets to public/vendor/redline/?', true)) {
            $this->call('vendor:publish', ['--tag' => 'redline-assets', '--force' => false]);
        }

        $this->line('');

        // ----------------------------------------------------------------
        // 6. Run migrations / create directories
        // ----------------------------------------------------------------
        if ($storageClass === DatabaseStorageProvider::class) {
            $this->line('  <options=bold>Running database migrations …</>');

            $this->configureDatabaseConnection();

            $this->call('migrate', [
                '--path' => 'vendor/zipavlin/redline/database/redline',
                '--database' => 'redline',
            ]);

            $this->line('  <fg=green>✓</> Redline database tables created.');
        } elseif ($storageClass === FileStorageProvider::class) {
            $dirs = [
                storage_path('app/redline/reports'),
                storage_path('app/redline/screenshots'),
                storage_path('app/redline/videos'),
                storage_path('app/redline/comments'),
                storage_path('app/redline/users'),
            ];

            foreach ($dirs as $dir) {
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $this->line('  <fg=green>✓</> Redline storage directories created.');
        }

        $this->line('');

        // ----------------------------------------------------------------
        // 7. Create first admin user
        // ----------------------------------------------------------------
        if ($storageClass !== CloudStorageProvider::class) {
            $this->line('  <options=bold>First admin user</>');

            if ($this->confirm('Would you like to create the first admin user?', true)) {
                $this->bootStorageProvider($storageClass);
                $this->call('redline:user');
            }

            $this->line('');
        }

        $this->line('  <fg=green>✓</> Redline installed successfully!');
        $this->line('');
        $this->line('  Next steps:');
        $this->line('  1. Set APP_ENV=staging and APP_DEBUG=true on your staging server.');
        $this->line('  2. Deploy — the widget will appear automatically.');

        if ($storageClass !== CloudStorageProvider::class) {
            $this->line('  3. Visit <options=underscore>/redline/dashboard</> to review reports.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Generic provider discovery + prompt
    // -----------------------------------------------------------------------

    /**
     * Scan a directory for concrete classes that implement Listable,
     * present them as a choice list, and return the selected FQCN.
     *
     * @param  class-string  $baseClass  Abstract base to filter by
     * @return class-string
     */
    private function promptProvider(
        string $baseClass,
        string $directory,
        string $namespace,
        string $question,
    ): string {
        $options = $this->discoverProviders($baseClass, $directory, $namespace);

        $label = $this->choice($question, array_keys($options), 0);

        return $options[$label];
    }

    /**
     * Return [ title => FQCN ] for every concrete Listable class found in $directory.
     *
     * @param  class-string  $baseClass
     * @return array<string, class-string>
     */
    private function discoverProviders(string $baseClass, string $directory, string $namespace): array
    {
        $options = [];

        $found = [];

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $class = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if ($ref->isAbstract() || ! $ref->implementsInterface(Listable::class) || ! $ref->isSubclassOf($baseClass)) {
                continue;
            }

            $found[] = $class;
        }

        usort($found, fn ($a, $b) => $a::position() <=> $b::position());

        foreach ($found as $class) {
            $options[$class::title()] = $class;
        }

        return $options;
    }

    // -----------------------------------------------------------------------
    // Storage follow-up prompts
    // -----------------------------------------------------------------------

    private function handleStorageFollowUp(string $storageClass): void
    {
        if ($storageClass === FileStorageProvider::class
            && ! class_exists('RyanChandler\Orbit\Concerns\Orbital')
        ) {
            $this->line('  <fg=yellow>⚠</> ryangjchandler/orbit is not installed.');
            $this->line('  Run: <options=bold>composer require ryangjchandler/orbit</> after setup.');
        }

        if ($storageClass === CloudStorageProvider::class) {
            $cloudUrl = $this->ask('Remote API URL (e.g. https://api.redline.cloud)');

            if ($cloudUrl) {
                $this->writeEnvValue('REDLINE_CLOUD_URL', $cloudUrl);
                $this->line("  <fg=green>✓</> REDLINE_CLOUD_URL=$cloudUrl");
            }
        }
    }

    // -----------------------------------------------------------------------
    // Auth provider prompt
    // -----------------------------------------------------------------------

    private function promptAuthProvider(string $storageClass): string
    {
        if ($storageClass === CloudStorageProvider::class) {
            $this->line('  Cloud storage — auth is handled by the remote API.');

            return OpenAuthProvider::class;
        }

        $authClass = $this->promptProvider(
            baseClass: AuthProvider::class,
            directory: __DIR__.'/../Providers/Auth',
            namespace: 'Zipavlin\\Redline\\Providers\\Auth',
            question: 'Which auth provider would you like to use?',
        );

        $this->handleAuthFollowUp($authClass);

        return $authClass;
    }

    // -----------------------------------------------------------------------
    // Email setup — offer Resend when a mailer is needed but not configured
    // -----------------------------------------------------------------------

    private function handleEmailSetup(string $authClass): void
    {
        if (RedlineServiceProvider::mailConfigured()) {
            return; // a real mailer is already configured
        }

        $requiresEmail = (new $authClass)->requiresEmailVerification();

        $this->line('  <options=bold>Email</>');

        if ($requiresEmail) {
            $this->line('  <fg=yellow>⚠</> '.class_basename($authClass).' verifies new users by email, but no mailer is configured —');
            $this->line('  registration verification and password reset will not send until one is set up.');
        } else {
            $this->line('  No mailer is configured. Password reset and new-report notifications need one');
            $this->line('  (Redline degrades gracefully without it — those emails are simply skipped).');
        }

        if (! $this->confirm('Set up Resend now? (free transactional email — sign up at https://resend.com/signup)', $requiresEmail)) {
            $this->line('  Skipped. Configure any Laravel mailer later, or set REDLINE_MAIL=false to silence this.');
            $this->line('');

            return;
        }

        $this->line('  Installing <options=bold>resend/resend-php</> …');

        try {
            $process = new Process(['composer', 'require', 'resend/resend-php'], base_path());
            $process->setTimeout(300);
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));

            if (! $process->isSuccessful()) {
                throw new RuntimeException('composer require failed');
            }
        } catch (Throwable) {
            $this->line('  <fg=yellow>⚠</> Could not run composer automatically. Run this manually:');
            $this->line('     <options=bold>composer require resend/resend-php</>');
        }

        $this->writeEnvValue('MAIL_MAILER', 'resend');
        $this->line('  <fg=green>✓</> MAIL_MAILER=resend');

        $apiKey = $this->secret('Resend API key (re_… — create one at https://resend.com/api-keys)') ?? '';
        if ($apiKey !== '') {
            $this->writeEnvValue('RESEND_API_KEY', $apiKey);
            $this->line('  <fg=green>✓</> RESEND_API_KEY=<fg=yellow>***</>');
        }

        $this->line('  Verify a sending domain in Resend, and ensure config/services.php has a');
        $this->line("  'resend' => ['key' => env('RESEND_API_KEY')] entry (Laravel 11+ ships this).");
        $this->line('');
    }

    private function handleAuthFollowUp(string $authClass): void
    {
        if ($authClass === PinAuthProvider::class) {
            $reporterPin = $this->secret('Reporter PIN (leave blank to skip)') ?? '';
            if ($reporterPin !== '') {
                $this->writeEnvValue('REDLINE_AUTH_PIN_REPORTER', $reporterPin);
                $this->line('  <fg=green>✓</> REDLINE_AUTH_PIN_REPORTER=<fg=yellow>***</>');
            }

            $developerPin = $this->secret('Developer PIN (leave blank to skip)') ?? '';
            if ($developerPin !== '') {
                $this->writeEnvValue('REDLINE_AUTH_PIN_DEVELOPER', $developerPin);
                $this->line('  <fg=green>✓</> REDLINE_AUTH_PIN_DEVELOPER=<fg=yellow>***</>');
            }
        } elseif ($authClass === DomainAuthProvider::class) {
            $domains = $this->ask('Allowed email domains, comma-separated (e.g. example.com,acme.org)');
            if ($domains) {
                $this->writeEnvValue('REDLINE_AUTH_DOMAINS', $domains);
                $this->line("  <fg=green>✓</> REDLINE_AUTH_DOMAINS=$domains");
            }
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function bootStorageProvider(string $storageClass): void
    {
        /** @var StorageProvider $provider */
        $provider = new $storageClass;
        $provider->boot();
    }

    private function configureDatabaseConnection(): void
    {
        if (config('database.connections.redline') !== null) {
            return;
        }

        $dbDir = storage_path(implode(DIRECTORY_SEPARATOR, [
            'app',
            config('redline.storage.path.base', 'redline'),
            config('redline.storage.path.database', 'database'),
        ]));

        if (! is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        config(['database.connections.redline' => [
            'driver' => 'sqlite',
            'database' => $dbDir.DIRECTORY_SEPARATOR.'redline.db',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Write or update a key=value pair in the host application's .env file.
     */
    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn("  No .env file found at: $envPath — skipping.");

            return;
        }

        $formatted = preg_match('/[\s#]/', $value)
            ? '"'.str_replace('"', '\\"', $value).'"'
            : $value;

        $content = file_get_contents($envPath);
        $escaped = preg_quote($key, '/');
        $newLine = "$key=$formatted";

        if (preg_match("/^$escaped=/m", $content)) {
            $content = preg_replace("/^$escaped=.*/m", $newLine, $content);
        } else {
            $content = rtrim($content)."\n$newLine\n";
        }

        file_put_contents($envPath, $content);
    }
}
