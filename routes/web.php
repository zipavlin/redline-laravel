<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

Route::prefix(config('redline.path', 'redline'))
    ->name('redline.')
    ->middleware(SubstituteBindings::class)
    ->group(function () {
        // Inject Vite URL and version into all views - we use both in the layout.
        View::share('vite', RedlineServiceProvider::getViteUrl('dashboard'));
        View::share('version', RedlineServiceProvider::getVersion());

        // Dashboard SPA shell - serves the Vue app at the redline root and all
        // sub-paths EXCEPT the /api subtree (handled by routes/api.php). vue-router
        // routes client-side; the API enforces auth.
        Route::get('{any?}', function () {
            $storage = app(StorageProvider::class);

            // Capability flags (features/auth/mail) moved to GET /redline/api/bootstrap,
            // which the dashboard fetches at mount - the shell only knows its URLs plus
            // the CSRF cookie name the embed contract needs synchronously (also in bootstrap).
            return view('redline::dashboard', [
                'url' => $storage->apiUrl(),                              // API base (may be a different host for Cloud)
                'base' => parse_url($storage->dashboardUrl(), PHP_URL_PATH) ?: '/', // vue-router base path
                'csrfCookie' => config('redline.auth.csrf_cookie', 'redline_csrf'), // double-submit CSRF cookie name
            ]);
        })->where('any', '^(?!api(/|$)).*$')->name('dashboard');
    });
