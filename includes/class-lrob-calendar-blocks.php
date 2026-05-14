<?php
/**
 * Block registration + REST endpoints.
 *
 * Each block lives in /blocks/<name>/ with its own block.json (metadata),
 * edit.js (editor), render.php (server-side render), and style.css. Rendering
 * helpers shared between blocks are in LRob_Calendar_Block_Helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Blocks {

    public function __construct() {
        // Asset handles must be registered before block.json is read so the
        // string-handle references in block.json's editorScript/style/viewScript
        // resolve. wp_register_* on init priority 5 runs before register_block_type
        // (init default priority 10). Localized data (categories/tags lookup)
        // depends on the taxonomies being registered, so it waits until priority 20.
        add_action('init', [$this, 'register_assets'], 5);
        add_action('init', [$this, 'register_blocks']);
        add_action('init', [$this, 'register_localized_data'], 20);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // REST-response cache invalidation — bump a version counter on event save /
        // delete so cached responses become unreachable, but we don't have to scan
        // and delete transients individually.
        add_action('save_post_' . LRob_Calendar_Post_Types::POST_TYPE, [self::class, 'bump_rest_cache_version']);
        add_action('before_delete_post', [self::class, 'maybe_bump_on_delete']);
    }

    const REST_CACHE_VERSION_OPTION = 'lrob_calendar_rest_cache_version';
    const REST_CACHE_TTL            = 5 * MINUTE_IN_SECONDS;

    public static function bump_rest_cache_version(): void {
        $v = (int) get_option(self::REST_CACHE_VERSION_OPTION, 0);
        update_option(self::REST_CACHE_VERSION_OPTION, $v + 1, false); // non-autoloaded
    }

    public static function maybe_bump_on_delete(int $post_id): void {
        if (get_post_type($post_id) === LRob_Calendar_Post_Types::POST_TYPE) {
            self::bump_rest_cache_version();
        }
    }

    private function rest_cache_key(array $args): string {
        $version = (int) get_option(self::REST_CACHE_VERSION_OPTION, 0);
        return 'lrob_rest_v' . $version . '_' . md5(serialize($args));
    }

    /**
     * Register shared asset handles referenced by block.json files.
     * Nothing is enqueued here — block.json + WordPress handle conditional enqueue
     * (only loaded when a block that lists the handle is present on the page).
     */
    public function register_assets(): void {
        $url = LROB_CALENDAR_URL;
        $ver = LROB_CALENDAR_VERSION;

        // CSS — tokens are the root, every other stylesheet declares it as a dep.
        wp_register_style('lrob-calendar-tokens',           $url . 'assets/css/tokens.css',            [],                              $ver);
        wp_register_style('lrob-calendar-event-card',       $url . 'assets/css/event-card.css',        ['lrob-calendar-tokens'],        $ver);
        wp_register_style('lrob-calendar-lightbox',         $url . 'assets/css/lightbox.css',          [],                              $ver);
        wp_register_style('lrob-calendar-single-event-page', $url . 'assets/css/single-event-page.css', ['lrob-calendar-tokens'],        $ver);
        wp_register_style('lrob-calendar-blocks-editor',    $url . 'assets/css/blocks-editor.css',     [],                              $ver);

        // Per-block styles referenced by handle in block.json's `style` field.
        wp_register_style('lrob-calendar-block-calendar',    $url . 'blocks/calendar/style.css',    ['lrob-calendar-tokens'],     $ver);
        wp_register_style('lrob-calendar-block-events-list', $url . 'blocks/events-list/style.css', ['lrob-calendar-event-card'], $ver);

        // JS — shared lightbox, editor data, calendar viewScript, event-card lightbox wirer.
        wp_register_script('lrob-calendar-lightbox', $url . 'assets/js/lightbox.js', [], $ver, true);
        wp_localize_script('lrob-calendar-lightbox', 'lrobLightboxI18n', [
            'close' => __('Close', 'lrob-calendar'),
        ]);

        wp_register_script(
            'lrob-calendar-blocks-shared',
            $url . 'assets/js/blocks-shared.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            $ver,
            true
        );

        // Pre-register each block's edit.js with a named handle so we can set
        // translations explicitly per handle. Using block.json's `file:./edit.js`
        // gives WP an auto-generated handle whose translation auto-loading is
        // timing-sensitive (textdomain must be registered before block registration),
        // which proved unreliable. Named handles avoid that entirely.
        $editor_deps = ['lrob-calendar-blocks-shared'];
        $edit_scripts = [
            'lrob-calendar-block-calendar-edit'     => 'blocks/calendar/edit.js',
            'lrob-calendar-block-events-list-edit'  => 'blocks/events-list/edit.js',
            'lrob-calendar-block-single-event-edit' => 'blocks/single-event/edit.js',
        ];
        foreach ($edit_scripts as $handle => $rel_path) {
            wp_register_script($handle, $url . $rel_path, $editor_deps, $ver, true);
            wp_set_script_translations($handle, 'lrob-calendar', LROB_CALENDAR_PATH . 'languages');
        }

        wp_register_script(
            'lrob-calendar-view',
            $url . 'blocks/calendar/view.js',
            ['jquery', 'lrob-calendar-lightbox'],
            $ver,
            true
        );

        // Auto-wires click handlers on `.lrob-event-thumbnail--clickable` to the shared lightbox.
        wp_register_script(
            'lrob-calendar-event-card-lightbox',
            $url . 'assets/js/event-card-lightbox.js',
            ['lrob-calendar-lightbox'],
            $ver,
            true
        );

        // Adds a "Show more / Show less" toggle on event-card excerpts that
        // overflow their clamp height. Used by events-list + single-event blocks.
        wp_register_script(
            'lrob-calendar-event-card-expand',
            $url . 'assets/js/event-card-expand.js',
            [],
            $ver,
            true
        );

        // Intercepts pagination clicks on the events-list block and swaps the
        // wrapper innerHTML without a full page reload. Depends on the lightbox
        // module so it can re-bind handlers on the swapped event cards, and
        // on the expand module so the toggle re-binds too.
        wp_register_script(
            'lrob-calendar-events-list-pagination',
            $url . 'assets/js/events-list-pagination.js',
            ['lrob-calendar-lightbox', 'lrob-calendar-event-card-expand'],
            $ver,
            true
        );

    }

    /**
     * Inline data + i18n strings that depend on WP being more fully initialized
     * (taxonomies registered, locale loaded). Runs after register_assets so the
     * script handles already exist; localize calls attach the inline payloads.
     */
    public function register_localized_data(): void {
        // Categories + tags for the inspector dropdowns.
        wp_localize_script('lrob-calendar-blocks-shared', 'lrobCalendarBlocks', [
            'categories' => $this->get_terms_for_blocks(LRob_Calendar_Post_Types::TAX_CATEGORY),
            'tags'       => $this->get_terms_for_blocks(LRob_Calendar_Post_Types::TAX_TAG),
        ]);

        // Calendar viewScript runtime config (month/day names, locale, icons, etc.).
        $this->localize_view_script();

        // Card expand/collapse labels (used by event-card-expand.js).
        wp_localize_script('lrob-calendar-event-card-expand', 'lrobCalCardI18n', [
            'showMore' => __('Show more', 'lrob-calendar'),
            'showLess' => __('Show less', 'lrob-calendar'),
        ]);
    }

    private function localize_view_script(): void {
        global $wp_locale;

        $month_names = [];
        for ($i = 1; $i <= 12; $i++) {
            $month_names[] = $wp_locale->get_month($i);
        }
        $day_names = [];
        for ($i = 0; $i <= 6; $i++) {
            $day_names[] = $wp_locale->get_weekday_abbrev($wp_locale->get_weekday($i));
        }

        wp_localize_script('lrob-calendar-view', 'lrobCalendar', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'restUrl'            => rest_url('lrob-calendar/v1/events'),
            'nonce'              => wp_create_nonce('lrob_calendar'),
            'monthNames'         => $month_names,
            'dayNames'           => $day_names,
            'startOfWeek'        => LRob_Calendar::get_start_of_week(),
            'siteLocale'         => str_replace('_', '-', get_locale()),
            'publicPagesEnabled' => LRob_Calendar::public_pages_enabled(),
            'i18n' => [
                'close'       => __('Close', 'lrob-calendar'),
                'viewImage'   => __('View image', 'lrob-calendar'),
                'prevEvent'   => __('Previous event', 'lrob-calendar'),
                'nextEvent'   => __('Next event', 'lrob-calendar'),
                'noUpcoming'  => __('No upcoming events.', 'lrob-calendar'),
                'recurring'   => __('Recurring', 'lrob-calendar'),
                'free'        => __('Free', 'lrob-calendar'),
                'getTickets'  => __('Get tickets', 'lrob-calendar'),
            ],
            // SVG markup for the popup's inline pictograms — single source of truth (LRob_Calendar_Icons).
            'icons' => [
                'calendar'  => LRob_Calendar_Icons::get('calendar'),
                'clock'     => LRob_Calendar_Icons::get('clock'),
                'location'  => LRob_Calendar_Icons::get('location'),
                'recurring' => LRob_Calendar_Icons::get('recurring'),
                'ticket'    => LRob_Calendar_Icons::get('ticket'),
            ],
        ]);
    }

    private function get_terms_for_blocks(string $taxonomy): array {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(fn($term) => ['value' => $term->term_id, 'label' => $term->name], $terms);
    }

    public function register_blocks(): void {
        register_block_type(LROB_CALENDAR_PATH . 'blocks/calendar');
        register_block_type(LROB_CALENDAR_PATH . 'blocks/events-list');
        register_block_type(LROB_CALENDAR_PATH . 'blocks/single-event');
    }

    public function register_rest_routes(): void {
        // Explicit args schema gives us free validation + sanitization (and helps
        // any future OpenAPI tooling / WP-CLI introspection).
        $list_args = [
            'range_start' => [
                'description'       => __('Unix timestamp; only events ending at/after this are returned.', 'lrob-calendar'),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'range_end' => [
                'description'       => __('Unix timestamp; only events starting at/before this are returned.', 'lrob-calendar'),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'category' => [
                'description'       => __('Event category term ID.', 'lrob-calendar'),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'tag' => [
                'description'       => __('Event tag term ID.', 'lrob-calendar'),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'limit' => [
                'description'       => __('Maximum number of events to return.', 'lrob-calendar'),
                'type'              => 'integer',
                'default'           => 500,
                'minimum'           => 1,
                'maximum'           => 2000,
                'sanitize_callback' => 'absint',
            ],
            'include_past' => [
                'description'       => __('When 1, include events that have already ended (default: upcoming only).', 'lrob-calendar'),
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
        ];

        register_rest_route('lrob-calendar/v1', '/events', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_events'],
            'permission_callback' => '__return_true', // public-facing calendar
            'args'                => $list_args,
        ]);

        register_rest_route('lrob-calendar/v1', '/events/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'rest_get_event'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'description'       => __('Event post ID.', 'lrob-calendar'),
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'required'          => true,
                ],
            ],
        ]);
    }

    public function rest_get_events(WP_REST_Request $request): WP_REST_Response {
        $args = [
            'limit'    => (int) $request->get_param('limit'),
            'category' => $request->get_param('category') ?: null,
            'tag'      => $request->get_param('tag') ?: null,
        ];

        $range_start = $request->get_param('range_start');
        if ($range_start !== null && $range_start !== '') {
            $args['start'] = (int) $range_start;
        } elseif (!$request->get_param('include_past')) {
            $args['start'] = time();
        }

        $range_end = $request->get_param('range_end');
        if ($range_end !== null && $range_end !== '') {
            $args['end'] = (int) $range_end;
        }

        // Transient cache (versioned: bumped on event save/delete so cached entries
        // become unreachable rather than needing per-key deletion).
        $cache_key = $this->rest_cache_key($args);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return new WP_REST_Response($cached);
        }

        $events = LRob_Calendar_Event::get_events($args);
        LRob_Calendar_Block_Helpers::prime_caches_for_events($events);
        $response = array_map(
            [LRob_Calendar_Block_Helpers::class, 'format_event_for_client'],
            $events
        );

        set_transient($cache_key, $response, self::REST_CACHE_TTL);
        return new WP_REST_Response($response);
    }

    public function rest_get_event(WP_REST_Request $request): WP_REST_Response {
        $event = new LRob_Calendar_Event((int) $request->get_param('id'));

        if (!$event->get_post()) {
            return new WP_REST_Response(['error' => 'Event not found'], 404);
        }

        return new WP_REST_Response(LRob_Calendar_Block_Helpers::format_event_for_client($event));
    }
}
