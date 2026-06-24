<?php
/**
 * Event model
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Event {
    
    private int $post_id = 0;
    private ?WP_Post $post = null;
    private array $data = [];
    
    private static array $defaults = [
        'start' => 0,
        'end' => 0,
        // Empty by default; resolved at display/save via get_default_timezone().
        'timezone' => '',
        'allday' => 0,
        'instant_event' => 0,
        'recurrence_rules' => '',
        'exception_rules' => '',
        'recurrence_dates' => '',
        'exception_dates' => '',
        'venue' => '',
        'address' => '',
        'city' => '',
        'province' => '',
        'postal_code' => '',
        'country' => '',
        'latitude' => null,
        'longitude' => null,
        'show_map' => 0,
        'show_coordinates' => 0,
        'contact_name' => '',
        'contact_phone' => '',
        'contact_email' => '',
        'contact_url' => '',
        'cost' => '',
        'is_free' => 0,
        'ticket_url' => '',
        'ical_uid' => '',
        'ical_feed_url' => '',
        'ical_source_url' => '',
        'ical_organizer' => '',
        'ical_contact' => '',
    ];
    
    public function __construct(int $post_id = 0) {
        $this->data = self::$defaults;

        if ($post_id > 0) {
            $this->load($post_id);
        }
    }

    /**
     * Default timezone string for new events. Prefers the plugin's configured
     * default; falls back to WordPress's site timezone.
     */
    public static function get_default_timezone(): string {
        $configured = get_option('lrob_calendar_default_timezone', '');
        return $configured !== '' ? $configured : wp_timezone_string();
    }
    
    public function load(int $post_id): bool {
        global $wpdb;
        
        $post = get_post($post_id);
        if (!$post || $post->post_type !== LRob_Calendar_Post_Types::POST_TYPE) {
            return false;
        }
        
        $this->post_id = $post_id;
        $this->post = $post;
        
        $table = LRob_Calendar_Database::get_events_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d",
            $post_id
        ), ARRAY_A);
        
        if ($row) {
            $this->data = array_merge(self::$defaults, $row);
        }
        
        return true;
    }
    
    public function save(): bool {
        global $wpdb;
        
        if ($this->post_id <= 0) {
            return false;
        }
        
        $table = LRob_Calendar_Database::get_events_table();
        $data = $this->data;
        $data['post_id'] = $this->post_id;
        
        // Generate ical_uid if empty
        if (empty($data['ical_uid'])) {
            $data['ical_uid'] = $this->generate_ical_uid();
        }
        
        // Pull the existing row (if any) up-front so we can detect whether the
        // instance-affecting fields actually changed — regenerating instances on
        // every save is wasteful for events whose recurrence didn't change.
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT start, end, timezone, recurrence_rules, exception_rules, recurrence_dates, exception_dates
             FROM {$table} WHERE post_id = %d",
            $this->post_id
        ), ARRAY_A);

        if ($original) {
            $result = $wpdb->update($table, $data, ['post_id' => $this->post_id]);
        } else {
            $result = $wpdb->insert($table, $data);
        }

        if ($result !== false) {
            // Only rebuild instances when something that affects them changed
            // (or it's a brand-new row).
            $instance_fields = ['start', 'end', 'timezone', 'recurrence_rules', 'exception_rules', 'recurrence_dates', 'exception_dates'];
            $instances_changed = !$original;
            if (!$instances_changed) {
                foreach ($instance_fields as $f) {
                    if ((string) ($original[$f] ?? '') !== (string) ($data[$f] ?? '')) {
                        $instances_changed = true;
                        break;
                    }
                }
            }
            if ($instances_changed) {
                $this->update_instances();
            }
            return true;
        }

        return false;
    }
    
    public function delete(): bool {
        global $wpdb;
        
        if ($this->post_id <= 0) {
            return false;
        }
        
        $events_table = LRob_Calendar_Database::get_events_table();
        $instances_table = LRob_Calendar_Database::get_instances_table();
        
        $wpdb->delete($instances_table, ['post_id' => $this->post_id]);
        $wpdb->delete($events_table, ['post_id' => $this->post_id]);
        
        return true;
    }
    
    private function update_instances(): void {
        global $wpdb;

        $table = LRob_Calendar_Database::get_instances_table();

        // Clear existing instances
        $wpdb->delete($table, ['post_id' => $this->post_id]);

        // Get all instances (base + recurrences)
        $instances = $this->calculate_instances();
        if (empty($instances)) {
            return;
        }

        // Bulk INSERT, chunked to stay well under MySQL max_allowed_packet for
        // long-running recurrences (up to 500 instances at 5 years).
        foreach (array_chunk($instances, 100) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $instance) {
                $placeholders[] = '(%d, %d, %d)';
                $values[] = $this->post_id;
                $values[] = (int) $instance['start'];
                $values[] = (int) $instance['end'];
            }
            $sql = "INSERT INTO {$table} (post_id, start, end) VALUES " . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values));
        }
    }
    
    private function calculate_instances(): array {
        $instances = [];
        
        // Base instance
        $instances[] = [
            'start' => (int) $this->data['start'],
            'end' => (int) $this->data['end'],
        ];
        
        // Recurrence instances
        if (!empty($this->data['recurrence_rules'])) {
            $recurrence = new LRob_Calendar_Recurrence(
                (int) $this->data['start'],
                (int) $this->data['end'],
                $this->data['recurrence_rules'],
                $this->data['exception_rules'],
                $this->data['recurrence_dates'],
                $this->data['exception_dates'],
                $this->data['timezone']
            );
            
            $instances = array_merge($instances, $recurrence->get_instances());
        }
        
        return $instances;
    }
    
    private function generate_ical_uid(): string {
        return sprintf(
            '%s-%s@%s',
            $this->post_id,
            wp_generate_password(8, false),
            parse_url(home_url(), PHP_URL_HOST)
        );
    }
    
    // Getters and setters
    
    public function get_post_id(): int {
        return $this->post_id;
    }
    
    public function set_post_id(int $post_id): self {
        $this->post_id = $post_id;
        return $this;
    }
    
    public function get_post(): ?WP_Post {
        return $this->post;
    }
    
    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }
    
    public function set(string $key, $value): self {
        if (array_key_exists($key, self::$defaults)) {
            $this->data[$key] = $value;
        }
        return $this;
    }
    
    public function get_all(): array {
        return $this->data;
    }
    
    public function set_all(array $data): self {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
        return $this;
    }
    
    // Helper methods
    
    public function get_start_datetime(): DateTimeImmutable {
        $tz = new DateTimeZone($this->data['timezone'] ?: self::get_default_timezone());
        return (new DateTimeImmutable('@' . $this->data['start']))->setTimezone($tz);
    }
    
    public function get_end_datetime(): DateTimeImmutable {
        $tz = new DateTimeZone($this->data['timezone'] ?: self::get_default_timezone());
        return (new DateTimeImmutable('@' . $this->data['end']))->setTimezone($tz);
    }
    
    public function is_allday(): bool {
        return (bool) $this->data['allday'];
    }

    public function is_instant(): bool {
        return (bool) $this->data['instant_event'];
    }

    public function is_recurring(): bool {
        return !empty($this->data['recurrence_rules']);
    }

    /**
     * Format the event's "when" line using WordPress date/time settings.
     * Centralizes the start/end/all-day/instant matrix so every renderer
     * (single page, blocks, popup, admin columns) produces consistent output.
     */
    public function format_when(?string $date_format = null, ?string $time_format = null, string $separator = ' — '): string {
        $date_format = $date_format ?? get_option('date_format');
        $time_format = $time_format ?? get_option('time_format');

        $start_ts = (int) $this->data['start'];

        // Instant: just the start, no end half.
        if ($this->is_instant()) {
            return $this->is_allday()
                ? wp_date($date_format, $start_ts)
                : wp_date($date_format . ' ' . $time_format, $start_ts);
        }

        $end_ts   = (int) $this->data['end'];
        $same_day = wp_date('Y-m-d', $start_ts) === wp_date('Y-m-d', $end_ts);

        if ($this->is_allday()) {
            $out = wp_date($date_format, $start_ts);
            if (!$same_day) {
                $out .= $separator . wp_date($date_format, $end_ts);
            }
            return $out;
        }

        $out = wp_date($date_format . ' ' . $time_format, $start_ts);
        if ($same_day) {
            $out .= $separator . wp_date($time_format, $end_ts);
        } else {
            $out .= $separator . wp_date($date_format . ' ' . $time_format, $end_ts);
        }
        return $out;
    }
    
    /**
     * Return the date and time as TWO separate strings so callers can stack
     * them on different lines. Mirrors the JS formatEventDateAndTime() used
     * by the popup. Time is '' for all-day events.
     *
     * Returns ['date' => '...', 'time' => '...'].
     */
    public function format_date_and_time(?string $date_format = null, ?string $time_format = null): array {
        $date_format = $date_format ?? get_option('date_format');
        $time_format = $time_format ?? get_option('time_format');

        $start_ts = (int) $this->data['start'];

        if ($this->is_instant()) {
            return [
                'date' => wp_date($date_format, $start_ts),
                'time' => $this->is_allday() ? '' : wp_date($time_format, $start_ts),
            ];
        }

        $end_ts   = (int) $this->data['end'];
        $same_day = wp_date('Y-m-d', $start_ts) === wp_date('Y-m-d', $end_ts);

        if ($this->is_allday()) {
            return [
                'date' => $same_day
                    ? wp_date($date_format, $start_ts)
                    : wp_date($date_format, $start_ts) . ' – ' . wp_date($date_format, $end_ts),
                'time' => '',
            ];
        }

        if ($same_day) {
            return [
                'date' => wp_date($date_format, $start_ts),
                'time' => wp_date($time_format, $start_ts) . ' – ' . wp_date($time_format, $end_ts),
            ];
        }

        return [
            'date' => wp_date($date_format, $start_ts) . ' – ' . wp_date($date_format, $end_ts),
            'time' => wp_date($time_format, $start_ts) . ' – ' . wp_date($time_format, $end_ts),
        ];
    }

    public function is_free(): bool {
        return (bool) $this->data['is_free'];
    }
    
    public function has_location(): bool {
        return !empty($this->data['venue']) || !empty($this->data['address']);
    }
    
    public function get_categories(): array {
        if ($this->post_id <= 0) {
            return [];
        }
        
        $terms = wp_get_post_terms($this->post_id, LRob_Calendar_Post_Types::TAX_CATEGORY);
        return is_wp_error($terms) ? [] : $terms;
    }
    
    public function get_tags(): array {
        if ($this->post_id <= 0) {
            return [];
        }
        
        $terms = wp_get_post_terms($this->post_id, LRob_Calendar_Post_Types::TAX_TAG);
        return is_wp_error($terms) ? [] : $terms;
    }
    
    public function get_instances(int $limit = 100): array {
        global $wpdb;

        if ($this->post_id <= 0) {
            return [];
        }

        $table = LRob_Calendar_Database::get_instances_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, start, end FROM {$table} WHERE post_id = %d ORDER BY start ASC LIMIT %d",
            $this->post_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Find the earliest instance that hasn't ended yet (or any instance if all
     * are past). Used so recurring events whose base is in the past but whose
     * recurrence continues forward still display a useful "next occurrence" date.
     * Returns ['start' => int, 'end' => int] or null.
     */
    public function get_next_instance_after(int $from): ?array {
        global $wpdb;
        if ($this->post_id <= 0) {
            return null;
        }
        $table = LRob_Calendar_Database::get_instances_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT start, end FROM {$table} WHERE post_id = %d AND end >= %d ORDER BY start ASC LIMIT 1",
            $this->post_id,
            $from
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Materialized occurrences overlapping [range_start, range_end] (either bound
     * may be null = open-ended). Used to expand a recurring event into one entry
     * per occurrence for calendar display.
     *
     * @return array<int, array{start:int,end:int}>
     */
    public function get_instances_in_range(?int $range_start, ?int $range_end, int $limit = 500): array {
        global $wpdb;
        if ($this->post_id <= 0) {
            return [];
        }
        $table  = LRob_Calendar_Database::get_instances_table();
        $where  = ['post_id = %d'];
        $params = [$this->post_id];
        if ($range_start !== null) { $where[] = 'end >= %d';   $params[] = $range_start; }
        if ($range_end !== null)   { $where[] = 'start <= %d'; $params[] = $range_end; }
        $params[] = $limit;

        $sql = "SELECT start, end FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY start ASC LIMIT %d';
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    // Static query methods
    
    public static function get_events(array $args = []): array {
        global $wpdb;
        
        $defaults = [
            'start' => null,
            'end' => null,
            'category' => null,
            'tag' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'start',
            'order' => 'ASC',
            'status' => 'publish',
        ];
        
        $args = wp_parse_args($args, $defaults);
        $args = self::apply_global_age_limit($args);
        $events_table = LRob_Calendar_Database::get_events_table();
        $posts_table = $wpdb->posts;

        $where = ["p.post_type = %s", "p.post_status = %s"];
        $params = [LRob_Calendar_Post_Types::POST_TYPE, $args['status']];
        $instances_table = LRob_Calendar_Database::get_instances_table();

        if ($args['start'] !== null) {
            // Include events whose base end is >= start (the simple case), OR
            // recurring events that have at least one instance still ending >= start.
            // This catches "happening now" non-recurring events (e.end > now) AND
            // recurring events whose base is past but whose recurrence is ongoing.
            $where[] = "(e.end >= %d OR (e.recurrence_rules <> '' AND EXISTS (
                SELECT 1 FROM {$instances_table} ix WHERE ix.post_id = e.post_id AND ix.end >= %d
            )))";
            $params[] = $args['start'];
            $params[] = $args['start'];
        }

        if ($args['end'] !== null) {
            $where[] = "(e.start <= %d OR (e.recurrence_rules <> '' AND EXISTS (
                SELECT 1 FROM {$instances_table} ix2 WHERE ix2.post_id = e.post_id AND ix2.start <= %d
            )))";
            $params[] = $args['end'];
            $params[] = $args['end'];
        }

        $join = '';

        if ($args['category']) {
            $join .= " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
            $join .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[] = "tt.taxonomy = %s AND tt.term_id = %d";
            $params[] = LRob_Calendar_Post_Types::TAX_CATEGORY;
            $params[] = $args['category'];
        }

        if ($args['tag']) {
            if (!$args['category']) {
                $join .= " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
                $join .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            }
            $where[] = "tt.taxonomy = %s AND tt.term_id = %d";
            $params[] = LRob_Calendar_Post_Types::TAX_TAG;
            $params[] = $args['tag'];
        }
        
        $orderby = in_array($args['orderby'], ['start', 'end']) ? "e.{$args['orderby']}" : 'e.start';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        
        $sql = "SELECT DISTINCT e.*, p.* 
                FROM {$events_table} e
                INNER JOIN {$posts_table} p ON e.post_id = p.ID
                {$join}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";
        
        $params[] = $args['limit'];
        $params[] = $args['offset'];
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        
        $events = [];
        foreach ($results as $row) {
            $event = new self();
            $event->post_id = (int) $row['post_id'];
            $event->post = get_post($row['post_id']);
            $event->data = array_intersect_key($row, self::$defaults);
            $events[] = $event;
        }
        
        return $events;
    }
    
    /**
     * If the admin has set a global "max event age" cap and the caller didn't
     * specify a `start` arg, apply the cap as a default lower bound. Prevents
     * runaway queries on archives with thousands of historical events.
     */
    private static function apply_global_age_limit(array $args): array {
        if (isset($args['start']) && $args['start'] !== null) {
            return $args;
        }
        $months = (int) get_option('lrob_calendar_max_event_age_months', 0);
        if ($months > 0) {
            $args['start'] = time() - ($months * 30 * DAY_IN_SECONDS);
        }
        return $args;
    }

    public static function get_upcoming(int $limit = 10): array {
        return self::get_events([
            'start' => time(),
            'limit' => $limit,
        ]);
    }

    /**
     * Count events matching the same filters as `get_events()` (start/end/category/
     * tag/status). Used by the events-list block for pagination — we need a total
     * to compute the number of pages.
     */
    public static function count_events(array $args = []): int {
        global $wpdb;

        $defaults = [
            'start'    => null,
            'end'      => null,
            'category' => null,
            'tag'      => null,
            'status'   => 'publish',
        ];

        $args = wp_parse_args($args, $defaults);
        $args = self::apply_global_age_limit($args);
        $events_table = LRob_Calendar_Database::get_events_table();
        $posts_table  = $wpdb->posts;

        $where  = ['p.post_type = %s', 'p.post_status = %s'];
        $params = [LRob_Calendar_Post_Types::POST_TYPE, $args['status']];
        $instances_table = LRob_Calendar_Database::get_instances_table();

        if ($args['start'] !== null) {
            // Mirror get_events(): also count recurring events with an ongoing instance.
            $where[] = "(e.end >= %d OR (e.recurrence_rules <> '' AND EXISTS (
                SELECT 1 FROM {$instances_table} ix WHERE ix.post_id = e.post_id AND ix.end >= %d
            )))";
            $params[] = $args['start'];
            $params[] = $args['start'];
        }
        if ($args['end'] !== null) {
            $where[] = "(e.start <= %d OR (e.recurrence_rules <> '' AND EXISTS (
                SELECT 1 FROM {$instances_table} ix2 WHERE ix2.post_id = e.post_id AND ix2.start <= %d
            )))";
            $params[] = $args['end'];
            $params[] = $args['end'];
        }

        $join = '';
        if ($args['category']) {
            $join    .= " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
            $join    .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[]  = 'tt.taxonomy = %s AND tt.term_id = %d';
            $params[] = LRob_Calendar_Post_Types::TAX_CATEGORY;
            $params[] = $args['category'];
        }
        if ($args['tag']) {
            if (!$args['category']) {
                $join .= " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
                $join .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            }
            $where[]  = 'tt.taxonomy = %s AND tt.term_id = %d';
            $params[] = LRob_Calendar_Post_Types::TAX_TAG;
            $params[] = $args['tag'];
        }

        $sql = "SELECT COUNT(DISTINCT p.ID)
                FROM {$events_table} e
                INNER JOIN {$posts_table} p ON e.post_id = p.ID
                {$join}
                WHERE " . implode(' AND ', $where);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }
}
