<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionClass;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Zipavlin\Redline\Contracts\Listable;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\Providers\Auth\DomainAuthProvider;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Auth\PinAuthProvider;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Guard\IpGuardProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class InstallCommand extends Command
{
    public $signature = 'redline:install';

    public $description = 'Interactive setup for Redline';

    /**
     * @throws BindingResolutionException
     */
    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=red>●</> <options=bold>Redline</> - interactive setup');
        $this->line('');

        // Providers and features
        $this->handleStorageProvider();
        $auth = $this->handleAuthProvider();
        $this->handleGuardProvider();
        $this->handleFeatures();

        // Support
        $this->handleEmailSetup($auth);
        $this->handleAssetsAndConfig();
        $this->handleFirstUser();

        return self::SUCCESS;
    }

    // Handlers

    /**
     * Sets up the package's single local storage backend.
     *
     * The schema is always created - there is no provider choice to make.
     */
    public function handleStorageProvider(): void
    {
        // Intro
        $this->line('  <options=bold>Storage provider</>');
        $this->line('  Controls how data is saved.');

        // There is one backend, so its schema is always created.
        $this->line('  <options=bold>Running database migrations …</>');

        $this->configureDatabaseConnection();

        $this->call('migrate', [
            '--path' => 'vendor/zipavlin/redline/database/redline',
            '--database' => 'redline',
        ]);

        $this->line('  <fg=green>✓</> Redline database tables created.');

        $this->line('');
    }

    /**
     * Handles the selection of an authentication provider by prompting the user and displaying relevant information.
     */
    public function handleAuthProvider(): string
    {
        // Intro
        $this->line('  <options=bold>Auth provider</>');
        $this->line('  Controls how users register and which roles they can claim.');

        // Prompt user to select auth provider
        $auth = $this->promptProvider(
            baseClass: AuthProvider::class,
            directory: __DIR__.'/../Providers/Auth',
            namespace: 'Zipavlin\\Redline\\Providers\\Auth',
            question: 'Which auth provider would you like to use?',
        );

        // Reflect the choice in runtime config immediately: the rest of this
        // process - handleFirstUser(), and any nested redline:user call - must
        // see the same provider the .env will provide on the next boot.
        config(['redline.auth.provider' => $auth]);

        // Write env
        $this->writeEnvValue('REDLINE_AUTH_PROVIDER', $auth);
        $this->line("  <fg=green>✓</> REDLINE_AUTH_PROVIDER=$auth");

        // Prompt user to configure auth provider if necessary
        if ($auth === PinAuthProvider::class) {
            $reporter_pin = $this->ask('Reporter PIN (leave blank to skip)') ?? '';
            if ($reporter_pin !== '') {
                $this->writeEnvValue('REDLINE_AUTH_PIN_REPORTER', $reporter_pin);
                $this->line('  <fg=green>✓</> REDLINE_AUTH_PIN_REPORTER=<fg=yellow>***</>');
            }

            $developer_pin = $this->ask('Developer PIN (leave blank to skip; different than reporter pin)') ?? '';
            if ($developer_pin === $reporter_pin) {
                $this->error('Developer PIN cannot be the same as the reporter PIN.');
                $developer_pin = $this->ask('Developer PIN') ?? '';
            }
            if ($developer_pin !== '') {
                $this->writeEnvValue('REDLINE_AUTH_PIN_DEVELOPER', $developer_pin);
                $this->line('  <fg=green>✓</> REDLINE_AUTH_PIN_DEVELOPER=<fg=yellow>***</>');
            }
        } elseif ($auth === DomainAuthProvider::class) {
            $domains = $this->ask('Allowed email domains, comma-separated (e.g. example.com,acme.org)');
            if ($domains) {
                $this->writeEnvValue('REDLINE_AUTH_DOMAINS', $domains);
                $this->line("  <fg=green>✓</> REDLINE_AUTH_DOMAINS=$domains");
            }
        }

        $this->line('');

        return $auth;
    }

    /**
     *  Handles the selection of an guard provider by prompting the user and displaying relevant information.
     */
    public function handleGuardProvider(): string
    {
        // Intro
        $this->line('  <options=bold>Guard provider</>');
        $this->line('  Controls when Redline (widget and dashboard) is loaded.');

        // Prompt user to select guard provider
        $guard = $this->promptProvider(
            baseClass: GuardProvider::class,
            directory: __DIR__.'/../Providers/Guard',
            namespace: 'Zipavlin\\Redline\\Providers\\Guard',
            question: 'Which guard provider would you like to use?',
        );

        // Write env
        $this->writeEnvValue('REDLINE_GUARD_PROVIDER', $guard);
        $this->line("  <fg=green>✓</> REDLINE_GUARD_PROVIDER=$guard");

        // Prompt user to configure guard provider if necessary
        if ($guard === IpGuardProvider::class) {
            $ips = $this->ask('Allowed IPs, comma-separated (e.g. 127.0.0.1)');
            if ($ips) {
                $this->writeEnvValue('REDLINE_GUARD_ALLOWED_IPS', $ips);
                $this->line("  <fg=green>✓</> REDLINE_GUARD_ALLOWED_IPS=$ips");
            }
        }

        $this->line('');

        return $guard;
    }

    /**
     * Handles the selection of features to disable.
     *
     * Presents the user with a list of all available features and allows them to select
     * which features should be disabled. An empty selection means that no features are disabled.
     * Ensures that only valid features can be selected for disabling.
     *
     * @return array<string> A list of feature values that the user has selected to disable.
     */
    public function handleFeatures(): array
    {
        // Intro
        $this->line('  <options=bold>Features</>');
        $this->line('  All features are enabled by default. Select any you want to disable.');

        // Collect features
        $all_features = array_column(Feature::cases(), 'value');

        // Prompt user to select features to disable
        $question = new ChoiceQuestion(
            'Which features should be disabled? (separate multiple with commas, or press Enter for none)',
            $all_features,
        );
        $question->setMultiselect(true);

        // Symfony's default ChoiceQuestion validator rejects an empty answer (it
        // regexes the raw string), so replace it: an empty answer means "disable
        // none", and the parsed list is returned (Symfony 7.3+ uses the validator's
        // return value as the answer; older versions treat non-string returns as
        // valid, so this works across both).
        $question->setValidator(function ($selected) use ($all_features) {
            $selected = trim((string) $selected);

            if ($selected === '') {
                return [];
            }

            $values = array_map('trim', explode(',', $selected));

            foreach ($values as $value) {
                if (! in_array($value, $all_features, true)) {
                    throw new InvalidArgumentException("Invalid selection: $value");
                }
            }

            return $values;
        });

        $to_disable = $this->output->askQuestion($question);
        $to_disable = array_filter((array) $to_disable);

        // Write env
        if (! empty($to_disable)) {
            $features = implode(',', $to_disable);
            $this->writeEnvValue('REDLINE_DISABLED', $features);
            $this->line("  <fg=green>✓</> REDLINE_DISABLED=$features");
        }

        $this->line('');

        return $to_disable;
    }

    /**
     * Handles the interactive email configuration setup.
     *
     * Checks if a mailer is already configured and prompts the user to choose
     * between Resend (which needs a DNS-verified sending domain), a no-DNS SMTP
     * provider (e.g. Mailjet), or skipping the setup. Installs the required
     * package, writes the necessary environment variables, and provides guidance
     * for completing the configuration.
     */
    private function handleEmailSetup(string $authClass): void
    {
        if (RedlineServiceProvider::mailConfigured()) {
            return; // a real mailer is already configured
        }

        $requiresEmail = (new $authClass)->requiresEmailVerification();

        $this->line('  <options=bold>Email</>');

        if ($requiresEmail) {
            $this->line('  <fg=yellow>⚠</> '.class_basename($authClass).' verifies new users by email, but no mailer is configured -');
            $this->line('  registration verification and password reset will not send until one is set up.');
        } else {
            $this->line('  No mailer is configured. Password reset and new-report notifications need one');
            $this->line('  (Redline degrades gracefully without it - those emails are simply skipped).');
        }

        $options = [
            'Resend (API) - requires verifying a sending domain (DNS)' => 'resend',
            'SMTP without DNS (recommended for staging - e.g. Mailjet)' => 'smtp',
            'Skip - I\'ll configure a mailer later' => 'skip',
        ];

        $selection = $options[$this->choice(
            'How would you like to set up email?',
            array_keys($options),
            $requiresEmail ? 0 : 2,
        )];

        $this->line('');

        if ($selection === 'resend') {
            $this->setupResendMailer();
        } elseif ($selection === 'smtp') {
            $this->setupSmtpMailer();
        } else {
            $this->line('  Skipped. Configure any Laravel mailer later, or set REDLINE_MAIL=false to silence this.');
            $this->line('');
        }
    }

    /**
     * Configures the Resend mailer: installs resend/resend-php and stores the API key.
     */
    private function setupResendMailer(): void
    {
        $this->line('  <fg=yellow>⚠</> Resend requires a sending domain verified via DNS in the Resend dashboard');
        $this->line('  before mail reaches real recipients - without one, only the account\'s own signup');
        $this->line('  email works (the @resend.dev testing domain rejects all other recipients).');
        $this->line('');

        $this->line('  Installing <options=bold>resend/resend-php</> …');
        $this->composerInstall('resend/resend-php');

        $this->writeEnvValue('MAIL_MAILER', 'resend');
        $this->line('  <fg=green>✓</> MAIL_MAILER=resend');

        $apiKey = $this->ask('Resend API key (re_… - create one at https://resend.com/api-keys)') ?? '';
        if ($apiKey !== '') {
            $this->writeEnvValue('RESEND_API_KEY', $apiKey);
            $this->line('  <fg=green>✓</> RESEND_API_KEY=<fg=yellow>***</>');
        }

        $this->line('  Ensure config/services.php has a');
        $this->line("  'resend' => ['key' => env('RESEND_API_KEY')] entry (Laravel 11+ ships this).");
        $this->line('');
    }

    /**
     * Configures an SMTP mailer (Mailjet by default) that needs no DNS setup.
     */
    private function setupSmtpMailer(): void
    {
        $this->line('  <fg=green>✓</> Recommended: <options=bold>Mailjet</> - 6,000 free emails/mo (200/day), no credit card.');
        $this->line('  Sender verification is a link clicked in the from-address inbox - no DNS records.');
        $this->line('  SMTP: in-v3.mailjet.com, port 587 (STARTTLS) or 465 (SSL); username = API key, password = Secret key.');
        $this->line('  Recipients see "via mailjet.net" - fine for internal staging notifications.');
        $this->line('  Avoid a Gmail/Yahoo from-address (spam risk).');
        $this->line('');

        $host = $this->ask('SMTP host', 'in-v3.mailjet.com') ?? '';
        $port = $this->ask('SMTP port', '587') ?? '';
        $username = $this->ask('SMTP username (Mailjet API key)') ?? '';
        $password = $this->secret('SMTP password (Mailjet Secret key)') ?? '';
        $fromAddress = $this->ask('From address (the inbox that receives the sender-verification link)') ?? '';
        $fromName = $this->ask('From name', 'Redline') ?? '';

        $this->writeEnvValue('MAIL_MAILER', 'smtp');
        $this->writeEnvValue('MAIL_HOST', $host);
        $this->writeEnvValue('MAIL_PORT', $port);
        $this->writeEnvValue('MAIL_USERNAME', $username);
        $this->writeEnvValue('MAIL_PASSWORD', $password);
        $this->writeEnvValue('MAIL_FROM_ADDRESS', $fromAddress);
        $this->writeEnvValue('MAIL_FROM_NAME', $fromName);
        $this->line('  <fg=green>✓</> SMTP mailer configured - MAIL_MAILER=smtp, MAIL_HOST='.$host.', MAIL_PORT='.$port.'.');

        $this->line('  Set MAIL_FROM_ADDRESS to the email address you used to open the Mailjet account - that');
        $this->line('  address is validated automatically.');
        $this->line('  To send from any other address add it as a sender in the Mailjet.');
        $this->line('  Alternatives: Brevo (300/day free, SMTP relay needs a one-time activation ticket); Postmark (100/mo free, sender must be a private-domain mailbox); Mailtrap Email Sandbox (testing only - emails are never delivered).');
        $this->line("  If a send ever hangs the request (browser shows 'network error', laravel.log stays empty), give the smtp");
        $this->line("  mailer a timeout: set 'timeout' => 10 in config/mail.php (Laravel does not read a MAIL_TIMEOUT env var).");
        $this->line('');
    }

    /**
     * Writes or updates a key-value pair in the .env file. If the key already exists, its value is updated.
     * If the key does not exist, it appends the new key-value pair at the end of the file.
     *
     * @param  string  $key  The environment variable key to write or update.
     * @param  string  $value  The value to set for the specified key. Special characters are properly escaped.
     */
    protected function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->warn("  No .env file found at: $envPath - skipping.");

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

    /**
     * Handles the publication of configuration and assets for the Redline package.
     *
     * Prompts the user to confirm publishing of the Redline configuration file and assets.
     * - If the user chooses to publish the configuration file (`redline.php`), it is saved
     *   in the configuration directory unless it already exists and the user declines to overwrite it.
     * - If the user agrees, Redline assets are published to the `public/vendor/redline/` directory.
     */
    private function handleAssetsAndConfig(): void
    {
        if ($this->confirm('Publish Redline config? This is not needed - you can set Redline in .env')) {
            $configPath = config_path('redline.php');
            $configExists = file_exists($configPath);
            if (! $configExists || $this->confirm('config/redline.php already exists. Overwrite?')) {
                $this->call('vendor:publish', [
                    '--tag' => 'redline-config',
                    '--force' => $configExists,
                ]);
            }
        }

        if ($this->confirm('Publish Redline assets to public/vendor/redline/?', true)) {
            $this->call('vendor:publish', ['--tag' => 'redline-assets', '--force' => false]);
        }

        $this->line('');
    }

    /**
     * Handles the creation of the first admin user and outputs information to the console.
     */
    private function handleFirstUser(): void
    {
        $this->line('  <options=bold>First admin user</>');

        // Under LaravelAuthProvider there is nothing to create: redline users are
        // provisioned from host-application logins, so the create flow is blocked.
        if (is_a(config('redline.auth.provider'), LaravelAuthProvider::class, true)) {
            $this->line('  Redline users are provisioned automatically on first host-application login;');
            $this->line('  manage them in your host application.');
        } elseif ($this->confirm('Would you like to create the first admin user?', true)) {
            $this->call('redline:user');
        }

        $this->line('');

        $this->line('  <fg=green>✓</> Redline installed successfully!');
        $this->line('');
        $this->line('  Next steps:');
        $this->line('  1. Set APP_ENV=staging and APP_DEBUG=true on your staging server.');
        $this->line('  2. Deploy - the widget will appear automatically.');

        $this->line('  3. Visit <options=underscore>/redline/dashboard</> to review reports.');

        $this->line('');
    }

    // Helpers
    /**
     * Scan a directory for concrete classes that implement Listable,
     * present them as a choice list, and return the selected FQCN.
     *
     * @param  class-string  $baseClass  Abstract base to filter by
     * @return class-string
     */
    private function promptProvider(string $baseClass, string $directory, string $namespace, string $question): string
    {
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

    /**
     * Configures a database connection
     */
    private function configureDatabaseConnection(): void
    {
        if (config('database.connections.redline') !== null) {
            return;
        }

        $dir = storage_path(implode(DIRECTORY_SEPARATOR, [
            'app',
            config('redline.storage.path.base', 'redline'),
            config('redline.storage.path.database', 'database'),
        ]));

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        config(['database.connections.redline' => [
            'driver' => 'sqlite',
            'database' => $dir.DIRECTORY_SEPARATOR.'redline.db',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Executes the `composer require` command to install the specified package.
     *
     * @param  string  $package  The name of the package to be installed via Composer.
     * @return bool Whether the package was installed automatically.
     *
     * @throws BindingResolutionException
     */
    protected function composerInstall(string $package): bool
    {
        $composer = RedlineServiceProvider::composer();
        if ($composer->requirePackages([$package])) {
            return true;
        }
        $this->line('  <fg=yellow>⚠</> Could not run composer automatically. Run this manually then restart this command:');
        $this->line("     <options=bold>composer require $package</>");

        return false;
    }
}
