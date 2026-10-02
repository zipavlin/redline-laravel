<?php

use Illuminate\Support\Facades\Hash;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Auth\LaravelAuthProvider;
use Zipavlin\Redline\Tests\Support\LaravelAuthProviderSubclass;

it('creates a user with a hashed password and role', function () {
    $this->artisan('redline:user')
        ->expectsQuestion('Name', 'Zig')
        ->expectsQuestion('Email', 'zig@example.com')
        ->expectsQuestion('Password (min 6 characters)', 'secret123')
        ->expectsQuestion('Role', 'admin')
        ->expectsOutputToContain('User Zig (zig@example.com) created')
        ->assertExitCode(0);

    $user = app(User::class)->where('email', 'zig@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secret123', $user->password))->toBeTrue()
        ->and($user->role)->toBe(Role::ADMIN);
});

it('refuses to create a user under LaravelAuthProvider', function () {
    config(['redline.auth.provider' => LaravelAuthProvider::class]);

    $this->artisan('redline:user')
        ->expectsOutputToContain('provisioned automatically on first host-application login')
        ->assertExitCode(1);

    expect(app(User::class)->count())->toBe(0);
});

it('lets --edit and --promote run under LaravelAuthProvider', function () {
    config(['redline.auth.provider' => LaravelAuthProvider::class]);

    app(User::class)->create([
        'name' => 'Existing',
        'email' => 'existing@example.com',
        'role' => Role::REPORTER,
    ]);

    $label = 'Existing (existing@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Existing')
        ->expectsQuestion('Email', 'existing@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'developer')
        ->assertExitCode(0);

    expect(app(User::class)->where('email', 'existing@example.com')->first()->role)->toBe(Role::DEVELOPER);
});

it('promotes the selected non-admin users to admin', function () {
    $anna = app(User::class)->create(['name' => 'Anna', 'email' => 'anna@example.com', 'role' => Role::REPORTER]);
    $bo = app(User::class)->create(['name' => 'Bo', 'email' => 'bo@example.com', 'role' => Role::DEVELOPER]);
    $cara = app(User::class)->create(['name' => 'Cara', 'email' => 'cara@example.com', 'role' => Role::ADMIN]);

    $labels = [
        'Anna (anna@example.com) - reporter',
        'Bo (bo@example.com) - developer',
    ];

    $this->artisan('redline:user', ['--promote' => true])
        ->expectsChoice('Select users to promote to admin (comma-separated)', $labels, $labels)
        ->expectsOutputToContain('Promoted Anna (anna@example.com) to admin')
        ->expectsOutputToContain('Promoted Bo (bo@example.com) to admin')
        ->assertExitCode(0);

    expect($anna->fresh()->role)->toBe(Role::ADMIN)
        ->and($bo->fresh()->role)->toBe(Role::ADMIN)
        ->and($cara->fresh()->role)->toBe(Role::ADMIN);
});

it('reports when there are no non-admin users to promote', function () {
    app(User::class)->create(['name' => 'Cara', 'email' => 'cara@example.com', 'role' => Role::ADMIN]);

    $this->artisan('redline:user', ['--promote' => true])
        ->expectsOutputToContain('No non-admin users to promote')
        ->assertExitCode(0);
});

it('promotes only the selected users', function () {
    $anna = app(User::class)->create(['name' => 'Anna', 'email' => 'anna@example.com', 'role' => Role::REPORTER]);
    $bo = app(User::class)->create(['name' => 'Bo', 'email' => 'bo@example.com', 'role' => Role::DEVELOPER]);

    $selected = ['Anna (anna@example.com) - reporter'];

    $this->artisan('redline:user', ['--promote' => true])
        ->expectsChoice('Select users to promote to admin (comma-separated)', $selected, [
            'Anna (anna@example.com) - reporter',
            'Bo (bo@example.com) - developer',
        ])
        ->assertExitCode(0);

    expect($anna->fresh()->role)->toBe(Role::ADMIN)
        ->and($bo->fresh()->role)->toBe(Role::DEVELOPER);
});

it('edits a user name, email and role', function () {
    $user = app(User::class)->create(['name' => 'Old Name', 'email' => 'old@example.com', 'role' => Role::REPORTER]);
    $label = 'Old Name (old@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'New Name')
        ->expectsQuestion('Email', 'new@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'developer')
        ->expectsOutputToContain('Updated name')
        ->expectsOutputToContain('Updated email')
        ->expectsOutputToContain('Updated role')
        ->assertExitCode(0);

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('New Name')
        ->and($fresh->email)->toBe('new@example.com')
        ->and($fresh->role)->toBe(Role::DEVELOPER);
});

it('keeps the current email when the email answer is left blank', function () {
    $user = app(User::class)->create(['name' => 'Target', 'email' => 'target@example.com', 'role' => Role::REPORTER]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', '')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'developer')
        ->expectsOutputToContain('Updated role')
        ->assertExitCode(0);

    $fresh = $user->fresh();

    expect($fresh->email)->toBe('target@example.com')
        ->and($fresh->role)->toBe(Role::DEVELOPER);
});

it('rejects an edit email that belongs to another user', function () {
    app(User::class)->create(['name' => 'Other', 'email' => 'other@example.com', 'role' => Role::REPORTER]);
    $user = app(User::class)->create(['name' => 'Target', 'email' => 'target@example.com', 'role' => Role::REPORTER]);

    $labels = [
        'Other (other@example.com) - reporter',
        'Target (target@example.com) - reporter',
    ];

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', 'Target (target@example.com) - reporter', $labels)
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'other@example.com')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(1);

    expect($user->fresh()->email)->toBe('target@example.com');
});

it('rejects an edit email that is not a valid address', function () {
    $user = app(User::class)->create(['name' => 'Target', 'email' => 'target@example.com', 'role' => Role::REPORTER]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'not-an-email')
        ->expectsOutputToContain('valid email address is required')
        ->assertExitCode(1);

    expect($user->fresh()->email)->toBe('target@example.com');
});

it('keeps the password hash and token version when the password is left blank', function () {
    $user = app(User::class)->create([
        'name' => 'Target',
        'email' => 'target@example.com',
        'role' => Role::REPORTER,
        'password' => Hash::make('oldsecret'),
    ]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'target@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'reporter')
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    $fresh = $user->fresh();

    expect(Hash::check('oldsecret', $fresh->password))->toBeTrue()
        ->and((int) $fresh->token_version)->toBe(0);
});

it('hashes a changed password and bumps the token version', function () {
    $user = app(User::class)->create([
        'name' => 'Target',
        'email' => 'target@example.com',
        'role' => Role::REPORTER,
        'password' => Hash::make('oldsecret'),
    ]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'target@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', 'newsecret')
        ->expectsQuestion('Role', 'reporter')
        ->expectsOutputToContain('Password updated')
        ->expectsOutputToContain('Existing sessions invalidated')
        ->assertExitCode(0);

    $fresh = $user->fresh();

    expect(Hash::check('newsecret', $fresh->password))->toBeTrue()
        ->and((int) $fresh->token_version)->toBe(1);
});

it('rejects using --promote and --edit together', function () {
    $this->artisan('redline:user', ['--promote' => true, '--edit' => true])
        ->expectsOutputToContain('Use either --promote or --edit, not both.')
        ->assertExitCode(1);
});

it('promotes nothing when the multi-select is empty', function () {
    $anna = app(User::class)->create(['name' => 'Anna', 'email' => 'anna@example.com', 'role' => Role::REPORTER]);
    $label = 'Anna (anna@example.com) - reporter';

    $this->artisan('redline:user', ['--promote' => true])
        ->expectsChoice('Select users to promote to admin (comma-separated)', [], [$label])
        ->expectsOutputToContain('No users selected; nothing promoted')
        ->assertExitCode(0);

    expect($anna->fresh()->role)->toBe(Role::REPORTER);
});

it('keeps the current name when the edit name answer is blank', function () {
    $user = app(User::class)->create(['name' => 'Target', 'email' => 'target@example.com', 'role' => Role::REPORTER]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', '')
        ->expectsQuestion('Email', 'target@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'reporter')
        ->expectsOutputToContain('No changes made')
        ->assertExitCode(0);

    expect($user->fresh()->name)->toBe('Target');
});

it('refuses to create a user under a LaravelAuthProvider subclass', function () {
    config(['redline.auth.provider' => LaravelAuthProviderSubclass::class]);

    $this->artisan('redline:user')
        ->expectsOutputToContain('provisioned automatically on first host-application login')
        ->assertExitCode(1);

    expect(app(User::class)->count())->toBe(0);
});

it('rejects a create email that differs only in case from an existing user', function () {
    app(User::class)->create(['name' => 'Existing', 'email' => 'foo@ex.com', 'role' => Role::REPORTER]);

    $this->artisan('redline:user')
        ->expectsQuestion('Name', 'New')
        ->expectsQuestion('Email', 'FOO@ex.com')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(1);

    expect(app(User::class)->where('name', 'New')->exists())->toBeFalse();
});

it('never offers the shared guest identity for promotion', function () {
    app(User::class)->create(['name' => 'Guest', 'email' => 'guest@redline.local', 'role' => Role::GUEST]);
    $anna = app(User::class)->create(['name' => 'Anna', 'email' => 'anna@example.com', 'role' => Role::REPORTER]);

    $label = 'Anna (anna@example.com) - reporter';

    $this->artisan('redline:user', ['--promote' => true])
        ->expectsChoice('Select users to promote to admin (comma-separated)', [$label], [$label])
        ->assertExitCode(0);

    expect($anna->fresh()->role)->toBe(Role::ADMIN);
});

it('never offers the shared guest identity for editing', function () {
    app(User::class)->create(['name' => 'Guest', 'email' => 'guest@redline.local', 'role' => Role::GUEST]);
    app(User::class)->create(['name' => 'Anna', 'email' => 'anna@example.com', 'role' => Role::REPORTER]);

    $label = 'Anna (anna@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Anna')
        ->expectsQuestion('Email', 'anna@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'reporter')
        ->assertExitCode(0);
});

it('rejects a short password when editing', function () {
    $user = app(User::class)->create(['name' => 'Target', 'email' => 'target@example.com', 'role' => Role::REPORTER]);
    $label = 'Target (target@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'target@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', 'short')
        ->expectsOutputToContain('Password must be at least 6 characters.')
        ->assertExitCode(1);

    expect((int) $user->fresh()->token_version)->toBe(0);
});

it('warns when the email changes under LaravelAuthProvider', function () {
    config(['redline.auth.provider' => LaravelAuthProvider::class]);

    $user = app(User::class)->create(['name' => 'Target', 'email' => 'old@example.com', 'role' => Role::REPORTER]);
    $label = 'Target (old@example.com) - reporter';

    $this->artisan('redline:user', ['--edit' => true])
        ->expectsChoice('Select a user to edit', $label, [$label])
        ->expectsQuestion('Name', 'Target')
        ->expectsQuestion('Email', 'new@example.com')
        ->expectsQuestion('Password (leave blank to keep current)', '')
        ->expectsQuestion('Role', 'reporter')
        ->expectsOutputToContain('will provision a NEW reporter')
        ->assertExitCode(0);

    expect($user->fresh()->email)->toBe('new@example.com');
});
