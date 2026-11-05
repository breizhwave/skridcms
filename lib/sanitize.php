<?php
declare(strict_types=1);

/**
 * Clean the inner HTML of an edited element before it is written into the page.
 * Only inline markup survives (strong, em, a, span, br…); anything else is unwrapped
 * (its text is kept). Links are checked, editor attributes removed, stray &nbsp; tidied.
 */

const SK_KEEP_TAGS = [
    'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd',
    'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
];
const SK_KEEP_ATTRS = ['class', 'id', 'lang', 'dir', 'title', 'translate', 'role', 'aria-label', 'aria-hidden'];
const SK_TAG_ATTRS = [
    'a' => ['href', 'target', 'rel', 'hreflang', 'download'],
    'time' => ['datetime'],
    'data' => ['value'],
    'q' => ['cite'],
    'del' => ['datetime', 'cite'],
    'ins' => ['datetime', 'cite'],
    'bdo' => ['dir'],
];

/** A link target that is safe to keep: relative, #anchor, http(s), mailto:, tel:. */
function skrid_safe_href(string $href): bool
{
    $h = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $h = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $h);    // "java\nscript:" tricks
    if ($h === '') {
        return false;
    }
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $h, $m)) {
        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
    return true;                                                // relative or #anchor
}

function skrid_sanitize(string $html): string
{
    if (trim($html) === '') {
        return $html;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="skrid-root">'
        . $html . '</div></body></html>',
        LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('skrid-root');
    if (!$root) {
        return htmlspecialchars(strip_tags($html), ENT_NOQUOTES, 'UTF-8');
    }
    skrid_clean_children($doc, $root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return skrid_tidy_spaces($out);
}

function skrid_clean_children(DOMDocument $doc, DOMNode $node): void
{
    // Copy the list first: we replace nodes while walking.
    $children = [];
    foreach ($node->childNodes as $c) {
        $children[] = $c;
    }
    foreach ($children as $c) {
        if ($c instanceof DOMText || $c instanceof DOMComment) {
            continue;
        }
        if (!$c instanceof DOMElement) {
            $node->removeChild($c);
            continue;
        }
        $tag = strtolower($c->tagName);

        // Gone completely, content included.
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'meta', 'link', 'noscript', 'head', 'title'], true)) {
            $node->removeChild($c);
            continue;
        }

        skrid_clean_children($doc, $c);

        // Not inline: keep the text, drop the tag (a block becomes a line break).
        if (!in_array($tag, SK_KEEP_TAGS, true)) {
            $isBlock = in_array($tag, ['div', 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'section', 'article', 'ul', 'ol', 'tr'], true);
            if ($isBlock && $c->previousSibling !== null) {
                $node->insertBefore($doc->createElement('br'), $c);
            }
            while ($c->firstChild) {
                $node->insertBefore($c->firstChild, $c);
            }
            $node->removeChild($c);
            continue;
        }

        // What the browser's bold/italic buttons produce → semantic tags (icon <i class="…"> kept).
        if (($tag === 'b' || ($tag === 'i' && !$c->hasAttribute('class'))) && !$c->hasAttributes()) {
            $new = $doc->createElement($tag === 'b' ? 'strong' : 'em');
            while ($c->firstChild) {
                $new->appendChild($c->firstChild);
            }
            $node->replaceChild($new, $c);
            $c = $new;
            $tag = $c->tagName;
        }

        // Attributes.
        $allowed = array_merge(SK_KEEP_ATTRS, SK_TAG_ATTRS[$tag] ?? []);
        $names = [];
        foreach ($c->attributes as $a) {
            $names[] = $a->nodeName;
        }
        foreach ($names as $an) {
            $l = strtolower($an);
            $keep = in_array($l, $allowed, true)
                || (str_starts_with($l, 'data-') && !str_starts_with($l, 'data-skrid'))
                || ($l === 'style' && !preg_match('/url\s*\(|expression|javascript:|@import|behavior/i', $c->getAttribute($an)));
            if (!$keep) {
                $c->removeAttribute($an);
            }
        }
        if ($tag === 'a') {
            if ($c->hasAttribute('href') && !skrid_safe_href($c->getAttribute('href'))) {
                $c->removeAttribute('href');
            }
            $target = $c->getAttribute('target');
            if ($target !== '' && !in_array($target, ['_blank', '_self'], true)) {
                $c->removeAttribute('target');
            }
            if ($c->getAttribute('target') === '_blank' && !preg_match('/\bnoopener\b/', $c->getAttribute('rel'))) {
                $c->setAttribute('rel', trim($c->getAttribute('rel') . ' noopener'));
            }
        }
        // An empty inline wrapper (e.g. <strong></strong> left by the editor) is useless.
        if ($tag !== 'br' && $tag !== 'wbr' && $c->childNodes->length === 0 && !$c->hasAttributes()) {
            $node->removeChild($c);
        }
    }
}

/**
 * Browsers turn a trailing space into &nbsp; while you type. Keep the &nbsp; that typography wants
 * (between two words, before « : ; ! ? ») but drop those at the edges or next to a normal space.
 */
function skrid_tidy_spaces(string $html): string
{
    $html = str_replace("\u{00A0}", '&nbsp;', $html);
    $html = (string) preg_replace('/(?:&nbsp;)+( )|( )(?:&nbsp;)+/', '$1$2', $html);
    $html = (string) preg_replace('/^(?:&nbsp;)+|(?:&nbsp;)+$/', '', $html);
    $html = (string) preg_replace('/(?:&nbsp;)+(<br>)/', '$1', $html);
    return $html;
}
