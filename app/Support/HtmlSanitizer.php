<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Strips dangerous markup (script tags, event-handler attributes, javascript:
 * URIs, etc.) out of rich-text HTML before it is stored.
 *
 * Anywhere user-authored HTML ends up rendered with v-html for someone other
 * than its author (e.g. a Todo description shown to whoever it's assigned
 * to), it must be passed through here first on the way in. The summernote
 * editor's own escaping only happens in that one browser; anyone posting
 * directly to the endpoint bypasses it entirely, so the server has to be the
 * one enforcing this.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        // Avoid needing a writable cache directory for the def cache.
        $config->set('Cache.DefinitionImpl', null);

        // Allowlist matches what the summernote toolbars in this app expose
        // (bold/underline/color/fontname, lists, headings, tables, links,
        // images) — nothing that can carry script (no <script>, no on*
        // attributes, no style beyond a safe property list).
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'div', 'span[style]',
            'b', 'strong', 'i', 'em', 'u', 's', 'strike',
            'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
            'a[href|title|target|rel]',
            'img[src|alt|width|height|style]',
            'table', 'thead', 'tbody', 'tr', 'td', 'th',
            'font[color|face|size]',
        ]));

        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'font-family', 'font-size',
            'text-align', 'width', 'max-width', 'height',
        ]);

        // No javascript:/data: URIs — links and images may only point at
        // real http(s) resources (or mailto: for links).
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);

        return self::$purifier = new HTMLPurifier($config);
    }
}
