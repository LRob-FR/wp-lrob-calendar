<?php
/**
 * Export functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Export {
    
    public function export_all(bool $include_instances = true): array {
        return [
            'meta' => [
                'plugin' => 'LRob Calendar',
                'version' => LROB_CALENDAR_VERSION,
                'exported_at' => gmdate('c'),
                'site_url' => get_site_url(),
            ],
            'categories' => $this->export_categories(),
            'tags' => $this->export_tags(),
            'events' => $this->export_events($include_instances),
        ];
    }
    
    public function export_categories(): array {
        global $wpdb;
        
        $categories = [];
        
        $terms = get_terms([
            'taxonomy' => LRob_Calendar_Post_Types::TAX_CATEGORY,
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        // Get category meta
        $meta_table = LRob_Calendar_Database::get_category_meta_table();
        $meta_data = [];
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$meta_table}'") === $meta_table) {
            $results = $wpdb->get_results("SELECT * FROM {$meta_table}");
            foreach ($results as $row) {
                $meta_data[$row->term_id] = [
                    'color' => $row->color,
                    'image' => $row->image,
                ];
            }
        }
        
        foreach ($terms as $term) {
            $cat = [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'parent_id' => (int) $term->parent ?: null,
                'count' => (int) $term->count,
            ];
            
            if (isset($meta_data[$term->term_id])) {
                $cat['color'] = $meta_data[$term->term_id]['color'];
                $cat['image'] = $meta_data[$term->term_id]['image'];
            }
            
            $categories[] = $cat;
        }
        
        return $categories;
    }
    
    public function export_tags(): array {
        $tags = [];
        
        $terms = get_terms([
            'taxonomy' => LRob_Calendar_Post_Types::TAX_TAG,
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        foreach ($terms as $term) {
            $tags[] = [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'count' => (int) $term->count,
            ];
        }
        
        return $tags;
    }
    
    public function export_events(bool $include_instances = true): array {
        global $wpdb;
        
        $events = [];
        $events_table = LRob_Calendar_Database::get_events_table();
        $posts_table = $wpdb->posts;
        
        $query = "
            SELECT e.*, p.*
            FROM {$events_table} e
            INNER JOIN {$posts_table} p ON e.post_id = p.ID
            WHERE p.post_type = %s
            ORDER BY e.start ASC
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, LRob_Calendar_Post_Types::POST_TYPE), ARRAY_A);
        
        foreach ($results as $row) {
            $post_id = (int) $row['post_id'];
            
            $event = [
                'id' => $post_id,
                'title' => $row['post_title'],
                'slug' => $row['post_name'],
                'content' => $row['post_content'],
                'excerpt' => $row['post_excerpt'],
                'status' => $row['post_status'],
                'author_id' => (int) $row['post_author'],
                'created_at' => $row['post_date_gmt'],
                'updated_at' => $row['post_modified_gmt'],
                
                'start' => $this->format_timestamp($row['start']),
                'end' => $this->format_timestamp($row['end']),
                'timezone' => $row['timezone'],
                'allday' => (bool) $row['allday'],
                'instant_event' => (bool) $row['instant_event'],
                
                'recurrence_rules' => $row['recurrence_rules'] ?: null,
                'exception_rules' => $row['exception_rules'] ?: null,
                'recurrence_dates' => $row['recurrence_dates'] ?: null,
                'exception_dates' => $row['exception_dates'] ?: null,
                
                'location' => [
                    'venue' => $row['venue'] ?: null,
                    'address' => $row['address'] ?: null,
                    'city' => $row['city'] ?: null,
                    'province' => $row['province'] ?: null,
                    'postal_code' => $row['postal_code'] ?: null,
                    'country' => $row['country'] ?: null,
                    'latitude' => $row['latitude'] ? (float) $row['latitude'] : null,
                    'longitude' => $row['longitude'] ? (float) $row['longitude'] : null,
                    'show_map' => (bool) $row['show_map'],
                    'show_coordinates' => (bool) $row['show_coordinates'],
                ],
                
                'contact' => [
                    'name' => $row['contact_name'] ?: null,
                    'phone' => $row['contact_phone'] ?: null,
                    'email' => $row['contact_email'] ?: null,
                    'url' => $row['contact_url'] ?: null,
                ],
                
                'cost' => [
                    'value' => $row['cost'] ?: null,
                    'is_free' => (bool) $row['is_free'],
                ],
                'ticket_url' => $row['ticket_url'] ?: null,
                
                'ical' => [
                    'uid' => $row['ical_uid'] ?: null,
                    'feed_url' => $row['ical_feed_url'] ?: null,
                    'source_url' => $row['ical_source_url'] ?: null,
                    'organizer' => $row['ical_organizer'] ?: null,
                    'contact' => $row['ical_contact'] ?: null,
                ],
                
                'categories' => $this->get_event_terms($post_id, LRob_Calendar_Post_Types::TAX_CATEGORY),
                'tags' => $this->get_event_terms($post_id, LRob_Calendar_Post_Types::TAX_TAG),
                'featured_image' => $this->get_featured_image($post_id),
            ];
            
            if ($include_instances && !empty($row['recurrence_rules'])) {
                $event['instances'] = $this->get_instances($post_id);
            }
            
            $events[] = $event;
        }
        
        return $events;
    }
    
    private function format_timestamp($timestamp): ?string {
        if (empty($timestamp)) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
    
    private function get_event_terms(int $post_id, string $taxonomy): array {
        $terms = wp_get_post_terms($post_id, $taxonomy);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        return array_map(function ($term) {
            return [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
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
            'id' => (int) $thumbnail_id,
            'url' => $image[0],
            'width' => (int) $image[1],
            'height' => (int) $image[2],
            'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
        ];
    }
    
    private function get_instances(int $post_id): array {
        global $wpdb;
        
        $table = LRob_Calendar_Database::get_instances_table();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, start, end FROM {$table} WHERE post_id = %d ORDER BY start ASC",
            $post_id
        ), ARRAY_A);
        
        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'start' => $this->format_timestamp($row['start']),
                'end' => $this->format_timestamp($row['end']),
            ];
        }, $results);
    }
}
