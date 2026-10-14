<?php

use Zipavlin\Redline\Support\ComposerScripts;

/**
 * The Redline composer post-package-update entry in the host composer.json:
 * install adds it, update offers it, remove drops it. Every operation must be
 * merge-safe - the host's other keys and listeners are never touched.
 */
afterEach(function () {
    cleanComposerJsonFixtures();
});

it('adds the hook to an empty composer.json', function () {
    $path = composerJsonFixture('{}');

    expect((new ComposerScripts($path))->addUpdateHook())->toBeTrue();
    expect((new ComposerScripts($path))->hasUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-package-update'])->toBe([ComposerScripts::UPDATE_HOOK]);
});

it('adds the hook only once', function () {
    $path = composerJsonFixture('{}');

    expect((new ComposerScripts($path))->addUpdateHook())->toBeTrue();
    expect((new ComposerScripts($path))->addUpdateHook())->toBeFalse();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-package-update'])->toBe([ComposerScripts::UPDATE_HOOK]);
});

it('keeps every other composer.json key and listener intact', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'require' => ['php' => '^8.3'],
        'scripts' => [
            'post-autoload-dump' => ['@php artisan package:discover --ansi'],
            'post-package-update' => ['Acme\\Pkg\\Hook::run'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->addUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['name'])->toBe('acme/app');
    expect($decoded['require'])->toBe(['php' => '^8.3']);
    expect($decoded['scripts']['post-autoload-dump'])->toBe(['@php artisan package:discover --ansi']);
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK]);
});

it('normalizes a plain-string post-package-update listener to a list', function () {
    $path = composerJsonFixture(json_encode([
        'scripts' => ['post-package-update' => 'Acme\\Pkg\\Hook::run'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->addUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run', ComposerScripts::UPDATE_HOOK]);
});

it('matches and removes a case-mangled hook entry', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => [
            'post-package-update' => ['Acme\\Pkg\\Hook::run', 'zipavlin\\redline\\composerhook::postPackageUpdate'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeTrue();
    expect((new ComposerScripts($path))->addUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded['name'])->toBe('acme/app');
    expect($decoded['scripts']['post-package-update'])->toBe(['Acme\\Pkg\\Hook::run']);
    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
});

it('leaves non-string listeners intact while removing the hook', function () {
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

it('reports no hook when the event only holds non-string entries', function () {
    $path = composerJsonFixture(json_encode([
        'scripts' => ['post-package-update' => [['nested']]],
    ]));

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeFalse();
});

it('removes only its own hook and keeps the other listener', function () {
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

it('drops the event key and then the scripts key when nothing is left', function () {
    $path = composerJsonFixture(json_encode([
        'name' => 'acme/app',
        'scripts' => ['post-package-update' => [ComposerScripts::UPDATE_HOOK]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect((new ComposerScripts($path))->removeUpdateHook())->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBe(['name' => 'acme/app']);
});

it('writes the hook under the FQCN Composer will call', function () {
    $path = composerJsonFixture('{}');

    expect((new ComposerScripts($path))->addUpdateHook())->toBeTrue();

    // Four source backslashes: single-quoted PHP halves them, and the file then
    // holds the JSON-escaped FQCN Composer resolves the class from.
    expect(file_get_contents($path))->toContain('Zipavlin\\\\Redline\\\\ComposerHook::postPackageUpdate');
});

it('reports false and writes nothing when the file is missing', function () {
    $path = storage_path('app/composer-json-missing-'.uniqid().'.json');

    expect((new ComposerScripts($path))->hasUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->addUpdateHook())->toBeFalse();
    expect((new ComposerScripts($path))->removeUpdateHook())->toBeFalse();
    expect($path)->not->toBeFile();
});

it('reports false and leaves invalid JSON alone', function () {
    $path = composerJsonFixture('{ this is not json');

    expect((new ComposerScripts($path))->addUpdateHook())->toBeFalse();
    expect(file_get_contents($path))->toBe('{ this is not json');
});
