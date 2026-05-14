<?php
/**
 * Admin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_init', [$this, 'handle_import']);

        // "Visit lrob.fr" link on the plugins.php row.
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
        
        // Category meta fields
        add_action(LRob_Calendar_Post_Types::TAX_CATEGORY . '_add_form_fields', [$this, 'category_add_fields']);
        add_action(LRob_Calendar_Post_Types::TAX_CATEGORY . '_edit_form_fields', [$this, 'category_edit_fields']);
        add_action('created_' . LRob_Calendar_Post_Types::TAX_CATEGORY, [$this, 'save_category_meta']);
        add_action('edited_' . LRob_Calendar_Post_Types::TAX_CATEGORY, [$this, 'save_category_meta']);
        add_action('delete_' . LRob_Calendar_Post_Types::TAX_CATEGORY, [$this, 'delete_category_meta']);
        
        // Delete event data when post is deleted
        add_action('before_delete_post', [$this, 'delete_event_data']);
        
        // Admin columns
        add_filter('manage_' . LRob_Calendar_Post_Types::POST_TYPE . '_posts_columns', [$this, 'add_columns']);
        add_action('manage_' . LRob_Calendar_Post_Types::POST_TYPE . '_posts_custom_column', [$this, 'render_columns'], 10, 2);
        add_filter('manage_edit-' . LRob_Calendar_Post_Types::POST_TYPE . '_sortable_columns', [$this, 'sortable_columns']);
    }
    
    public function add_menu_pages(): void {
        add_submenu_page(
            'edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE,
            __('Import / Export', 'lrob-calendar'),
            __('Import / Export', 'lrob-calendar'),
            'manage_options',
            'lrob-calendar-import-export',
            [$this, 'render_import_export_page']
        );
        
        add_submenu_page(
            'edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE,
            __('Settings', 'lrob-calendar'),
            __('Settings', 'lrob-calendar'),
            'manage_options',
            'lrob-calendar-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function enqueue_assets(string $hook): void {
        global $post_type, $taxonomy;
        
        // Load on event edit pages and category pages
        $is_event_page = ($post_type === LRob_Calendar_Post_Types::POST_TYPE);
        $is_category_page = (
            $taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY ||
            (isset($_GET['taxonomy']) && $_GET['taxonomy'] === LRob_Calendar_Post_Types::TAX_CATEGORY)
        );
        
        if (!$is_event_page && !$is_category_page) {
            return;
        }
        
        // Enqueue media library
        wp_enqueue_media();
        
        // Enqueue WordPress color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        wp_enqueue_style(
            'lrob-calendar-admin',
            LROB_CALENDAR_URL . 'admin/css/lrob-calendar-admin.css',
            ['wp-color-picker'],
            LROB_CALENDAR_VERSION
        );
        
        wp_enqueue_script(
            'lrob-calendar-admin',
            LROB_CALENDAR_URL . 'admin/js/lrob-calendar-admin.js',
            ['jquery', 'jquery-ui-datepicker', 'wp-color-picker', 'wp-date', 'wp-i18n'],
            LROB_CALENDAR_VERSION,
            true
        );

        wp_localize_script('lrob-calendar-admin', 'lrobCalendarAdmin', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('lrob_calendar_admin'),
            'selectImageTitle' => __('Select Image', 'lrob-calendar'),
            'useImageText'     => __('Use this image', 'lrob-calendar'),
            // PHP date() format tokens; wp.date.dateI18n() in admin JS uses these
            // to render the live preview in the site's locale, not the browser's.
            'dateFormat'       => get_option('date_format'),
            'timeFormat'       => get_option('time_format'),
        ]);
    }
    
    public function render_import_export_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $message = '';
        if (isset($_GET['exported'])) {
            $message = '<div class="notice notice-success"><p>' . esc_html__('Export completed successfully.', 'lrob-calendar') . '</p></div>';
        }
        if (isset($_GET['imported'])) {
            $count = (int) $_GET['imported'];
            /* translators: %d: number of events imported */
            $message = '<div class="notice notice-success"><p>' . sprintf(esc_html__('%d events imported successfully.', 'lrob-calendar'), $count) . '</p></div>';
        }
        if (isset($_GET['import_error'])) {
            $message = '<div class="notice notice-error"><p>' . esc_html__('Import failed. Please check your file format.', 'lrob-calendar') . '</p></div>';
        }

        // Non-blocking warnings from the previous import (large file size,
        // private-IP image URLs). Stored in a transient by handle_import().
        $warnings = get_transient('lrob_calendar_import_warnings');
        if (is_array($warnings) && !empty($warnings)) {
            delete_transient('lrob_calendar_import_warnings');
            foreach ($warnings as $w) {
                $message .= '<div class="notice notice-warning"><p>' . esc_html($w) . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import / Export Events', 'lrob-calendar'); ?></h1>

            <?php echo $message; // already escaped above (esc_html__ in success/error notices) ?>

            <div class="lrob-import-export-container">
                <div class="lrob-box">
                    <h2><?php esc_html_e('Export', 'lrob-calendar'); ?></h2>
                    <p><?php esc_html_e('Export all events, categories and tags to a JSON file.', 'lrob-calendar'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('lrob_calendar_export', 'lrob_export_nonce'); ?>
                        <p>
                            <label>
                                <input type="checkbox" name="include_instances" value="1" checked>
                                <?php esc_html_e('Include recurring event instances', 'lrob-calendar'); ?>
                            </label>
                        </p>
                        <p>
                            <button type="submit" name="lrob_export" class="button button-primary">
                                <?php esc_html_e('Export to JSON', 'lrob-calendar'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <div class="lrob-box">
                    <h2><?php esc_html_e('Import', 'lrob-calendar'); ?></h2>
                    <p><?php esc_html_e('Import events from a JSON file (LRob Calendar or All-in-One Event Calendar format).', 'lrob-calendar'); ?></p>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php wp_nonce_field('lrob_calendar_import', 'lrob_import_nonce'); ?>
                        <p>
                            <input type="file" name="import_file" accept=".json" required>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="skip_existing" value="1" checked>
                                <?php esc_html_e('Skip existing events (match by title)', 'lrob-calendar'); ?>
                            </label>
                        </p>
                        <p>
                            <button type="submit" name="lrob_import" class="button button-primary">
                                <?php esc_html_e('Import from JSON', 'lrob-calendar'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <style>
            .lrob-import-export-container { display: flex; gap: 20px; margin-top: 20px; }
            .lrob-box { background: #fff; padding: 20px; border: 1px solid #ccd0d4; flex: 1; }
            .lrob-box h2 { margin-top: 0; }
        </style>
        <?php
        $this->render_credit_footer();
    }

    /**
     * Small "by LRob" credit + version block shown at the bottom of each
     * plugin admin page. Centralized so a single edit updates every page.
     */
    private function render_credit_footer(): void {
        ?>
        <p class="lrob-calendar-credit"
           style="margin-top: 2em; padding-top: 1em; border-top: 1px solid #dcdcde; color: #50575e; font-size: 12px;">
            <?php
            printf(
                /* translators: 1: plugin name with link to author, 2: version number */
                esc_html__('%1$s — version %2$s', 'lrob-calendar'),
                '<strong><a href="https://www.lrob.fr" target="_blank" rel="noopener">LRob Calendar</a></strong>',
                esc_html(LROB_CALENDAR_VERSION)
            );
            ?>
            &nbsp;·&nbsp;
            <a href="<?php echo esc_url(LROB_CALENDAR_GITHUB_URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('Source on GitHub', 'lrob-calendar'); ?></a>
            &nbsp;·&nbsp;
            <a href="<?php echo esc_url(LROB_CALENDAR_GITHUB_ISSUES_URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('Report an issue', 'lrob-calendar'); ?></a>
        </p>
        <?php
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_POST['lrob_save_settings']) && wp_verify_nonce($_POST['lrob_settings_nonce'], 'lrob_calendar_settings')) {
            update_option('lrob_calendar_default_timezone', sanitize_text_field($_POST['default_timezone'] ?? 'UTC'));

            $start_of_week = sanitize_text_field($_POST['start_of_week'] ?? 'auto');
            if (!in_array($start_of_week, ['auto', '0', '1', '6'], true)) {
                $start_of_week = 'auto';
            }
            update_option('lrob_calendar_start_of_week', $start_of_week);

            // Public-pages toggle: schedule a rewrite-rules flush if it changed,
            // consumed on the next init after the CPT re-registers with new flags.
            $previous_disabled = (bool) get_option('lrob_calendar_disable_public_pages', false);
            $new_disabled      = isset($_POST['disable_public_pages']);
            update_option('lrob_calendar_disable_public_pages', $new_disabled ? 1 : 0);
            if ($previous_disabled !== $new_disabled) {
                update_option('lrob_calendar_flush_rewrite_rules', 1);
            }

            $max_age = max(0, (int) ($_POST['max_event_age_months'] ?? 0));
            update_option('lrob_calendar_max_event_age_months', $max_age);

            $max_inst  = max(1, (int) ($_POST['max_recurrence_instances'] ?? 500));
            $max_years = max(1, (int) ($_POST['max_recurrence_years'] ?? 5));
            update_option('lrob_calendar_max_recurrence_instances', $max_inst);
            update_option('lrob_calendar_max_recurrence_years', $max_years);

            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'lrob-calendar') . '</p></div>';
        }

        $default_timezone = get_option('lrob_calendar_default_timezone', wp_timezone_string());
        $start_of_week    = get_option('lrob_calendar_start_of_week', 'auto');
        $disable_pages    = (bool) get_option('lrob_calendar_disable_public_pages', false);
        $max_age          = (int) get_option('lrob_calendar_max_event_age_months', 0);
        $max_inst         = (int) get_option('lrob_calendar_max_recurrence_instances', 500);
        $max_years        = (int) get_option('lrob_calendar_max_recurrence_years', 5);
        $effective_start_of_week = LRob_Calendar::get_start_of_week();
        $day_labels = [
            0 => __('Sunday', 'lrob-calendar'),
            1 => __('Monday', 'lrob-calendar'),
            6 => __('Saturday', 'lrob-calendar'),
        ];
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Calendar Settings', 'lrob-calendar'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('lrob_calendar_settings', 'lrob_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="default_timezone"><?php esc_html_e('Default Timezone', 'lrob-calendar'); ?></label></th>
                        <td>
                            <select name="default_timezone" id="default_timezone">
                                <?php echo wp_timezone_choice($default_timezone); ?>
                            </select>
                            <p class="description"><?php esc_html_e('Default timezone for new events.', 'lrob-calendar'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="start_of_week"><?php esc_html_e('First day of the week', 'lrob-calendar'); ?></label></th>
                        <td>
                            <select name="start_of_week" id="start_of_week">
                                <option value="auto" <?php selected($start_of_week, 'auto'); ?>>
                                    <?php
                                    printf(
                                        /* translators: %s: the auto-detected first day of the week (e.g. Sunday or Monday) */
                                        esc_html__('Auto — follow site language (currently %s)', 'lrob-calendar'),
                                        esc_html($day_labels[$effective_start_of_week] ?? $day_labels[1])
                                    );
                                    ?>
                                </option>
                                <option value="0" <?php selected($start_of_week, '0'); ?>><?php echo esc_html($day_labels[0]); ?></option>
                                <option value="1" <?php selected($start_of_week, '1'); ?>><?php echo esc_html($day_labels[1]); ?></option>
                                <option value="6" <?php selected($start_of_week, '6'); ?>><?php echo esc_html($day_labels[6]); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Day shown in the first column of the calendar grid.', 'lrob-calendar'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Public event pages', 'lrob-calendar'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="disable_public_pages" value="1" <?php checked($disable_pages); ?>>
                                <?php esc_html_e('Disable dedicated pages for events and categories', 'lrob-calendar'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When disabled, events and their taxonomies are only accessible through the calendar. Direct URLs return 404, and frontend links are stripped from blocks and the calendar popup.', 'lrob-calendar'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_event_age_months"><?php esc_html_e('Maximum event age', 'lrob-calendar'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="max_event_age_months" name="max_event_age_months"
                                   value="<?php echo esc_attr($max_age); ?>" min="0" max="600" step="1" style="width: 80px;">
                            <span class="description"><?php esc_html_e('months', 'lrob-calendar'); ?></span>
                            <p class="description">
                                <?php esc_html_e('Queries that don\'t set their own date range will ignore events older than this. Use 0 for no limit. Helps performance on sites with thousands of historical events.', 'lrob-calendar'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_recurrence_instances"><?php esc_html_e('Recurrence limits', 'lrob-calendar'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="number" id="max_recurrence_instances" name="max_recurrence_instances"
                                       value="<?php echo esc_attr($max_inst); ?>" min="1" max="10000" step="1" style="width: 90px;">
                                <?php esc_html_e('max instances per event', 'lrob-calendar'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="number" id="max_recurrence_years" name="max_recurrence_years"
                                       value="<?php echo esc_attr($max_years); ?>" min="1" max="100" step="1" style="width: 90px;">
                                <?php esc_html_e('max years ahead', 'lrob-calendar'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Safety caps for recurring-event instance generation. Raise these for events that repeat daily over many years (default 500 / 5 = ~1.4 years of a daily event).', 'lrob-calendar'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Date & Time Format', 'lrob-calendar'); ?></th>
                        <td>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %1$s: opening anchor tag, %2$s: closing anchor tag */
                                    esc_html__('Date and time formats follow your %1$sWordPress settings%2$s.', 'lrob-calendar'),
                                    '<a href="' . esc_url(admin_url('options-general.php')) . '">',
                                    '</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="lrob_save_settings" class="button button-primary">
                        <?php esc_html_e('Save Settings', 'lrob-calendar'); ?>
                    </button>
                </p>
            </form>
            <?php $this->render_credit_footer(); ?>
        </div>
        <?php
    }

    /**
     * Add "GitHub" + "Visit LRob" links to this plugin's row on wp-admin/plugins.php.
     */
    public function plugin_row_meta(array $links, string $file): array {
        if ($file !== LROB_CALENDAR_BASENAME) {
            return $links;
        }
        $links[] = '<a href="' . esc_url(LROB_CALENDAR_GITHUB_URL) . '" target="_blank" rel="noopener">' .
            esc_html__('GitHub', 'lrob-calendar') . '</a>';
        $links[] = '<a href="https://www.lrob.fr" target="_blank" rel="noopener">' .
            esc_html__('Visit LRob', 'lrob-calendar') . '</a>';
        return $links;
    }

    public function handle_export(): void {
        if (!isset($_POST['lrob_export']) || !wp_verify_nonce($_POST['lrob_export_nonce'] ?? '', 'lrob_calendar_export')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $include_instances = isset($_POST['include_instances']);
        
        $exporter = new LRob_Calendar_Export();
        $data = $exporter->export_all($include_instances);
        
        $filename = 'lrob-calendar-export-' . date('Y-m-d-His') . '.json';
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    public function handle_import(): void {
        if (!isset($_POST['lrob_import']) || !wp_verify_nonce($_POST['lrob_import_nonce'] ?? '', 'lrob_calendar_import')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(add_query_arg('import_error', 1, admin_url('edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE . '&page=lrob-calendar-import-export')));
            exit;
        }

        $warnings = [];

        // Heads-up for large imports (not a block — admin may legitimately import
        // years of events at once on a server with elevated PHP limits).
        $file_size = (int) $_FILES['import_file']['size'];
        if ($file_size > 10 * 1024 * 1024) {
            $warnings[] = sprintf(
                /* translators: %s: human-readable file size (e.g. "12 MB") */
                __('Large import file (%s). If the page times out, increase your PHP memory_limit / max_execution_time.', 'lrob-calendar'),
                size_format($file_size)
            );
        }

        $content = file_get_contents($_FILES['import_file']['tmp_name']);
        $data    = json_decode($content, true);

        if (!$data) {
            wp_redirect(add_query_arg('import_error', 1, admin_url('edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE . '&page=lrob-calendar-import-export')));
            exit;
        }

        $skip_existing = isset($_POST['skip_existing']);

        $importer = new LRob_Calendar_Import();
        $count    = $importer->import($data, $skip_existing);

        $warnings = array_merge($warnings, $importer->get_warnings());
        if (!empty($warnings)) {
            // Survives the redirect via a short transient (admin-only, 60 s).
            set_transient('lrob_calendar_import_warnings', $warnings, 60);
        }

        wp_redirect(add_query_arg('imported', $count, admin_url('edit.php?post_type=' . LRob_Calendar_Post_Types::POST_TYPE . '&page=lrob-calendar-import-export')));
        exit;
    }
    
    // Category meta fields
    
    public function category_add_fields(): void {
        ?>
        <div class="form-field">
            <label for="lrob_cat_color"><?php esc_html_e('Color', 'lrob-calendar'); ?></label>
            <input type="text" name="lrob_cat_color" id="lrob_cat_color" value="#3788d8" class="lrob-color-picker">
        </div>
        <div class="form-field">
            <label for="lrob_cat_image_id"><?php esc_html_e('Image', 'lrob-calendar'); ?></label>
            <input type="hidden" name="lrob_cat_image_id" id="lrob_cat_image_id" value="">
            <div id="lrob_cat_image_preview" class="lrob-image-preview"></div>
            <button type="button" class="button lrob-select-image" data-target="lrob_cat_image_id" data-preview="lrob_cat_image_preview">
                <?php esc_html_e('Select Image', 'lrob-calendar'); ?>
            </button>
            <button type="button" class="button lrob-remove-image" data-target="lrob_cat_image_id" data-preview="lrob_cat_image_preview" style="display:none;">
                <?php esc_html_e('Remove Image', 'lrob-calendar'); ?>
            </button>
        </div>
        <?php
    }
    
    public function category_edit_fields($term): void {
        global $wpdb;
        
        $table = LRob_Calendar_Database::get_category_meta_table();
        $meta = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE term_id = %d",
            $term->term_id
        ));
        
        $color = $meta->color ?? '#3788d8';
        $image_url = $meta->image ?? '';
        $image_id = $image_url ? attachment_url_to_postid($image_url) : 0;
        
        ?>
        <tr class="form-field">
            <th scope="row"><label for="lrob_cat_color"><?php esc_html_e('Color', 'lrob-calendar'); ?></label></th>
            <td>
                <input type="text" name="lrob_cat_color" id="lrob_cat_color" value="<?php echo esc_attr($color); ?>" class="lrob-color-picker">
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e('Image', 'lrob-calendar'); ?></label></th>
            <td>
                <input type="hidden" name="lrob_cat_image_id" id="lrob_cat_image_id" value="<?php echo esc_attr($image_id); ?>">
                <div id="lrob_cat_image_preview" class="lrob-image-preview">
                    <?php if ($image_url): ?>
                        <img src="<?php echo esc_url($image_url); ?>" style="max-width:150px;height:auto;">
                    <?php endif; ?>
                </div>
                <button type="button" class="button lrob-select-image" data-target="lrob_cat_image_id" data-preview="lrob_cat_image_preview">
                    <?php esc_html_e('Select Image', 'lrob-calendar'); ?>
                </button>
                <button type="button" class="button lrob-remove-image" data-target="lrob_cat_image_id" data-preview="lrob_cat_image_preview" style="<?php echo $image_url ? '' : 'display:none;'; ?>">
                    <?php esc_html_e('Remove Image', 'lrob-calendar'); ?>
                </button>
            </td>
        </tr>
        <?php
    }
    
    public function save_category_meta(int $term_id): void {
        global $wpdb;
        
        $table = LRob_Calendar_Database::get_category_meta_table();
        $color = sanitize_hex_color($_POST['lrob_cat_color'] ?? '');
        
        $image_id = absint($_POST['lrob_cat_image_id'] ?? 0);
        $image = $image_id ? wp_get_attachment_url($image_id) : '';
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$table} WHERE term_id = %d",
            $term_id
        ));
        
        if ($exists) {
            $wpdb->update($table, ['color' => $color, 'image' => $image], ['term_id' => $term_id]);
        } else {
            $wpdb->insert($table, ['term_id' => $term_id, 'color' => $color, 'image' => $image]);
        }

        LRob_Calendar_Block_Helpers::invalidate_category_color($term_id);
    }

    public function delete_category_meta(int $term_id): void {
        global $wpdb;
        $table = LRob_Calendar_Database::get_category_meta_table();
        $wpdb->delete($table, ['term_id' => $term_id]);

        LRob_Calendar_Block_Helpers::invalidate_category_color($term_id);
    }
    
    public function delete_event_data(int $post_id): void {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== LRob_Calendar_Post_Types::POST_TYPE) {
            return;
        }
        
        $event = new LRob_Calendar_Event($post_id);
        $event->delete();
    }
    
    // Admin columns
    
    public function add_columns(array $columns): array {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'title') {
                $new_columns['event_date'] = __('Date', 'lrob-calendar');
                $new_columns['event_location'] = __('Location', 'lrob-calendar');
            }
        }
        
        return $new_columns;
    }
    
    public function render_columns(string $column, int $post_id): void {
        $event = new LRob_Calendar_Event($post_id);
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        
        switch ($column) {
            case 'event_date':
                $start_ts = $event->get('start');
                
                if ($event->is_allday()) {
                    echo esc_html(wp_date($date_format, $start_ts));
                } else {
                    echo esc_html(wp_date($date_format . ' ' . $time_format, $start_ts));
                }
                
                if ($event->is_recurring()) {
                    echo ' <span class="dashicons dashicons-controls-repeat" title="' . esc_attr__('Recurring', 'lrob-calendar') . '"></span>';
                }
                break;
                
            case 'event_location':
                $venue = $event->get('venue');
                $city = $event->get('city');
                
                $parts = array_filter([$venue, $city]);
                echo esc_html(implode(', ', $parts) ?: '—');
                break;
        }
    }
    
    public function sortable_columns(array $columns): array {
        $columns['event_date'] = 'event_date';
        return $columns;
    }
}
