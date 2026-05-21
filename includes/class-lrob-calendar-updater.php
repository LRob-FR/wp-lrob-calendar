<?php
/**
 * Self-hosted plugin updater — surfaces GitHub releases as WordPress updates.
 *
 * Two filters do the work:
 *   1. pre_set_site_transient_update_plugins — when WP decides which plugins
 *      need updating, we hit the GitHub API, compare versions, and inject our
 *      entry if a newer release is published.
 *   2. plugins_api — the "View version details" / "View details" modal pulls
 *      release info from GitHub (changelog from the release body, formatted
 *      via a minimal Markdown→HTML conversion).
 *
 * The GitHub API response is cached in a transient for 1h. GitHub's
 * unauthenticated rate limit is 60 req/h per IP — well clear of that at this
 * cache rate, even on shared hosting where multiple sites share an outbound IP.
 * Admin intent signals (the "Check again" button on the Updates page, or
 * simply landing on update-core.php) bypass the cache for that one request.
 *
 * No external library. ~200 lines.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Updater {

    const TRANSIENT_KEY      = 'lrob_calendar_gh_release';
    const TRANSIENT_TTL      = HOUR_IN_SECONDS;
    const TRANSIENT_TTL_FAIL = HOUR_IN_SECONDS;   // shorter on network/API failure
    const PLUGIN_SLUG        = 'lrob-calendar';

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                          [$this, 'plugin_info'], 10, 3);
    }

    /**
     * Inject our update notice into the transient WordPress uses to decide
     * which plugins have updates available. Runs every ~1h via wp-cron
     * (and on any admin page load if the transient has expired).
     */
    public function check_for_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $release = $this->get_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = $this->normalize_version($release['tag_name'] ?? '');
        if (!$remote_version) {
            return $transient;
        }

        // Already on this version or newer — nothing to do.
        if (version_compare(LROB_CALENDAR_VERSION, $remote_version, '>=')) {
            return $transient;
        }

        $zip_url = $this->find_asset_url($release);
        if (!$zip_url) {
            // Release exists but has no zip asset attached — skip rather than
            // pointing WP at the GitHub-generated source tarball (which has a
            // commit-hash-named folder and would install side-by-side).
            return $transient;
        }

        $update = (object) [
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => LROB_CALENDAR_BASENAME,
            'new_version'  => $remote_version,
            'url'          => LROB_CALENDAR_GITHUB_URL,
            'package'      => $zip_url,
            'tested'       => $this->tested_wp_version(),
            'requires_php' => '8.0',
            'icons'        => [],
            'banners'      => [],
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[LROB_CALENDAR_BASENAME] = $update;
        return $transient;
    }

    /**
     * Fill the "View version details" modal for the Plugins screen.
     * Returns false-equivalent ($result unchanged) for any request that
     * isn't asking about THIS plugin.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = $this->get_release();
        if (!$release) {
            return $result;
        }

        $remote_version = $this->normalize_version($release['tag_name'] ?? '');
        $zip_url        = $this->find_asset_url($release);

        return (object) [
            'name'          => 'LRob - Calendar',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $remote_version,
            'author'        => '<a href="https://www.lrob.fr">LRob</a>',
            'homepage'      => defined('LROB_CALENDAR_PLUGIN_URL') ? LROB_CALENDAR_PLUGIN_URL : LROB_CALENDAR_GITHUB_URL,
            'requires'      => '6.0',
            'requires_php'  => '8.0',
            'tested'        => $this->tested_wp_version(),
            'last_updated'  => $release['published_at'] ?? '',
            'download_link' => $zip_url,
            'sections'      => [
                'description' => __('A clean event calendar for WordPress with recurring events, click-to-preview month grid, agenda view, AJAX-paginated event lists, and an OpenStreetMap embed on single-event pages.', 'lrob-calendar'),
                'changelog'   => $this->markdown_to_html($release['body'] ?? ''),
            ],
        ];
    }

    /**
     * Force-clear the cached release info — useful on plugin activation /
     * after a manual update, or from a future "check now" admin button.
     */
    public static function flush_cache(): void {
        delete_transient(self::TRANSIENT_KEY);
    }

    /* ─── Internals ──────────────────────────────────────────────────── */

    /**
     * Hit the GitHub API for the latest release. Cached 1h on success,
     * 1h on failure (to avoid hammering the API when it's flaky).
     * Returns null when there's no usable response.
     *
     * Cache lookup is skipped when is_force_refresh() — the admin is
     * actively looking for updates and shouldn't have to wait an hour for
     * the transient to roll over. The fresh response is still written back
     * so subsequent loads on the same page don't re-hit the API.
     */
    private function get_release(): ?array {
        if (!$this->is_force_refresh()) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if ($cached === 'none') {
                return null;
            }
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $api_url = 'https://api.github.com/repos/' . $this->github_repo() . '/releases/latest';
        $response = wp_remote_get($api_url, [
            'timeout' => 8,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::TRANSIENT_KEY, 'none', self::TRANSIENT_TTL_FAIL);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient(self::TRANSIENT_KEY, 'none', self::TRANSIENT_TTL_FAIL);
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $body, self::TRANSIENT_TTL);
        return $body;
    }

    /**
     * True when the admin is signalling "check now" intent:
     *   - ?force-check=1 on the URL — core sets this when "Check again" is
     *     clicked on the Updates page, and wp_update_plugins() reads the
     *     same flag to bypass its own cache.
     *   - pagenow === 'update-core.php' — they're on the Updates screen
     *     right now, so any cache hit there is just stale data they're
     *     trying to refresh.
     * Gated on is_admin() so frontend cron triggers never force-refresh.
     */
    private function is_force_refresh(): bool {
        if (!is_admin()) {
            return false;
        }
        if (isset($_GET['force-check']) && (string) $_GET['force-check'] === '1') {
            return true;
        }
        if (($GLOBALS['pagenow'] ?? '') === 'update-core.php') {
            return true;
        }
        return false;
    }

    private function github_repo(): string {
        // Derive from LROB_CALENDAR_GITHUB_URL so the URL lives in ONE place.
        $url = defined('LROB_CALENDAR_GITHUB_URL') ? LROB_CALENDAR_GITHUB_URL : '';
        if (preg_match('#github\.com/([^/]+/[^/]+?)/?$#', $url, $m)) {
            return $m[1];
        }
        return 'LRob-FR/wp-lrob-calendar';
    }

    private function normalize_version(?string $tag): string {
        if (!$tag) return '';
        return ltrim($tag, 'vV');
    }

    /**
     * Find the plugin zip on the release. release.sh uploads
     * `lrob-calendar-<version>.zip` as a release asset; we match by
     * filename prefix + .zip suffix to tolerate the version suffix.
     */
    private function find_asset_url(array $release): ?string {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) return null;
        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            $url  = $asset['browser_download_url'] ?? '';
            if (!$url) continue;
            if (strpos($name, self::PLUGIN_SLUG . '-') === 0 && substr($name, -4) === '.zip') {
                return $url;
            }
        }
        return null;
    }

    private function tested_wp_version(): string {
        // Bumping by hand each WP release is busywork; reporting the running
        // version sidesteps the "tested up to" warning without lying.
        return get_bloginfo('version');
    }

    /**
     * Minimal Markdown → HTML for the changelog modal. Covers what GitHub
     * release notes typically use: headings, bullets, bold, code spans,
     * links, paragraphs. Not a real parser — anything fancier renders as
     * escaped text, which is safe.
     */
    private function markdown_to_html(string $md): string {
        $md = trim($md);
        if ($md === '') return '';

        // Start by escaping all HTML. We'll selectively re-introduce the
        // markup we recognise below.
        $html = esc_html($md);

        // Headings — `## h2` → h3 (h2 is too big for a modal), `### h3` → h4
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m',  '<h3>$1</h3>', $html);
        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
        // Links — [text](url)
        $html = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function ($m) {
                return '<a href="' . esc_url($m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
            },
            $html
        );
        // Bullet lists: consecutive "- foo" lines wrapped in <ul><li>…</li></ul>.
        $html = preg_replace_callback(
            '/(?:^- .+(?:\n|$))+/m',
            function ($m) {
                $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($m[0]));
                return '<ul>' . $items . '</ul>';
            },
            $html
        );
        // Paragraph splitting on blank lines, skipping already-blockified content.
        $blocks = preg_split('/\n{2,}/', $html);
        $blocks = array_map(function ($b) {
            $b = trim($b);
            if ($b === '') return '';
            if (preg_match('/^<(h[1-6]|ul|ol|p|pre|blockquote)\b/i', $b)) return $b;
            return '<p>' . str_replace("\n", '<br>', $b) . '</p>';
        }, $blocks);
        return implode("\n", $blocks);
    }
}
