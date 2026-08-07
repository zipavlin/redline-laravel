<?php

// ---------------------------------------------------------------------------
// InjectRedline middleware tests
//
// Each test gets a fresh Orchestra Testbench app. We register a handful of
// test routes in beforeEach so the middleware (pushed to the `web` group by
// the service provider on boot) is exercised on every request.
// ---------------------------------------------------------------------------

beforeEach(function () {
    // Plain HTML page — the main target for injection.
    $this->app['router']
        ->get('/__test/html', fn () => response(
            '<html><head><title>Test</title></head><body><h1>Hello</h1></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        ))
        ->middleware('web');

    // HTML with an upper-case </BODY> to test case-insensitivity.
    $this->app['router']
        ->get('/__test/html-upper', fn () => response(
            '<html><body><h1>Hi</h1></BODY></html>',
            200,
            ['Content-Type' => 'text/html'],
        ))
        ->middleware('web');

    // HTML with no </body> at all.
    $this->app['router']
        ->get('/__test/html-no-body', fn () => response(
            '<html><head><title>Fragment</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ))
        ->middleware('web');

    // JSON API response — must pass through unchanged.
    $this->app['router']
        ->get('/__test/json', fn () => response()->json(['ok' => true]))
        ->middleware('web');

    // Plain text — must pass through unchanged.
    $this->app['router']
        ->get('/__test/text', fn () => response('just text', 200, ['Content-Type' => 'text/plain']))
        ->middleware('web');
});

// ---------------------------------------------------------------------------
// Injection — happy path
// ---------------------------------------------------------------------------

it('injects the <redline-widget> custom element with api/dashboard/csrf attributes and redline assets into an HTML response', function () {
    $content = $this->get('/__test/html')->getContent();

    expect($content)
        ->toContain('redline-widget')
        ->toContain('api=')
        ->toContain('dashboard=')
        ->toContain('csrf="redline_csrf"')
        ->toContain('/vendor/redline/redline.js')
        ->not->toContain('features=')
        ->not->toContain('fields=')
        ->not->toContain('can-register')
        ->not->toContain('guest=')
        ->not->toContain('csrf-cookie=');
});

it('places the injected content immediately before </body>', function () {
    $content = $this->get('/__test/html')->getContent();

    $scriptPos = strpos($content, 'redline-widget');
    $bodyPos = strpos($content, '</body>');

    expect($scriptPos)->not->toBeFalse()
        ->and($bodyPos)->not->toBeFalse()
        ->and($scriptPos)->toBeLessThan($bodyPos);
});

it('preserves all original HTML content before and after the injection point', function () {
    $content = $this->get('/__test/html')->getContent();

    expect($content)
        ->toContain('<h1>Hello</h1>')
        ->toContain('</body>')
        ->toContain('</html>');
});

it('works when </body> is upper-case', function () {
    $content = $this->get('/__test/html-upper')->getContent();

    expect($content)
        ->toContain('redline-widget')
        ->toContain('</BODY>');
});

it('does not inject window.__Redline config (config is now set by consuming app)', function () {
    $content = $this->get('/__test/html')->getContent();

    expect($content)->not->toContain('window.__Redline');
});

// ---------------------------------------------------------------------------
// Pass-through cases — middleware must not touch these
// ---------------------------------------------------------------------------

it('does not inject into a JSON response', function () {
    $content = $this->get('/__test/json')->getContent();

    expect($content)->not->toContain('redline-widget');
});

it('does not inject into a plain-text response', function () {
    $content = $this->get('/__test/text')->getContent();

    expect($content)->not->toContain('redline-widget');
});

it('does not modify an HTML response that has no </body> tag', function () {
    $original = '<html><head><title>Fragment</title></head></html>';
    $content = $this->get('/__test/html-no-body')->getContent();

    expect($content)->toBe($original);
});
