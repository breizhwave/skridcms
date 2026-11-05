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
        $e = $scan['editables'][(int) $n];
        $v = htmlspecialchars(trim((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
                $ops[] = [$from, $end, ''];
            } elseif ($quote === '') {
                $ops[] = [$vs, $ve, '"' . $v . '"'];
            } else {
                $ops[] = [$vs, $ve, $quote === "'" ? str_replace("'", '&#039;', $v) : $v];
            }
        } elseif ($v !== '') {
            $at = $e['open_end'] - 1;
            if (($s[$at - 1] ?? '') === '/') {
                $at--;
            }
            $ops[] = [$at, $at, ' class="' . $v . '"'];
        }
    }
    usort($ops, static fn ($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
    foreach ($ops as [$a, $b, $r]) {
        $s = substr($s, 0, $a) . $r . substr($s, $b);
    }
    return $s;
}

/** Inner HTML of editable N, as written in the file. */
function skrid_inner(string $s, array $scan, int $n): string
{
    $e = $scan['editables'][$n];
    return substr($s, $e['inner_start'], $e['inner_end'] - $e['inner_start']);
}
