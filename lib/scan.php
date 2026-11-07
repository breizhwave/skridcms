<?php
declare(strict_types=1);

/**
 * Skrid scanner.
 *
 * Reads a page as plain text and finds, with byte offsets, the elements whose text can be
 * edited in place. Nothing is ever re-serialised: saving replaces only the bytes between the
 * start and end tag of the elements that were edited, so the rest of the file stays identical
 * (indentation, comments, attributes, scripts…).
 *
 * Editable = an element that holds text and only inline markup (strong, em, a, span, br…),
 * outermost wins (a <p> rather than the <strong> inside it), and that sits in the editing zone:
 * inside <main> when the page has one, otherwise in <body> minus the site's own <header>,
 * <footer> and <nav>. Never inside buttons, forms, SVG, scripts or templates.
 *
 * The site header and footer are recorded (ranges + hash) so they can be grouped per language
 * and, later, edited once for every page that shares them.
 *
 * Blocks (sections, cards, list items, paragraphs…) are recorded with their full byte range, so they
 * can be duplicated, moved among their siblings or deleted by moving bytes only (skrid_block_op).
 *
 * PHP pages: the PHP blocks (<?php … ?>, <?= … ?>) are blanked out before reading, so the scanner only
 * sees the page's fixed HTML. An element that contains or touches PHP code is never editable, and
 * saving only rewrites bytes outside the PHP blocks (api.php checks the code is unchanged).
 */

const SK_VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

/** Raw-text elements: their content is not markup. */
const SK_RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext'];

/** Inline elements allowed inside an editable element. */
const SK_PHRASING = [
    'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd',
    'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
];

/** Elements that can become an editable block. */
const SK_EDITABLE = [
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'figcaption', 'dt', 'dd', 'td', 'th',
    'caption', 'summary', 'address', 'legend', 'label',
    'div', 'span', 'a', 'small', 'strong', 'em', 'b', 'i', 'time', 'cite', 'q', 'mark',
];

/** Nothing inside these is ever editable. */
const SK_FORBIDDEN = [
    'head', 'button', 'select', 'option', 'optgroup', 'datalist', 'textarea', 'svg', 'math', 'template',
    'object', 'canvas', 'video', 'audio', 'form',
];

/**
 * Elements that can be duplicated, moved among their siblings or deleted as a whole ("blocks").
 * An <a> counts too when it wraps block content (a card link).
 */
const SK_BLOCK = [
    'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset', 'figure',
    'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'li', 'menu', 'nav', 'ol', 'p', 'pre',
    'section', 'table', 'tr', 'ul',
];

/** Drop rules: what an element dropped in each kind of parent becomes (see skrid_drop_fit). */
const SK_LISTS = ['ul', 'ol', 'menu'];
const SK_TABLE_PARTS = ['table', 'thead', 'tbody', 'tfoot'];
/** Parents that take any block. */
const SK_FLOW = [
    'main', 'body', 'div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'li', 'dd', 'figure',
    'blockquote', 'details', 'fieldset', 'address', 'td', 'th', 'a', 'dialog', 'search',
];

/** Attributes that point to an id: renamed with it in a duplicated block. */
const SK_ID_REFS = ['for', 'headers', 'list', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns'];

/** Site chrome: excluded from editing when the page has no <main>, recorded for later. */
const SK_CHROME = ['header', 'footer', 'nav'];
const SK_SECTIONING = ['article', 'section', 'main', 'aside'];

/** Opening one of these closes an open <p> (HTML optional end tag). */
const SK_CLOSES_P = [
    'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure',
    'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'main', 'menu', 'nav',
    'ol', 'p', 'pre', 'section', 'table', 'ul',
];

/**
 * Scan one HTML document.
 *
 * @return array{
 *   editables: list<array{tag:string,start:int,open_end:int,inner_start:int,inner_end:int}>,
 *   blocks: list<array{tag:string,start:int,open_end:int,end:int,parent:?int,prev:?int,next:?int}>,
 *   title: ?array{0:int,1:int},
 *   meta: array<string,array{0:int,1:int}>,
 *   lang: string,
 *   has_main: bool,
 *   chrome: list<array{tag:string,start:int,end:int,hash:string}>,
 *   head_open_end: ?int, head_close: ?int, body_close: ?int
 * }
 */
function skrid_scan(string $s): array
{
    $php = skrid_php_ranges($s);
    foreach ($php as [$a, $b]) {
        $s = substr_replace($s, str_repeat(' ', $b - $a), $a, $b - $a);   // same length: offsets hold
    }
    $chunks = [];       // text runs between tags (to tell PHP that prints content from PHP inside a tag)
    $len = strlen($s);
    $hasMain = (bool) preg_match('/<main[\s>]/i', $s);

    $nodes = [];        // every element ever opened: index => data
    $stack = [];        // indexes into $nodes
    $cands = [];        // candidate index => node index (kept in a separate map so parents can drop children)
    $dropped = [];
    $out = [
        'editables' => [], 'title' => null, 'meta' => [], 'lang' => '', 'has_main' => $hasMain,
        'chrome' => [], 'head_open_end' => null, 'head_close' => null, 'body_close' => null,
        'body_open_end' => null, 'php' => [],
    ];

    $top = static function () use (&$stack): ?int {
        return $stack ? $stack[count($stack) - 1] : null;
    };

    // Close the element on top of the stack. $endStart = offset of "</", $endEnd = offset after ">".
    // $clean = false when the element was closed implicitly (its inner end is unknown).
    $close = static function (?int $endStart, ?int $endEnd, bool $clean) use (&$stack, &$nodes, &$cands, &$dropped, &$out, $hasMain): void {
        $i = array_pop($stack);
        if ($i === null) {
            return;
        }
        $n = &$nodes[$i];
        $n['clean'] = $clean;
        if ($clean) {
            $n['inner_end'] = $endStart;
            $n['end'] = $endEnd;
        }

        // Editable?
        $zoneOk = $hasMain ? $n['in_main'] : !$n['chrome'];
        $isCand = $clean && $zoneOk && !$n['forbidden'] && $n['phrasing'] && $n['text']
            && in_array($n['name'], SK_EDITABLE, true);
        if ($isCand) {
            foreach ($n['cands'] as $c) {
                $dropped[$c] = true;   // outermost wins
            }
            $cands[$i] = $i;
            $n['cands'] = [$i];
        }

        // Site header/footer/nav (outermost only), for grouping pages that share them.
        if ($clean && $n['chrome_root']) {
            $out['chrome'][] = ['tag' => $n['name'], '_range' => [$n['start'], (int) $endEnd]];
        }

        // Tell the parent about this child.
        $p = $stack ? $stack[count($stack) - 1] : null;
        if ($p !== null) {
            $pn = &$nodes[$p];
            $pn['text'] = $pn['text'] || $n['text'];
            $pn['phrasing'] = $pn['phrasing'] && $n['phrasing'] && in_array($n['name'], SK_PHRASING, true);
            foreach ($n['cands'] as $c) {
                $pn['cands'][] = $c;
            }
            unset($pn);
        }
        unset($n);
    };

    $p = 0;
    while ($p < $len && ($lt = strpos($s, '<', $p)) !== false) {
        // Text before the tag.
        if ($php && $lt > $p) {
            $chunks[] = [$p, $lt];
        }
        if ($lt > $p && $stack) {
            $chunk = substr($s, $p, $lt - $p);
            if (trim($chunk, " \t\n\r\f\v") !== '') {
                $nodes[$top()]['text'] = true;
            }
        }
        $next = $s[$lt + 1] ?? '';

        // Comment.
        if ($next === '!' && substr_compare($s, '<!--', $lt, 4) === 0) {
            $e = strpos($s, '-->', $lt + 4);
            $p = $e === false ? $len : $e + 3;
            continue;
        }
        // Doctype, CDATA, processing instruction.
        if ($next === '!' || $next === '?') {
            $e = strpos($s, '>', $lt);
            $p = $e === false ? $len : $e + 1;
            continue;
        }

        // End tag.
        if ($next === '/') {
            if (preg_match('/\G<\/([a-zA-Z][a-zA-Z0-9:-]*)[^>]*>/', $s, $m, 0, $lt)) {
                $name = strtolower($m[1]);
                $endEnd = $lt + strlen($m[0]);
                if ($name === 'head') {
                    $out['head_close'] ??= $lt;
                }
                if ($name === 'body') {
                    $out['body_close'] = $lt;
                }
                // Find it in the stack; anything above it was closed implicitly.
                $found = false;
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($nodes[$stack[$k]]['name'] === $name) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    while ($nodes[$top()]['name'] !== $name) {
                        $close(null, null, false);
                    }
                    $close($lt, $endEnd, true);
                }
                $p = $endEnd;
            } else {
                $p = $lt + 1;
            }
            continue;
        }

        // Not a tag: a "<" in text.
        if (!preg_match('/\G<([a-zA-Z][a-zA-Z0-9:-]*)/', $s, $m, 0, $lt)) {
            if ($stack) {
                $nodes[$top()]['text'] = true;
            }
            $p = $lt + 1;
            continue;
        }

        // Start tag: read attributes up to the closing ">" (quotes respected).
        $name = strtolower($m[1]);
        $q = $lt + strlen($m[0]);
        $attrs = [];
        $selfClose = false;
        while ($q < $len) {
            $ch = $s[$q];
            if ($ch === '>') {
                break;
            }
            if ($ch === '/' && ($s[$q + 1] ?? '') === '>') {
                $selfClose = true;
                $q++;
                break;
            }
            if (ctype_space($ch) || $ch === '/') {
                $q++;
                continue;
            }
            // Attribute name.
            $ns = $q;
            while ($q < $len && !ctype_space($s[$q]) && !in_array($s[$q], ['=', '>', '/'], true)) {
                $q++;
            }
            $an = strtolower(substr($s, $ns, $q - $ns));
            while ($q < $len && ctype_space($s[$q])) {
                $q++;
            }
            $vs = $ve = null;
            $quote = '';
            if (($s[$q] ?? '') === '=') {
                $q++;
                while ($q < $len && ctype_space($s[$q])) {
                    $q++;
                }
                $quote = $s[$q] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $vs = $q + 1;
                    $e = strpos($s, $quote, $vs);
                    $ve = $e === false ? $len : $e;
                    $q = $ve + 1;
                } else {
                    $quote = '';
                    $vs = $q;
                    while ($q < $len && !ctype_space($s[$q]) && $s[$q] !== '>') {
                        $q++;
                    }
                    $ve = $q;
                }
            }
            if ($an !== '') {
                $attrs[$an] = [$vs, $ve, $quote];
            }
        }
        $openEnd = min($q + 1, $len);
        $val = static fn (string $a): ?string => isset($attrs[$a]) && $attrs[$a][0] !== null
            ? html_entity_decode(substr($s, $attrs[$a][0], $attrs[$a][1] - $attrs[$a][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;

        if ($name === 'html' && $out['lang'] === '') {
            $out['lang'] = strtolower((string) $val('lang'));
        }
        if ($name === 'head') {
            $out['head_open_end'] ??= $openEnd;
        }
        if ($name === 'body') {
            $out['body_open_end'] ??= $openEnd;
        }
        if ($name === 'meta') {
            $key = strtolower((string) ($val('name') ?? $val('property') ?? ''));
            if (in_array($key, ['description', 'og:title', 'og:description', 'twitter:title', 'twitter:description'], true)
                && isset($attrs['content']) && $attrs['content'][0] !== null && !isset($out['meta'][$key])) {
                $out['meta'][$key] = [$attrs['content'][0], $attrs['content'][1]];
            }
        }

        // Optional end tags: a new block closes an open <p>, a new <li> closes the previous one, etc.
        $implicit = static function (array $closes, array $scopes) use (&$stack, &$nodes, $close): void {
            for ($k = count($stack) - 1; $k >= 0; $k--) {
                $nm = $nodes[$stack[$k]]['name'];
                if (in_array($nm, $scopes, true)) {
                    return;
                }
                if (in_array($nm, $closes, true)) {
                    while (count($stack) > $k) {
                        $close(null, null, false);
                    }
                    return;
                }
            }
        };
        if (in_array($name, SK_CLOSES_P, true) && $stack && $nodes[$top()]['name'] === 'p') {
            $close(null, null, false);
        }
        if ($name === 'li') {
            $implicit(['li'], ['ul', 'ol', 'menu']);
        } elseif ($name === 'dt' || $name === 'dd') {
            $implicit(['dt', 'dd'], ['dl']);
        } elseif ($name === 'tr') {
            $implicit(['tr'], ['table', 'thead', 'tbody', 'tfoot']);
        } elseif ($name === 'td' || $name === 'th') {
            $implicit(['td', 'th'], ['tr', 'table']);
        } elseif ($name === 'option') {
            $implicit(['option'], ['select', 'datalist']);
        }

        $parent = $stack ? $nodes[$top()] : null;

        // Void or self-closing: a leaf that only affects its parent.
        if (in_array($name, SK_VOID, true) || $selfClose) {
            if ($stack) {
                $pi = $top();
                $nodes[$pi]['phrasing'] = $nodes[$pi]['phrasing'] && in_array($name, SK_PHRASING, true);
            }
            $p = $openEnd;
            continue;
        }

        // Raw text: skip to its end tag.
        if (in_array($name, SK_RAW, true)) {
            $e = $openEnd;
            $endStart = $len;
            $endEnd = $len;
            while (($e = stripos($s, '</' . $name, $e)) !== false) {
                $after = $s[$e + 2 + strlen($name)] ?? '>';
                if ($after === '>' || $after === '/' || ctype_space($after)) {
                    $endStart = $e;
                    $gt = strpos($s, '>', $e);
                    $endEnd = $gt === false ? $len : $gt + 1;
                    break;
                }
                $e += 2;
            }
            if ($name === 'title' && $out['title'] === null && $parent !== null && ($parent['in_head'] ?? false)) {
                $out['title'] = [$openEnd, $endStart];
            }
            if ($stack) {
                $pi = $top();
                $nodes[$pi]['phrasing'] = false;
            }
            $p = $endEnd;
            continue;
        }

        // Normal element.
        $chromeRoot = in_array($name, SK_CHROME, true)
            && !($parent['chrome'] ?? false) && !($parent['sectioned'] ?? false);
        $nodes[] = [
            'name' => $name,
            'parent' => $stack ? $top() : null,
            'start' => $lt,
            'open_end' => $openEnd,
            'inner_end' => null,
            'clean' => false,
            'text' => false,
            'phrasing' => true,
            'cands' => [],
            'in_main' => ($parent['in_main'] ?? false) || $name === 'main',
            'in_head' => ($parent['in_head'] ?? false) || $name === 'head',
            'forbidden' => ($parent['forbidden'] ?? false) || in_array($name, SK_FORBIDDEN, true),
            'sectioned' => ($parent['sectioned'] ?? false) || in_array($name, SK_SECTIONING, true),
            'chrome' => ($parent['chrome'] ?? false) || $chromeRoot,
            'chrome_root' => $chromeRoot,
            'class' => isset($attrs['class']) && $attrs['class'][0] !== null ? $attrs['class'] : null,
        ];
        $stack[] = count($nodes) - 1;
        $p = $openEnd;
    }

    // Whatever is still open at the end was never closed explicitly.
    while ($stack) {
        $close(null, null, false);
    }
    if ($php && $p < $len) {
        $chunks[] = [$p, $len];
    }

    // Candidates in document order.
    $list = [];
    foreach ($cands as $i) {
        if (!isset($dropped[$i])) {
            $n = $nodes[$i];
            if ($php && skrid_touches_php($php, $n['start'], (int) $n['inner_end'] + 1)) {
                continue;                              // text written by PHP: not editable here
            }
            $list[] = [
                'tag' => $n['name'],
                'start' => $n['start'],
                'open_end' => $n['open_end'],
                'inner_start' => $n['open_end'],
                'inner_end' => $n['inner_end'],
                'class' => $n['class'],                // [value start, value end, quote] or null
            ];
        }
    }
    usort($list, static fn ($a, $b) => $a['start'] <=> $b['start']);
    $out['editables'] = $list;
    $out['blocks'] = skrid_blocks($nodes, $hasMain, $php);
    $edStarts = array_flip(array_column($list, 'start'));
    foreach ($out['blocks'] as &$b) {
        $b['editable'] = isset($edStarts[$b['start']]);   // a text too: nothing can be dropped inside it
    }
    unset($b);
    // The zone itself (<main>, or <body>): a block can be dropped straight into it.
    $out['root'] = null;
    foreach ($nodes as $n) {
        if ($n['name'] === ($hasMain ? 'main' : 'body')) {
            if ($n['clean']) {
                $out['root'] = ['tag' => $n['name'], 'start' => $n['start'], 'open_end' => $n['open_end'],
                                'inner_end' => $n['inner_end'], 'ctx' => skrid_ctx($nodes, $n['parent'])];
            }
            break;
        }
    }

    // PHP blocks: [start, end, printed among the page's text (not inside a tag, script or style)].
    foreach ($php as [$a, $b]) {
        $inText = false;
        foreach ($chunks as [$c0, $c1]) {
            if ($a >= $c0 && $b <= $c1) {
                $inText = true;
                break;
            }
        }
        $out['php'][] = [$a, $b, $inText];
    }

    // Header/footer fingerprints (whitespace-insensitive).
    foreach ($out['chrome'] as $k => $c) {
        [$a, $b] = $c['_range'];
        $out['chrome'][$k] = [
            'tag' => $c['tag'], 'start' => $a, 'end' => $b,
            'hash' => substr(sha1((string) preg_replace('/\s+/', ' ', substr($s, $a, $b - $a))), 0, 12),
        ];
    }
    return $out;
}

/**
 * The page as sent to the browser in editing mode: each editable element gets data-skrid="N".
 * Only attributes are inserted; no other byte changes.
 *
 * $dynamic: also surround each PHP block printed in the body with <!--skrid-dyn--> … <!--/skrid-dyn-->,
 * so the editor can show what the page's code generates (used on a copy, never saved).
 */
function skrid_mark(string $s, array $scan, bool $dynamic = false): string
{
    $ins = [];                                       // [offset, text]
    foreach ($scan['editables'] as $n => $e) {
        $at = $e['open_end'] - 1;                    // just before ">"
        if (($s[$at - 1] ?? '') === '/') {
            $at--;                                   // "<p/>" never happens for editables, but be safe
        }
        $ins[] = [$at, ' data-skrid="' . $n . '"'];
    }
    foreach ($scan['blocks'] ?? [] as $k => $b) {
        $at = $b['open_end'] - 1;
        if (($s[$at - 1] ?? '') === '/') {
            $at--;
        }
        $ins[] = [$at, ' data-skrid-block="' . $k . '"'];
    }
    if (!empty($scan['root'])) {
        $at = $scan['root']['open_end'] - 1;
        $ins[] = [$at, ' data-skrid-root'];
    }
    if ($dynamic && $scan['body_open_end'] !== null) {
        foreach ($scan['php'] ?? [] as [$a, $b, $inText]) {
            if ($inText && $a >= $scan['body_open_end']) {
                $ins[] = [$a, '<!--skrid-dyn-->'];
                $ins[] = [$b, '<!--/skrid-dyn-->'];
            }
        }
    }
    usort($ins, static fn ($x, $y) => $y[0] <=> $x[0]);
    foreach ($ins as [$at, $text]) {
        $s = substr($s, 0, $at) . $text . substr($s, $at);
    }
    return $s;
}

/**
 * PHP blocks of a page as byte ranges [start, end). Empty for plain HTML.
 * PHP's own tokenizer is used when available, so a "?>" inside a PHP string does not end the block.
 */
function skrid_php_ranges(string $s): array
{
    if (stripos($s, '<?php') === false && !str_contains($s, '<?=')) {
        return [];
    }
    $ranges = [];
    if (function_exists('token_get_all')) {
        $pos = 0;
        $open = null;
        foreach (token_get_all($s) as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if (is_array($tok) && $tok[0] === T_INLINE_HTML) {
                if ($open !== null) {
                    $ranges[] = [$open, $pos];
                    $open = null;
                }
            } else {
                $open ??= $pos;
            }
            $pos += strlen($text);
        }
        if ($open !== null) {
            $ranges[] = [$open, $pos];
        }
        return $ranges;
    }
    preg_match_all('/<\?(?:php\b|=).*?(?:\?>\n?|\z)/is', $s, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$txt, $off]) {
        $ranges[] = [$off, $off + strlen($txt)];
    }
    return $ranges;
}

/** Does [a, b) overlap a PHP block? $php = skrid_php_ranges() or $scan['php']. */
function skrid_touches_php(array $php, int $a, int $b): bool
{
    foreach ($php as $r) {
        if ($r[0] < $b && $a < $r[1]) {
            return true;
        }
    }
    return false;
}

/** The PHP code of a page, all blocks together: must be identical before and after a save. */
function skrid_php_code(string $s): string
{
    $out = '';
    foreach (skrid_php_ranges($s) as [$a, $b]) {
        $out .= substr($s, $a, $b - $a) . "\0";
    }
    return $out;
}

/**
 * Replace the inner HTML of some editable elements. $changes = [N => html].
 * Offsets come from a scan of the same, unchanged text.
 */
function skrid_patch(string $s, array $scan, array $changes): string
{
    return skrid_apply($s, $scan, $changes, []);
}

/**
 * Apply inner HTML changes [N => html] and class changes [N => "a b c"] in one pass.
 * A class change rewrites only the class attribute of the start tag ("" removes it).
 */
function skrid_apply(string $s, array $scan, array $inner, array $classes): string
{
    $ops = [];
    foreach ($inner as $n => $html) {
        $e = $scan['editables'][(int) $n];
        $ops[] = [$e['inner_start'], $e['inner_end'], (string) $html];
    }
    foreach ($classes as $n => $value) {
        $op = skrid_class_op($s, $scan['editables'][(int) $n], (string) $value);
        if ($op !== null) {
            $ops[] = $op;
        }
    }
    usort($ops, static fn ($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
    foreach ($ops as [$a, $b, $r]) {
        $s = substr($s, 0, $a) . $r . substr($s, $b);
    }
    return $s;
}

/**
 * The byte change [start, end, replacement] that sets the class attribute of element $e (an editable or a
 * block: start, open_end, class) to $value. "" removes the attribute. Null when there is nothing to do.
 */
function skrid_class_op(string $s, array $e, string $value): ?array
{
    $v = htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($e['class'] !== null) {
        [$vs, $ve, $quote] = $e['class'];
        // Attribute start: back from the value to the space before "class".
        $attrStart = strripos(substr($s, $e['start'], $vs - $e['start']), 'class');
        $attrStart = $attrStart === false ? null : $e['start'] + $attrStart;
        $end = $ve + ($quote !== '' ? 1 : 0);
        if ($v === '' && $attrStart !== null) {
            $from = $attrStart;
            while ($from > $e['start'] && ctype_space($s[$from - 1])) {
                $from--;
            }
            return [$from, $end, ''];
        }
        if ($quote === '') {
            return [$vs, $ve, '"' . $v . '"'];
        }
        return [$vs, $ve, $quote === "'" ? str_replace("'", '&#039;', $v) : $v];
    }
    if ($v === '') {
        return null;
    }
    $at = $e['open_end'] - 1;
    if (($s[$at - 1] ?? '') === '/') {
        $at--;
    }
    return [$at, $at, ' class="' . $v . '"'];
}

/** Inner HTML of editable N, as written in the file. */
function skrid_inner(string $s, array $scan, int $n): string
{
    $e = $scan['editables'][$n];
    return substr($s, $e['inner_start'], $e['inner_end'] - $e['inner_start']);
}

// ---------------------------------------------------------------- blocks

/**
 * Blocks of the page, in document order (an outer block comes before the blocks inside it).
 * A block is a clean element (explicit end tag) of SK_BLOCK in the editing zone, outside forbidden
 * ancestors, that neither contains nor touches PHP code.
 *
 * parent: index of the nearest enclosing block (null at the top).
 * prev / next: the neighbouring block with the same parent element, where a move can go. A move never
 * crosses PHP code (it could carry a block in or out of an "if" or a loop), so a gap with PHP gives null.
 *
 * ctx: tags of the elements around it, outermost first (the last one is its parent element).
 * class: [value start, value end, quote] of its class attribute, or null.
 *
 * @return list<array{tag:string,start:int,open_end:int,inner_end:int,end:int,class:?array,ctx:list<string>,parent:?int,prev:?int,next:?int}>
 */
function skrid_blocks(array $nodes, bool $hasMain, array $php): array
{
    $byNode = [];                                    // node index => block index
    $list = [];
    foreach ($nodes as $i => $n) {
        if (!$n['clean'] || $n['forbidden'] || $n['in_head']) {
            continue;
        }
        $zoneOk = $hasMain ? ($n['in_main'] && $n['name'] !== 'main') : !$n['chrome'];
        $isBlock = in_array($n['name'], SK_BLOCK, true) || ($n['name'] === 'a' && !$n['phrasing']);
        if (!$zoneOk || !$isBlock || ($php && skrid_touches_php($php, $n['start'], $n['end']))) {
            continue;
        }
        $byNode[$i] = count($list);
        $list[] = ['tag' => $n['name'], 'start' => $n['start'], 'open_end' => $n['open_end'], 'inner_end' => $n['inner_end'],
                   'end' => $n['end'], 'class' => $n['class'], 'ctx' => skrid_ctx($nodes, $n['parent']),
                   'parent' => null, 'prev' => null, 'next' => null, '_node' => $n['parent']];
    }
    $last = [];                                      // parent element => last block seen in it
    foreach ($list as $k => &$b) {
        for ($p = $b['_node']; $p !== null; $p = $nodes[$p]['parent']) {
            if (isset($byNode[$p])) {
                $b['parent'] = $byNode[$p];
                break;
            }
        }
        $key = $b['_node'] ?? -1;
        if (isset($last[$key])) {
            $prev = $last[$key];
            if (!($php && skrid_touches_php($php, $list[$prev]['end'], $b['start']))) {
                $b['prev'] = $prev;
                $list[$prev]['next'] = $k;
            }
        }
        $last[$key] = $k;
    }
    unset($b);
    foreach ($list as &$b) {
        unset($b['_node']);
    }
    unset($b);
    return $list;
}

/** Tags from the outermost element down to node $i (null: none). */
function skrid_ctx(array $nodes, ?int $i): array
{
    $ctx = [];
    for (; $i !== null; $i = $nodes[$i]['parent']) {
        $ctx[] = $nodes[$i]['name'];
    }
    return array_reverse($ctx);
}

/** Start of the run of spaces, tabs and line breaks that ends at $at. */
function skrid_ws_start(string $s, int $at): int
{
    while ($at > 0 && str_contains(" \t\n\r\f", $s[$at - 1])) {
        $at--;
    }
    return $at;
}

/**
 * Change block K of the page: 'duplicate' (a copy right after it), 'up' / 'down' (swap with the
 * previous / next sibling block), 'delete'. Only the bytes of the blocks concerned move; the rest of
 * the file stays identical. Duplicate then delete, and up then down, give back the original bytes.
 *
 * @return array{0:string,1:?int} [new page, offset where the block of interest now starts (null after a delete)]
 * @throws InvalidArgumentException 'bad-block' | 'bad-op' | 'no-sibling'
 */
function skrid_block_op(string $s, array $scan, int $k, string $op): array
{
    $b = $scan['blocks'][$k] ?? null;
    if ($b === null) {
        throw new InvalidArgumentException('bad-block');
    }
    $bytes = substr($s, $b['start'], $b['end'] - $b['start']);
    switch ($op) {
        case 'duplicate':
            // Same separator as in front of the original (its line break and indentation, or nothing).
            $ws = skrid_ws_start($s, $b['start']);
            $sep = substr($s, $ws, $b['start'] - $ws);
            $copy = skrid_fresh_ids($bytes, $s);
            return [substr($s, 0, $b['end']) . $sep . $copy . substr($s, $b['end']), $b['end'] + strlen($sep)];

        case 'delete':
            // The block and the spacing in front of it: the line goes away cleanly.
            return [substr($s, 0, skrid_ws_start($s, $b['start'])) . substr($s, $b['end']), null];

        case 'up':
        case 'down':
            $j = $op === 'up' ? $b['prev'] : $b['next'];
            $o = $j === null ? null : $scan['blocks'][$j];
            if ($o === null) {
                throw new InvalidArgumentException('no-sibling');
            }
            [$first, $second] = $op === 'up' ? [$o, $b] : [$b, $o];
            $one = substr($s, $first['start'], $first['end'] - $first['start']);
            $two = substr($s, $second['start'], $second['end'] - $second['start']);
            $gap = substr($s, $first['end'], $second['start'] - $first['end']);
            $new = substr($s, 0, $first['start']) . $two . $gap . $one . substr($s, $second['end']);
            return [$new, $op === 'up' ? $first['start'] : $first['start'] + strlen($two) + strlen($gap)];
    }
    throw new InvalidArgumentException('bad-op');
}

/** Index of the block that starts at $offset, or null. */
function skrid_block_at(array $scan, ?int $offset): ?int
{
    foreach ($scan['blocks'] as $k => $b) {
        if ($b['start'] === $offset) {
            return $k;
        }
    }
    return null;
}

/**
 * Drag and drop: move block K before / after block T, or inside it (at its end). T = -1 with 'inside' is the
 * zone itself (<main>). The block may change parent, so it goes through skrid_drop_fit() first: a list item
 * outside a list loses its <li>, anything dropped in a list gets one, a table only takes rows…
 * The moved lines are re-indented to their new depth when that is exactly reversible.
 *
 * @return array{0:string,1:?int,2:string} [new page, offset where the moved block now starts, what it became ('' = as is)]
 * @throws InvalidArgumentException a code: bad-block, bad-target, into-itself, not-container, php, or a skrid_drop_fit() refusal
 */
function skrid_block_drop(string $s, array $scan, int $k, int $target, string $where): array
{
    $blocks = $scan['blocks'];
    $b = $blocks[$k] ?? throw new InvalidArgumentException('bad-block');
    if (!in_array($where, ['before', 'after', 'inside'], true)) {
        throw new InvalidArgumentException('bad-op');
    }
    if ($target === -1) {
        if ($where !== 'inside' || empty($scan['root'])) {
            throw new InvalidArgumentException('bad-target');
        }
        $t = null;
    } else {
        $t = $blocks[$target] ?? throw new InvalidArgumentException('bad-target');
        for ($p = $target; $p !== null; $p = $blocks[$p]['parent']) {
            if ($p === $k) {
                throw new InvalidArgumentException('into-itself');
            }
        }
    }

    // Where the bytes go: $at, the separator in front of them, and the elements around them ($ctx).
    $sep = '';
    if ($where === 'inside') {
        if ($t !== null && $t['editable']) {
            throw new InvalidArgumentException('not-container');
        }
        $parent = $target === -1 ? null : $target;
        $last = null;
        foreach ($blocks as $j => $x) {
            if ($x['parent'] === $parent && $j !== $k) {
                $last = $j;
            }
        }
        if ($last !== null) {
            [$t, $where] = [$blocks[$last], 'after'];     // at its end = after its last block
        } else {
            $box = $t ?? $scan['root'];
            $at = skrid_ws_start($s, $box['inner_end']);
            $close = substr($s, $at, $box['inner_end'] - $at);
            $sep = str_contains($close, "\n") ? $close . '  ' : $close;
            $ctx = array_merge($box['ctx'], [$box['tag']]);
        }
    }
    if ($where !== 'inside') {
        $ws = skrid_ws_start($s, $t['start']);
        $sep = substr($s, $ws, $t['start'] - $ws);
        $at = $where === 'before' ? $ws : $t['end'];
        $ctx = $t['ctx'];
    }

    [$bytes, $became] = skrid_drop_fit($s, $b, $ctx);
    // Without <main>, a header/footer/nav outside any section is the site's own chrome: it would leave the zone.
    if (empty($scan['has_main']) && $became === '' && in_array($b['tag'], SK_CHROME, true) && !array_intersect($ctx, SK_SECTIONING)) {
        throw new InvalidArgumentException('becomes-chrome');
    }

    // Same indentation as its new neighbours, when every line can be shifted back exactly.
    $rmA = skrid_ws_start($s, $b['start']);
    $rmB = $b['end'];
    $lead = substr($s, $rmA, $b['start'] - $rmA);
    if (str_contains($lead, "\n") && str_contains($sep, "\n") && !preg_match('/<(pre|textarea|script|style)[\s>]/i', $bytes)) {
        $old = substr($lead, strrpos($lead, "\n") + 1);
        $new = substr($sep, strrpos($sep, "\n") + 1);
        if ($old !== $new && !preg_match('/\n(?!' . preg_quote($old, '/') . ')/', $bytes)) {
            $bytes = str_replace("\n" . $old, "\n" . $new, $bytes);
        }
    }

    if (!empty($scan['php']) && skrid_touches_php($scan['php'], min($at, $rmA), max($at, $rmB))) {
        throw new InvalidArgumentException('php');      // never carry a block across PHP code
    }
    $ins = $sep . $bytes;
    if ($at >= $rmB) {
        $out = substr($s, 0, $rmA) . substr($s, $rmB, $at - $rmB) . $ins . substr($s, $at);
        $focus = $at - ($rmB - $rmA) + strlen($sep);
    } elseif ($at <= $rmA) {
        $out = substr($s, 0, $at) . $ins . substr($s, $at, $rmA - $at) . substr($s, $rmB);
        $focus = $at + strlen($sep);
    } else {
        throw new InvalidArgumentException('into-itself');
    }
    $focus += strlen($bytes) - strlen(ltrim($bytes));
    return [$out, $focus, $became];
}

/**
 * Compatibility rules for a block moved into a new parent. $ctx = tags around the drop point, outermost
 * first; the last one is the new parent element.
 *
 * @return array{0:string,1:string} [bytes to insert, what changed: '' | 'li' (wrapped in <li>) | 'p>li' | 'li>p' | 'unwrap']
 * @throws InvalidArgumentException link-in-link | header-in-header | in-address | table-needs-row |
 *         row-needs-table | dl-needs-dt-dd | dl-group | hgroup | no-block-here
 */
function skrid_drop_fit(string $s, array $b, array $ctx): array
{
    $tag = $b['tag'];
    $bytes = substr($s, $b['start'], $b['end'] - $b['start']);
    $inner = substr($s, $b['open_end'], $b['inner_end'] - $b['open_end']);
    $parent = $ctx ? $ctx[count($ctx) - 1] : 'body';
    $grand = $ctx[count($ctx) - 2] ?? '';
    $has = static fn (string $tags): bool => (bool) preg_match('/<(?:' . $tags . ')[\s>\/]/i', $inner);
    $fail = static fn (string $code) => throw new InvalidArgumentException($code);

    // Nesting that HTML forbids.
    if (in_array('a', $ctx, true) && ($tag === 'a' || $has('a|button|input|select|textarea'))) {
        $fail('link-in-link');
    }
    if (array_intersect(['header', 'footer'], $ctx) && (in_array($tag, ['header', 'footer'], true) || $has('header|footer'))) {
        $fail('header-in-header');
    }
    if (in_array('address', $ctx, true)
        && (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'footer', 'section', 'article', 'aside', 'nav', 'address'], true)
            || $has('h[1-6]|header|footer|section|article|aside|nav|address'))) {
        $fail('in-address');
    }

    // Tables take rows, rows go in tables.
    if (in_array($parent, SK_TABLE_PARTS, true)) {
        return $tag === 'tr' ? [$bytes, ''] : $fail('table-needs-row');
    }
    if ($tag === 'tr') {
        $fail('row-needs-table');
    }
    // Lists take list items: a paragraph becomes one, anything else is wrapped in one.
    if (in_array($parent, SK_LISTS, true)) {
        if ($tag === 'li') {
            return [$bytes, ''];
        }
        return $tag === 'p' ? ['<li>' . $inner . '</li>', 'p>li'] : ['<li>' . $bytes . '</li>', 'li'];
    }
    // Description lists take dt / dd (or a div grouping them).
    $dtdd = $tag === 'div' && $has('dt|dd') && !$has('dl');
    if ($parent === 'dl' || ($parent === 'div' && $grand === 'dl')) {
        if (in_array($tag, ['dt', 'dd'], true) || ($dtdd && $parent === 'dl')) {
            return [$bytes, ''];
        }
        $fail('dl-needs-dt-dd');
    }
    if ($dtdd) {
        $fail('dl-group');                           // its dt / dd would be orphans
    }
    if ($parent === 'hgroup') {
        return preg_match('/^(h[1-6]|p)$/', $tag) ? [$bytes, ''] : $fail('hgroup');
    }
    if (!in_array($parent, SK_FLOW, true)) {
        $fail('no-block-here');                      // a paragraph, a heading…: text only
    }
    // A list item (or dt / dd) outside its list: its content, in a paragraph when it is only text.
    if (in_array($tag, ['li', 'dt', 'dd'], true)) {
        $blocky = $has(implode('|', SK_BLOCK) . '|figure|table|form|hr|iframe|video|audio|canvas|svg');
        return $blocky ? [trim($inner), 'unwrap'] : ['<p>' . trim($inner) . '</p>', $tag . '>p'];
    }
    return [$bytes, ''];
}

/**
 * Set the classes of block K. Tokens may hold anything but spaces, quotes, < > and ` (Tailwind's
 * md:w-1/2, text-[var(--x)], [&>p]:mt-2… are fine; & is written &amp;).
 *
 * @throws InvalidArgumentException bad-block | bad-class
 */
function skrid_block_classes(string $s, array $scan, int $k, string $classes): string
{
    $b = $scan['blocks'][$k] ?? throw new InvalidArgumentException('bad-block');
    $tokens = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) > 80) {
        throw new InvalidArgumentException('bad-class');
    }
    foreach ($tokens as $c) {
        if (strlen($c) > 120 || preg_match('/["\'<>`]/', $c)) {
            throw new InvalidArgumentException('bad-class');
        }
    }
    $op = skrid_class_op($s, $b, implode(' ', array_unique($tokens)));
    return $op === null ? $s : substr($s, 0, $op[0]) . $op[2] . substr($s, $op[1]);
}

/**
 * A duplicated block must not repeat the ids of the original (anchors, labels): every id in the copy
 * becomes id-2 (or -3… if taken), and the references to it inside the copy (for, aria-*, href="#…") follow.
 */
function skrid_fresh_ids(string $copy, string $doc): string
{
    $val = '("[^"]*"|\'[^\']*\'|[^\s"\'>]+)';
    if (!preg_match_all('/\sid\s*=\s*' . $val . '/i', $copy, $m)) {
        return $copy;
    }
    $taken = preg_match_all('/\sid\s*=\s*' . $val . '/i', $doc, $all) ? array_map(static fn ($v) => trim($v, '"\''), $all[1]) : [];
    $taken = array_flip($taken);
    $map = [];
    foreach ($m[1] as $v) {
        $id = trim($v, '"\'');
        if ($id === '' || isset($map[$id])) {
            continue;
        }
        $i = 2;
        while (isset($taken[$id . '-' . $i])) {
            $i++;
        }
        $map[$id] = $id . '-' . $i;
        $taken[$map[$id]] = true;
    }
    $attrs = implode('|', array_map(static fn ($a) => preg_quote($a, '/'), array_merge(['id', 'href'], SK_ID_REFS)));
    return (string) preg_replace_callback('/(\s(' . $attrs . ')\s*=\s*)' . $val . '/i', static function (array $x) use ($map): string {
        $q = in_array($x[3][0], ['"', "'"], true) ? $x[3][0] : '';
        $v = $q === '' ? $x[3] : substr($x[3], 1, -1);
        $name = strtolower($x[2]);
        if ($name === 'href') {
            $v = str_starts_with($v, '#') && isset($map[substr($v, 1)]) ? '#' . $map[substr($v, 1)] : $v;
        } else {
            $v = (string) preg_replace_callback('/[^\s]+/', static fn ($t) => $map[$t[0]] ?? $t[0], $v);
        }
        return $x[1] . $q . $v . $q;
    }, $copy);
}
