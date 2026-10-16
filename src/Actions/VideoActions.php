<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\Support\MediaFile;

/**
 * THE video path (read, upload, binary serve) - verbatim port of
 * VideoController. Same null-outcome contract as ScreenshotActions: a null
 * model reports "not found" / "already exists" through the controller's data
 * envelope; the gates refuse through the shared exceptions.
 */
class VideoActions extends Action
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * The report's one video (HTTP GET /report/{report}/video). Null when the
     * report has none (the controller answers its 404 data envelope).
     */
    public function get(User $user, Report $report): ?Video
    {
        $this->assertAllowed($user, Operation::VIEW);

        return app(Video::class)->where('report_id', $report->id)->first();
    }

    /**
     * Upload a WebM for the report (max 50 MB). Null when the report already
     * has a video (the controller answers its 409 data envelope).
     */
    public function store(User $user, Report $report, Request $request): ?Video
    {
        $this->assertFeature(Feature::VIDEOS);
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $existing = app(Video::class)->where('report_id', $report->id)->first();

        if ($existing) {
            return null;
        }

        $request->validate([
            'video' => ['required', 'file', 'max:51200'],
        ]);

        $path = $this->storage->storeVideo($request->file('video'), Str::uuid());

        return app(Video::class)->create(['report_id' => $report->id, 'path' => $path]);
    }

    /**
     * Serve the stored WebM file to the caller (HTTP GET
     * /report/{report}/video/{video}/binary).
     */
    public function binary(User $user, Report $report, Video $video): ?BinaryFileResponse
    {
        $this->assertAllowed($user, Operation::VIEW);
        $this->assertRelationship($report, $video);

        $plainName = MediaFile::plainName($video->path);

        if ($plainName === null) {
            return null;
        }

        return response()->file($this->absolutePath($plainName));
    }

    /**
     * The absolute path of a video file inside the video directory.
     */
    public function absolutePath(string $plainName): string
    {
        return $this->storage->videoPath().DIRECTORY_SEPARATOR.$plainName;
    }
}
