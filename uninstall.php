<?php
/**
 * Uninstall LRob Calendar
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Delete posts
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'lrob_event'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");

// Delete terms
$wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id IN (SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('lrob_event_category', 'lrob_event_tag'))");
$wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('lrob_event_category', 'lrob_event_tag')");
$wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy})");

// Drop custom tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lrob_events");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lrob_event_instances");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}lrob_event_category_meta");

// Delete options
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lrob_calendar_%'");

// Clear rewrite rules
flush_rewrite_rules();
