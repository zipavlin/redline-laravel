<?php

use Symfony\Component\Process\Process;
use Zipavlin\Redline\ComposerHook;
use Zipavlin\Redline\Support\UpdateMarker;

/**
 * ComposerHook keeps an installed Redline current from composer runs.
 * composer/composer is not installed here, so Composer's own classes do not
 * exist: the hook duck-types the real event shape - a PackageEvent whose
 * getOperation() carries an UpdateOperation exposing getTargetPackage() - and
 * must decide correctly (and stay silent) without them. Spawn tests inject a
 * fake Process and redirect the writers through the package-private test
 * seams; the cwd is chdir'd into a throwaway fixture root with PHP restoring
 * it afterwards.
 */
afterEach(function () {
    putenv(ComposerHook::SKIP_ENV);
    ComposerHook::setProcessFactory(null);
    ComposerHook::setOutputStreams();
    chdir(hookTestCwd());
    cleanHookFixtures();
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

// ---------------------------------------------------------------------------
// ensureCurrent - the per-run post-autoload-dump entry
// ---------------------------------------------------------------------------

/**
 * The suite's cwd - captured at file load, before any test chdir'd into a
 * fixture root - so afterEach can restore it.
 */
if (! isset($GLOBALS['hookTestCwd'])) {
    $GLOBALS['hookTestCwd'] = (string) getcwd();
}

function hookTestCwd(): string
{
    return $GLOBALS['hookTestCwd'];
}

function hookFixtureRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-hook-'.uniqid();

    mkdir($root, 0777, true);

    // Only its presence is checked; the hook never executes the file.
    file_put_contents($root.DIRECTORY_SEPARATOR.'artisan', "<?php\n\n// fixture only\n");

    return $root;
}

function cleanHookFixtures(): void
{
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-hook-*', GLOB_ONLYDIR) ?: [] as $dir) {
        @unlink($dir.DIRECTORY_SEPARATOR.'artisan');
        @unlink($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'redline'.DIRECTORY_SEPARATOR.'update-marker.json');
        @rmdir($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'redline');
        @rmdir($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app');
        @rmdir($dir.DIRECTORY_SEPARATOR.'storage');
        @rmdir($dir);
    }
}

/**
 * A stand-in for Symfony Process implementing exactly the calls runUpdate()
 * makes, optionally echoing two streamed chunks and optionally throwing from
 * run().
 */
function fakeUpdateProcess(bool $successful, bool $throws = false): object
{
    return new class($successful, $throws)
    {
        public function __construct(private readonly bool $successful, private readonly bool $throws = false) {}

        public function run(?callable $callback = null): int
        {
            if ($this->throws) {
                throw new RuntimeException('condap failed');
            }

            $callback(Process::OUT, 'pending migrations streamed');
            $callback(Process::ERR, 'publish warning streamed');

            return $this->successful ? 0 : 1;
        }

        public function isSuccessful(): bool
        {
            return $this->successful;
        }

        public function getExitCode(): int
        {
            return $this->successful ? 0 : 1;
        }

        public function getOutput(): string
        {
            return 'pending migrations streamed';
        }

        public function getErrorOutput(): string
        {
            return 'publish warning streamed';
        }
    };
}

/**
 * Open the in-memory streams the hook writes into and install them through
 * the test seam; also install a fake process factory recording every spawn.
 *
 * @param  array<int, array<string, mixed>>  $spawns
 * @return array<string, mixed> out/err stream handles
 */
function hookTestSink(array &$spawns, bool $successful, bool $throws = false): array
{
    $streams = ['out' => fopen('php://memory', 'w+'), 'err' => fopen('php://memory', 'w+')];

    ComposerHook::setOutputStreams($streams['out'], $streams['err']);

    ComposerHook::setProcessFactory(function (string $root) use (&$spawns, $successful, $throws) {
        $spawns[] = $root;

        return fakeUpdateProcess($successful, $throws);
    });

    return $streams;
}

function streamText($stream): string
{
    rewind($stream);
    $content = stream_get_contents($stream);
    fclose($stream);

    return (string) $content;
}

it('spawns nothing and stays silent when REDLINE_SKIP_UPDATE_HOOK is truthy', function () {
    putenv(ComposerHook::SKIP_ENV.'=1');

    chdir(hookFixtureRoot());

    $spawns = [];
    ComposerHook::setProcessFactory(fn () => throw new AssertionError('the hook must not spawn'));

    expect(fn () => ComposerHook::ensureCurrent())->not->toThrow(Throwable::class);

    expect($spawns)->toBe([]);
});

it('stays silent when no artisan file exists at the project root', function () {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'redline-hook-'.uniqid();
    mkdir($root, 0777, true); // no artisan file

    chdir($root);

    $spawns = [];
    ComposerHook::setProcessFactory(fn () => throw new AssertionError('the hook must not spawn'));

    $streams = hookTestSink($spawns, successful: true);

    ComposerHook::ensureCurrent();

    expect($spawns)->toBe([]);
    expect(streamText($streams['out']))->toBe('');
    expect(streamText($streams['err']))->toBe('');
});

it('stays silent when the marker says the install is current', function () {
    chdir(hookFixtureRoot());

    UpdateMarker::mark(UpdateMarker::currentVersionKey(), UpdateMarker::pathWithin((string) getcwd()));

    $spawns = [];
    $streams = hookTestSink($spawns, successful: true);

    ComposerHook::ensureCurrent();

    expect($spawns)->toBe([]);
    expect(streamText($streams['out']))->toBe('');
    expect(streamText($streams['err']))->toBe('');
});

it('spawns redline:update on drift and streams its output through the writers', function () {
    chdir(hookFixtureRoot());

    $spawns = [];
    $streams = hookTestSink($spawns, successful: true);

    ComposerHook::ensureCurrent();

    // The marker is missing, so exactly one spawn resolves the drift.
    expect($spawns)->toBe([(string) getcwd()]);
    expect(streamText($streams['out']))
        ->toContain('[redline] running php artisan redline:update --force...')
        ->toContain('[redline] redline:update finished successfully.')
        ->toContain('pending migrations streamed');
    expect(streamText($streams['err']))->toContain('publish warning streamed');
});

it('reports a failure without failing the composer run', function () {
    chdir(hookFixtureRoot());

    $spawns = [];
    $streams = hookTestSink($spawns, successful: false);

    expect(fn () => ComposerHook::ensureCurrent())->not->toThrow(Throwable::class);

    expect($spawns)->toHaveCount(1);
    expect(streamText($streams['err']))
        ->toContain('[redline] Automatic "php artisan redline:update --force" failed (exit 1)');
    expect(streamText($streams['out']))
        ->toContain('[redline] running php artisan redline:update --force...');
});

it('catches a throwable from the spawn and keeps it off the composer run', function () {
    chdir(hookFixtureRoot());

    $spawns = [];
    $streams = hookTestSink($spawns, successful: true, throws: true);

    expect(fn () => ComposerHook::ensureCurrent())->not->toThrow(Throwable::class);

    expect($spawns)->toHaveCount(1);
    expect(streamText($streams['err']))
        ->toContain('Automatic "php artisan redline:update --force" failed (condap failed)');
});
