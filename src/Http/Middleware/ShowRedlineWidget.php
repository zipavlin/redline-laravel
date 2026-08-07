<?php

namespace Zipavlin\Redline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Throwable;
use Zipavlin\Redline\Providers\Guard\GuardProvider;
use Zipavlin\Redline\Providers\Storage\StorageProvider;
use Zipavlin\Redline\RedlineServiceProvider;

readonly class ShowRedlineWidget
{
    public function __construct(
        private GuardProvider $guard,
        private StorageProvider $storage,
    ) {}

    /**
     * Inject the Redline widget script before </body> on HTML responses.
     *
     * Passes through unchanged when:
     *   - The guard is inactive (safety net - guard gates registration too)
     *   - The response is not a regular string-body response (binary, streamed…)
     *   - The Content-Type is not text/html
     *   - The response body contains no </body> tag
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $response = $next($request);

        if (! $this->guard->shouldShowWidget($request)) {
            return $response;
        }

        if (! $response instanceof Response) {
            return $response;
        }

        if (! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        $pos = strripos($content, '</body>');

        if ($pos === false) {
            return $response;
        }

        $response->setContent(
            substr($content, 0, $pos)
            .$this->buildScript()
            .substr($content, $pos)
        );

        return $response;
    }

    /**
     * Build the full widget injection: config, CSS, HTML, and scripts.
     *
     * @throws Throwable
     */
    private function buildScript(): string
    {
        return view(
            view: 'redline::widget',
            data: [
                'version' => RedlineServiceProvider::getVersion(),
                'url' => $this->storage->apiUrl(),
                'dashboard' => $this->storage->dashboardUrl(),
                'csrfCookie' => config('redline.auth.csrf_cookie', 'redline_csrf'),
                'vite' => RedlineServiceProvider::getViteUrl('widget'),
            ]
        )->render();
    }
}
