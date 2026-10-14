<?php

use Zipavlin\Redline\ComposerHook;

/**
 * ComposerHook is the host composer.json `post-package-update` listener.
 * composer/composer is not installed here, so Composer's own classes do not
 * exist: the hook duck-types the real shape - a PackageEvent whose
 * getOperation() carries an UpdateOperation exposing getTargetPackage() - and
 * must decide correctly (and stay silent) without them. Only non-redline events
 * are driven through postPackageUpdate() - a redline event would spawn a real
 * artisan process.
 */
afterEach(function () {
    putenv(ComposerHook::SKIP_ENV);
});

/**
 * A stand-in for Composer\Installer\PackageEvent carrying an UpdateOperation
 * whose target package is the named one; a null name models an operation whose
 * target package is null.
 */
function composerPackageEvent(?string $packageName): object
{
    return new class($packageName)
    {
        public function __construct(private ?string $name) {}

        public function getOperation(): object
        {
            $name = $this->name;

            return new class($name)
            {
                public function __construct(private ?string $name) {}

                public function getTargetPackage(): ?object
                {
                    if ($this->name === null) {
                        return null;
                    }

                    $name = $this->name;

                    return new class($name)
                    {
                        public function __construct(private string $name) {}

                        public function getName(): string
                        {
                            return $this->name;
                        }
                    };
                }
            };
        }
    };
}

it('skips the update when REDLINE_SKIP_UPDATE_HOOK is truthy', function () {
    putenv(ComposerHook::SKIP_ENV.'=1');

    expect(ComposerHook::skipped())->toBeTrue();
    expect(ComposerHook::shouldUpdate(composerPackageEvent(ComposerHook::PACKAGE)))->toBeFalse();
});

it('does not skip when REDLINE_SKIP_UPDATE_HOOK is falsy or unset', function () {
    putenv(ComposerHook::SKIP_ENV.'=0');
    expect(ComposerHook::skipped())->toBeFalse();

    putenv(ComposerHook::SKIP_ENV.'=false');
    expect(ComposerHook::skipped())->toBeFalse();

    putenv(ComposerHook::SKIP_ENV);
    expect(ComposerHook::skipped())->toBeFalse();
});

it('updates only when the updated package is redline itself', function () {
    putenv(ComposerHook::SKIP_ENV);

    expect(ComposerHook::shouldUpdate(composerPackageEvent(ComposerHook::PACKAGE)))->toBeTrue();
    expect(ComposerHook::shouldUpdate(composerPackageEvent('acme/other-package')))->toBeFalse();
});

it('reads the updated package name off the operation the event carries', function () {
    expect(ComposerHook::updatedPackageName(composerPackageEvent(ComposerHook::PACKAGE)))
        ->toBe(ComposerHook::PACKAGE);
    expect(ComposerHook::updatedPackageName(composerPackageEvent('acme/other-package')))
        ->toBe('acme/other-package');
});

it('reports no updated package when the event cannot supply one', function () {
    putenv(ComposerHook::SKIP_ENV);

    $events = [
        'event without getOperation()' => new class {},
        'null operation' => new class
        {
            public function getOperation(): ?object
            {
                return null;
            }
        },
        'operation without getTargetPackage()' => new class
        {
            public function getOperation(): object
            {
                return new class {};
            }
        },
        'null target package' => composerPackageEvent(null),
        'target package without getName()' => new class
        {
            public function getOperation(): object
            {
                return new class
                {
                    public function getTargetPackage(): ?object
                    {
                        return new class {};
                    }
                };
            }
        },
        'null package name' => new class
        {
            public function getOperation(): object
            {
                return new class
                {
                    public function getTargetPackage(): ?object
                    {
                        return new class
                        {
                            public function getName(): ?string
                            {
                                return null;
                            }
                        };
                    }
                };
            }
        },
    ];

    foreach ($events as $label => $event) {
        expect(ComposerHook::updatedPackageName($event))->toBeNull($label);
        expect(ComposerHook::shouldUpdate($event))->toBeFalse($label);
    }
});

it('does nothing for a non-redline package update', function () {
    putenv(ComposerHook::SKIP_ENV);

    expect(fn () => ComposerHook::postPackageUpdate(composerPackageEvent('acme/other-package')))
        ->not->toThrow(Throwable::class);
});

it('stays silent for an event it cannot interpret', function () {
    putenv(ComposerHook::SKIP_ENV);

    expect(fn () => ComposerHook::postPackageUpdate(new class {}))
        ->not->toThrow(Throwable::class);
});
