<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Base\Report;
use Zipavlin\Redline\Models\Base\Video;
use Zipavlin\Redline\Providers\Storage\StorageProvider;

class VideoController extends Controller
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * GET /redline/api/report/{report}/video
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::VIEW);

        $video = app(Video::class)->where('report_id', $report->id)->first();

        if (! $video) {
            return response()->json(['message' => 'Video not found.'], 404);
        }

        return response()->json($video->toArray());
    }

    /**
     * POST /redline/api/report/{report}/video
     *
     * Upload WebM. Max 50 MB.
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->checkFeature(Feature::VIDEOS);
        $this->checkUser($request, Operation::UPDATE, $report);

        $existing = app(Video::class)->where('report_id', $report->id)->first();

        if ($existing) {
            return response()->json(['message' => 'Video already exists for this report.'], 409);
        }

        $request->validate([
            'video' => ['required', 'file', 'max:51200'],
        ]);

        $path = $this->storage->storeVideo($request->file('video'), Str::uuid());
        $video = app(Video::class)->create(['report_id' => $report->id, 'path' => $path]);

        return response()->json($video->fresh()->toArray(), 201);
    }

    /**
     * GET /redline/api/report/{report}/video/{video}/binary
     */
    public function binary(Request $request, Report $report, Video $video): BinaryFileResponse
    {
        $this->checkUser($request, Operation::VIEW);
        $this->checkParent($report, $video);

        return response()->file($this->storage->videoPath().DIRECTORY_SEPARATOR.$video->path);
    }
}
