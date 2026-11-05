<?php
declare(strict_types=1);

/**
 * Text styles.
 *
 * Inline styles wrap part of a text (right-click on a selection): a tag and classes, e.g. <span class="sk-larger">.
 * Block styles are classes put on the whole paragraph or title (selector in the toolbar).
 *
 * Skrid's own styles (sk-…) work on any site: the first time a page uses one, a small <style id="skrid-styles">
 * is written into its <head>. Styles made of the site's own classes (Admin › Settings) need nothing more.
 */

/** Skrid's own styles: class => CSS. */
const SK_BUILTIN_CSS = [
    'sk-larger' => 'font-size:1.25em',
    'sk-smaller' => 'font-size:.82em',
    'sk-light' => 'font-weight:300',
    'sk-heavy' => 'font-weight:900',
    'sk-caps' => 'text-transform:uppercase;letter-spacing:.08em;font-size:.86em',
    'sk-lead' => 'font-size:1.2em;line-height:1.5',
    'sk-note' => 'font-size:.875em;opacity:.78',
    'sk-center' => 'text-align:center',
];

/** Tags an inline style may use. */
const SK_STYLE_TAGS = ['span', 'small', 'strong', 'em', 'mark', 'b', 'i', 'u', 's', 'code', 'sub', 'sup', 'q', 'cite', 'abbr', 'kbd'];

function sk_builtin_styles(): array
{
    return [
        'inline' => [
            ['label' => t('style.larger'), 'tag' => 'span', 'classes' => ['sk-larger']],
            ['label' => t('style.smaller'), 'tag' => 'span', 'classes' => ['sk-smaller']],
            ['label' => t('style.light'), 'tag' => 'span', 'classes' => ['sk-light']],
            ['label' => t('style.heavy'), 'tag' => 'span', 'classes' => ['sk-heavy']],
            ['label' => t('style.caps'), 'tag' => 'span', 'classes' => ['sk-caps']],
            ['label' => t('style.mark'), 'tag' => 'mark', 'classes' => []],
        ],
        'block' => [
            ['label' => t('style.lead'), 'classes' => ['sk-lead']],
            ['label' => t('style.note'), 'classes' => ['sk-note']],
            ['label' => t('style.center'), 'classes' => ['sk-center']],
        ],
    ];
}

/** A class name as written in CSS: letters, digits, - _ and the characters Tailwind uses (: / [ ] . % #). */
function sk_valid_class(string $c): bool
{
    return (bool) preg_match('/^-?[A-Za-z_][\w\-:\/\[\]\.%#]*$/u', $c) && strlen($c) <= 80;
}

/**
 * The site's own styles, from the two text boxes in Settings. One style per line:
 *   inline:  "Fin de titre = span h1-tail"   (tag optional: "Gris = text-slate-2" means a <span>)
 *   block:   "Introduction = text-lead"
 * Returns [list of styles, list of lines that could not be read].
 */
function sk_parse_styles(string $text, bool $inline): array
{
    $out = [];
    $bad = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^(.+?)\s*=\s*(.+)$/u', $line, $m)) {
            $bad[] = $line;
            continue;
        }
        $label = trim($m[1]);
        $tokens = preg_split('/\s+/', trim($m[2])) ?: [];
        $tag = 'span';
        if ($inline && in_array(strtolower($tokens[0] ?? ''), SK_STYLE_TAGS, true)) {
            $tag = strtolower(array_shift($tokens));
        }
        $tokens = array_map(static fn ($c) => ltrim($c, '.'), $tokens);
        $ok = $label !== '' && mb_strlen($label) <= 40 && array_filter($tokens, 'sk_valid_class') === $tokens
            && ($tokens || ($inline && $tag !== 'span'));
        if (!$ok) {
            $bad[] = $line;
            continue;
        }
        $out[] = $inline ? ['label' => $label, 'tag' => $tag, 'classes' => array_values($tokens)] : ['label' => $label, 'classes' => array_values($tokens)];
    }
    return [$out, $bad];
}

/** Every style offered in the editor: Skrid's own first, then the site's. */
function sk_styles(): array
{
    $set = sk_settings();
    $b = sk_builtin_styles();
    $useBuiltin = ($set['styles_builtin'] ?? true) !== false;
    [$inline] = sk_parse_styles((string) ($set['styles_inline'] ?? ''), true);
    [$block] = sk_parse_styles((string) ($set['styles_block'] ?? ''), false);
    return [
        'inline' => array_merge($useBuiltin ? $b['inline'] : [], $inline),
        'block' => array_merge($useBuiltin ? $b['block'] : [], $block),
    ];
}

/** Every class a block style may set: the only classes Skrid adds to or removes from a paragraph. */
function sk_block_classes(): array
{
    $all = [];
    foreach (sk_styles()['block'] as $st) {
        foreach ($st['classes'] as $c) {
            $all[$c] = true;
        }
    }
    // Skrid's own classes stay removable even when they are no longer offered.
    foreach (['sk-lead', 'sk-note', 'sk-center'] as $c) {
        $all[$c] = true;
    }
    return array_keys($all);
}

function sk_builtin_css(): string
{
    $css = '';
    foreach (SK_BUILTIN_CSS as $class => $rule) {
        $css .= '.' . $class . '{' . $rule . '}';
    }
    return $css;
}

/**
 * When the page uses one of Skrid's own classes, make sure its <head> holds their CSS
 * (<style id="skrid-styles">, added once, refreshed if Skrid's styles change).
 */
function sk_ensure_builtin_css(string $html): string
{
    if (!preg_match('/\bclass\s*=\s*["\']?[^"\'>]*\bsk-(?:' . implode('|', array_map(static fn ($c) => preg_quote(substr($c, 3), '/'), array_keys(SK_BUILTIN_CSS))) . ')\b/i', $html)) {
        return $html;
    }
    $block = '<style id="skrid-styles">' . sk_builtin_css() . '</style>';
    if (preg_match('/<style id="skrid-styles">.*?<\/style>/s', $html, $m, PREG_OFFSET_CAPTURE)) {
        return $m[0][0] === $block ? $html : substr_replace($html, $block, $m[0][1], strlen($m[0][0]));
    }
    $scan = skrid_scan($html);
    if ($scan['head_close'] === null) {
        return $block . "\n" . $html;
    }
    return substr($html, 0, $scan['head_close']) . $block . "\n" . substr($html, $scan['head_close']);
}
