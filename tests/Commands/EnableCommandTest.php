<?php

use Illuminate\Contracts\Console\Kernel;
use Zipavlin\Redline\Commands\EnableCommand;

/**
 * redline:enable reads the currently enabled features from config and writes
 * REDLINE_FEATURES back to the host .env without touching unrelated lines.
 * The fixture .env is redirected through the envFilePath() seam, so the
 * skeleton's own environment file is never written.
 */
beforeEach(function () {
    $this->envPath = storage_path('app/redline-enable-env-'.uniqid().'.env');
});

afterEach(function () {
    if (file_exists($this->envPath)) {
        @unlink($this->envPath);
    }
});

/**
 * Bind a command instance whose .env writes go to a throwaway fixture.
 */
function fakeEnableCommand(string $envPath): EnableCommand
{
    $command = new class($envPath) extends EnableCommand
    {
        public function __construct(public string $envFixturePath)
        {
            parent::__construct();
        }

        protected function envFilePath(): string
        {
            return $this->envFixturePath;
        }
    };

    app()->instance(EnableCommand::class, $command);

    // The Artisan console app is built during app boot with real command
    // instances; rebuild it so the fake above is the one registered by name.
    app(Kernel::class)->setArtisan(null);

    return $command;
}

function enableCommandEnv(string $envPath, string $redlineFeatures = ''): void
{
    file_put_contents($envPath, implode("\n", array_filter([
        'APP_NAME=Laravel',
        'APP_DEBUG=true',
        $redlineFeatures !== '' ? "REDLINE_FEATURES=$redlineFeatures" : null,
    ]))."\n");
}

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

it('lists every feature with its state when called without arguments', function () {
    config(['redline.features' => ['comments']]);

    $this->artisan('redline:enable')
        ->expectsOutputToContain('screenshots disabled')
        ->expectsOutputToContain('videos      disabled')
        ->expectsOutputToContain('files       disabled')
        ->expectsOutputToContain('comments    enabled')
        ->expectsOutputToContain('mcp         disabled')
        ->expectsOutputToContain('REDLINE_FEATURES')
        ->assertExitCode(0);
});

// ---------------------------------------------------------------------------
// Enabling
// ---------------------------------------------------------------------------

it('enables a comma-separated list and preserves unrelated .env content', function () {
    fakeEnableCommand($this->envPath);
    enableCommandEnv($this->envPath, 'videos');
    config(['redline.features' => ['videos']]);

    $this->artisan('redline:enable', ['feature' => ['screenshots,comments']])
        ->expectsOutputToContain('REDLINE_FEATURES=screenshots,videos,comments')
        ->assertExitCode(0);

    $content = (string) file_get_contents($this->envPath);

    expect($content)->toContain('APP_NAME=Laravel')
        ->and($content)->toContain('APP_DEBUG=true')
        ->and($content)->toContain('REDLINE_FEATURES=screenshots,videos,comments');
});

it('enables space-separated and repeated arguments', function () {
    fakeEnableCommand($this->envPath);
    enableCommandEnv($this->envPath);
    config(['redline.features' => []]);

    $this->artisan('redline:enable', ['feature' => ['videos', 'files']])
        ->expectsOutputToContain('REDLINE_FEATURES=videos,files')
        ->assertExitCode(0);
});

// ---------------------------------------------------------------------------
// Disabling
// ---------------------------------------------------------------------------

it('disables comma-separated features and keeps the rest', function () {
    fakeEnableCommand($this->envPath);
    enableCommandEnv($this->envPath, 'screenshots,videos,comments');
    config(['redline.features' => ['screenshots', 'videos', 'comments']]);

    $this->artisan('redline:enable', ['--disable' => ['videos,comments']])
        ->expectsOutputToContain('REDLINE_FEATURES=screenshots')
        ->assertExitCode(0);
});

it('supports repeated --disable flags and writes an all-off bundle explicitly', function () {
    fakeEnableCommand($this->envPath);
    enableCommandEnv($this->envPath, 'screenshots,comments');
    config(['redline.features' => ['screenshots', 'comments']]);

    $this->artisan('redline:enable', ['--disable' => ['screenshots', 'comments']])
        ->expectsOutputToContain('REDLINE_FEATURES= (no features enabled)')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toContain('REDLINE_FEATURES=');
});

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

it('fails with a clear error and writes nothing for an unknown feature name', function () {
    fakeEnableCommand($this->envPath);
    enableCommandEnv($this->envPath, 'videos');
    config(['redline.features' => ['videos']]);
    $before = file_get_contents($this->envPath);

    $this->artisan('redline:enable', ['feature' => ['vidoes']])
        ->expectsOutputToContain('Unknown Redline feature(s): vidoes. Valid features: screenshots, videos, files, comments, mcp.')
        ->assertExitCode(1);

    expect(file_get_contents($this->envPath))->toBe($before);
});
