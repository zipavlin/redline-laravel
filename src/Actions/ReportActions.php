<?php

namespace Zipavlin\Redline\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Enums\Priority;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Enums\Status;
use Zipavlin\Redline\Exceptions\InvalidStatusTransition;
use Zipavlin\Redline\Http\Query\ResourceQuery;
use Zipavlin\Redline\Mail\NewReportMail;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;
use Zipavlin\Redline\Support\HtmlSanitizer;

/**
 * THE report read+write path, shared by the HTTP controllers and the MCP tools
 *
 * The class is HTTP-agnostic: it never calls abort() directly. Failures come
 * back as package domain exceptions (see the Exceptions namespace) or Laravel
 * ValidationException, which the redline API group renders to today's
 * 403/404/422 envelopes and a tool maps to an actionable error message.
 */
class ReportActions extends Action
{
    public function __construct(private readonly StorageProvider $storage) {}

    /**
     * The grammar-driven collection read (HTTP GET /report, MCP report_list):
     * paginated / sorted / filtered, shaped by `include[]`. All authenticated
     * roles see all reports (no ownership scoping) unless `filter[mine]=1` is
     * sent. The caller's user is read from the request itself - MCP tools pass
     * a synthetic request carrying their authenticated caller (see
     * Mcp\Concerns\CanBuildQueryRequests).
     */
    public function list(Request $request): array
    {
        $this->assertAllowed($this->requestUser($request), Operation::LIST);

        return Report::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::INCLUDE, QueryCapability::SORT, QueryCapability::PAGINATE)
            ->parse()
            ->list();
    }

    /**
     * The aggregate read (HTTP GET /report/aggregate). `filter[...]` scopes
     * which rows the aggregate is computed over.
     */
    public function aggregate(Request $request): array
    {
        $this->assertAllowed($this->requestUser($request), Operation::LIST);

        return Report::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::AGGREGATE, QueryCapability::GROUP)
            ->parse()
            ->aggregate();
    }

    /**
     * A single report shaped by `include[]` (HTTP GET /report/{report}). Pure
     * read: `browser` is developer-only and has `user_agent` stripped
     * regardless of role. The HTTP endpoint's new -> noticed auto-acknowledge
     * is NOT part of this method - each surface keeps its guard and calls
     * updateStatus() itself (ReportController::show, the report_get tool), and
     * show() runs includeQuery() first so the read is validated before the
     * write.
     */
    public function get(Request $request, Report $report): array
    {
        return $this->includeQuery($request, $this->requestUser($request))->hydrate($report);
    }

    /**
     * The VIEW gate plus the parsed `include[]` query of a single-report read,
     * on its own so a caller that mutates AROUND the read can validate the read
     * FIRST: ReportController::show acknowledges a new report, and a request
     * that is refused or 422s on `include[]` must never leave that write
     * behind.
     */
    public function includeQuery(Request $request, User $user): ResourceQuery
    {
        $this->assertAllowed($user, Operation::VIEW);

        return Report::resourceQuery($request)
            ->use(QueryCapability::INCLUDE)
            ->parse();
    }

    /**
     * File a new report (HTTP POST /report and MCP report_create).
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, array $payload): Report
    {
        $this->assertAllowed($user, Operation::CREATE);

        $validated = Validator::make($payload, self::storeRules())->validate();

        if (array_key_exists('due_at', $validated)) {
            $validated['due_at'] = self::parseDueAt($validated['due_at']);
        }

        $validated['body'] = HtmlSanitizer::clean((string) $validated['body']);

        $report = app(Report::class)->create(array_merge($validated, [
            'author_id' => $user->id,
            'status' => Status::NEW->value,
        ]));

        $this->notifyNewReport($report, $user);

        return $report->fresh();
    }

    /**
     * Rewrite a report's body (HTTP PATCH /report/{report}). Author only -
     * developers/admins must not rewrite someone else's body.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateBody(Report $report, User $user, array $payload): Report
    {
        $this->assertAllowed($user, Operation::EDIT, $report);

        $validated = Validator::make($payload, ['body' => ['required', 'string']])->validate();
        $validated['body'] = HtmlSanitizer::clean((string) $validated['body']);

        $report->update($validated);

        return $report->fresh();
    }

    /**
     * Move a report along the status lifecycle (HTTP PATCH
     * /report/{report}/status and MCP report_update_status).
     *
     * Order matters and is shared by both surfaces: role gate, then status
     * validation (422), then the transition check (403), then - with the
     * previous status captured BEFORE the update - the Commit side effect.
     *
     * @param  mixed  $statusValue  the raw wire string
     */
    public function updateStatus(Report $report, User $user, mixed $statusValue): Report
    {
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $validated = Validator::make(['status' => $statusValue], [
            'status' => ['required', Rule::enum(Status::class)],
        ])->validate();

        $target = Status::from($validated['status']);
        $previous = Status::from($report->status);

        if (! $report->canUserSetStatus($user, $target)) {
            throw new InvalidStatusTransition($previous, $target, $report->allowedStatusTargets($user));
        }

        $report->update(['status' => $target->value]);

        $this->assignAcceptor($report, $previous, $target, $user);

        return $report->fresh();
    }

    /**
     * Set the priority (HTTP PATCH /report/{report}/priority). The UPDATE gate
     * lets owners and developers through. Unlike status there is no transition
     * graph - any of the four levels is settable at any time.
     */
    public function updatePriority(Report $report, User $user, Request $request): Report
    {
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $validated = $request->validate([
            'priority' => ['required', Rule::enum(Priority::class)],
        ]);

        $report->update(['priority' => $validated['priority']]);

        return $report->fresh();
    }

    /**
     * Set or clear the triage deadline (HTTP PATCH /report/{report}/due_at).
     * The field is `present` (an absent key is a 422) but `nullable`: a
     * timestamp sets the deadline, an explicit `null` clears it. Any accepted
     * RFC3339 offset is normalized to UTC before it touches storage, so the
     * client's local wall clock is only ever a wire format.
     */
    public function updateDueAt(Report $report, User $user, Request $request): Report
    {
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $validated = $request->validate([
            'due_at' => ['present', 'nullable', 'date'],
        ]);

        $report->update(['due_at' => self::parseDueAt($validated['due_at'])]);

        return $report->fresh();
    }

    /**
     * Relocate the pin (HTTP POST /report/{report}/move). Own or developer+.
     */
    public function updatePin(Report $report, User $user, Request $request): Report
    {
        $this->assertAllowed($user, Operation::UPDATE, $report);

        $validated = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'scroll_x' => ['nullable', 'numeric'],
            'scroll_y' => ['nullable', 'numeric'],
            'prefer_selector' => ['nullable', 'boolean'],
        ]);

        $report->update(['pin' => $validated]);

        return $report->fresh();
    }

    /**
     * Delete a report and its associated media files (member rows cascade in
     * the database). Own or admin.
     */
    public function delete(Report $report, User $user): void
    {
        $this->assertAllowed($user, Operation::DELETE, $report);

        $report->load(['screenshot', 'video']);

        $this->storage->deleteScreenshot($report->screenshot?->path);
        $this->storage->deleteVideo($report->video?->path);

        $report->delete();
    }

    /**
     * The POST /report validation rule set. On HTTP these run through the
     * error bag exactly as `$request->validate()` did; MCP turns the same
     * ValidationException into an actionable 422-equivalent (contract section
     * 4).
     *
     * @return array<string, mixed>
     */
    public static function storeRules(): array
    {
        return [
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
        ];
    }

    /**
     * Parse an accepted RFC3339-style timestamp into the UTC instant stored in
     * the deadline column. A null passes straight through so "clear" stays a
     * no-op.
     */
    public static function parseDueAt(mixed $dueAt): ?Carbon
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

        if (! $user->isEligibleMember || $report->isAuthoredBy($user->id)) {
            return;
        }

        try {
            $report->members()->syncWithoutDetaching([$user->id]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Email developers/admins (except the reporter) that a new report was
     * filed. No-op unless a mailer is configured; failures are swallowed (see
     * RedlineServiceProvider::mail()).
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

        $title = Str::limit(trim(strip_tags((string) $report->body)), 80) ?: 'Untitled report';
        $url = rtrim($this->storage->dashboardUrl(), '/').'?redline-report='.$report->id;

        RedlineServiceProvider::mail($recipients, new NewReportMail($reporter->name, $title, $url));
    }
}
