<?php
/**
 * LRob Calendar — uninstall handler.
 *
 * INTENTIONALLY A NO-OP. Earlier versions wiped every event, every custom
 * table, and every plugin option whenever the user removed the plugin from
 * the WordPress admin. That's destructive behaviour the user can't easily
 * undo or even preview, and WordPress provides no native way to surface a
 * "what do you want to delete?" prompt in the deletion flow.
 *
 * So this file keeps plugin data untouched on uninstall. Re-installing the
 * plugin later picks up everything where you left off. Users who genuinely
 * want to scrub the database can drop the `{prefix}lrob_events`,
 * `{prefix}lrob_event_instances` and `{prefix}lrob_event_category_meta`
 * tables manually, and delete the `lrob_calendar_*` options + the
 * `lrob_event` post type rows + the `lrob_event_category` /
 * `lrob_event_tag` taxonomy terms.
 *
 * The principle: it should never be possible to lose months of event data
 * by clicking "Delete" on a plugin row.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Intentionally empty.
