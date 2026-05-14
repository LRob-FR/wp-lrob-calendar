<?php
/**
 * Import functionality - supports LRob Calendar and All-in-One Event Calendar formats
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Import {

    private array $category_map = [];
    private array $tag_map      = [];
    private array $warnings     = [];

    /**
     * Non-blocking warnings collected during the import (large files, image URLs
     * pointing to private/localhost IPs, etc.). The admin shows these as notices
     * after the import completes; nothing here aborts the import flow.
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    public function import(array $data, bool $skip_existing = true): int {
        $imported = 0;
        
        // Detect format
        $is_ai1ec = $this->is_ai1ec_format($data);
        
        // Import categories first
        if (!empty($data['categories'])) {
            $this->import_categories($data['categories'], $is_ai1ec);
        }
        
        // Import tags
        if (!empty($data['tags'])) {
            $this->import_tags($data['tags']);
        }
        
        // Import events
        if (!empty($data['events'])) {
            foreach ($data['events'] as $event_data) {
                if ($this->import_event($event_data, $skip_existing, $is_ai1ec)) {
                    $imported++;
                }
            }
        }
        
        return $imported;
    }
    
    private function is_ai1ec_format(array $data): bool {
        // Explicit plugin marker takes precedence
        if (isset($data['meta']['plugin'])) {
            return strpos($data['meta']['plugin'], 'All-in-One') !== false;
        }

        if (empty($data['events'])) {
            return false;
        }

        // Sample the first few events — a single event missing AI1EC markers
        // (e.g. an event with no cost block) shouldn't misdetect a whole AI1EC export.
        foreach (array_slice($data['events'], 0, 5) as $event) {
            if (isset($event['ical']['uid'])) {
                return true;
            }
            if (isset($event['cost']) && is_array($event['cost']) && isset($event['cost']['value'])) {
                return true;
            }
        }

        return false;
    }
    
    private function import_categories(array $categories, bool $is_ai1ec = false): void {
        global $wpdb;
        
        $taxonomy = LRob_Calendar_Post_Types::TAX_CATEGORY;
        $meta_table = LRob_Calendar_Database::get_category_meta_table();
        
        foreach ($categories as $cat) {
            $old_id = (int) $cat['id'];
            
            // Check if term exists by slug
            $existing = get_term_by('slug', $cat['slug'], $taxonomy);
            
            if ($existing) {
                $term_id = $existing->term_id;
            } else {
                // Create new term
                $args = [];
                if (!empty($cat['description'])) {
                    $args['description'] = $cat['description'];
                }
                if (!empty($cat['parent_id']) && isset($this->category_map[$cat['parent_id']])) {
                    $args['parent'] = $this->category_map[$cat['parent_id']];
                }
                
                $result = wp_insert_term($cat['name'], $taxonomy, $args);
                
                if (is_wp_error($result)) {
                    continue;
                }
                
                $term_id = $result['term_id'];
            }
            
            $this->category_map[$old_id] = $term_id;
            
            // Save meta (color, image)
            $color = $cat['color'] ?? ($cat['term_color'] ?? null);
            $image = $cat['image'] ?? ($cat['term_image'] ?? null);
            
            // If image is a URL, import it to media library
            $image_id = null;
            if ($image && filter_var($image, FILTER_VALIDATE_URL)) {
                $image_id = $this->import_image($image);
                $image = $image_id ? wp_get_attachment_url($image_id) : $image;
            }
            
            if ($color || $image) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT term_id FROM {$meta_table} WHERE term_id = %d",
                    $term_id
                ));
                
                $meta_data = [
                    'term_id' => $term_id,
                    'color' => $color ?: '',
                    'image' => $image ?: '',
                ];
                
                if ($exists) {
                    $wpdb->update($meta_table, $meta_data, ['term_id' => $term_id]);
                } else {
                    $wpdb->insert($meta_table, $meta_data);
                }
            }
        }
    }
    
    private function import_tags(array $tags): void {
        $taxonomy = LRob_Calendar_Post_Types::TAX_TAG;
        
        foreach ($tags as $tag) {
            $old_id = (int) $tag['id'];
            
            $existing = get_term_by('slug', $tag['slug'], $taxonomy);
            
            if ($existing) {
                $term_id = $existing->term_id;
            } else {
                $args = [];
                if (!empty($tag['description'])) {
                    $args['description'] = $tag['description'];
                }
                
                $result = wp_insert_term($tag['name'], $taxonomy, $args);
                
                if (is_wp_error($result)) {
                    continue;
                }
                
                $term_id = $result['term_id'];
            }
            
            $this->tag_map[$old_id] = $term_id;
        }
    }
    
    private function import_event(array $data, bool $skip_existing, bool $is_ai1ec): bool {
        $title = $data['title'] ?? '';
        
        if (empty($title)) {
            return false;
        }
        
        // Check if event exists
        if ($skip_existing) {
            $existing = get_posts([
                'post_type' => LRob_Calendar_Post_Types::POST_TYPE,
                'title' => $title,
                'post_status' => 'any',
                'numberposts' => 1,
            ]);
            
            if (!empty($existing)) {
                return false;
            }
        }
        
        // Create post
        $post_data = [
            'post_type' => LRob_Calendar_Post_Types::POST_TYPE,
            'post_title' => $title,
            'post_content' => $data['content'] ?? '',
            'post_excerpt' => $data['excerpt'] ?? '',
            'post_status' => $data['status'] ?? 'publish',
            'post_name' => $data['slug'] ?? '',
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }
        
        // Create event record
        $event = new LRob_Calendar_Event();
        $event->set_post_id($post_id);
        
        // Parse dates
        $start = $this->parse_datetime($data['start'] ?? '');
        $end = $this->parse_datetime($data['end'] ?? '');
        
        if (!$start) {
            $start = time();
        }
        if (!$end) {
            $end = $start + 3600;
        }
        
        $event->set('start', $start);
        $event->set('end', $end);
        $event->set('timezone', $data['timezone'] ?? 'UTC');
        $event->set('allday', !empty($data['allday']) ? 1 : 0);
        $event->set('instant_event', !empty($data['instant_event']) ? 1 : 0);
        
        // Recurrence
        $event->set('recurrence_rules', $data['recurrence_rules'] ?? '');
        $event->set('exception_rules', $data['exception_rules'] ?? '');
        $event->set('recurrence_dates', $data['recurrence_dates'] ?? '');
        $event->set('exception_dates', $data['exception_dates'] ?? '');
        
        // Location - handle both flat and nested formats
        $location = $data['location'] ?? $data;
        $event->set('venue', $location['venue'] ?? '');
        $event->set('address', $location['address'] ?? '');
        $event->set('city', $location['city'] ?? '');
        $event->set('province', $location['province'] ?? '');
        $event->set('postal_code', $location['postal_code'] ?? '');
        $event->set('country', $location['country'] ?? '');
        $event->set('latitude', $location['latitude'] ?? null);
        $event->set('longitude', $location['longitude'] ?? null);
        $event->set('show_map', !empty($location['show_map']) ? 1 : 0);
        $event->set('show_coordinates', !empty($location['show_coordinates']) ? 1 : 0);
        
        // Contact
        $contact = $data['contact'] ?? $data;
        $event->set('contact_name', $contact['contact_name'] ?? ($contact['name'] ?? ''));
        $event->set('contact_phone', $contact['contact_phone'] ?? ($contact['phone'] ?? ''));
        $event->set('contact_email', $contact['contact_email'] ?? ($contact['email'] ?? ''));
        $event->set('contact_url', $contact['contact_url'] ?? ($contact['url'] ?? ''));
        
        // Cost
        $cost = $data['cost'] ?? [];
        if (is_array($cost)) {
            $event->set('cost', $cost['value'] ?? '');
            $event->set('is_free', !empty($cost['is_free']) ? 1 : 0);
        } else {
            $event->set('cost', $cost);
        }
        $event->set('ticket_url', $data['ticket_url'] ?? '');
        
        // iCal
        $ical = $data['ical'] ?? $data;
        $event->set('ical_uid', $ical['ical_uid'] ?? ($ical['uid'] ?? ''));
        $event->set('ical_feed_url', $ical['ical_feed_url'] ?? ($ical['feed_url'] ?? ''));
        $event->set('ical_source_url', $ical['ical_source_url'] ?? ($ical['source_url'] ?? ''));
        $event->set('ical_organizer', $ical['ical_organizer'] ?? ($ical['organizer'] ?? ''));
        $event->set('ical_contact', $ical['ical_contact'] ?? ($ical['contact'] ?? ''));
        
        $event->save();
        
        // Assign categories
        if (!empty($data['categories'])) {
            $cat_ids = [];
            foreach ($data['categories'] as $cat) {
                $old_id = is_array($cat) ? ($cat['id'] ?? 0) : $cat;
                if (isset($this->category_map[$old_id])) {
                    $cat_ids[] = $this->category_map[$old_id];
                } elseif (is_array($cat) && !empty($cat['slug'])) {
                    $term = get_term_by('slug', $cat['slug'], LRob_Calendar_Post_Types::TAX_CATEGORY);
                    if ($term) {
                        $cat_ids[] = $term->term_id;
                    }
                }
            }
            if (!empty($cat_ids)) {
                wp_set_object_terms($post_id, $cat_ids, LRob_Calendar_Post_Types::TAX_CATEGORY);
            }
        }
        
        // Assign tags
        if (!empty($data['tags'])) {
            $tag_ids = [];
            foreach ($data['tags'] as $tag) {
                $old_id = is_array($tag) ? ($tag['id'] ?? 0) : $tag;
                if (isset($this->tag_map[$old_id])) {
                    $tag_ids[] = $this->tag_map[$old_id];
                } elseif (is_array($tag) && !empty($tag['slug'])) {
                    $term = get_term_by('slug', $tag['slug'], LRob_Calendar_Post_Types::TAX_TAG);
                    if ($term) {
                        $tag_ids[] = $term->term_id;
                    }
                }
            }
            if (!empty($tag_ids)) {
                wp_set_object_terms($post_id, $tag_ids, LRob_Calendar_Post_Types::TAX_TAG);
            }
        }
        
        // Handle featured image from URL
        if (!empty($data['featured_image']['url'])) {
            $this->import_featured_image($post_id, $data['featured_image']['url']);
        }
        
        return true;
    }
    
    private function parse_datetime($value): ?int {
        if (empty($value)) {
            return null;
        }
        
        // Already a timestamp
        if (is_numeric($value)) {
            return (int) $value;
        }
        
        // ISO 8601 format
        try {
            $dt = new DateTime($value);
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function import_featured_image(int $post_id, string $url): void {
        if (empty($url)) {
            return;
        }

        $this->note_private_url($url);

        // Check if image already exists in media library by URL
        $existing_id = $this->find_existing_attachment($url);
        
        if ($existing_id) {
            set_post_thumbnail($post_id, $existing_id);
            return;
        }
        
        // Download and import image
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attachment_id = media_sideload_image($url, $post_id, '', 'id');
        
        if (!is_wp_error($attachment_id)) {
            // Store original URL as meta for future duplicate detection
            update_post_meta($attachment_id, '_lrob_original_url', $url);
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    /**
     * Find existing attachment by URL (checks both guid and our custom meta)
     */
    private function find_existing_attachment(string $url): ?int {
        global $wpdb;
        
        // Normalize URL
        $url = esc_url_raw($url);
        
        // 1. Check by exact URL in guid
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s",
            $url
        ));
        
        if ($attachment_id) {
            return (int) $attachment_id;
        }
        
        // 2. Check by our custom meta (for previously imported images)
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lrob_original_url' AND meta_value = %s",
            $url
        ));
        
        if ($attachment_id) {
            return (int) $attachment_id;
        }
        
        // 3. Check by filename in the uploads directory
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if ($filename) {
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'attachment' 
                AND guid LIKE %s",
                '%/' . $wpdb->esc_like($filename)
            ));
            
            if ($attachment_id) {
                return (int) $attachment_id;
            }
        }
        
        return null;
    }
    
    /**
     * Heads-up check for image URLs pointing at private/localhost hosts.
     * NOT a block — devs importing on localhost are a valid use case. Just adds
     * a warning so the admin knows to verify reachability before going live.
     * Each unique host is reported once per import.
     */
    private function note_private_url(string $url): void {
        static $reported = [];
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || isset($reported[$host])) {
            return;
        }
        $is_private = false;
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $is_private = true;
        } else {
            $ip = gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE returns false for private/reserved.
                $is_private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            }
        }
        if ($is_private) {
            $reported[$host] = true;
            $this->warnings[] = sprintf(
                /* translators: %s: hostname */
                __('Imported image(s) reference a private or localhost host (%s). They may not be reachable from your live site after migration.', 'lrob-calendar'),
                $host
            );
        }
    }

    /**
     * Import image from URL and return attachment ID
     */
    public function import_image(string $url): ?int {
        if (empty($url)) {
            return null;
        }

        $this->note_private_url($url);
        
        // Check if already exists
        $existing = $this->find_existing_attachment($url);
        if ($existing) {
            return $existing;
        }
        
        // Import new image
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attachment_id = media_sideload_image($url, 0, '', 'id');
        
        if (is_wp_error($attachment_id)) {
            return null;
        }
        
        // Store original URL
        update_post_meta($attachment_id, '_lrob_original_url', $url);
        
        return (int) $attachment_id;
    }
}
