<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class ScreenshotController extends Controller
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * GET /redline/api/report/{report}/screenshot
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::VIEW);

        $screenshot = app(Screenshot::class)
            ->where('report_id', $report->id)
            ->first();

        if (! $screenshot) {
            return response()->json(['message' => 'Screenshot not found.'], 404);
        }

        return response()->json($screenshot->toArray());
    }

    /**
     * POST /redline/api/report/{report}/screenshot
     *
     * Upload PNG. Max 5 MB.
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->checkFeature(Feature::SCREENSHOTS);
        $this->checkUser($request, Operation::UPDATE, $report);

        $existing = app(Screenshot::class)->where('report_id', $report->id)->first();

        if ($existing) {
            return response()->json(['message' => 'Screenshot already exists for this report.'], 409);
        }

        $request->validate([
            'screenshot' => ['required', 'file', 'mimes:png', 'max:5120'],
        ]);

        $id = (string) Str::orderedUuid();
        $path = $this->storage->storeScreenshot($request->file('screenshot'), $id);

        $screenshot = app(Screenshot::class)->create(['id' => $id, 'report_id' => $report->id, 'path' => $path]);

        return response()->json($screenshot->toArray(), 201);
    }

    /**
     * GET /redline/api/report/{report}/screenshot/{screenshot}/binary
     */
    public function binary(Request $request, Report $report, Screenshot $screenshot): BinaryFileResponse
    {
        $this->checkUser($request, Operation::VIEW);
        $this->checkParent($report, $screenshot);

        return response()->file($this->storage->screenshotPath().DIRECTORY_SEPARATOR.$screenshot->path);
    }
}
