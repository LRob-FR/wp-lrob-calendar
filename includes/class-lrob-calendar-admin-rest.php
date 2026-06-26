<?php
/**
 * Admin REST controller — authenticated CRUD for the custom event management
 * screen and (from v1.2) the dynamic modal editor.
 *
 * Routes (namespace lrob-calendar/v1):
 *   GET    /admin/events           list (search, status, taxonomy filters, paging)
 *   POST   /admin/events           create
 *   GET    /admin/events/{id}      full editable payload (one event)
 *   PUT    /admin/events/{id}      update
 *   DELETE /admin/events/{id}      trash (permanent cleanup runs on real delete)
 *
 * All routes require the lrob_event capability set and the standard REST cookie
 * nonce (X-WP-Nonce). Writes go through LRob_Calendar_Event::save() so the
 * instances table is rebuilt exactly like every other save path, then the public
 * REST cache version is bumped so the front-end calendar refreshes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Admin_REST {

    const NAMESPACE = 'lrob-calendar/v1';

    /** Description HTML kept intentionally small — the simple editor's guardrail. */
    private static function allowed_description_html(): array {
        return [
            'p'      => [],
            'br'     => [],
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'u'      => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'a'      => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
        ];
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/admin/events', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list_events'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_event'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/terms', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list_terms'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_term'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/terms/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_term'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_term'],
                'permission_callback' => [$this, 'can_edit_events'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/events/(?P<id>\d+)/duplicate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'duplicate_event'],
            'permission_callback' => [$this, 'can_edit_events'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/events/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_event'],
                'permission_callback' => [$this, 'can_edit_event'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE, // PUT/PATCH
                'callback'            => [$this, 'update_event'],
                'permission_callback' => [$this, 'can_edit_event'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_event'],
                'permission_callback' => [$this, 'can_delete_event'],
            ],
        ]);
    }

    /* ── Permissions ─────────────────────────────────────────────────────── */

    public function can_edit_events(): bool {
        return current_user_can('edit_lrob_events');
    }

    public function can_edit_event(WP_REST_Request $request): bool {
        return current_user_can('edit_lrob_event', (int) $request['id']);
    }

    public function can_delete_event(WP_REST_Request $request): bool {
        return current_user_can('delete_lrob_event', (int) $request['id']);
    }

    /* ── List ────────────────────────────────────────────────────────────── */

    public function list_events(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?: 20)));
        $paged    = max(1, (int) ($request->get_param('paged') ?: 1));
        $offset   = ($paged - 1) * $per_page;

        $search   = trim((string) $request->get_param('search'));
        $status   = sanitize_key((string) $request->get_param('status')) ?: 'any';
        $category = (int) $request->get_param('category');
        $tag      = (int) $request->get_param('tag');
        $past     = rest_sanitize_boolean($request->get_param('past'));

        $orderby  = in_array($request->get_param('orderby'), ['start', 'title', 'modified'], true)
            ? $request->get_param('orderby') : 'start';
        $order    = strtoupper((string) $request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';

        $events_table = LRob_Calendar_Database::get_events_table();

        $where  = ['p.post_type = %s'];
        $params = [LRob_Calendar_Post_Types::POST_TYPE];

        if ($status === 'any') {
            $where[] = "p.post_status IN ('publish','future','draft','pending','private')";
        } else {
            $where[]  = 'p.post_status = %s';
            $params[] = $status;
        }

        // Upcoming only by default: base ends today or later, or a recurring
        // event still has a future occurrence. Past events are opt-in.
        if (!$past) {
            $instances_table = LRob_Calendar_Database::get_instances_table();
            $cutoff = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
            $where[]  = "(e.end >= %d OR (e.recurrence_rules <> '' AND EXISTS (
                SELECT 1 FROM {$instances_table} ix WHERE ix.post_id = e.post_id AND ix.end >= %d
            )))";
            $params[] = $cutoff;
            $params[] = $cutoff;
        }

        if ($search !== '') {
            $where[]  = '(p.post_title LIKE %s OR p.post_content LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $join = '';
        if ($category || $tag) {
            $join     = " INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id"
                      . " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where[]  = 'tt.term_id = %d';
            $params[] = $category ?: $tag;
        }

        $where_sql = implode(' AND ', $where);

        $order_col = $orderby === 'title' ? 'p.post_title'
                   : ($orderby === 'modified' ? 'p.post_modified' : 'e.start');

        // Total (distinct posts — the taxonomy join can fan out rows).
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$events_table} e ON e.post_id = p.ID
             {$join}
             WHERE {$where_sql}",
            $params
        ));

        $rows_params = array_merge($params, [$per_page, $offset]);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$events_table} e ON e.post_id = p.ID
             {$join}
             WHERE {$where_sql}
             ORDER BY {$order_col} {$order}, p.ID DESC
             LIMIT %d OFFSET %d",
            $rows_params
        ));

        $items = [];
        foreach ($ids as $id) {
            $items[] = $this->serialize_list_item((int) $id);
        }

        return new WP_REST_Response([
            'events' => $items,
            'total'  => $total,
            'pages'  => (int) ceil($total / $per_page),
            'paged'  => $paged,
        ]);
    }

    private function serialize_list_item(int $post_id): array {
        $event = new LRob_Calendar_Event($post_id);
        $post  = $event->get_post();

        $thumb_id = get_post_thumbnail_id($post_id);

        $cats = wp_get_post_terms($post_id, LRob_Calendar_Post_Types::TAX_CATEGORY, ['fields' => 'names']);
        $tags = wp_get_post_terms($post_id, LRob_Calendar_Post_Types::TAX_TAG, ['fields' => 'names']);

        return [
            'id'        => $post_id,
            'title'     => $post ? $post->post_title : '',
            'status'    => $post ? $post->post_status : '',
            'start'     => (int) $event->get('start'),
            'end'       => (int) $event->get('end'),
            'allday'    => (bool) $event->get('allday'),
            'recurring' => $event->is_recurring(),
            'venue'     => $event->get('venue'),
            'city'      => $event->get('city'),
            'country'   => $event->get('country'),
            'categories'=> is_wp_error($cats) ? [] : $cats,
            'tags'      => is_wp_error($tags) ? [] : $tags,
            'thumbnail' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : null,
            'editLink'  => get_edit_post_link($post_id, 'raw'),
        ];
    }

    /* ── Read one (full editable payload) ────────────────────────────────── */

    public function get_event(WP_REST_Request $request): WP_REST_Response {
        $event = new LRob_Calendar_Event((int) $request['id']);
        $post  = $event->get_post();

        if (!$post) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }

        return new WP_REST_Response($this->serialize_full($event));
    }

    private function serialize_full(LRob_Calendar_Event $event): array {
        $post     = $event->get_post();
        $post_id  = $event->get_post_id();
        $fields   = $event->get_all();
        $thumb_id = get_post_thumbnail_id($post_id);

        return [
            'id'          => $post_id,
            'title'       => $post->post_title,
            'content'     => $post->post_content,
            'excerpt'     => $post->post_excerpt,
            'status'      => $post->post_status,
            // Did this content come from Gutenberg? Lets the editor warn before
            // simplifying block markup (handled fully in a later phase).
            'hasBlocks'   => function_exists('has_blocks') ? has_blocks($post->post_content) : (strpos($post->post_content, '<!-- wp:') !== false),
            'fields'      => $fields,
            // Wall-clock date/time split in the event's own timezone, so the
            // editor never does timezone math (server owns the conversion, just
            // like the classic meta box).
            'datetime'    => $this->event_datetime_block($event),
            'categories'  => wp_get_post_terms($post_id, LRob_Calendar_Post_Types::TAX_CATEGORY, ['fields' => 'ids']),
            'tags'        => wp_get_post_terms($post_id, LRob_Calendar_Post_Types::TAX_TAG, ['fields' => 'ids']),
            'featuredImage' => $thumb_id ? [
                'id'  => (int) $thumb_id,
                'url' => wp_get_attachment_image_url($thumb_id, 'medium'),
            ] : null,
            'editLink'    => get_edit_post_link($post_id, 'raw'),
        ];
    }

    /* ── Create / Update ─────────────────────────────────────────────────── */

    public function create_event(WP_REST_Request $request): WP_REST_Response {
        $body   = $request->get_json_params() ?: $request->get_params();
        $status = $this->resolve_status($body['status'] ?? 'draft');

        $post_id = wp_insert_post([
            'post_type'    => LRob_Calendar_Post_Types::POST_TYPE,
            'post_title'   => sanitize_text_field($body['title'] ?? ''),
            'post_content' => $this->sanitize_description($body['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($body['excerpt'] ?? ''),
            'post_status'  => $status,
        ], true);

        if (is_wp_error($post_id)) {
            return new WP_REST_Response(['error' => $post_id->get_error_message()], 400);
        }

        $this->apply_event_payload((int) $post_id, $body);

        $event = new LRob_Calendar_Event((int) $post_id);
        return new WP_REST_Response($this->serialize_full($event), 201);
    }

    public function update_event(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request['id'];
        $event   = new LRob_Calendar_Event($post_id);
        if (!$event->get_post()) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }

        $body = $request->get_json_params() ?: $request->get_params();

        $update = ['ID' => $post_id];
        if (array_key_exists('title', $body))   $update['post_title']   = sanitize_text_field($body['title']);
        if (array_key_exists('content', $body)) $update['post_content'] = $this->sanitize_description($body['content']);
        if (array_key_exists('excerpt', $body)) $update['post_excerpt'] = sanitize_textarea_field($body['excerpt']);
        if (array_key_exists('status', $body))  $update['post_status']  = $this->resolve_status($body['status']);

        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                return new WP_REST_Response(['error' => $result->get_error_message()], 400);
            }
        }

        $this->apply_event_payload($post_id, $body);

        $event = new LRob_Calendar_Event($post_id);
        return new WP_REST_Response($this->serialize_full($event));
    }

    /**
     * Write the custom-table fields, taxonomies and featured image, then
     * invalidate the public REST cache. Shared by create + update.
     */
    private function apply_event_payload(int $post_id, array $body): void {
        $event = new LRob_Calendar_Event($post_id);

        if (isset($body['fields']) && is_array($body['fields'])) {
            foreach ($this->sanitize_event_fields($body['fields']) as $key => $value) {
                $event->set($key, $value);
            }
        }
        if (isset($body['datetime']) && is_array($body['datetime'])) {
            $this->apply_datetime($event, $body['datetime']);
        }
        $event->save();

        if (isset($body['categories']) && is_array($body['categories'])) {
            wp_set_object_terms($post_id, array_map('intval', $body['categories']), LRob_Calendar_Post_Types::TAX_CATEGORY);
        }
        if (isset($body['tags']) && is_array($body['tags'])) {
            wp_set_object_terms($post_id, array_map('intval', $body['tags']), LRob_Calendar_Post_Types::TAX_TAG);
        }

        if (array_key_exists('featuredImageId', $body)) {
            $img = (int) $body['featuredImageId'];
            if ($img > 0) {
                set_post_thumbnail($post_id, $img);
            } else {
                delete_post_thumbnail($post_id);
            }
        }

        // Front-end calendar cache is keyed by a version counter — bump it so the
        // edit is visible on the next public fetch.
        if (method_exists('LRob_Calendar_Blocks', 'bump_rest_cache_version')) {
            LRob_Calendar_Blocks::bump_rest_cache_version();
        }
    }

    /* ── Duplicate ───────────────────────────────────────────────────────── */

    public function duplicate_event(WP_REST_Request $request): WP_REST_Response {
        $src_id = (int) $request['id'];
        $src    = get_post($src_id);
        if (!$src || $src->post_type !== LRob_Calendar_Post_Types::POST_TYPE) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }

        $new_id = wp_insert_post([
            'post_type'    => LRob_Calendar_Post_Types::POST_TYPE,
            /* translators: appended to a duplicated event's title */
            'post_title'   => trim($src->post_title . ' ' . __('(copy)', 'lrob-calendar')),
            'post_content' => $src->post_content,
            'post_excerpt' => $src->post_excerpt,
            'post_status'  => 'draft', // copies start as drafts
        ], true);

        if (is_wp_error($new_id)) {
            return new WP_REST_Response(['error' => $new_id->get_error_message()], 400);
        }
        $new_id = (int) $new_id;

        // Copy the custom event fields (fresh iCal UID so the copy is distinct).
        $src_event = new LRob_Calendar_Event($src_id);
        $new_event = new LRob_Calendar_Event($new_id);
        foreach ($src_event->get_all() as $key => $value) {
            if ($key !== 'post_id' && $key !== 'ical_uid') {
                $new_event->set($key, $value);
            }
        }
        $new_event->set('ical_uid', '');
        $new_event->save();

        // Taxonomies + featured image.
        foreach ([LRob_Calendar_Post_Types::TAX_CATEGORY, LRob_Calendar_Post_Types::TAX_TAG] as $tax) {
            $terms = wp_get_object_terms($src_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($terms)) {
                wp_set_object_terms($new_id, $terms, $tax);
            }
        }
        $thumb = get_post_thumbnail_id($src_id);
        if ($thumb) {
            set_post_thumbnail($new_id, $thumb);
        }

        if (method_exists('LRob_Calendar_Blocks', 'bump_rest_cache_version')) {
            LRob_Calendar_Blocks::bump_rest_cache_version();
        }

        return new WP_REST_Response($this->serialize_full(new LRob_Calendar_Event($new_id)), 201);
    }

    /* ── Quick term creation (categories/tags from the editor) ───────────── */

    /** Resolve "category"/"tag"/full names to a supported taxonomy, or '' if invalid. */
    private function resolve_taxonomy($value): string {
        $map = [
            'category' => LRob_Calendar_Post_Types::TAX_CATEGORY,
            'tag'      => LRob_Calendar_Post_Types::TAX_TAG,
        ];
        $tax = sanitize_key((string) $value);
        $tax = $map[$tax] ?? $tax;
        $allowed = [LRob_Calendar_Post_Types::TAX_CATEGORY, LRob_Calendar_Post_Types::TAX_TAG];
        return in_array($tax, $allowed, true) ? $tax : '';
    }

    private function serialize_term(WP_Term $term, string $taxonomy): array {
        $row = [
            'id'     => (int) $term->term_id,
            'name'   => $term->name,
            'slug'   => $term->slug,
            'parent' => (int) $term->parent,
            'count'  => (int) $term->count,
        ];
        if ($taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY) {
            $row['color'] = $this->get_term_color((int) $term->term_id);
        }
        return $row;
    }

    private function get_term_color(int $term_id): string {
        global $wpdb;
        $table = LRob_Calendar_Database::get_category_meta_table();
        $color = $wpdb->get_var($wpdb->prepare("SELECT color FROM {$table} WHERE term_id = %d", $term_id));
        return $color ? (string) $color : '';
    }

    private function save_term_color(int $term_id, string $color): void {
        global $wpdb;
        $color = sanitize_hex_color($color) ?: '';
        $table = LRob_Calendar_Database::get_category_meta_table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$table} WHERE term_id = %d", $term_id));
        if ($exists) {
            $wpdb->update($table, ['color' => $color], ['term_id' => $term_id]);
        } else {
            $wpdb->insert($table, ['term_id' => $term_id, 'color' => $color, 'image' => '']);
        }
        if (method_exists('LRob_Calendar_Block_Helpers', 'invalidate_category_color')) {
            LRob_Calendar_Block_Helpers::invalidate_category_color($term_id);
        }
    }

    public function list_terms(WP_REST_Request $request): WP_REST_Response {
        $taxonomy = $this->resolve_taxonomy($request->get_param('taxonomy'));
        if (!$taxonomy) {
            return new WP_REST_Response(['error' => 'invalid_taxonomy'], 400);
        }
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return new WP_REST_Response([]);
        }
        $out = [];
        foreach ($terms as $term) {
            $out[] = $this->serialize_term($term, $taxonomy);
        }
        return new WP_REST_Response($out);
    }

    public function create_term(WP_REST_Request $request): WP_REST_Response {
        $body     = $request->get_json_params() ?: $request->get_params();
        $taxonomy = $this->resolve_taxonomy($body['taxonomy'] ?? '');
        $name     = sanitize_text_field($body['name'] ?? '');
        $parent   = (int) ($body['parent'] ?? 0);

        if (!$taxonomy) {
            return new WP_REST_Response(['error' => 'invalid_taxonomy'], 400);
        }
        if ($name === '') {
            return new WP_REST_Response(['error' => 'empty_name'], 400);
        }

        $args = [];
        if ($parent && $taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY) {
            $args['parent'] = $parent;
        }

        $result = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($result)) {
            // A duplicate name still resolves to a usable id (error data is the
            // existing term_id, as an int or inside an array depending on WP).
            $existing = $result->get_error_data();
            if (is_array($existing) && isset($existing['term_id'])) {
                $result = ['term_id' => (int) $existing['term_id']];
            } elseif (is_numeric($existing)) {
                $result = ['term_id' => (int) $existing];
            } else {
                return new WP_REST_Response(['error' => $result->get_error_message()], 400);
            }
        }

        $term_id = (int) $result['term_id'];
        if ($taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY && isset($body['color'])) {
            $this->save_term_color($term_id, (string) $body['color']);
        }

        return new WP_REST_Response($this->serialize_term(get_term($term_id, $taxonomy), $taxonomy), 201);
    }

    public function update_term(WP_REST_Request $request): WP_REST_Response {
        $term_id = (int) $request['id'];
        $body    = $request->get_json_params() ?: $request->get_params();
        $term    = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        $taxonomy = $this->resolve_taxonomy($term->taxonomy);
        if (!$taxonomy) {
            return new WP_REST_Response(['error' => 'invalid_taxonomy'], 400);
        }

        $args = [];
        if (array_key_exists('name', $body)) {
            $args['name'] = sanitize_text_field($body['name']);
        }
        if (array_key_exists('parent', $body) && $taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY) {
            $args['parent'] = (int) $body['parent'];
        }
        if (!empty($args)) {
            $res = wp_update_term($term_id, $taxonomy, $args);
            if (is_wp_error($res)) {
                return new WP_REST_Response(['error' => $res->get_error_message()], 400);
            }
        }
        if ($taxonomy === LRob_Calendar_Post_Types::TAX_CATEGORY && array_key_exists('color', $body)) {
            $this->save_term_color($term_id, (string) $body['color']);
        }

        return new WP_REST_Response($this->serialize_term(get_term($term_id, $taxonomy), $taxonomy));
    }

    public function delete_term(WP_REST_Request $request): WP_REST_Response {
        $term_id = (int) $request['id'];
        $term    = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }
        $taxonomy = $this->resolve_taxonomy($term->taxonomy);
        if (!$taxonomy) {
            return new WP_REST_Response(['error' => 'invalid_taxonomy'], 400);
        }
        $res = wp_delete_term($term_id, $taxonomy);
        if (is_wp_error($res) || !$res) {
            return new WP_REST_Response(['error' => 'delete_failed'], 400);
        }
        return new WP_REST_Response(['deleted' => true, 'id' => $term_id]);
    }

    /* ── Delete ──────────────────────────────────────────────────────────── */

    public function delete_event(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request['id'];
        $force   = rest_sanitize_boolean($request->get_param('force'));

        if (!get_post($post_id)) {
            return new WP_REST_Response(['error' => 'not_found'], 404);
        }

        // Trash by default (custom-table cleanup runs on permanent delete via the
        // before_delete_post hook); force=1 deletes permanently.
        $result = $force ? wp_delete_post($post_id, true) : wp_trash_post($post_id);

        if (!$result) {
            return new WP_REST_Response(['error' => 'delete_failed'], 500);
        }

        if (method_exists('LRob_Calendar_Blocks', 'bump_rest_cache_version')) {
            LRob_Calendar_Blocks::bump_rest_cache_version();
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $post_id]);
    }

    /* ── Sanitizers ──────────────────────────────────────────────────────── */

    private function resolve_status(string $status): string {
        $status = sanitize_key($status);
        $allowed = ['publish', 'draft', 'pending', 'private', 'future'];
        if (!in_array($status, $allowed, true)) {
            $status = 'draft';
        }
        // Demote to pending if the user can't publish.
        if ($status === 'publish' && !current_user_can('publish_lrob_events')) {
            $status = 'pending';
        }
        return $status;
    }

    private function sanitize_description(string $html): string {
        return wp_kses($html, self::allowed_description_html());
    }

    /**
     * Map + sanitize the incoming editable event fields onto our schema. Only
     * known keys are passed through; everything is type-coerced server-side.
     */
    private function sanitize_event_fields(array $in): array {
        $out = [];

        $text   = ['timezone', 'venue', 'address', 'city', 'province', 'postal_code', 'country',
                   'contact_name', 'cost', 'ical_uid', 'ical_organizer', 'ical_contact'];
        $urls   = ['contact_url', 'ticket_url', 'ical_feed_url', 'ical_source_url'];
        $bools  = ['allday', 'instant_event', 'show_map', 'show_coordinates', 'is_free'];
        $rrules = ['recurrence_rules', 'exception_rules', 'recurrence_dates', 'exception_dates'];

        if (isset($in['start'])) $out['start'] = absint($in['start']);
        if (isset($in['end']))   $out['end']   = absint($in['end']);

        foreach ($text as $k) {
            if (isset($in[$k])) $out[$k] = sanitize_text_field((string) $in[$k]);
        }
        foreach ($urls as $k) {
            if (isset($in[$k])) $out[$k] = esc_url_raw((string) $in[$k]);
        }
        if (isset($in['contact_phone'])) {
            $out['contact_phone'] = sanitize_text_field((string) $in['contact_phone']);
        }
        if (isset($in['contact_email'])) {
            $out['contact_email'] = sanitize_email((string) $in['contact_email']);
        }
        foreach ($bools as $k) {
            if (isset($in[$k])) $out[$k] = !empty($in[$k]) ? 1 : 0;
        }
        foreach ($rrules as $k) {
            if (isset($in[$k])) $out[$k] = sanitize_textarea_field((string) $in[$k]);
        }
        foreach (['latitude', 'longitude'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = ($in[$k] === '' || $in[$k] === null) ? null : (float) $in[$k];
            }
        }

        return $out;
    }

    /**
     * Stored timestamps → wall-clock parts in the event's timezone, for the editor.
     */
    private function event_datetime_block(LRob_Calendar_Event $event): array {
        $tzname = $event->get('timezone') ?: LRob_Calendar_Event::get_default_timezone();
        try {
            $tz = new DateTimeZone($tzname);
        } catch (Exception $e) {
            $tz = wp_timezone();
            $tzname = $tz->getName();
        }

        $start = (int) $event->get('start');
        $end   = (int) $event->get('end');
        $sdt   = (new DateTime('@' . $start))->setTimezone($tz);
        $edt   = (new DateTime('@' . $end))->setTimezone($tz);

        $type = $event->is_instant() ? 'instant' : ($event->is_allday() ? 'allday' : 'standard');

        return [
            'type'       => $type,
            'timezone'   => $tzname,
            'start_date' => $start ? $sdt->format('Y-m-d') : '',
            'start_time' => $start ? $sdt->format('H:i') : '',
            'end_date'   => $end ? $edt->format('Y-m-d') : '',
            'end_time'   => $end ? $edt->format('H:i') : '',
        ];
    }

    /**
     * Wall-clock parts (in a given timezone) → stored timestamps, mirroring the
     * classic meta box so both editors behave identically. Invalid/empty input
     * leaves the existing values untouched.
     */
    private function apply_datetime(LRob_Calendar_Event $event, array $dt): void {
        $tzname = sanitize_text_field($dt['timezone'] ?? '');
        if ($tzname === '') {
            $tzname = LRob_Calendar_Event::get_default_timezone();
        }
        try {
            $tz = new DateTimeZone($tzname);
        } catch (Exception $e) {
            $tzname = LRob_Calendar_Event::get_default_timezone();
            $tz = new DateTimeZone($tzname);
        }

        $type = in_array($dt['type'] ?? 'standard', ['standard', 'allday', 'instant'], true)
            ? $dt['type'] : 'standard';
        $allday  = ($type === 'allday');
        $instant = ($type === 'instant');

        $start_date = sanitize_text_field($dt['start_date'] ?? '');
        if ($start_date === '') {
            return; // No date provided — keep existing.
        }
        $start_time = $allday ? '00:00' : sanitize_text_field($dt['start_time'] ?? '00:00');
        $end_date   = sanitize_text_field($dt['end_date'] ?? '') ?: $start_date;
        $end_time   = $allday ? '23:59' : sanitize_text_field($dt['end_time'] ?? '00:00');

        try {
            $sdt = new DateTime("{$start_date} {$start_time}", $tz);
            $edt = new DateTime("{$end_date} {$end_time}", $tz);
        } catch (Exception $e) {
            return;
        }
        if ($instant) {
            $edt = clone $sdt;
        }

        $event->set('start', $sdt->getTimestamp());
        $event->set('end', $edt->getTimestamp());
        $event->set('timezone', $tzname);
        $event->set('allday', $allday ? 1 : 0);
        $event->set('instant_event', $instant ? 1 : 0);
    }
}
