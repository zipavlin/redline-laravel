<?php

namespace Zipavlin\Redline\Http\Controllers\Api;

use HTMLPurifier_Config;
use Illuminate\Http\Request;
use Zipavlin\Redline\Enums\Feature;
use Zipavlin\Redline\Enums\Operation;
use Zipavlin\Redline\Models\Comment;
use Zipavlin\Redline\Models\Report;
use Zipavlin\Redline\Models\Screenshot;
use Zipavlin\Redline\Models\User;
use Zipavlin\Redline\Models\Video;

class Controller extends \Illuminate\Routing\Controller
{
    protected function authUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    protected function checkUser(Request $request, Operation $operation, null|Report|Comment $model = null): void
    {
        if (! $request->user() || ! $request->user()->can($operation, $model)) {
            abort(403, 'Forbidden.');
        }
    }

    protected function checkParent(Report|Screenshot $parent, Comment|Screenshot|Video $child): void
    {
        if ($child->report_id !== $parent->id) {
            abort(404);
        }
    }

    protected function checkFeature(Feature $feature): void
    {
        if (in_array($feature, config('redline.disabled', []))) {
            abort(403, 'Feature disabled.');
        }
    }

    /**
     * Allowlist-sanitize rich-text HTML (report/comment bodies) on write, so
     * stored content is safe regardless of how it is later rendered (the
     * dashboard/widget render bodies with v-html). The allowlist matches the
     * TipTap editor's enabled marks/nodes; everything else is stripped.
     */
    protected function sanitizeHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        static $purifier = null;

        if ($purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            // No custom HTMLDefinition → definition caching is unnecessary and
            // would otherwise write serialized files to the vendor dir.
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p,br,strong,em,s,a[href|title],ul,ol,li');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            // Force links to open safely (target=_blank + rel=noopener noreferrer).
            $config->set('HTML.TargetBlank', true);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);

            $purifier = new \HTMLPurifier($config);
        }

        return $purifier->purify($html);
    }
}
