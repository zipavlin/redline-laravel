<?php

namespace Zipavlin\Redline\Commands;

use Illuminate\Console\Command;
use Zipavlin\Redline\Commands\Concerns\WritesEnv;
use Zipavlin\Redline\Enums\Feature;

class EnableCommand extends Command
{
    use WritesEnv;

    public $signature = 'redline:enable
        {feature?* : Feature name(s) to enable - comma-separated, space-separated or repeated}
        {--D|disable=* : Feature name(s) to disable - comma-separated, space-separated or repeated}';

    public $description = 'List or toggle Redline features (every feature is off until enabled)';

    public function handle(): int
    {
        $toEnable = $this->uniqueNames((array) $this->argument('feature'));
        $toDisable = $this->uniqueNames((array) $this->option('disable'));

        if ($toEnable === [] && $toDisable === []) {
            $this->reportState();

            return self::SUCCESS;
        }

        try {
            $this->writeEnabled($this->nextEnabled($toEnable, $toDisable));
        } catch (\InvalidArgumentException $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');

        return self::SUCCESS;
    }

    // Handlers

    /**
     * The currently enabled set plus/minus the requested toggles, keyed by
     * feature value. Invalid names throw before anything is written, so a
     * typo never leaves a half-applied REDLINE_FEATURES behind.
     *
     * @param  array<int, string>  $toEnable
     * @param  array<int, string>  $toDisable
     * @return array<string, true>
     */
    private function nextEnabled(array $toEnable, array $toDisable): array
    {
        Feature::assertKnown([...$toEnable, ...$toDisable]);

        $enabled = array_fill_keys(
            array_map(fn (Feature $feature) => $feature->value, Feature::enabled()),
            true,
        );

        foreach ($toEnable as $name) {
            $enabled[$name] = true;
        }

        foreach ($toDisable as $name) {
            unset($enabled[$name]);
        }

        return $enabled;
    }

    /**
     * Writes the target set to REDLINE_FEATURES in enum order and echoes it.
     * Empty output keeps the key present so the all-off state is explicit.
     *
     * @param  array<string, true>  $enabled
     */
    private function writeEnabled(array $enabled): void
    {
        $features = implode(',', array_map(
            fn (Feature $feature) => $feature->value,
            array_values(array_filter(
                Feature::cases(),
                fn (Feature $feature) => isset($enabled[$feature->value]),
            )),
        ));

        $this->writeEnvValue('REDLINE_FEATURES', $features);
        $this->line($features === ''
            ? '  <fg=green>✓</> REDLINE_FEATURES= (no features enabled)'
            : "  <fg=green>✓</> REDLINE_FEATURES=$features");
    }

    private function reportState(): void
    {
        $enabled = array_fill_keys(
            array_map(fn (Feature $feature) => $feature->value, Feature::enabled()),
            true,
        );

        $this->line('  Redline features - driven by REDLINE_FEATURES; every feature is off until it is listed:');
        foreach (Feature::cases() as $feature) {
            $state = isset($enabled[$feature->value]) ? '<fg=green>enabled</>' : 'disabled';
            $this->line('  '.str_pad($feature->value, 12).$state);
        }
        $this->line('');
        $this->line('  Toggle with `php artisan redline:enable <names>` or `--disable=<names>`.');
        $this->line('');
    }

    /**
     * Splits comma-separated, space-separated and repeated input into trimmed
     * unique names: ["a,b"], ["a", "b"] and ["a,b", "b"] all yield [a, b].
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function uniqueNames(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $name): string => strtolower(trim($name)),
                explode(',', implode(',', $values)),
            ),
            fn (string $name) => $name !== '',
        )));
    }
}
