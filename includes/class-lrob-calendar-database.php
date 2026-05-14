<?php
/**
 * Database management - tables, schema migrations, version tracking.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Database {

    const DB_VERSION_OPTION = 'lrob_calendar_db_version';

    public static function get_events_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lrob_events';
    }

    public static function get_instances_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lrob_event_instances';
    }

    public static function get_category_meta_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lrob_event_category_meta';
    }

    /**
     * Single entry point for installing / upgrading the schema.
     *
     * Cheap when nothing's changed: one option read and early return.
     * Idempotent: safe to call on every request and from the activation hook.
     *
     * On version bump:
     *   1. Run each incremental migration whose target version is newer than
     *      the stored version. Migrations handle renames, drops, data backfills.
     *   2. Run dbDelta via apply_schema() to pick up additive changes
     *      (new tables, new columns, new indexes).
     *   3. Bump the stored version.
     *
     * ADDING A MIGRATION
     *   - Write a `private static function migrate_to_X_Y_Z(): void` that does
     *     the structural change idempotently (guard ALTERs with column checks,
     *     etc. — migrations may re-run on partial failures).
     *   - Register it in self::get_migrations() keyed by the TARGET version,
     *     keeping the array sorted oldest → newest.
     *   - Update self::apply_schema() to reflect the post-migration column set
     *     so fresh installs land on the right schema.
     *
     * RULE OF THUMB
     *   Pure ADD column/table/index → just update apply_schema(); dbDelta handles it.
     *   RENAME, DROP, retype, or data backfill → write a migration.
     */
    public static function maybe_upgrade(): void {
        $stored  = (string) get_option(self::DB_VERSION_OPTION, '0');
        $current = LROB_CALENDAR_VERSION;

        if (version_compare($stored, $current, '>=')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::get_migrations() as $target_version => $migration) {
            if (version_compare($stored, $target_version, '<')) {
                call_user_func($migration);
            }
        }

        self::apply_schema();

        update_option(self::DB_VERSION_OPTION, $current);
    }

    /**
     * Target-version => migration callable. Sorted oldest → newest.
     * Each migration must be idempotent.
     */
    private static function get_migrations(): array {
        return [
            // '1.1.0' => [self::class, 'migrate_to_1_1_0'],
        ];
    }

    /**
     * Run dbDelta against the current schema definitions.
     * Called by maybe_upgrade() after migrations; never directly externally.
     */
    private static function apply_schema(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $events_table        = self::get_events_table();
        $instances_table     = self::get_instances_table();
        $category_meta_table = self::get_category_meta_table();

        $sql_events = "CREATE TABLE {$events_table} (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            start BIGINT(20) NOT NULL,
            end BIGINT(20) NOT NULL,
            timezone VARCHAR(64) DEFAULT 'UTC',
            allday TINYINT(1) DEFAULT 0,
            instant_event TINYINT(1) DEFAULT 0,
            recurrence_rules TEXT,
            exception_rules TEXT,
            recurrence_dates TEXT,
            exception_dates TEXT,
            venue VARCHAR(255),
            address VARCHAR(255),
            city VARCHAR(128),
            province VARCHAR(128),
            postal_code VARCHAR(32),
            country VARCHAR(128),
            latitude DECIMAL(10, 6),
            longitude DECIMAL(10, 6),
            show_map TINYINT(1) DEFAULT 0,
            show_coordinates TINYINT(1) DEFAULT 0,
            contact_name VARCHAR(255),
            contact_phone VARCHAR(64),
            contact_email VARCHAR(128),
            contact_url VARCHAR(255),
            cost VARCHAR(128),
            is_free TINYINT(1) DEFAULT 0,
            ticket_url VARCHAR(255),
            ical_uid VARCHAR(255),
            ical_feed_url TEXT,
            ical_source_url TEXT,
            ical_organizer VARCHAR(255),
            ical_contact VARCHAR(255),
            PRIMARY KEY (post_id),
            KEY idx_start (start),
            KEY idx_end (end),
            KEY idx_start_end (start, end)
        ) {$charset_collate};";

        $sql_instances = "CREATE TABLE {$instances_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            start BIGINT(20) NOT NULL,
            end BIGINT(20) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post_id (post_id),
            KEY idx_start (start),
            KEY idx_post_start (post_id, start),
            UNIQUE KEY idx_unique_instance (post_id, start)
        ) {$charset_collate};";

        $sql_category_meta = "CREATE TABLE {$category_meta_table} (
            term_id BIGINT(20) UNSIGNED NOT NULL,
            color VARCHAR(7),
            image VARCHAR(255),
            PRIMARY KEY (term_id)
        ) {$charset_collate};";

        dbDelta($sql_events);
        dbDelta($sql_instances);
        dbDelta($sql_category_meta);
    }

    /**
     * @deprecated since 1.0.0 — call maybe_upgrade() instead.
     * Kept as a thin alias so older external callers (and the activation hook)
     * keep working without touching every call site.
     */
    public static function create_tables(): void {
        self::maybe_upgrade();
    }

    /**
     * Helper for migrations: does a column exist on a given table?
     */
    protected static function column_exists(string $table, string $column): bool {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $column
        ));
        return $result !== null;
    }

    public static function drop_tables(): void {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS " . self::get_events_table());
        $wpdb->query("DROP TABLE IF EXISTS " . self::get_instances_table());
        $wpdb->query("DROP TABLE IF EXISTS " . self::get_category_meta_table());

        delete_option(self::DB_VERSION_OPTION);
    }
}
