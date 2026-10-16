<?php

namespace Zipavlin\Redline\Mcp\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Zipavlin\Redline\Models\Report;

/**
 * Report addressing for the MCP tools (see the MCP contract's conventions):
 * a `report` input holds the report's UUID, a `url` input holds any link or
 * address whose trailing UUID is the report id - a bare page URL or a Redline
 * dashboard link (`?redline-report=<uuid>`) both resolve, because the LAST
 * UUID in the address is the report id.
 *
 * An unknown or unparseable address throws an actionable
 * InvalidArgumentException that names what was tried - never a silent empty
 * result. Tool `handle()` methods convert it to an error Response.
 */
trait CanResolveReport
{
    /**
     * Resolve one report from the caller's `report`/`url` pair. Exactly one
     * of the two must be supplied.
     */
    protected function resolveReport(mixed $reportAddress, mixed $urlAddress): Report
    {
        if ($reportAddress !== null && $urlAddress !== null) {
            throw new InvalidArgumentException(
                'Provide either [report] (a report UUID) or [url] (a report address) - not both.'
            );
        }

        if ($reportAddress === null && $urlAddress === null) {
            throw new InvalidArgumentException(
                'Provide [report] (a report UUID) or [url] (a report address whose trailing UUID is a report id).'
            );
        }

        $source = 'report';
        $address = $reportAddress;

        if ($urlAddress !== null) {
            $source = 'url';
            $address = $urlAddress;
        }

        $id = $source === 'report'
            ? self::reportIdFromId($address)
            : self::reportIdFromUrl($address);

        if ($id === null) {
            throw new InvalidArgumentException(
                'The ['.$source.'] value ['.self::addressLabel($address).'] does not address a report'
                .' - a report address must be a UUID or a link whose trailing UUID is one.'
            );
        }

        $report = app(Report::class)->find($id);

        if ($report === null) {
            throw new InvalidArgumentException('No report found for ['.$id.'].');
        }

        return $report;
    }

    /** A well-formed report id, or null naming [report]'s own failure mode. */
    private static function reportIdFromId(mixed $address): ?string
    {
        if (! is_string($address) || ! Str::isUuid($address)) {
            return null;
        }

        return $address;
    }

    /**
     * The last UUID in an address is the report id - that single rule covers a
     * bare URL carrying the page's own path plus `?redline-report=<uuid>`,
     * a dashboard link, and an already-trimmed report URL alike.
     */
    private static function reportIdFromUrl(mixed $address): ?string
    {
        if (! is_string($address)) {
            return null;
        }

        preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $address, $matches);

        $ids = $matches[0];

        return $ids === [] ? null : $ids[count($ids) - 1];
    }

    /** A printable rendering of any caller-supplied address for error text. */
    private static function addressLabel(mixed $address): string
    {
        return is_string($address) ? $address : '{'.gettype($address).'}';
    }
}
