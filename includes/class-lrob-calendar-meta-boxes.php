<?php
/**
 * Meta boxes for event editing
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Meta_Boxes {
    
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . LRob_Calendar_Post_Types::POST_TYPE, [$this, 'save_meta_boxes'], 10, 2);
    }
    
    public function add_meta_boxes(): void {
        add_meta_box(
            'lrob_event_datetime',
            __('Date & Time', 'lrob-calendar'),
            [$this, 'render_datetime_box'],
            LRob_Calendar_Post_Types::POST_TYPE,
            'normal',
            'high'
        );
        
        add_meta_box(
            'lrob_event_recurrence',
            __('Recurrence', 'lrob-calendar'),
            [$this, 'render_recurrence_box'],
            LRob_Calendar_Post_Types::POST_TYPE,
            'normal',
            'high'
        );
        
        add_meta_box(
            'lrob_event_location',
            __('Location', 'lrob-calendar'),
            [$this, 'render_location_box'],
            LRob_Calendar_Post_Types::POST_TYPE,
            'normal',
            'default'
        );
        
        add_meta_box(
            'lrob_event_contact',
            __('Contact & Cost', 'lrob-calendar'),
            [$this, 'render_contact_box'],
            LRob_Calendar_Post_Types::POST_TYPE,
            'normal',
            'default'
        );
    }
    
    public function render_datetime_box(WP_Post $post): void {
        $event = new LRob_Calendar_Event($post->ID);
        
        $start_ts = $event->get('start');
        $end_ts = $event->get('end');
        
        $start = $start_ts ? $event->get_start_datetime() : new DateTimeImmutable('+1 hour');
        $end = $end_ts ? $event->get_end_datetime() : new DateTimeImmutable('+2 hours');
        $timezone = $event->get('timezone') ?: LRob_Calendar_Event::get_default_timezone();
        $allday = $event->get('allday');
        $instant = $event->get('instant_event');
        
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        
        wp_nonce_field('lrob_calendar_save', 'lrob_calendar_nonce');
        ?>
        <table class="form-table lrob-meta-table">
            <tr>
                <th><label><?php esc_html_e('Start', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="date" name="lrob_start_date" id="lrob_start_date" 
                           value="<?php echo esc_attr($start->format('Y-m-d')); ?>" required>
                    <input type="time" name="lrob_start_time" id="lrob_start_time" 
                           value="<?php echo esc_attr($start->format('H:i')); ?>" class="lrob-time-input">
                    <span class="lrob-date-formatted"><?php echo esc_html(wp_date($date_format . ' ' . $time_format, $start_ts ?: $start->getTimestamp())); ?></span>
                </td>
            </tr>
            <tr class="lrob-end-row">
                <th><label><?php esc_html_e('End', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="date" name="lrob_end_date" id="lrob_end_date"
                           value="<?php echo esc_attr($end->format('Y-m-d')); ?>" required>
                    <input type="time" name="lrob_end_time" id="lrob_end_time"
                           value="<?php echo esc_attr($end->format('H:i')); ?>" class="lrob-time-input">
                    <span class="lrob-date-formatted"><?php echo esc_html(wp_date($date_format . ' ' . $time_format, $end_ts ?: $end->getTimestamp())); ?></span>
                </td>
            </tr>
            <tr>
                <th><label for="lrob_timezone"><?php esc_html_e('Timezone', 'lrob-calendar'); ?></label></th>
                <td>
                    <select name="lrob_timezone" id="lrob_timezone">
                        <?php echo wp_timezone_choice($timezone); ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Event type', 'lrob-calendar'); ?></th>
                <td>
                    <?php
                    // Derive radio value from the two underlying flags.
                    // (DB still stores allday + instant_event separately — no schema change.)
                    $event_type = $allday ? 'allday' : ($instant ? 'instant' : 'standard');
                    ?>
                    <label>
                        <input type="radio" name="lrob_event_type" value="standard" <?php checked($event_type, 'standard'); ?>>
                        <?php esc_html_e('Standard event', 'lrob-calendar'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="lrob_event_type" value="allday" <?php checked($event_type, 'allday'); ?>>
                        <?php esc_html_e('All day event', 'lrob-calendar'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="lrob_event_type" value="instant" <?php checked($event_type, 'instant'); ?>>
                        <?php esc_html_e('No end time (instant event)', 'lrob-calendar'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function render_recurrence_box(WP_Post $post): void {
        $event = new LRob_Calendar_Event($post->ID);
        
        $rules = $event->get('recurrence_rules');
        $exception_dates = $event->get('exception_dates');
        
        // Parse existing rule for UI
        $parsed = $this->parse_rrule_for_ui($rules);
        ?>
        <table class="form-table lrob-meta-table">
            <tr>
                <th><label for="lrob_repeat"><?php esc_html_e('Repeat', 'lrob-calendar'); ?></label></th>
                <td>
                    <select name="lrob_repeat" id="lrob_repeat">
                        <option value=""><?php esc_html_e('No repeat', 'lrob-calendar'); ?></option>
                        <option value="DAILY" <?php selected($parsed['freq'], 'DAILY'); ?>><?php esc_html_e('Daily', 'lrob-calendar'); ?></option>
                        <option value="WEEKLY" <?php selected($parsed['freq'], 'WEEKLY'); ?>><?php esc_html_e('Weekly', 'lrob-calendar'); ?></option>
                        <option value="MONTHLY" <?php selected($parsed['freq'], 'MONTHLY'); ?>><?php esc_html_e('Monthly', 'lrob-calendar'); ?></option>
                        <option value="YEARLY" <?php selected($parsed['freq'], 'YEARLY'); ?>><?php esc_html_e('Yearly', 'lrob-calendar'); ?></option>
                    </select>
                </td>
            </tr>
            <tr class="lrob-recurrence-options" style="<?php echo empty($parsed['freq']) ? 'display:none;' : ''; ?>">
                <th><label for="lrob_interval"><?php esc_html_e('Every', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="number" name="lrob_interval" id="lrob_interval" 
                           value="<?php echo esc_attr($parsed['interval']); ?>" min="1" max="99" style="width:60px;">
                    <span class="lrob-interval-label"><?php esc_html_e('occurrence(s)', 'lrob-calendar'); ?></span>
                </td>
            </tr>
            <tr class="lrob-recurrence-options lrob-weekly-options" style="<?php echo $parsed['freq'] !== 'WEEKLY' ? 'display:none;' : ''; ?>">
                <th><?php esc_html_e('On days', 'lrob-calendar'); ?></th>
                <td>
                    <?php
                    $days = ['MO' => __('Mon', 'lrob-calendar'), 'TU' => __('Tue', 'lrob-calendar'), 'WE' => __('Wed', 'lrob-calendar'), 'TH' => __('Thu', 'lrob-calendar'), 'FR' => __('Fri', 'lrob-calendar'), 'SA' => __('Sat', 'lrob-calendar'), 'SU' => __('Sun', 'lrob-calendar')];
                    foreach ($days as $code => $label):
                    ?>
                        <label style="margin-right:10px;">
                            <input type="checkbox" name="lrob_byday[]" value="<?php echo $code; ?>" 
                                   <?php checked(in_array($code, $parsed['byday'])); ?>>
                            <?php echo $label; ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr class="lrob-recurrence-options" style="<?php echo empty($parsed['freq']) ? 'display:none;' : ''; ?>">
                <th><label for="lrob_repeat_end"><?php esc_html_e('End repeat', 'lrob-calendar'); ?></label></th>
                <td>
                    <select name="lrob_repeat_end" id="lrob_repeat_end">
                        <option value="never" <?php selected($parsed['end_type'], 'never'); ?>><?php esc_html_e('Never', 'lrob-calendar'); ?></option>
                        <option value="count" <?php selected($parsed['end_type'], 'count'); ?>><?php esc_html_e('After X occurrences', 'lrob-calendar'); ?></option>
                        <option value="until" <?php selected($parsed['end_type'], 'until'); ?>><?php esc_html_e('On date', 'lrob-calendar'); ?></option>
                    </select>
                    <input type="number" name="lrob_count" id="lrob_count" 
                           value="<?php echo esc_attr($parsed['count']); ?>" min="1" max="999" style="width:60px; <?php echo $parsed['end_type'] !== 'count' ? 'display:none;' : ''; ?>">
                    <input type="date" name="lrob_until" id="lrob_until" 
                           value="<?php echo esc_attr($parsed['until']); ?>" style="<?php echo $parsed['end_type'] !== 'until' ? 'display:none;' : ''; ?>">
                </td>
            </tr>
            <tr class="lrob-recurrence-options" style="<?php echo empty($parsed['freq']) ? 'display:none;' : ''; ?>">
                <th><label for="lrob_exception_dates"><?php esc_html_e('Exclude dates', 'lrob-calendar'); ?></label></th>
                <td>
                    <textarea name="lrob_exception_dates" id="lrob_exception_dates" rows="2" style="width:100%;"><?php echo esc_textarea($exception_dates); ?></textarea>
                    <p class="description"><?php esc_html_e('Comma-separated dates to exclude (YYYYMMDD format)', 'lrob-calendar'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="lrob_rrule_raw"><?php esc_html_e('Raw RRULE', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="text" name="lrob_rrule_raw" id="lrob_rrule_raw" 
                           value="<?php echo esc_attr($rules); ?>" style="width:100%;">
                    <p class="description"><?php esc_html_e('Advanced: Direct RRULE string (overrides above settings if not empty)', 'lrob-calendar'); ?></p>
                </td>
            </tr>
        </table>
        <?php
        // Recurrence UI handlers live in admin/js/lrob-calendar-admin.js.
    }

    public function render_location_box(WP_Post $post): void {
        $event = new LRob_Calendar_Event($post->ID);
        ?>
        <table class="form-table lrob-meta-table">
            <tr>
                <th><label for="lrob_venue"><?php esc_html_e('Venue', 'lrob-calendar'); ?></label></th>
                <td><input type="text" name="lrob_venue" id="lrob_venue" value="<?php echo esc_attr($event->get('venue')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_address"><?php esc_html_e('Address', 'lrob-calendar'); ?></label></th>
                <td><input type="text" name="lrob_address" id="lrob_address" value="<?php echo esc_attr($event->get('address')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_city"><?php esc_html_e('City', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="text" name="lrob_city" id="lrob_city" value="<?php echo esc_attr($event->get('city')); ?>" style="width:49%;">
                    <input type="text" name="lrob_postal_code" id="lrob_postal_code" value="<?php echo esc_attr($event->get('postal_code')); ?>" placeholder="<?php esc_attr_e('Postal code', 'lrob-calendar'); ?>" style="width:49%;">
                </td>
            </tr>
            <tr>
                <th><label for="lrob_province"><?php esc_html_e('Province / State', 'lrob-calendar'); ?></label></th>
                <td><input type="text" name="lrob_province" id="lrob_province" value="<?php echo esc_attr($event->get('province')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_country"><?php esc_html_e('Country', 'lrob-calendar'); ?></label></th>
                <td><input type="text" name="lrob_country" id="lrob_country" value="<?php echo esc_attr($event->get('country')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_latitude"><?php esc_html_e('Coordinates', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="text" name="lrob_latitude" id="lrob_latitude" value="<?php echo esc_attr($event->get('latitude')); ?>" placeholder="<?php esc_attr_e('Latitude', 'lrob-calendar'); ?>" style="width:49%;">
                    <input type="text" name="lrob_longitude" id="lrob_longitude" value="<?php echo esc_attr($event->get('longitude')); ?>" placeholder="<?php esc_attr_e('Longitude', 'lrob-calendar'); ?>" style="width:49%;">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Map Options', 'lrob-calendar'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lrob_show_map" value="1" <?php checked($event->get('show_map')); ?>>
                        <?php esc_html_e('Show map', 'lrob-calendar'); ?>
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="checkbox" name="lrob_show_coordinates" value="1" <?php checked($event->get('show_coordinates')); ?>>
                        <?php esc_html_e('Show coordinates', 'lrob-calendar'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function render_contact_box(WP_Post $post): void {
        $event = new LRob_Calendar_Event($post->ID);
        ?>
        <table class="form-table lrob-meta-table">
            <tr>
                <th><label for="lrob_contact_name"><?php esc_html_e('Contact Name', 'lrob-calendar'); ?></label></th>
                <td><input type="text" name="lrob_contact_name" id="lrob_contact_name" value="<?php echo esc_attr($event->get('contact_name')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_contact_email"><?php esc_html_e('Email', 'lrob-calendar'); ?></label></th>
                <td><input type="email" name="lrob_contact_email" id="lrob_contact_email" value="<?php echo esc_attr($event->get('contact_email')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_contact_phone"><?php esc_html_e('Phone', 'lrob-calendar'); ?></label></th>
                <td><input type="tel" name="lrob_contact_phone" id="lrob_contact_phone" value="<?php echo esc_attr($event->get('contact_phone')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_contact_url"><?php esc_html_e('Website', 'lrob-calendar'); ?></label></th>
                <td><input type="url" name="lrob_contact_url" id="lrob_contact_url" value="<?php echo esc_attr($event->get('contact_url')); ?>" style="width:100%;"></td>
            </tr>
            <tr>
                <th><label for="lrob_cost"><?php esc_html_e('Cost', 'lrob-calendar'); ?></label></th>
                <td>
                    <input type="text" name="lrob_cost" id="lrob_cost" value="<?php echo esc_attr($event->get('cost')); ?>" style="width:200px;">
                    &nbsp;
                    <label>
                        <input type="checkbox" name="lrob_is_free" id="lrob_is_free" value="1" <?php checked($event->get('is_free')); ?>>
                        <?php esc_html_e('Free event', 'lrob-calendar'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="lrob_ticket_url"><?php esc_html_e('Ticket URL', 'lrob-calendar'); ?></label></th>
                <td><input type="url" name="lrob_ticket_url" id="lrob_ticket_url" value="<?php echo esc_attr($event->get('ticket_url')); ?>" style="width:100%;"></td>
            </tr>
        </table>
        <?php
    }
    
    public function save_meta_boxes(int $post_id, WP_Post $post): void {
        if (!isset($_POST['lrob_calendar_nonce']) || !wp_verify_nonce($_POST['lrob_calendar_nonce'], 'lrob_calendar_save')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $event = new LRob_Calendar_Event();
        $event->set_post_id($post_id);
        
        // Date & Time — wrap DateTime constructors so an unparseable POST value
        // (gibberish timezone, empty start_date, etc.) doesn't fatal the save_post hook.
        $timezone = sanitize_text_field($_POST['lrob_timezone'] ?? '');
        if ($timezone === '') {
            $timezone = LRob_Calendar_Event::get_default_timezone();
        }
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            // Bad TZ string — fall back to site default; existing event data is preserved.
            $timezone = LRob_Calendar_Event::get_default_timezone();
            $tz = new DateTimeZone($timezone);
        }

        $start_date = sanitize_text_field($_POST['lrob_start_date'] ?? '');
        $start_time = sanitize_text_field($_POST['lrob_start_time'] ?? '00:00');
        $end_date = sanitize_text_field($_POST['lrob_end_date'] ?? '');
        $end_time = sanitize_text_field($_POST['lrob_end_time'] ?? '00:00');

        $event_type = sanitize_text_field($_POST['lrob_event_type'] ?? 'standard');
        if (!in_array($event_type, ['standard', 'allday', 'instant'], true)) {
            $event_type = 'standard';
        }
        $allday  = ($event_type === 'allday');
        $instant = ($event_type === 'instant');

        if ($allday) {
            $start_time = '00:00';
            $end_time = '23:59';
        }

        try {
            $start_dt = new DateTime("{$start_date} {$start_time}", $tz);
            $end_dt   = new DateTime("{$end_date} {$end_time}", $tz);
        } catch (Exception $e) {
            // Couldn't parse the dates — skip date fields and let other meta save.
            // (Empty/invalid input is the only way to get here under normal use.)
            return;
        }

        // Instant events have no end — normalize end to match start so any
        // consumer that reads `end` without checking is_instant() still gets
        // sensible data.
        if ($instant) {
            $end_dt = clone $start_dt;
        }

        $event->set('start', $start_dt->getTimestamp());
        $event->set('end', $end_dt->getTimestamp());
        $event->set('timezone', $timezone);
        $event->set('allday', $allday ? 1 : 0);
        $event->set('instant_event', $instant ? 1 : 0);
        
        // Recurrence
        $rrule = $this->build_rrule_from_post();
        $event->set('recurrence_rules', $rrule);
        $event->set('exception_dates', sanitize_text_field($_POST['lrob_exception_dates'] ?? ''));
        
        // Location
        $event->set('venue', sanitize_text_field($_POST['lrob_venue'] ?? ''));
        $event->set('address', sanitize_text_field($_POST['lrob_address'] ?? ''));
        $event->set('city', sanitize_text_field($_POST['lrob_city'] ?? ''));
        $event->set('province', sanitize_text_field($_POST['lrob_province'] ?? ''));
        $event->set('postal_code', sanitize_text_field($_POST['lrob_postal_code'] ?? ''));
        $event->set('country', sanitize_text_field($_POST['lrob_country'] ?? ''));
        $event->set('latitude', floatval($_POST['lrob_latitude'] ?? 0) ?: null);
        $event->set('longitude', floatval($_POST['lrob_longitude'] ?? 0) ?: null);
        $event->set('show_map', isset($_POST['lrob_show_map']) ? 1 : 0);
        $event->set('show_coordinates', isset($_POST['lrob_show_coordinates']) ? 1 : 0);
        
        // Contact & Cost
        $event->set('contact_name', sanitize_text_field($_POST['lrob_contact_name'] ?? ''));
        $event->set('contact_email', sanitize_email($_POST['lrob_contact_email'] ?? ''));
        $event->set('contact_phone', sanitize_text_field($_POST['lrob_contact_phone'] ?? ''));
        $event->set('contact_url', esc_url_raw($_POST['lrob_contact_url'] ?? ''));
        $event->set('cost', sanitize_text_field($_POST['lrob_cost'] ?? ''));
        $event->set('is_free', isset($_POST['lrob_is_free']) ? 1 : 0);
        $event->set('ticket_url', esc_url_raw($_POST['lrob_ticket_url'] ?? ''));
        
        $event->save();
    }
    
    private function parse_rrule_for_ui(string $rrule): array {
        $result = [
            'freq' => '',
            'interval' => 1,
            'byday' => [],
            'count' => 10,
            'until' => '',
            'end_type' => 'never',
        ];
        
        if (empty($rrule)) {
            return $result;
        }
        
        $rrule = preg_replace('/^RRULE:/i', '', $rrule);
        $parts = explode(';', $rrule);
        
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) continue;
            [$key, $value] = explode('=', $part, 2);
            $key = strtoupper(trim($key));
            
            switch ($key) {
                case 'FREQ':
                    $result['freq'] = strtoupper($value);
                    break;
                case 'INTERVAL':
                    $result['interval'] = (int) $value;
                    break;
                case 'COUNT':
                    $result['count'] = (int) $value;
                    $result['end_type'] = 'count';
                    break;
                case 'UNTIL':
                    $result['until'] = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
                    $result['end_type'] = 'until';
                    break;
                case 'BYDAY':
                    $result['byday'] = array_map('trim', explode(',', $value));
                    break;
            }
        }
        
        return $result;
    }
    
    private function build_rrule_from_post(): string {
        // Check for raw RRULE first
        $raw = trim($_POST['lrob_rrule_raw'] ?? '');
        if (!empty($raw)) {
            return $raw;
        }
        
        $freq = strtoupper(sanitize_text_field($_POST['lrob_repeat'] ?? ''));
        if (empty($freq)) {
            return '';
        }
        
        $parts = ['FREQ=' . $freq];
        
        $interval = max(1, (int) ($_POST['lrob_interval'] ?? 1));
        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }
        
        if ($freq === 'WEEKLY' && !empty($_POST['lrob_byday'])) {
            $byday = array_map('sanitize_text_field', $_POST['lrob_byday']);
            $parts[] = 'BYDAY=' . implode(',', $byday);
        }
        
        $end_type = sanitize_text_field($_POST['lrob_repeat_end'] ?? 'never');
        
        if ($end_type === 'count') {
            $count = max(1, (int) ($_POST['lrob_count'] ?? 10));
            $parts[] = 'COUNT=' . $count;
        } elseif ($end_type === 'until') {
            $until = sanitize_text_field($_POST['lrob_until'] ?? '');
            if ($until) {
                $parts[] = 'UNTIL=' . str_replace('-', '', $until) . 'T235959Z';
            }
        }
        
        return implode(';', $parts);
    }
}
