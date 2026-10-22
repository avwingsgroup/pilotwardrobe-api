<?php

declare(strict_types=1);

namespace App\Services\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises CMS HTML on write (`pages.body_html`) so the SPA can render it with v-html safely:
 * safe elements only, no scripts/styles/event handlers, links limited to https/mailto.
 */
final class HtmlSanitizer
{
    private SymfonySanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowMediaSchemes(['https'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropElement('style')
            ->dropElement('script')
            ->withMaxInputLength(200_000);

        $this->sanitizer = new SymfonySanitizer($config);
    }

    public function clean(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
