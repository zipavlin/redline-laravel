<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Zipavlin\Redline\Providers\Auth\AuthProvider;
use Zipavlin\Redline\RedlineServiceProvider;

class BootstrapController extends Controller
{
    public function __construct(protected readonly AuthProvider $auth) {}

    /**
     * GET /redline/api/bootstrap - public read-only capability manifest.
     *
     * The widget/dashboard fetch this at mount to learn which features and auth
     * capabilities are enabled. It is intentionally public (no auth, no CSRF):
     * the widget boots on every host page load, before any login exists. No
     * secrets or request data - only capability flags and config names.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'version' => 1,
            'features' => RedlineServiceProvider::getFeatures(),
            'capabilities' => [
                'guest_reporting' => (bool) config('redline.auth.guest', false),
                'auth' => [
                    'can_login' => $this->auth->canLogin(),
                    'can_register' => $this->auth->canRegister(),
                    'fields' => $this->auth->fields(),
                    'can_select_role' => $this->auth->canSelectRole(),
                    'requires_approval' => $this->auth->requiresApproval(),
                    'requires_email_verification' => $this->auth->requiresEmailVerification(),
                ],
                'mail' => [
                    'configured' => RedlineServiceProvider::mailConfigured(),
                ],
            ],
            'csrf' => [
                'cookie' => config('redline.auth.csrf_cookie', 'redline_csrf'),
                'header' => config('redline.auth.csrf_header', 'X-Redline-CSRF'),
            ],
        ]);
    }
}
