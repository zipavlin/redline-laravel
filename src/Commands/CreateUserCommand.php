<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class CreateUserCommand extends Command
{
    public $signature = 'redline:user';

    public $description = 'Create a Redline user directly (bypasses auth provider policies)';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=red>●</> <options=bold>Redline</> - create user');
        $this->line('');

        /** @var class-string<StorageProvider> $storageClass */
        $storageClass = config('redline.storage.provider');

        try {
            (new $storageClass)->boot();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $name = $this->ask('Name');

        if (! $name) {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $email = $this->ask('Email');

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        if (app(User::class)->where('email', $email)->exists()) {
            $this->error("A user with the email $email already exists.");

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 6 characters)');
        if (! $password || strlen($password) < 6) {
            $this->error('Password must be at least 6 characters.');

            return self::FAILURE;
        }

        $role = Role::from($this->choice('Role', ['reporter', 'developer', 'admin'], 2));

        app(User::class)->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
        ]);

        $this->line('');
        $this->line("  <fg=green>✓</> User <options=bold>$name</> ($email) created with role <options=bold>$role->value</>.");
        $this->line('');

        return self::SUCCESS;
    }
}
