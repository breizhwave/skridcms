<?php
declare(strict_types=1);

/** Pieces shared by the admin pages (admin.php, data.php). */

/** The site's name, from the home page title ("Music | wave.bzh" → "wave.bzh" is not guessed: first part). */
function sk_site_name(array $pages): string
{
    $home = array_values(array_filter($pages, static fn ($p) => (bool) preg_match('/^index\.(html?|php)$/i', $p['rel'])))[0] ?? ($pages[0] ?? null);
    $name = (string) ($home['title'] ?? ($_SERVER['HTTP_HOST'] ?? 'Site'));
    return trim((string) preg_split('/\s*[|:·–—,]\s+/u', $name)[0]) ?: $name;
}

/** Top bar of the admin: brand, site, tabs, "Edit the site", log out. */
function sk_top_bar(string $tab, string $browseUrl, string $siteName, bool $hasData): string
{
    $tabs = ['pages' => 'nav.pages'];
    if ($hasData) {
        $tabs['data'] = 'nav.data';
    }
    $tabs += ['activity' => 'nav.activity', 'chrome' => 'nav.chrome', 'settings' => 'nav.settings'];
    $links = '';
    foreach ($tabs as $k => $label) {
        $links .= '<a href="admin.php?tab=' . $k . '"' . ($tab === $k ? ' aria-current="page"' : '') . '>' . h(t($label)) . '</a>';
    }
    return '<header class="top"><div class="top-in">'
        . '<a class="brand" href="admin.php"><img src="assets/skrid.svg" alt="" width="30" height="30"> <span>Skrid</span></a>'
        . '<span class="site" title="' . h(sk_site_root()) . '">' . h($siteName) . '</span>'
        . '<nav class="tabs" aria-label="Skrid">' . $links . '</nav>'
        . '<span class="grow"></span>'
        . '<a class="btn primary" href="' . h($browseUrl) . '">' . h(t('nav.browse')) . '</a>'
        . '<button class="btn ghost icon" type="button" data-logout data-to="' . h(sk_site_url()) . '" title="' . h(t('nav.logout')) . '" aria-label="' . h(t('nav.logout')) . '">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
        . '</div></header>';
}

function sk_size(int $bytes): string
{
    $fr = skrid_lang() === 'fr';
    return $bytes < 1024 ? $bytes . ($fr ? ' o' : ' B') : number_format($bytes / 1024, 1, $fr ? ',' : '.', '') . ($fr ? ' Ko' : ' KB');
}
