<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Actions\VideoActions;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Video;

class VideoController extends Controller
{
    public function __construct(private readonly VideoActions $actions) {}

    /**
     * GET /redline/api/report/{report}/video
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $video = $this->actions->get($this->authUser($request), $report);

        if ($video === null) {
            return response()->json(['message' => 'Video not found.'], 404);
        }

        return response()->json($video->toArray());
    }

    /**
     * POST /redline/api/report/{report}/video
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $video = $this->actions->store($this->authUser($request), $report, $request);

        if ($video === null) {
            return response()->json(['message' => 'Video already exists for this report.'], 409);
        }

        return response()->json($video->fresh()->toArray(), 201);
    }

    /**
     * GET /redline/api/report/{report}/video/{video}/binary
     */
    public function binary(Request $request, Report $report, Video $video): BinaryFileResponse|JsonResponse
    {
        $file = $this->actions->binary($this->authUser($request), $report, $video);

        if ($file === null) {
            return response()->json(['message' => 'Video not found.'], 404);
        }

        return $file;
    }
}
