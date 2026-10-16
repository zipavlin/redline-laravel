<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Actions\ScreenshotActions;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;

class ScreenshotController extends Controller
{
    public function __construct(private readonly ScreenshotActions $actions) {}

    /**
     * GET /redline/api/report/{report}/screenshot
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $screenshot = $this->actions->get($this->authUser($request), $report);

        if ($screenshot === null) {
            return response()->json(['message' => 'Screenshot not found.'], 404);
        }

        return response()->json($screenshot->toArray());
    }

    /**
     * POST /redline/api/report/{report}/screenshot
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $screenshot = $this->actions->store($this->authUser($request), $report, $request);

        if ($screenshot === null) {
            return response()->json(['message' => 'Screenshot already exists for this report.'], 409);
        }

        return response()->json($screenshot->toArray(), 201);
    }

    /**
     * GET /redline/api/report/{report}/screenshot/{screenshot}/binary
     */
    public function binary(Request $request, Report $report, Screenshot $screenshot): BinaryFileResponse|JsonResponse
    {
        $file = $this->actions->binary($this->authUser($request), $report, $screenshot);

        if ($file === null) {
            return response()->json(['message' => 'Screenshot not found.'], 404);
        }

        return $file;
    }
}
