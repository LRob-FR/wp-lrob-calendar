<?php
/**
 * Custom event management screen (v1.2).
 *
 * A dynamic, REST-driven list of events that replaces the stock WordPress post
 * table as the primary way authors browse and manage events. The create/edit
 * modal is layered on in a later phase; for now the screen lists events, filters
 * them, and deletes without a page reload, bridging edit/new to the native
 * editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Manage_Screen {

    const SLUG = 'lrob-calendar-manage';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE,
            __('Manage Events', 'lrob-calendar'),
            __('Manage Events', 'lrob-calendar'),
            'edit_lrob_events',
            self::SLUG,
            [$this, 'render']
        );
    }

    private function is_screen(string $hook): bool {
        return $hook === LRob_Calendar_Post_Types::POST_TYPE . '_page_' . self::SLUG;
    }

    public function enqueue_assets(string $hook): void {
        if (!$this->is_screen($hook)) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'lrob-calendar-manage',
            LROB_CALENDAR_URL . 'admin/css/manage-events.css',
            [],
            LROB_CALENDAR_VERSION
        );
        wp_enqueue_style(
            'lrob-calendar-event-modal',
            LROB_CALENDAR_URL . 'admin/css/event-modal.css',
            ['lrob-calendar-manage'],
            LROB_CALENDAR_VERSION
        );

        // The modal script loads first and carries the shared config global; the
        // list script depends on it so window.lrobCalendarManage is always ready.
        wp_enqueue_script(
            'lrob-calendar-event-modal',
            LROB_CALENDAR_URL . 'admin/js/event-modal.js',
            ['wp-i18n'],
            LROB_CALENDAR_VERSION,
            true
        );
        wp_set_script_translations('lrob-calendar-event-modal', 'lrob-calendar', LROB_CALENDAR_PATH . 'languages');

        wp_enqueue_script(
            'lrob-calendar-manage',
            LROB_CALENDAR_URL . 'admin/js/manage-events.js',
            ['wp-i18n', 'lrob-calendar-event-modal'],
            LROB_CALENDAR_VERSION,
            true
        );
        wp_set_script_translations('lrob-calendar-manage', 'lrob-calendar', LROB_CALENDAR_PATH . 'languages');

        $public_pages = LRob_Calendar::public_pages_enabled();

        wp_localize_script('lrob-calendar-event-modal', 'lrobCalendarManage', [
            'restRoot'     => esc_url_raw(rest_url(LRob_Calendar_Admin_REST::NAMESPACE . '/admin/events')),
            'nonce'        => wp_create_nonce('wp_rest'),
            'newLink'      => admin_url('post-new.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE),
            'canPublish'   => current_user_can('publish_lrob_events'),
            'dateFormat'   => get_option('date_format'),
            'timeFormat'   => get_option('time_format'),
            'locale'       => str_replace('_', '-', get_locale()),
            'categories'   => $this->term_options(LRob_Calendar_Post_Types::TAX_CATEGORY),
            'tags'         => $this->term_options(LRob_Calendar_Post_Types::TAX_TAG),
            'statuses'     => [
                ['value' => 'any',     'label' => __('All statuses', 'lrob-calendar')],
                ['value' => 'publish', 'label' => __('Published', 'lrob-calendar')],
                ['value' => 'draft',   'label' => __('Draft', 'lrob-calendar')],
                ['value' => 'pending', 'label' => __('Pending', 'lrob-calendar')],
                ['value' => 'future',  'label' => __('Scheduled', 'lrob-calendar')],
                ['value' => 'private', 'label' => __('Private', 'lrob-calendar')],
            ],
            // Modal editor:
            'defaultTimezone'  => LRob_Calendar_Event::get_default_timezone(),
            'timezoneChoice'   => wp_timezone_choice(LRob_Calendar_Event::get_default_timezone()),
            'publicPages'      => $public_pages,
            // Adaptive recommended description length (chars). Tighter when there
            // is no public event page to host a long write-up.
            'descRecommended'  => $public_pages
                ? (int) apply_filters('lrob_calendar_description_recommended', 800)
                : (int) apply_filters('lrob_calendar_description_recommended_no_pages', 350),
        ]);
    }

    private function term_options(string $taxonomy): array {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(static function ($t) {
            return ['value' => (int) $t->term_id, 'label' => $t->name, 'parent' => (int) $t->parent];
        }, $terms);
    }

    public function render(): void {
        if (!current_user_can('edit_lrob_events')) {
            return;
        }
        ?>
        <div class="wrap lrob-manage-wrap">
            <div id="lrob-cal-manage" class="lrob-cal-manage">
                <noscript><?php esc_html_e('This screen requires JavaScript.', 'lrob-calendar'); ?></noscript>
            </div>
        </div>
        <?php
    }
}
