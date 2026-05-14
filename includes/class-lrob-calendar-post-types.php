<?php
/**
 * Custom Post Type and Taxonomies
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Post_Types {
    
    const POST_TYPE = 'lrob_event';
    const TAX_CATEGORY = 'lrob_event_category';
    const TAX_TAG = 'lrob_event_tag';
    
    public function register(): void {
        $this->register_post_type();
        $this->register_taxonomies();
    }
    
    private function register_post_type(): void {
        $labels = [
            'name'                  => __('Events', 'lrob-calendar'),
            'singular_name'         => __('Event', 'lrob-calendar'),
            'menu_name'             => __('Calendar', 'lrob-calendar'),
            'add_new'               => __('Add Event', 'lrob-calendar'),
            'add_new_item'          => __('Add New Event', 'lrob-calendar'),
            'edit_item'             => __('Edit Event', 'lrob-calendar'),
            'new_item'              => __('New Event', 'lrob-calendar'),
            'view_item'             => __('View Event', 'lrob-calendar'),
            'search_items'          => __('Search Events', 'lrob-calendar'),
            'not_found'             => __('No events found', 'lrob-calendar'),
            'not_found_in_trash'    => __('No events found in Trash', 'lrob-calendar'),
            'all_items'             => __('All Events', 'lrob-calendar'),
        ];
        
        // Admin UI is always available; only the public-facing URLs change.
        $public = LRob_Calendar::public_pages_enabled();

        $args = [
            'labels'              => $labels,
            'public'              => $public,
            'publicly_queryable'  => $public,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'query_var'           => $public,
            'rewrite'             => $public ? ['slug' => 'event', 'with_front' => false] : false,
            'capability_type'     => ['lrob_event', 'lrob_events'],
            'map_meta_cap'        => true,
            'has_archive'         => $public ? 'events' : false,
            'exclude_from_search' => !$public,
            'hierarchical'        => false,
            'menu_position'       => 5,
            'menu_icon'           => 'dashicons-calendar-alt',
            'supports'            => ['title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions'],
        ];
        
        register_post_type(self::POST_TYPE, $args);
        
        // Add capabilities to admin and editor roles only
        $this->add_capabilities();
    }
    
    private function add_capabilities(): void {
        $caps = [
            'edit_lrob_event',
            'read_lrob_event',
            'delete_lrob_event',
            'edit_lrob_events',
            'edit_others_lrob_events',
            'publish_lrob_events',
            'read_private_lrob_events',
            'delete_lrob_events',
            'delete_private_lrob_events',
            'delete_published_lrob_events',
            'delete_others_lrob_events',
            'edit_private_lrob_events',
            'edit_published_lrob_events',
        ];
        
        $roles = ['administrator', 'editor'];
        
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
    }
    
    private function register_taxonomies(): void {
        // Categories
        $cat_labels = [
            'name'              => __('Event Categories', 'lrob-calendar'),
            'singular_name'     => __('Event Category', 'lrob-calendar'),
            'search_items'      => __('Search Categories', 'lrob-calendar'),
            'all_items'         => __('All Categories', 'lrob-calendar'),
            'parent_item'       => __('Parent Category', 'lrob-calendar'),
            'parent_item_colon' => __('Parent Category:', 'lrob-calendar'),
            'edit_item'         => __('Edit Category', 'lrob-calendar'),
            'update_item'       => __('Update Category', 'lrob-calendar'),
            'add_new_item'      => __('Add New Category', 'lrob-calendar'),
            'new_item_name'     => __('New Category Name', 'lrob-calendar'),
            'menu_name'         => __('Categories', 'lrob-calendar'),
        ];
        
        $public = LRob_Calendar::public_pages_enabled();

        register_taxonomy(self::TAX_CATEGORY, self::POST_TYPE, [
            'labels'             => $cat_labels,
            'hierarchical'       => true,
            'public'             => $public,
            'publicly_queryable' => $public,
            'show_ui'            => true,
            'show_in_rest'       => true,
            'show_admin_column'  => true,
            'query_var'          => $public,
            'rewrite'            => $public ? ['slug' => 'event-category'] : false,
        ]);
        
        // Tags
        $tag_labels = [
            'name'              => __('Event Tags', 'lrob-calendar'),
            'singular_name'     => __('Event Tag', 'lrob-calendar'),
            'search_items'      => __('Search Tags', 'lrob-calendar'),
            'all_items'         => __('All Tags', 'lrob-calendar'),
            'edit_item'         => __('Edit Tag', 'lrob-calendar'),
            'update_item'       => __('Update Tag', 'lrob-calendar'),
            'add_new_item'      => __('Add New Tag', 'lrob-calendar'),
            'new_item_name'     => __('New Tag Name', 'lrob-calendar'),
            'menu_name'         => __('Tags', 'lrob-calendar'),
        ];
        
        register_taxonomy(self::TAX_TAG, self::POST_TYPE, [
            'labels'             => $tag_labels,
            'hierarchical'       => false,
            'public'             => $public,
            'publicly_queryable' => $public,
            'show_ui'            => true,
            'show_in_rest'       => true,
            'show_admin_column'  => true,
            'query_var'          => $public,
            'rewrite'            => $public ? ['slug' => 'event-tag'] : false,
        ]);
    }
}
