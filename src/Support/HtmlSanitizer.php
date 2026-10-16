<?php

namespace Zipavlin\Redline\Support;

use HTMLPurifier_Config;

/**
 * The single HTML allowlist for report/comment bodies, shared by HTTP and MCP
 * writes through the Actions layer (previously a private base-controller
 * method).
 *
 * Allowlist-sanitize rich-text HTML on write, so stored content is safe
 * regardless of how it is later rendered (the dashboard/widget render bodies
 * with v-html). The allowlist matches the TipTap editor's enabled marks/nodes;
 * everything else is stripped.
 */
final class HtmlSanitizer
{
    public static function clean(?string $html): string
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
