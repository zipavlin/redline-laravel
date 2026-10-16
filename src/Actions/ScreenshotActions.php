<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\Support\MediaFile;

/**
 * THE screenshot path (read, upload, binary serve) - verbatim port of
 * ScreenshotController. The "not found" / "already exists" outcomes return a
 * null model so the controller keeps shaping their data envelopes; the
 * authorization and feature gates refuse through the shared exceptions.
 */
class ScreenshotActions extends Action
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * The report's one screenshot (HTTP GET /report/{report}/screenshot).
     * Null when the report has none (the controller answers its 404 data
     * envelope).
     */
    public function get(User $user, Report $report): ?Screenshot
    {
        $this->assertAllowed($user, Operation::VIEW);

        return app(Screenshot::class)
            ->where('report_id', $report->id)
            ->first();
    }

    /**
     * Upload a PNG for the report (max 5 MB). Null when the report already
     * has a screenshot (the controller answers its 409 data envelope) - the
     * report-owned upload stays its one media row.
     */
    public function store(User $user, Report $report, Request $request): ?Screenshot
    {
        $this->assertFeature(Feature::SCREENSHOTS);
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $existing = app(Screenshot::class)->where('report_id', $report->id)->first();

        if ($existing) {
            return null;
        }

        $request->validate([
            'screenshot' => ['required', 'file', 'mimes:png', 'max:5120'],
        ]);

        $id = (string) Str::orderedUuid();
        $path = $this->storage->storeScreenshot($request->file('screenshot'), $id);

        return app(Screenshot::class)->create(['id' => $id, 'report_id' => $report->id, 'path' => $path]);
    }

    /**
     * Serve the stored PNG file to the caller (HTTP GET
     * /report/{report}/screenshot/{screenshot}/binary). Null when the row's
     * stored name is unusable, so the controller answers its own 404 envelope -
     * the same null-outcome contract get() and store() keep.
     */
    public function binary(User $user, Report $report, Screenshot $screenshot): ?BinaryFileResponse
    {
        $this->assertAllowed($user, Operation::VIEW);
        $this->assertRelationship($report, $screenshot);

        $plainName = MediaFile::plainName($screenshot->path);

        if ($plainName === null) {
            return null;
        }

        return response()->file($this->absolutePath($plainName));
    }

    /**
     * The absolute path of a screenshot file inside the screenshot directory.
     */
    public function absolutePath(string $plainName): string
    {
        return $this->storage->screenshotPath().DIRECTORY_SEPARATOR.$plainName;
    }
}
