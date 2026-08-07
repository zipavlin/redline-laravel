<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Zipavlin\Redline\Enums\QueryCapability;
use Zipavlin\Redline\Enums\Role;
use Zipavlin\Redline\Mail\UserInvitationMail;
use Zipavlin\Redline\Models\Base\User;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class UserController extends Controller
{
    /**
     * GET /redline/api/user (admin only)
     *
     * Grammar-driven list (filter[]/include[]/sort[]/paginate[] — see
     * User::querySchema()). Tab counts are fetched separately via /user/aggregate
     * so they honour search/role filters but NOT the status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $this->checkAdmin($request);

        $result = User::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::INCLUDE, QueryCapability::SORT, QueryCapability::PAGINATE)
            ->parse()
            ->list();

        return response()->json($result);
    }

    /**
     * GET /redline/api/user/aggregate (admin only)
     *
     * Tab counts (all/active/pending) honour search + role filters but NOT
     * the status filter, so tabs don't jump as you switch them. Sent from
     * the frontend as:
     *   /user/aggregate?aggregate[status:count]=approved,pending&filter[search]=...&filter[role]=...
     */
    public function aggregate(Request $request): JsonResponse
    {
        $this->checkAdmin($request);

        $query = User::resourceQuery($request)
            ->use(QueryCapability::FILTER, QueryCapability::AGGREGATE)
            ->parse();

        return response()->json($query->aggregate());
    }

    /**
     * GET /redline/api/user/{user} (admin only)
     *
     * Single user shaped by include[] — stats and
     * last_active_at are include-gated (show-only). The base row is
     * id/status/created_at.
     */
    public function show(Request $request, $user): JsonResponse
    {
        $this->checkAdmin($request);

        $model = app(User::class)->findOrFail($user);

        $query = User::resourceQuery($request)->use(QueryCapability::INCLUDE)->parse();

        return response()->json($query->hydrate($model));
    }

    /**
     * POST /redline/api/user (admin only)
     *
     * With a configured mailer the initial password is optional — omitting it
     * emails a signed set-password invitation. Without mail the admin must set
     * a password (host apps may have no mailer).
     */
    public function store(Request $request): JsonResponse
    {
        $this->checkAdmin($request);

        $mailConfigured = RedlineServiceProvider::mailConfigured();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('redline.redline_users', 'email')],
            'role' => ['required', Rule::in([Role::REPORTER->value, Role::DEVELOPER->value, Role::ADMIN->value])],
            'password' => $mailConfigured
                ? ['nullable', 'string', 'min:8']
                : ['required', 'string', 'min:8'],
        ]);

        $password = $validated['password'] ?? null;

        $user = app(User::class)->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => Role::from($validated['role']),
            'password' => $password ? Hash::make($password) : null,
            'status' => 'approved',
        ]);

        $this->audit($request, 'user.store', $user->id);

        $invitationSent = $password ? false : $this->sendInvitation($user);

        return response()->json([
            ...$this->row($user->loadCount(['reports', 'comments'])),
            'invitation_sent' => $invitationSent,
        ], 201);
    }

    /**
     * Email a signed "set your password" link pointing at the dashboard reset
     * page. Reuses the reset-password signed route so the SPA's existing reset
     * flow works unchanged. Returns true once an invitation was dispatched.
     */
    private function sendInvitation(User $user): bool
    {
        $signed = URL::temporarySignedRoute(
            'redline.auth.reset-password',
            now()->addHour(),
            ['email' => $user->email],
        );

        // The SPA re-POSTs the signed query to the reset endpoint, so the link
        // can point at the dashboard reset page carrying that same query.
        $query = parse_url($signed, PHP_URL_QUERY);
        $base = rtrim(app(StorageProvider::class)->dashboardUrl(), '/');

        RedlineServiceProvider::mail($user->email, new UserInvitationMail($user->name, $base.'/reset-password?'.$query));

        return true;
    }

    /**
     * PATCH /redline/api/user/{user} (admin only)
     */
    public function update(Request $request, $user): JsonResponse
    {
        $this->checkAdmin($request);

        $model = app(User::class)->findOrFail($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('redline.redline_users', 'email')->ignore($model->id)],
            'role' => ['sometimes', Rule::in([Role::REPORTER->value, Role::DEVELOPER->value, Role::ADMIN->value])],
            'status' => ['sometimes', Rule::in(['approved', 'pending'])],
        ]);

        if (isset($validated['role'])) {
            $validated['role'] = Role::from($validated['role']);
        }

        // Never let the last admin be demoted or deactivated — otherwise the
        // instance can be locked out of all admin-only actions.
        $demotes = isset($validated['role']) && $validated['role'] !== Role::ADMIN;
        $deactivates = isset($validated['status']) && $validated['status'] !== 'approved';
        if (($demotes || $deactivates) && $this->isLastAdmin($model)) {
            abort(422, 'Cannot demote or deactivate the last remaining admin.');
        }

        $model->update($validated);

        $this->audit($request, 'user.update', $model->id);

        return response()->json($this->row($model->fresh()->loadCount(['reports', 'comments'])));
    }

    /**
     * DELETE /redline/api/user/{user} (admin only)
     */
    public function destroy(Request $request, $user): Response|JsonResponse
    {
        $this->checkAdmin($request);

        $model = app(User::class)->findOrFail($user);

        if ($this->isLastAdmin($model)) {
            abort(422, 'Cannot delete the last remaining admin.');
        }

        $model->delete();

        $this->audit($request, 'user.delete', $model->id);

        return response()->noContent();
    }

    /**
     * POST /redline/api/user/{user}/approve (admin only)
     */
    public function approve(Request $request, $user): JsonResponse
    {
        $this->checkAdmin($request);

        $model = app(User::class)->findOrFail($user);
        $model->update(['status' => 'approved']);

        $this->audit($request, 'user.approve', $model->id);

        return response()->json($this->row($model->fresh()->loadCount(['reports', 'comments'])));
    }

    /**
     * POST /redline/api/user/{user}/reset-password (admin only)
     */
    public function resetPassword(Request $request, $user): JsonResponse
    {
        $this->checkAdmin($request);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $model = app(User::class)->findOrFail($user);
        $model->update(['password' => Hash::make($validated['password'])]);

        // Invalidate the user's outstanding JWTs after an admin resets their password.
        $model->bumpTokenVersion();

        $this->audit($request, 'user.reset-password', $model->id);

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The canonical index-row shape for a user.
     */
    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status,
            'reports_count' => (int) ($user->reports_count ?? $user->reports()->count()),
            'comments_count' => (int) ($user->comments_count ?? $user->comments()->count()),
        ];
    }

    private function checkAdmin(Request $request): void
    {
        if (! $this->authUser($request)->isAdmin) {
            abort(403, 'Admin access required.');
        }
    }

    /**
     * True when $model is an admin and no other admin remains.
     */
    private function isLastAdmin(User $model): bool
    {
        return $model->role === Role::ADMIN
            && app(User::class)->where('role', Role::ADMIN)->where('id', '!=', $model->id)->doesntExist();
    }

    /**
     * Minimal audit trail for admin user-management actions.
     */
    private function audit(Request $request, string $action, string $targetId): void
    {
        logger()->info('redline.audit', [
            'action' => $action,
            'actor_id' => $request->user()?->id,
            'target_id' => $targetId,
            'ip' => $request->ip(),
        ]);
    }
}
