<?php

use Zipavlin\Redline\Support\PageLocation;

/**
 * Unit cases for the widget-parity grouping key port (getUrlKey in
 * redline-app/src/core/url.ts). The MCP report_create derivation rides on
 * these guarantees, so the HTTP-sent and MCP-derived keys never diverge.
 */
it('keeps a plain path and drops the fragment', function () {
    expect(PageLocation::fromUrl('https://x.test/checkout#frag'))->toBe('/checkout');
});

it('defaults to the root path when the url has none', function () {
    expect(PageLocation::fromUrl('https://x.test'))->toBe('/');
});

it('sorts content params alphabetically', function () {
    expect(PageLocation::fromUrl('https://x.test/p?b=1&a=2'))->toBe('/p?a=2&b=1');
});

it('drops redline, utm and ad-click tracking params', function (string $key) {
    expect(PageLocation::fromUrl('https://x.test/p?'.$key.'=1&keep=1'))->toBe('/p?keep=1');
})->with([
    'own param' => 'redline-report',
    'utm variant' => 'utm_content',
    'google click id' => 'gclid',
    'meta click id' => 'fbclid',
    'marketo token' => 'mkt_tok',
    'openstat' => '_openstat',
]);

it('drops the query entirely when only tracking params remain', function () {
    expect(PageLocation::fromUrl('https://x.test/p?utm_source=x&gclid=1'))->toBe('/p');
});

it('keeps repeated keys in their order and stable-sorts around them', function () {
    expect(PageLocation::fromUrl('https://x.test/p?b=2&a=1&b=1'))->toBe('/p?a=1&b=2&b=1');
});

it('re-encodes values like URLSearchParams.toString', function () {
    expect(PageLocation::fromUrl('https://x.test/p?q=a%20b'))->toBe('/p?q=a+b');
});

it('keeps * unescaped in keys and values like URLSearchParams does', function () {
    // urlencode() would emit %2A, which would key the same page differently on
    // the widget side (URLSearchParams leaves * alone).
    expect(PageLocation::fromUrl('https://x.test/p?q=a*b&a*b=1'))->toBe('/p?a*b=1&q=a*b');
});

it('answers null for relative and non-http urls', function (string $url) {
    expect(PageLocation::fromUrl($url))->toBeNull();
})->with([
    'site-relative' => '/checkout',
    'no scheme' => '//x.test/p',
    'ftp scheme' => 'ftp://x/p',
    'javascript scheme' => 'javascript:alert(1)',
    'not a url' => 'not a url',
]);
