<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side cleaning for staff-authored rich text that is later rendered
 * raw ({!! !!} / v-html), e.g. storefront CMS pages.
 *
 * Keeps what the Quill 2 editor (RichTextEditor.vue) produces — headings,
 * lists (incl. Quill 2's <li data-list>), colours, ql-* alignment classes,
 * links, images (incl. pasted data: images), YouTube/Vimeo embeds — and drops
 * scripts, event handlers, javascript: URLs, forms, objects, etc.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    /** Clean every value of a locale => html map (translation columns). */
    public static function cleanMap($map)
    {
        if (! is_array($map)) {
            return $map;
        }

        foreach ($map as $locale => $html) {
            $map[$locale] = is_string($html) ? self::clean($html) : $html;
        }

        return $map;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        // HTMLPurifier's data: scheme only knows png/jpeg/gif; pasted WebP
        // images would otherwise be removed silently. It still verifies the
        // decoded bytes really are an image of an allowed type.
        $dataScheme = new \HTMLPurifier_URIScheme_data();
        $dataScheme->allowed_types['image/webp'] = true;
        \HTMLPurifier_URISchemeRegistry::instance()->register('data', $dataScheme);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Keep alt empty instead of copying the file name — for data: images
        // that "name" is the whole base64 payload, doubling the page size.
        $config->set('Attr.DefaultImageAlt', '');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');

        $def = $config->getHTMLDefinition(true);
        $def->addAttribute('li', 'data-list', 'Enum#ordered,bullet,checked,unchecked');
        $def->addAttribute('iframe', 'allowfullscreen', 'Bool#allowfullscreen');

        return self::$purifier = new HTMLPurifier($config);
    }
}
