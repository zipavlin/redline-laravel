<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Enums\Priority;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Http\Query\QuerySchema;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Mail\NewReportMail;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

/**
 * @see QuerySchema   the query grammar wire format (filter[]/include[]/sort[]/
 *                    paginate[]/aggregate[]) - the full reference lives there,
 *                    since the grammar is resource-agnostic, not report-specific.
 * @see ResourceQuery parsing + validation + apply (entry point: Report::resourceQuery())
 */
class ReportController extends Controller
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * GET /redline/api/report
     *
     * Paginated / sorted / filtered list, shaped by `include[]`. All authenticated
     * roles see all reports (no ownership scoping) unless `filter[mine]=1` is sent.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkUser($request, Operation::LIST);

        $query = Report::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::INCLUDE, QueryCapability::SORT, QueryCapability::PAGINATE)
            ->parse();

        return response()->json($query->list());
    }

    /**
     * GET /redline/api/report/aggregate
     *
     * See the grammar docblock above. `filter[...]` scopes which rows the
     * aggregate is computed over; typically sent WITHOUT `filter[status]` so
     * tab counts stay stable across tab switches.
     */
    public function aggregate(Request $request): JsonResponse
    {
        $this->checkUser($request, Operation::LIST);

        $query = Report::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::AGGREGATE, QueryCapability::GROUP)
            ->parse();

        return response()->json($query->aggregate());
    }

    /**
     * GET /redline/api/report/{report}
     *
     * Single report, shaped by `include[]`. `browser` is developer-only and has
     * `user_agent` stripped regardless of role.
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::VIEW);

        $query = Report::resourceQuery($request)->use(QueryCapability::INCLUDE)->parse();

        if ($report->status === Status::NEW->value && $this->authUser($request)->isDeveloper) {
            $report->update(['status' => Status::NOTICED->value]);
        }

        return response()->json($query->hydrate($report));
    }

    /**
     * POST /redline/api/report
     *
     * Submit a new report. Guest mode → attribute to shared Guest user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkUser($request, Operation::CREATE);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'location' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'pin' => ['nullable', 'array'],
            'pin.x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pin.y' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pin.prefer_selector' => ['nullable', 'boolean'],
            'pin.scroll_x' => ['nullable', 'numeric'],
            'pin.scroll_y' => ['nullable', 'numeric'],
            'element' => ['nullable', 'string'],
            'browser' => ['nullable', 'array'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('due_at', $validated)) {
            $validated['due_at'] = $this->normalizeDueAt($validated['due_at']);
        }

        $validated['body'] = $this->sanitizeHtml($validated['body']);

        $report = app(Report::class)->create(array_merge($validated, [
            'author_id' => $this->authUser($request)->id,
            'status' => Status::NEW->value,
        ]));

        $this->notifyNewReport($report, $this->authUser($request));

        return response()->json($report->fresh()->toArray(), 201);
    }

    /**
     * PATCH /redline/api/report/{report}
     *
     * Update body text only. Author only - developers/admins must not rewrite
     * someone else's body.
     */
    public function update(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::EDIT, $report);

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $validated['body'] = $this->sanitizeHtml($validated['body']);

        $report->update($validated);

        return response()->json($report->fresh()->toArray());
    }

    /**
     * DELETE /redline/api/report/{report}
     *
     * Delete report and its associated media files (member rows cascade in the
     * database). Own or admin.
     */
    public function destroy(Request $request, Report $report): Response|JsonResponse
    {
        $this->checkUser($request, Operation::DELETE, $report);

        $report->load(['screenshot', 'video']);

        $this->storage->deleteScreenshot($report->screenshot?->path);
        $this->storage->deleteVideo($report->video?->path);

        $report->delete();

        return response()->noContent();
    }

    /**
     * POST /redline/api/report/{report}/move
     *
     * Relocate the pin. Own or developer+.
     */
    public function move(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $validated = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'scroll_x' => ['nullable', 'numeric'],
            'scroll_y' => ['nullable', 'numeric'],
            'prefer_selector' => ['nullable', 'boolean'],
        ]);

        $report->update(['pin' => $validated]);

        return response()->json($report->fresh()->toArray());
    }

    /**
     * PATCH /redline/api/report/{report}/status
     *
     * Change status. The UPDATE gate lets owners and developers through; the
     * transition itself is then constrained by role and by the lifecycle chain
     * (see Report::allowedStatusTargets). Developers set noticed/progress/resolved;
     * a report owner may only Reopen (resolved->progress) or Confirm (resolved->done).
     */
    public function status(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(Status::class)],
        ]);

        $target = Status::from($validated['status']);
        $user = $this->authUser($request);

        if (! $report->canUserSetStatus($user, $target)) {
            abort(403, 'Invalid status transition.');
        }

        $previous = Status::from($report->status);

        $report->update(['status' => $target->value]);

        $this->assignAcceptor($report, $previous, $target, $user);

        $fresh = $report->fresh();

        return response()->json($fresh->toArray() + [
            'members' => $this->memberRows($fresh),
        ]);
    }

    /**
     * PATCH /redline/api/report/{report}/priority
     *
     * Set the priority. The UPDATE gate lets owners and developers through.
     * Unlike status there is no transition graph - any of the four levels is
     * settable at any time.
     */
    public function priority(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $validated = $request->validate([
            'priority' => ['required', Rule::enum(Priority::class)],
        ]);

        $report->update(['priority' => $validated['priority']]);

        return response()->json($report->fresh()->toArray());
    }

    /**
     * PATCH /redline/api/report/{report}/due_at
     *
     * Set or clear the triage deadline. The UPDATE gate lets owners and developers
     * through, like priority. The field is `present` (an absent key is a 422) but
     * `nullable`: a timestamp sets the deadline, an explicit `null` clears it. Any
     * accepted RFC3339 offset is normalized to UTC before it touches storage, so the
     * client's local wall clock is only ever a wire format.
     */
    public function dueAt(Request $request, Report $report): JsonResponse
    {
        $this->checkUser($request, Operation::UPDATE, $report);

        $validated = $request->validate([
            'due_at' => ['present', 'nullable', 'date'],
        ]);

        $report->update(['due_at' => $this->normalizeDueAt($validated['due_at'])]);

        return response()->json($report->fresh()->toArray());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Parse an accepted RFC3339-style timestamp into the UTC instant stored in the
     * deadline column. A null passes straight through so "clear" stays a no-op.
     */
    private function normalizeDueAt(mixed $dueAt): ?Carbon
    {
        return $dueAt === null ? null : Carbon::parse((string) $dueAt)->utc();
    }

    /**
     * The developer Claim action (`noticed -> progress`) records the acting
     * user as a collaborator so the board shows who picked the report up. The
     * author is never a member and an ineligible (pending/guest) actor is
     * skipped; the assignment is best-effort and must never fail the status
     * change it accompanies.
     */
    private function assignAcceptor(Report $report, Status $previous, Status $target, User $user): void
    {
        if ($previous !== Status::NOTICED || $target !== Status::PROGRESS) {
            return;
        }

        if (! $user->isApproved || $user->isGuest || $report->isAuthoredBy($user->id)) {
            return;
        }

        try {
            $report->members()->syncWithoutDetaching([$user->id]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The fresh report's members as `{id, name, initials}`, author excluded.
     *
     * @return array<int, array{id: string, name: string, initials: string}>
     */
    private function memberRows(Report $report): array
    {
        return $report->memberRows($report->members()->get());
    }

    /**
     * Email developers/admins (except the reporter) that a new report was filed.
     * No-op unless a mailer is configured; failures are swallowed (see mail()).
     */
    private function notifyNewReport(Report $report, User $reporter): void
    {
        $recipients = app(User::class)
            ->whereIn('role', [Role::DEVELOPER->value, Role::ADMIN->value])
            ->where('id', '!=', $reporter->id)
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        if (empty($recipients)) {
            return;
        }

        $title = Str::limit(trim(strip_tags($report->body)), 80) ?: 'Untitled report';
        $url = rtrim($this->storage->dashboardUrl(), '/').'?redline-report='.$report->id;

        RedlineServiceProvider::mail($recipients, new NewReportMail($reporter->name, $title, $url));
    }
}
