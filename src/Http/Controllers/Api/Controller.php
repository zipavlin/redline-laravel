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
        $purifier ??= self::makePurifier();

        return $purifier->purify($html);
    }

    private static function makePurifier(): \HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        // A custom HTMLDefinition is registered below (mention data-*
        // attributes), so caching stays off to avoid writing serialized files
        // to the vendor dir.
        $config->set('Cache.DefinitionImpl', null);
        // `span` carries the TipTap mention node; only its mention attributes
        // are allowed, so a forged span cannot smuggle on*/style/script.
        $config->set('HTML.Allowed', 'p,br,strong,em,s,a[href|title],ul,ol,li,span[data-type|data-id|data-label|data-mention-suggestion-char|class]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // Force links to open safely (target=_blank + rel=noopener noreferrer).
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        // HTMLPurifier's default HTML 4.01 doctype has no `data-*` attributes,
        // so listing them in HTML.Allowed alone still drops them (with a
        // warning); they must be registered on the element definition.
        $definition = $config->getHTMLDefinition(true);
        $definition->addAttribute('span', 'data-type', 'Text');
        $definition->addAttribute('span', 'data-id', 'Text');
        $definition->addAttribute('span', 'data-label', 'Text');
        $definition->addAttribute('span', 'data-mention-suggestion-char', 'Text');

        return new \HTMLPurifier($config);
    }
}
