<?php
/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar {
    
    private static $instance = null;
    
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies(): void {
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-post-types.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-database.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-event.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-recurrence.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-icons.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-block-helpers.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-blocks.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-single-event.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-export.php';
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-import.php';
        // Updater needs to load on every request — WP-cron driven update
        // checks happen on the frontend too, and those should still pick up
        // a new GitHub release.
        require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-updater.php';

        if (is_admin()) {
            require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-admin.php';
            require_once LROB_CALENDAR_PATH . 'includes/class-lrob-calendar-meta-boxes.php';
        }
    }
    
    private function init_hooks(): void {
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [new LRob_Calendar_Post_Types(), 'register']);
        // Rewrite rules need rebuilding when the "disable public pages" toggle changes.
        // Settings save sets this flag; we consume it AFTER post-type registration above.
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 20);

        // The single-event page CSS is needed only when an event single page is
        // rendered. Block CSS/JS are enqueued by WordPress via block.json — fully
        // conditional on the block being present on the page.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_single_event_page_assets']);

        new LRob_Calendar_Blocks();
        new LRob_Calendar_Single_Event();
        new LRob_Calendar_Updater();

        if (is_admin()) {
            new LRob_Calendar_Admin();
            new LRob_Calendar_Meta_Boxes();
        }
    }

    public function maybe_flush_rewrite_rules(): void {
        if (get_option('lrob_calendar_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_option('lrob_calendar_flush_rewrite_rules');
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'lrob-calendar',
            false,
            dirname(LROB_CALENDAR_BASENAME) . '/languages'
        );
    }

    /**
     * Enqueue the_content-injection styles only on the event single-post template,
     * and only when public event pages are enabled (otherwise that template is 404).
     */
    public function enqueue_single_event_page_assets(): void {
        if (!is_singular(LRob_Calendar_Post_Types::POST_TYPE)) {
            return;
        }
        if (!self::public_pages_enabled()) {
            return;
        }
        wp_enqueue_style('lrob-calendar-single-event-page');
    }

    /**
     * Whether events and their taxonomies expose public pages (single-event
     * URLs and taxonomy archives). When false, those URLs return 404 and the
     * frontend renderers strip clickable links — the calendar block popup
     * becomes the only way to view event details.
     */
    public static function public_pages_enabled(): bool {
        return !get_option('lrob_calendar_disable_public_pages', false);
    }

    /**
     * Effective first day of week (0 = Sunday … 6 = Saturday).
     *
     * Reads the `lrob_calendar_start_of_week` option. When set to 'auto' (default),
     * locales starting with `en` default to Sunday; all others default to Monday
     * (ISO 8601, used across most of continental Europe).
     */
    public static function get_start_of_week(): int {
        $setting = get_option('lrob_calendar_start_of_week', 'auto');

        if ($setting === 'auto') {
            return str_starts_with(get_locale(), 'en') ? 0 : 1;
        }

        $value = (int) $setting;
        return ($value >= 0 && $value <= 6) ? $value : 1;
    }

    public static function activate(): void {
        LRob_Calendar_Database::maybe_upgrade();

        $post_types = new LRob_Calendar_Post_Types();
        $post_types->register();

        flush_rewrite_rules();
    }
    
    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
