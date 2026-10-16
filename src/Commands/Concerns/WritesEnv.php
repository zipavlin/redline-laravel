<?php

namespace Zipavlin\Redline\Commands\Concerns;

trait WritesEnv
{
    /**
     * Writes or updates a key-value pair in the .env file. If the key already
     * exists, its value is updated. If the key does not exist, it appends the
     * new key-value pair at the end of the file.
     *
     * @param  string  $key  The environment variable key to write or update.
     * @param  string  $value  The value to set for the specified key. Special characters are properly escaped.
     */
    protected function writeEnvValue(string $key, string $value): void
    {
        $envPath = $this->envFilePath();

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
     * Path to the host .env file. A seam for tests, so command tests redirect
     * writes to a fixture instead of testbench's own environment file.
     */
    protected function envFilePath(): string
    {
        return base_path('.env');
    }
}
