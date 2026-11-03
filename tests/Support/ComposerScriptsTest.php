<?php

use Zipavlin\Redline\Support\ComposerScripts;

/**
 * The Redline composer script entries in the host composer.json: install
 * migrates to the per-run post-autoload-dump hook, update migrates it for
 * pre-existing installs, remove drops it. Every operation must be merge-safe
 * - the host's other keys and listeners are never touched.
 */
afterEach(function () {
    cleanComposerJsonFixtures();
});

it('migrates a legacy entry onto post-autoload-dump, preserving foreign keys and listeners', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'require' => ['php' => '^8.3'],
        'scripts' => [
            'post-autoload-dump' => ['@php artisan package:discover --ansi'],
            'post-package-update' => ['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->migrateHooks())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['name'])->toBe('acme/app');
    expect($decoded['require'])->toBe(['php' => '^8.3']);
    expect($decoded['scripts']['post-autoload-dump'])->toBe(['@php artisan package:discover --ansi', ComposerScripts::AUTOLOAD_HOOK]);
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
});

it('drops the legacy event key when the migration empties it and adds the autoload hook', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => ['post-package-update' => [ComposerScripts::UPDATE_HOOK]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->migrateHooks())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-autoload-dump'])->toBe([ComposerScripts::AUTOLOAD_HOOK]);
    expect(isset($decoded['scripts']['post-package-update']))->toBeFalse();
});

it('dedupes a case-mangled autoload entry while removing a separate legacy entry', function () {
    $path = composerJsonFixture(json_encode([
        'scripts' => [
            'post-autoload-dump' => ['zipavlin\\redline\\composerhook::ensurecurrent'],
            'post-package-update' => [ComposerScripts::UPDATE_HOOK],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->hasAutoloadHook())->toBeTrue();
    expect((new ComposerScripts($path))->migrateHooks())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-autoload-dump'])->toBe(['zipavlin\\redline\\composerhook::ensurecurrent']);
    expect(isset($decoded['scripts']['post-package-update']))->toBeFalse();
});

it('reports false and writes nothing when nothing needs migrating', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => ['post-autoload-dump' => ['@php artisan package:discover --ansi', ComposerScripts::AUTOLOAD_HOOK]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $before = (string) file_get_contents($path);

    expect((new ComposerScripts($path))->migrateHooks())->toBeFalse();
    expect((string) file_get_contents($path))->toBe($before);
});

it('reports false and writes nothing when the file is missing', function () {
    $path = storage_path('app/composer-json-missing-'.uniqid().'.json');

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->hasAutoloadHook())->toBeFalse();
    expect((new ComposerScripts($path))->migrateHooks())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeFalse();
    expect($path)->not->toBeFile();
});

it('reports false and leaves invalid JSON alone', function () {
    $path = composerJsonFixture('{ this is not json');

    expect((new ComposerScripts($path))->migrateHooks())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeFalse();
    expect(file_get_contents($path))->toBe('{ this is not json');
});

it('removes only the Redline hook of an event, leaving foreign and unrelated scripts intact', function () {
    $legacy = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => [
            'post-root-package-install' => 'Acme\\Pkg\\Install::run',
            'post-package-update' => ['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($legacy))->migrateHooks())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($legacy), true);

    // An unrelated event is untouched, still in Composer's plain-string form.
    expect($decoded['scripts']['post-root-package-install'])->toBe('Acme\\Pkg\\Install::run');
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
});

it('matches and removes a case-mangled legacy hook entry', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => [
            'post-package-update' => ['Acme\\Pkg\\Hook::run', 'zipavlin\\redline\\composerhook::postPackageUpdate'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeTrue();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['name'])->toBe('acme/app');
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
});

it('leaves non-string listeners intact while removing the legacy hook', function () {
    $path = composerJsonFixture(json_encode([
        'scripts' => [
            'post-package-update' => [['nested'], 'zipavlin\\redline\\composerhook::postpackageupdate'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeTrue();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-package-update'])->toBe([['nested']]);
});

it('reports no legacy hook when the event only holds non-string entries', function () {
    $path = composerJsonFixture(json_encode([
        'scripts' => ['post-package-update' => [['nested']]],
    ]));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeFalse();
});

it('removes only its own legacy hook and keeps the other listener', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => [
            'post-autoload-dump' => ['@php artisan package:discover --ansi'],
            'post-package-update' => ['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
    expect($decoded['scripts']['post-autoload-dump'])->toBe(['@php artisan package:discover --ansi']);
    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
});

it('removes only its own autoload hook and keeps the other listener', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => [
            'post-autoload-dump' => ['@php artisan package:discover --ansi', ComposerScripts::AUTOLOAD_HOOK],
            'post-package-update' => ['Acme\\Pkg\\Hook::run'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->removeAutoloadHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-autoload-dump'])->toBe(['@php artisan package:discover --ansi']);
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
});

it('drops the event key and then the scripts key when nothing is left', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => ['post-package-update' => [ComposerScripts::UPDATE_HOOK]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBe(['name' => 'acme/app']);
});

it('writes the autoload hook under the FQCN Composer will call', function () {
    $path = composerJsonFixture('{}');

    expect((new ComposerScripts($path))->migrateHooks())->toBeTrue();

    // Four source backslashes: single-quoted PHP halves them, and the file then
    // holds the JSON-escaped FQCN Composer resolves the class from.
    expect(file_get_contents($path))->toContain('Zipavlin\\\\Redline\\\\ComposerHook::ensureCurrent');
});
