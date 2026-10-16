<?php

/**
 * Harness integrity guard: the installed PHPUnit is 12.x, which no longer
 * supports the docblock annotation PHPUnit 11 deprecated - a docblock-anointed
 * test method is simply NOT COLLECTED, so the suite stays green while the
 * tests silently disappear (this is exactly what happened to the originally
 * written Mcp test classes, which hid three whole matrices).
 *
 * Declaring a test the supported way means either a Pest closure with it() or
 * test(), or - for plain PHPUnit classes - the real #[Test] attribute. The
 * regex below only matches lines that carry the deprecated annotation alone,
 * so ordinary prose mentioning it stays legal.
 */
it('collects every test without relying on the deprecated docblock annotation', function () {
    $offender = null;

    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__).'/tests',
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($sources as $source) {
        if (! $source->isFile() || $source->getExtension() !== 'php') {
            continue;
        }

        foreach (file($source->getPathname()) as $number => $line) {
            if (preg_match('/^\s*(\/\*\*|\*)\s*@test\s*(\*\/)?\s*$/', $line) === 1) {
                $offender = ['path' => $source->getPathname(), 'line' => $number + 1];

                break 2;
            }
        }
    }

    expect($offender)->toBeNull();
});
