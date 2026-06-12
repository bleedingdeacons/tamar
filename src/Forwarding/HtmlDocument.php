<?php

declare(strict_types=1);

namespace Tamar\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared libxml/encoding handling for turning the upstream's generated
 * HTML into a {@see \DOMDocument}.
 *
 * Both the page parser and the login-flow helper need to parse Tamar's
 * markup the same way: the upstream serves UTF-8 with no XML
 * declaration, so the `<?xml encoding="utf-8"?>` prefix forces
 * DOMDocument to read it as UTF-8 rather than guessing Latin-1; the
 * `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` flags stop libxml from
 * wrapping the fragment in implied `<html><body>`/doctype nodes; and
 * the warning/error suppression keeps the noisy libxml diagnostics that
 * generated HTML reliably produces out of the global error buffer.
 *
 * Centralising it here means the encoding and flag handling lives in
 * one place, so the two call sites can't drift apart.
 */
final class HtmlDocument
{
    /**
     * Parse an HTML fragment into a DOMDocument, restoring the caller's
     * libxml internal-error setting afterwards so we don't leak global
     * state.
     */
    public static function load(string $html): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="utf-8"?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }
}
