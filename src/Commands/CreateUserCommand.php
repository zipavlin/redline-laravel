<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use LogicException;
use RuntimeException;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class CreateUserCommand extends Command
{
    public $signature = 'redline:user {--promote : Promote existing users to admin} {--edit : Edit an existing user}';

    public $description = 'Create or manage a Redline user directly (bypasses auth provider policies)';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=red>●</> <options=bold>Redline</> - user management');
        $this->line('');

        if ($this->option('promote') && $this->option('edit')) {
            $this->error('Use either --promote or --edit, not both.');

            return self::FAILURE;
        }

        if (! $this->bootStorage()) {
            return self::FAILURE;
        }

        if ($this->option('promote')) {
            return $this->promoteUsers();
        }

        if ($this->option('edit')) {
            return $this->editUser();
        }

        return $this->createUser();
    }

    private function bootStorage(): bool
    {
        try {
            (new StorageProvider)->boot();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }

    // Create

    private function createUser(): int
    {
        if ($this->authProviderIsLaravel()) {
            return $this->blockCreateUnderLaravel();
        }

        $name = $this->promptNewName();
        if ($name === null) {
            return self::FAILURE;
        }

        $email = $this->promptNewEmail(null);
        if ($email === null) {
            return self::FAILURE;
        }

        $password = $this->promptNewPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $role = Role::from($this->choice('Role', self::roles(), 2));

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

    private function blockCreateUnderLaravel(): int
    {
        $this->error('Users are provisioned automatically on first host-application login under LaravelAuthProvider.');
        $this->line('  Manage users in the host application instead of creating them here.');
        $this->line('  Use --edit or --promote to adjust an existing Redline user.');

        return self::FAILURE;
    }

    private function promptNewName(): ?string
    {
        $name = trim((string) $this->ask('Name'));

        if ($name === '') {
            $this->error('Name is required.');

            return null;
        }

        return $name;
    }

    /**
     * Prompt for an email address.
     *
     * When editing ($except is the user being edited) the current address is the
     * default and an empty answer keeps it; in create mode ($except null) an
     * address is required. Either way it must be valid and unused.
     *
     * @param  User|null  $except  User whose own email must not count as a duplicate (edit mode).
     */
    private function promptNewEmail(?User $except): ?string
    {
        $email = trim((string) ($this->ask('Email', $except?->email) ?: $except?->email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return null;
        }

        if ($this->emailTakenByOther($email, $except)) {
            $this->error("A user with the email $email already exists.");

            return null;
        }

        return $email;
    }

    private function promptNewPassword(): ?string
    {
        $password = $this->secret('Password (min 6 characters)');

        if (! $password || strlen($password) < 6) {
            $this->error('Password must be at least 6 characters.');

            return null;
        }

        return $password;
    }

    private function emailTakenByOther(string $email, ?User $except): bool
    {
        // Case-insensitive: LaravelAuthProvider joins host sessions on a
        // lowercased email, so a case-variant row would be an ambiguous identity.
        $query = app(User::class)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);

        if ($except !== null) {
            $query->where('id', '!=', $except->id);
        }

        return $query->exists();
    }

    /**
     * The service provider only binds AuthProvider outside the console, so the
     * configured provider is resolved from config (an FQCN string) directly.
     */
    private function authProviderIsLaravel(): bool
    {
        return is_a(config('redline.auth.provider'), LaravelAuthProvider::class, true);
    }

    // Promote

    private function promoteUsers(): int
    {
        $choices = $this->promotionChoices();

        if ($choices === []) {
            $this->line('');
            $this->line('  <fg=yellow>●</> No non-admin users to promote.');
            $this->line('');

            return self::SUCCESS;
        }

        $selected = $this->askPromotionSelection(array_keys($choices));

        if ($selected === []) {
            $this->line('');
            $this->line('  <fg=yellow>●</> No users selected; nothing promoted.');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->applyPromotions($choices, $selected);
    }

    /**
     * @return array<string, User> label => user for every non-admin, non-guest user
     */
    private function promotionChoices(): array
    {
        $promotable = $this->manageableUsers()
            ->reject(fn (User $user) => $user->role === Role::ADMIN);

        return $this->userChoices($promotable);
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    private function askPromotionSelection(array $labels): array
    {
        $selected = $this->choice(
            'Select users to promote to admin (comma-separated)',
            $labels,
            null,
            null,
            true,
        );

        return array_values(array_filter((array) $selected, fn ($label) => in_array($label, $labels, true)));
    }

    /**
     * @param  array<string, User>  $choices
     * @param  array<int, string>  $selected
     */
    private function applyPromotions(array $choices, array $selected): int
    {
        $this->line('');

        foreach ($selected as $label) {
            $user = $choices[$label];
            $user->role = Role::ADMIN;
            $user->save();

            $this->line("  <fg=green>✓</> Promoted <options=bold>{$user->name}</> ({$user->email}) to admin.");
        }

        $this->line('');

        return self::SUCCESS;
    }

    // Edit

    private function editUser(): int
    {
        $users = $this->manageableUsers();

        if ($users->isEmpty()) {
            $this->line('');
            $this->line('  <fg=yellow>●</> No users to edit.');
            $this->line('');

            return self::SUCCESS;
        }

        $user = $this->pickUser($users);

        if ($user === null) {
            $this->line('');
            $this->line('  <fg=yellow>●</> No user selected; nothing changed.');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->applyEdits($user);
    }

    private function applyEdits(User $user): int
    {
        $edits = $this->promptEdits($user);

        if ($edits === null) {
            return self::FAILURE;
        }

        return $this->persistEdits($user, $edits);
    }

    /**
     * Prompt for every editable attribute; null when any answer is invalid.
     *
     * @return array{name: string, email: string, role: Role, password: ?string}|null
     */
    private function promptEdits(User $user): ?array
    {
        $name = $this->promptEditName($user->name);
        if ($name === null) {
            return null;
        }

        $email = $this->promptNewEmail($user);
        if ($email === null) {
            return null;
        }

        $password = $this->promptEditPassword();
        if ($password === null) {
            return null;
        }

        return [
            'name' => $name,
            'email' => $email,
            'role' => Role::from($this->choice('Role', self::roles(), $this->roleIndex($user->role))),
            'password' => $password === '' ? null : $password,
        ];
    }

    /**
     * A blank answer keeps the current name; null means the edit must fail.
     */
    private function promptEditName(string $current): ?string
    {
        $name = trim((string) ($this->ask('Name', $current) ?: $current));

        if ($name === '') {
            $this->error('Name is required.');

            return null;
        }

        return $name;
    }

    /**
     * A blank answer keeps the current password (returned as ''); null means the
     * answer was too short and the edit must fail.
     */
    private function promptEditPassword(): ?string
    {
        $password = $this->secret('Password (leave blank to keep current)') ?? '';

        if ($password === '' || strlen($password) >= 6) {
            return $password;
        }

        $this->error('Password must be at least 6 characters.');

        return null;
    }

    /**
     * @param  array<string, mixed>  $edits  New attribute values; 'password' is raw text.
     */
    private function persistEdits(User $user, array $edits): int
    {
        $this->warnIfLaravelEmailChanged($user, (string) ($edits['email'] ?? $user->email));

        $this->applyEditValues($user, $edits);

        $changed = array_keys($user->getDirty());

        if ($changed === []) {
            $this->line('');
            $this->line('  <fg=yellow>●</> No changes made.');
            $this->line('');

            return self::SUCCESS;
        }

        $user->save();

        // Only a password change invalidates JWTs: request authz re-reads the
        // user (role included) from the DB, so name/email/role edits need no bump.
        if (in_array('password', $changed, true)) {
            $user->bumpTokenVersion();
        }

        $this->printEditSummary($changed);

        return self::SUCCESS;
    }

    /**
     * Under LaravelAuthProvider the email is the host-session join key, so a
     * real change (beyond casing) orphans this account: the next host login for
     * the new address provisions a fresh reporter instead.
     */
    private function warnIfLaravelEmailChanged(User $user, string $email): void
    {
        if (! $this->authProviderIsLaravel() || mb_strtolower($email) === mb_strtolower((string) $user->email)) {
            return;
        }

        $this->line('  <fg=yellow>⚠</> Host login joins by email - the next host login for this address will provision a NEW reporter instead of this account.');
    }

    /**
     * Assign the edited attributes onto the model (password hashed when given).
     *
     * @param  array<string, mixed>  $edits
     */
    private function applyEditValues(User $user, array $edits): void
    {
        $password = $edits['password'] ?? null;
        unset($edits['password']);

        foreach ($edits as $attribute => $value) {
            $user->{$attribute} = $value;
        }

        if ($password !== null && $password !== '') {
            $user->password = Hash::make($password);
        }
    }

    /**
     * @param  array<int, string>  $changed
     */
    private function printEditSummary(array $changed): void
    {
        $this->line('');

        foreach ($changed as $attribute) {
            if ($attribute === 'password') {
                continue;
            }

            $this->line("  <fg=green>✓</> Updated <options=bold>$attribute</>.");
        }

        if (in_array('password', $changed, true)) {
            $this->line('  <fg=green>✓</> Password updated.');
            $this->line('  <fg=yellow>●</> Existing sessions invalidated (all tokens revoked).');
        }

        $this->line('');
    }

    private function roleIndex(Role $role): int
    {
        $index = array_search($role->value, self::roles(), true);

        if ($index === false) {
            throw new LogicException("Role {$role->value} cannot be selected here.");
        }

        return $index;
    }

    // Shared

    /**
     * Users that --promote/--edit may manage. The shared GUEST identity is
     * created and owned by the API on demand, so it is never listed here.
     *
     * @return Collection<int, User>
     */
    private function manageableUsers(): Collection
    {
        return app(User::class)
            ->where('role', '!=', Role::GUEST->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function pickUser(Collection $users): ?User
    {
        $choices = $this->userChoices($users);

        $selected = $this->choice('Select a user to edit', array_keys($choices));

        return $choices[$selected] ?? null;
    }

    /**
     * Build the label => user map shared by the promote and edit choice lists.
     *
     * @param  Collection<int, User>  $users
     * @return array<string, User>
     */
    private function userChoices(Collection $users): array
    {
        $choices = [];

        foreach ($users as $user) {
            $choices[$this->userLabel($user)] = $user;
        }

        return $choices;
    }

    private function userLabel(User $user): string
    {
        return "{$user->name} ({$user->email}) - {$user->role->value}";
    }

    /**
     * Assignable roles: every Role except the shared GUEST identity. Derived
     * from the enum so a future role can never be silently assignable here.
     *
     * @return array<int, string>
     */
    private static function roles(): array
    {
        $assignable = array_filter(Role::cases(), fn (Role $role) => $role !== Role::GUEST);

        return array_values(array_map(fn (Role $role) => $role->value, $assignable));
    }
}
