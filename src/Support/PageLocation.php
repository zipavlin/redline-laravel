<?php

namespace Zipavlin\Redline\Support;

/**
 * The page grouping key derived from a report's url: path plus the
 * content-defining query params only, sorted, so one page never fragments
 * into many report groups because of campaign links or param order.
 *
 * Mirrors the widget's getUrlKey() in redline-app/src/core/url.ts - that file
 * is the source of truth ("keep in sync"; the two repos cannot share code).
 */
final class PageLocation
{
    private const TRACKING_PARAMS = [
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'yclid', 'dclid',
        'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'igshid', 'mkt_tok', '_openstat',
    ];

    public static function fromUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! self::isAbsoluteHttp($parsed)) {
            return null;
        }

        $path = $parsed['path'] ?? '';
        $pairs = self::sortedPairs(self::keptPairs($parsed['query'] ?? ''));

        return self::keyFor($path, $pairs);
    }

    /**
     * An absolute URL needs an http(s) scheme AND a host ("//x" style or
     * "ftp://x" URLs are not addresses a report can be reopened from).
     */
    private static function isAbsoluteHttp(array|false $parsed): bool
    {
        return $parsed !== false
            && isset($parsed['scheme'], $parsed['host'])
            && in_array($parsed['scheme'], ['http', 'https'], true);
    }

    /**
     * @param  list<array{string, string}>  $pairs
     */
    private static function keyFor(string $path, array $pairs): string
    {
        $query = implode('&', array_map(static fn (array $pair): string => self::encodePair($pair), $pairs));

        return ($path === '' ? '/' : $path).($query === '' ? '' : '?'.$query);
    }

    /**
     * @return list<array{string, string}>
     */
    private static function keptPairs(string $query): array
    {
        $pairs = [];

        foreach (explode('&', $query) as $part) {
            $pair = self::decodePair($part);

            if ($pair !== null && ! self::isDroppedKey($pair[0])) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return list{string, string}|null
     */
    private static function decodePair(string $part): ?array
    {
        if ($part === '') {
            return null;
        }

        $fragments = explode('=', $part, 2);

        return [urldecode($fragments[0]), urldecode($fragments[1] ?? '')];
    }

    private static function isDroppedKey(string $key): bool
    {
        return str_starts_with($key, 'redline-')
            || str_starts_with($key, 'utm_')
            || in_array($key, self::TRACKING_PARAMS, true);
    }

    /**
     * Stable sort by key only (usort() is stable since PHP 8.0), matching
     * URLSearchParams.sort(): repeated keys keep their relative order.
     */
    private static function sortedPairs(array $pairs): array
    {
        usort($pairs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $pairs;
    }

    /**
     * urlencode() is PHP_QUERY_RFC1738 - space as "+", so the output matches
     * URLSearchParams.toString(). Pairs are joined by hand because PHP array
     * keys cannot repeat, and http_build_query() would bracket a repeated
     * key's value array instead of emitting `q=a&q=b`.
     *
     * @param  array{string, string}  $pair
     */
    private static function encodePair(array $pair): string
    {
        return self::urlencodeForm($pair[0]).'='.self::urlencodeForm($pair[1]);
    }

    /**
     * RFC1738 form encoding with one correction: urlencode() escapes `*` as
     * %2A while URLSearchParams - the widget's serializer, and the contract this
     * mirrors - leaves it alone. Without this a selector-ish value like `a*b`
     * keys differently on the two sides.
     */
    private static function urlencodeForm(string $value): string
    {
        return str_replace('%2A', '*', urlencode($value));
    }
}
