<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Zipavlin\Redline\Http\Controllers\Api\AuthController;
use Zipavlin\Redline\Http\Controllers\Api\BootstrapController;
use Zipavlin\Redline\Http\Controllers\Api\CommentController;
use Zipavlin\Redline\Http\Controllers\Api\MemberController;
use Zipavlin\Redline\Http\Controllers\Api\ReportController;
use Zipavlin\Redline\Http\Controllers\Api\ScreenshotController;
use Zipavlin\Redline\Http\Controllers\Api\UserController;
use Zipavlin\Redline\Http\Controllers\Api\VideoController;
use Zipavlin\Redline\Http\Middleware\RedlineActive;
use Zipavlin\Redline\Http\Middleware\RedlineApiAuth;
use Zipavlin\Redline\Http\Middleware\RedlineCsrf;

Route::prefix(config('redline.path', 'redline').'/api')
    // Guard enforced per request (RedlineActive) BEFORE authentication: a route
    // cache crosses environments, so an inactive guard must make this whole
    // surface behave as if the routes were absent (404) - see RedlineActive.
    // The default denial is 404; only the dashboard shell uses 403.
    ->middleware([RedlineActive::class, SubstituteBindings::class])
    ->group(function () {

        // -----------------------------------------------------------------------
        // Bootstrap - public capability manifest (widget/dashboard fetch at mount,
        // before any login, so it must stay outside the auth/CSRF middleware group)
        // -----------------------------------------------------------------------
        Route::middleware('throttle:redline-bootstrap')->group(function () {
            Route::get('bootstrap', [BootstrapController::class, 'index'])->name('redline.bootstrap');
        });

        // -----------------------------------------------------------------------
        // Auth - public endpoints (rate-limited to blunt brute-force/enumeration).
        //
        // The session stack is always on: a session-based provider resolves the host
        // Laravel user inside these routes, and route middleware lists are fixed at
        // registration time. The redline cookies stay EncryptCookies-exempt from the
        // provider boot, so they are not re-encrypted. No VerifyCsrfToken: redline's
        // own double-submit CSRF is the only CSRF scheme on these endpoints.
        // -----------------------------------------------------------------------
        Route::middleware([EncryptCookies::class, StartSession::class, 'throttle:redline-auth'])->group(function () {
            Route::post('auth/register', [AuthController::class, 'register'])->name('redline.auth.register');
            Route::post('auth/login', [AuthController::class, 'login'])->name('redline.auth.login');

            // The verification link is a signed URL; the signature is what proves email
            // ownership. It is validated inside AuthController::verify() via
            // $request->hasValidSignature(), so invalid/expired links can render a
            // friendly HTML page instead of Laravel's 403 page.
            Route::get('auth/verify', [AuthController::class, 'verify'])
                ->name('redline.auth.verify');

            // Self-service password reset: request a signed link, then set a new
            // password. The reset endpoint's signature proves the link is genuine.
            Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
                ->name('redline.auth.forgot-password');
            Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
                ->middleware('signed')
                ->name('redline.auth.reset-password');
        });

        // -----------------------------------------------------------------------
        // Protected routes
        // -----------------------------------------------------------------------
        Route::middleware([RedlineApiAuth::class, RedlineCsrf::class])->group(function () {

            Route::delete('auth', [AuthController::class, 'logout']);
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::get('auth/refresh', [AuthController::class, 'refresh']);

            // Users
            Route::get('user/aggregate', [UserController::class, 'aggregate']);
            Route::get('user', [UserController::class, 'index']);
            Route::post('user', [UserController::class, 'store']);
            Route::get('user/{user}', [UserController::class, 'show']);
            Route::patch('user/{user}', [UserController::class, 'update']);
            Route::delete('user/{user}', [UserController::class, 'destroy']);
            Route::post('user/{user}/approve', [UserController::class, 'approve']);
            Route::post('user/{user}/reset-password', [UserController::class, 'resetPassword']);

            // Reports
            Route::get('report/aggregate', [ReportController::class, 'aggregate']);
            Route::get('report', [ReportController::class, 'index']);
            Route::post('report', [ReportController::class, 'store']);
            Route::get('report/{report}', [ReportController::class, 'show']);
            Route::patch('report/{report}', [ReportController::class, 'updateBody']);
            Route::delete('report/{report}', [ReportController::class, 'destroy']);
            Route::post('report/{report}/move', [ReportController::class, 'updatePin']);
            Route::patch('report/{report}/status', [ReportController::class, 'updateStatus']);
            Route::patch('report/{report}/priority', [ReportController::class, 'updatePriority']);
            Route::patch('report/{report}/due_at', [ReportController::class, 'updateDueAt']);

            // Members
            Route::post('report/{report}/member', [MemberController::class, 'store']);
            Route::delete('report/{report}/member', [MemberController::class, 'destroy']);

            // Comments
            Route::get('report/{report}/comment', [CommentController::class, 'index']);
            Route::post('report/{report}/comment', [CommentController::class, 'store']);
            Route::patch('report/{report}/comment/{comment}', [CommentController::class, 'updateBody']);
            Route::delete('report/{report}/comment/{comment}', [CommentController::class, 'destroy']);

            // Screenshot
            Route::get('report/{report}/screenshot', [ScreenshotController::class, 'show']);
            Route::post('report/{report}/screenshot', [ScreenshotController::class, 'store']);
            Route::get('report/{report}/screenshot/{screenshot}/binary', [ScreenshotController::class, 'binary']);

            // Video
            Route::get('report/{report}/video', [VideoController::class, 'show']);
            Route::post('report/{report}/video', [VideoController::class, 'store']);
            Route::get('report/{report}/video/{video}/binary', [VideoController::class, 'binary']);

        });
    });
