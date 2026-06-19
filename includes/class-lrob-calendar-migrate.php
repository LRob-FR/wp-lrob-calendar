<?php
/**
 * Migration helpers — read events from other calendar plugins and emit them in
 * the canonical LRob Calendar export format (same shape as LRob_Calendar_Export),
 * so the existing importer can consume the result unchanged.
 *
 * Supported sources:
 *   - The Events Calendar (StellarWP / Modern Tribe): tribe_events post type,
 *     event data in postmeta, venues/organizers as separate post types.
 *   - All-in-One Event Calendar (Timely): custom {prefix}ai1ec_events table.
 *
 * The source plugin must be active when exporting (we rely on its post types /
 * taxonomies being registered). The two-step migration is then:
 *   1. Export from the foreign plugin here -> download canonical JSON.
 *   2. Import that JSON through the normal Import box.
 *
 * Images are referenced by their existing local URL; the importer re-attaches
 * them from the media library without re-downloading when they already exist.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Migrate {

    const SOURCE_TEC   = 'tec';
    const SOURCE_AI1EC = 'ai1ec';

    /**
     * Foreign sources detected on this site, with a human label and event count.
     * Only sources that are present (active plugin / existing table) are returned,
     * so the admin UI can hide buttons that would produce nothing.
     *
     * @return array<int, array{key:string,label:string,count:int}>
     */
    public function get_available_sources(): array {
        $sources = [];

        $tec_count = $this->tec_event_count();
        if ($tec_count !== null) {
            $sources[] = [
                'key'   => self::SOURCE_TEC,
                'label' => 'The Events Calendar',
                'count' => $tec_count,
            ];
        }

        $ai1ec_count = $this->ai1ec_event_count();
        if ($ai1ec_count !== null) {
            $sources[] = [
                'key'   => self::SOURCE_AI1EC,
                'label' => 'All-in-One Event Calendar',
                'count' => $ai1ec_count,
            ];
        }

        return $sources;
    }

    /**
     * Build the canonical export array for a foreign source.
     *
     * @throws InvalidArgumentException When the source key is unknown.
     */
    public function export(string $source): array {
        switch ($source) {
            case self::SOURCE_TEC:
                return [
                    'meta'       => $this->build_meta('The Events Calendar'),
                    'categories' => $this->tec_categories(),
                    'tags'       => $this->tec_tags(),
                    'events'     => $this->tec_events(),
                ];
            case self::SOURCE_AI1EC:
                return [
                    'meta'       => $this->build_meta('All-in-One Event Calendar'),
                    'categories' => $this->ai1ec_categories(),
                    'tags'       => $this->ai1ec_tags(),
                    'events'     => $this->ai1ec_events(),
                ];
            default:
                throw new InvalidArgumentException('Unknown migration source: ' . $source);
        }
    }

    private function build_meta(string $plugin): array {
        return [
            'plugin'      => $plugin,
            'version'     => LROB_CALENDAR_VERSION,
            'exported_at' => gmdate('c'),
            'site_url'    => get_site_url(),
            'source'      => $plugin,
        ];
    }

    /* ===================================================================
     * The Events Calendar
     * =================================================================== */

    const TEC_POST_TYPE = 'tribe_events';
    const TEC_VENUE     = 'tribe_venue';
    const TEC_ORGANIZER = 'tribe_organizer';
    const TEC_TAX_CAT   = 'tribe_events_cat';

    /** @return int|null Event count, or null when TEC is not present. */
    private function tec_event_count(): ?int {
        if (!post_type_exists(self::TEC_POST_TYPE)) {
            return null;
        }
        $counts = (array) wp_count_posts(self::TEC_POST_TYPE);
        $total  = 0;
        foreach (['publish', 'future', 'draft', 'pending', 'private'] as $status) {
            $total += (int) ($counts[$status] ?? 0);
        }
        return $total;
    }

    private function tec_categories(): array {
        $categories = [];

        $terms = get_terms([
            'taxonomy'   => self::TEC_TAX_CAT,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {
            // The Events Calendar has no per-category color/image, so those keys
            // are simply omitted (the importer treats them as empty).
            $categories[] = [
                'id'          => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent_id'   => (int) $term->parent ?: null,
                'count'       => (int) $term->count,
            ];
        }

        return $categories;
    }

    private function tec_tags(): array {
        $tags = [];

        // The Events Calendar reuses the core post_tag taxonomy for event tags.
        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'object_ids' => $this->tec_event_ids(),
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {
            $tags[] = [
                'id'          => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => (int) $term->count,
            ];
        }

        return $tags;
    }

    /** @return int[] */
    private function tec_event_ids(): array {
        return get_posts([
            'post_type'      => self::TEC_POST_TYPE,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);
    }

    private function tec_events(): array {
        $events = [];

        $ids = $this->tec_event_ids();

        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $timezone = get_post_meta($post_id, '_EventTimezone', true) ?: wp_timezone_string();

            $start = $this->tec_meta_datetime($post_id, '_EventStartDateUTC', '_EventStartDate', $timezone);
            $end   = $this->tec_meta_datetime($post_id, '_EventEndDateUTC', '_EventEndDate', $timezone);

            $allday = in_array(get_post_meta($post_id, '_EventAllDay', true), ['yes', '1'], true);

            $events[] = [
                'id'         => (int) $post_id,
                'title'      => $post->post_title,
                'slug'       => $post->post_name,
                'content'    => $post->post_content,
                'excerpt'    => $post->post_excerpt,
                'status'     => $post->post_status,
                'author_id'  => (int) $post->post_author,
                'created_at' => $post->post_date_gmt,
                'updated_at' => $post->post_modified_gmt,

                'start'         => $start,
                'end'           => $end,
                'timezone'      => $timezone,
                'allday'        => $allday,
                'instant_event' => false,

                'recurrence_rules' => null,
                'exception_rules'  => null,
                'recurrence_dates' => null,
                'exception_dates'  => null,

                'location'      => $this->tec_location($post_id),
                'contact'       => $this->tec_contact($post_id),

                'cost'       => $this->tec_cost($post_id),
                'ticket_url' => get_post_meta($post_id, '_EventURL', true) ?: null,

                'ical' => [
                    'uid'        => null,
                    'feed_url'   => null,
                    'source_url' => null,
                    'organizer'  => $this->tec_primary_organizer_name($post_id),
                    'contact'    => null,
                ],

                'categories'     => $this->tec_event_terms($post_id, self::TEC_TAX_CAT),
                'tags'           => $this->tec_event_terms($post_id, 'post_tag'),
                'featured_image' => $this->get_featured_image($post_id),
            ];

            // Recurrence (Events Calendar Pro / Aggregator-imported events) is
            // stored as an RFC 5545 RRULE blob; route its lines to our fields.
            $rrule = get_post_meta($post_id, '_EventRecurrenceRRULE', true);
            if (!empty($rrule)) {
                $this->apply_rrule_blob($events[count($events) - 1], $rrule);
            }
        }

        return $events;
    }

    /**
     * Resolve a TEC datetime to ISO-8601 UTC. Prefers the stored UTC meta;
     * falls back to the local meta interpreted in the event timezone.
     */
    private function tec_meta_datetime(int $post_id, string $utc_key, string $local_key, string $timezone): ?string {
        $utc = get_post_meta($post_id, $utc_key, true);
        if (!empty($utc)) {
            try {
                $dt = new DateTime($utc, new DateTimeZone('UTC'));
                return $dt->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {
                // fall through to local
            }
        }

        $local = get_post_meta($post_id, $local_key, true);
        if (empty($local)) {
            return null;
        }
        try {
            $dt = new DateTime($local, new DateTimeZone($timezone ?: 'UTC'));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    }

    private function tec_location(int $post_id): array {
        $venue_id = (int) get_post_meta($post_id, '_EventVenueID', true);

        $empty = [
            'venue'            => null,
            'address'          => null,
            'city'             => null,
            'province'         => null,
            'postal_code'      => null,
            'country'          => null,
            'latitude'         => null,
            'longitude'        => null,
            'show_map'         => false,
            'show_coordinates' => false,
        ];

        if (!$venue_id) {
            return $empty;
        }

        $venue = get_post($venue_id);
        if (!$venue) {
            return $empty;
        }

        $lat = get_post_meta($venue_id, '_VenueLat', true);
        $lng = get_post_meta($venue_id, '_VenueLng', true);

        // Country is stored as either a plain string or a [code, name] pair.
        $country = get_post_meta($venue_id, '_VenueCountry', true);
        if (is_array($country)) {
            $country = end($country) ?: null;
        }

        return [
            'venue'            => $venue->post_title ?: null,
            'address'          => get_post_meta($venue_id, '_VenueAddress', true) ?: null,
            'city'             => get_post_meta($venue_id, '_VenueCity', true) ?: null,
            'province'         => get_post_meta($venue_id, '_VenueStateProvince', true)
                                    ?: (get_post_meta($venue_id, '_VenueProvince', true)
                                    ?: (get_post_meta($venue_id, '_VenueState', true) ?: null)),
            'postal_code'      => get_post_meta($venue_id, '_VenueZip', true) ?: null,
            'country'          => $country ?: null,
            'latitude'         => $lat !== '' ? (float) $lat : null,
            'longitude'        => $lng !== '' ? (float) $lng : null,
            'show_map'         => in_array(get_post_meta($venue_id, '_VenueShowMap', true), ['true', '1', 'yes'], true),
            'show_coordinates' => ($lat !== '' && $lng !== ''),
        ];
    }

    private function tec_contact(int $post_id): array {
        $organizer_id = $this->tec_primary_organizer_id($post_id);

        if (!$organizer_id) {
            // Event-level phone can exist without an organizer post.
            $phone = get_post_meta($post_id, '_EventPhone', true);
            return [
                'name'  => null,
                'phone' => $phone ?: null,
                'email' => null,
                'url'   => null,
            ];
        }

        $organizer = get_post($organizer_id);

        return [
            'name'  => $organizer ? ($organizer->post_title ?: null) : null,
            'phone' => get_post_meta($organizer_id, '_OrganizerPhone', true) ?: null,
            'email' => get_post_meta($organizer_id, '_OrganizerEmail', true) ?: null,
            'url'   => get_post_meta($organizer_id, '_OrganizerWebsite', true) ?: null,
        ];
    }

    private function tec_primary_organizer_id(int $post_id): int {
        // _EventOrganizerID can be stored as multiple meta rows; take the first.
        $ids = get_post_meta($post_id, '_EventOrganizerID', false);
        foreach ((array) $ids as $id) {
            if ((int) $id > 0) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function tec_primary_organizer_name(int $post_id): ?string {
        $organizer_id = $this->tec_primary_organizer_id($post_id);
        if (!$organizer_id) {
            return null;
        }
        $organizer = get_post($organizer_id);
        return $organizer ? ($organizer->post_title ?: null) : null;
    }

    private function tec_cost(int $post_id): array {
        $cost = get_post_meta($post_id, '_EventCost', true);

        $is_free = false;
        if ($cost === '' || $cost === null) {
            $value = null;
        } else {
            $value   = (string) $cost;
            $is_free = in_array(strtolower(trim($value)), ['0', 'free', 'gratuit'], true);
        }

        return [
            'value'   => $value,
            'is_free' => $is_free,
        ];
    }

    private function tec_event_terms(int $post_id, string $taxonomy): array {
        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(function ($term) {
            return [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
    }

    /**
     * Split a TEC RRULE meta blob into recurrence_rules / recurrence_dates /
     * exception_dates. The blob may hold multiple RFC 5545 lines (RRULE, RDATE,
     * EXDATE). Our recurrence engine strips the line prefixes itself.
     */
    private function apply_rrule_blob(array &$event, string $blob): void {
        $rrules = [];
        $rdates = [];
        $exdates = [];

        foreach (preg_split('/[\r\n]+/', trim($blob)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'EXDATE') === 0) {
                $exdates[] = $line;
            } elseif (stripos($line, 'RDATE') === 0) {
                $rdates[] = $line;
            } elseif (stripos($line, 'RRULE') === 0) {
                $rrules[] = $line;
            } else {
                // Bare "FREQ=...;..." with no prefix.
                $rrules[] = $line;
            }
        }

        $event['recurrence_rules'] = $rrules ? implode("\n", $rrules) : null;
        $event['recurrence_dates'] = $rdates ? implode("\n", $rdates) : null;
        $event['exception_dates']  = $exdates ? implode("\n", $exdates) : null;
    }

    /* ===================================================================
     * All-in-One Event Calendar
     * =================================================================== */

    /** @return int|null Event count, or null when AI1EC tables are absent. */
    private function ai1ec_event_count(): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'ai1ec_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} e
             INNER JOIN {$wpdb->posts} p ON e.post_id = p.ID
             WHERE p.post_type = 'ai1ec_event'"
        );
    }

    private function ai1ec_categories(): array {
        global $wpdb;

        $categories = [];

        $terms = get_terms([
            'taxonomy'   => 'events_categories',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $meta_table = $wpdb->prefix . 'ai1ec_event_category_meta';
        $category_meta = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) === $meta_table) {
            foreach ($wpdb->get_results("SELECT term_id, term_image, term_color FROM {$meta_table}") as $row) {
                $category_meta[(int) $row->term_id] = [
                    'color' => $row->term_color,
                    'image' => $row->term_image,
                ];
            }
        }

        foreach ($terms as $term) {
            $cat = [
                'id'          => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent_id'   => (int) $term->parent ?: null,
                'count'       => (int) $term->count,
            ];
            if (isset($category_meta[$term->term_id])) {
                $cat['color'] = $category_meta[$term->term_id]['color'];
                $cat['image'] = $category_meta[$term->term_id]['image'];
            }
            $categories[] = $cat;
        }

        return $categories;
    }

    private function ai1ec_tags(): array {
        $tags = [];

        $terms = get_terms([
            'taxonomy'   => 'events_tags',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        foreach ($terms as $term) {
            $tags[] = [
                'id'          => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => (int) $term->count,
            ];
        }

        return $tags;
    }

    private function ai1ec_events(): array {
        global $wpdb;

        $events_table = $wpdb->prefix . 'ai1ec_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table)) !== $events_table) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT e.*, p.post_title, p.post_content, p.post_excerpt, p.post_status,
                    p.post_date_gmt, p.post_modified_gmt, p.post_author, p.post_name
             FROM {$events_table} e
             INNER JOIN {$wpdb->posts} p ON e.post_id = p.ID
             WHERE p.post_type = 'ai1ec_event'
             ORDER BY e.start ASC"
        );

        $events = [];

        foreach ($rows as $row) {
            $post_id = (int) $row->post_id;

            $events[] = [
                'id'         => $post_id,
                'title'      => $row->post_title,
                'slug'       => $row->post_name,
                'content'    => $row->post_content,
                'excerpt'    => $row->post_excerpt,
                'status'     => $row->post_status,
                'author_id'  => (int) $row->post_author,
                'created_at' => $row->post_date_gmt,
                'updated_at' => $row->post_modified_gmt,

                'start'         => $this->ts_to_iso($row->start),
                'end'           => $this->ts_to_iso($row->end),
                'timezone'      => $row->timezone_name ?: 'UTC',
                'allday'        => (bool) $row->allday,
                'instant_event' => (bool) $row->instant_event,

                'recurrence_rules' => $row->recurrence_rules ?: null,
                'exception_rules'  => $row->exception_rules ?: null,
                'recurrence_dates' => $row->recurrence_dates ?: null,
                'exception_dates'  => $row->exception_dates ?: null,

                'location' => [
                    'venue'            => $row->venue ?: null,
                    'address'          => $row->address ?: null,
                    'city'             => $row->city ?: null,
                    'province'         => $row->province ?: null,
                    'postal_code'      => $row->postal_code ?: null,
                    'country'          => $row->country ?: null,
                    'latitude'         => $row->latitude ? (float) $row->latitude : null,
                    'longitude'        => $row->longitude ? (float) $row->longitude : null,
                    'show_map'         => (bool) $row->show_map,
                    'show_coordinates' => (bool) $row->show_coordinates,
                ],

                'contact' => [
                    'name'  => $row->contact_name ?: null,
                    'phone' => $row->contact_phone ?: null,
                    'email' => $row->contact_email ?: null,
                    'url'   => $row->contact_url ?: null,
                ],

                'cost'       => $this->ai1ec_parse_cost($row->cost),
                'ticket_url' => $row->ticket_url ?: null,

                'ical' => [
                    'uid'        => $row->ical_uid ?: null,
                    'feed_url'   => $row->ical_feed_url ?: null,
                    'source_url' => $row->ical_source_url ?: null,
                    'organizer'  => $row->ical_organizer ?: null,
                    'contact'    => $row->ical_contact ?: null,
                ],

                'categories'     => $this->ai1ec_event_terms($post_id, 'events_categories'),
                'tags'           => $this->ai1ec_event_terms($post_id, 'events_tags'),
                'featured_image' => $this->get_featured_image($post_id),
            ];
        }

        return $events;
    }

    private function ai1ec_parse_cost($cost): array {
        if (empty($cost)) {
            return ['value' => null, 'is_free' => false];
        }
        $data = @unserialize($cost);
        if ($data === false) {
            return ['value' => (string) $cost, 'is_free' => false];
        }
        return [
            'value'   => $data['cost'] ?? null,
            'is_free' => (bool) ($data['is_free'] ?? false),
        ];
    }

    private function ai1ec_event_terms(int $post_id, string $taxonomy): array {
        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (is_wp_error($terms)) {
            return [];
        }
        return array_map(function ($term) {
            return [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
    }

    /* ===================================================================
     * Shared helpers
     * =================================================================== */

    private function ts_to_iso($timestamp): ?string {
        if (empty($timestamp)) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
    }

    private function get_featured_image(int $post_id): ?array {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return null;
        }
        $image = wp_get_attachment_image_src($thumbnail_id, 'full');
        if (!$image) {
            return null;
        }
        return [
            'id'     => (int) $thumbnail_id,
            'url'    => $image[0],
            'width'  => (int) $image[1],
            'height' => (int) $image[2],
            'alt'    => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
        ];
    }
}
