<?php
/**
 * Shared rendering helpers used by every block's render.php and by the REST handler.
 *
 * Static, stateless. All blocks call into here so output stays consistent.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Block_Helpers {

    /**
     * Shape an event for client consumption (REST + inlined initial JSON in the calendar).
     */
    public static function format_event_for_client(LRob_Calendar_Event $event): array {
        $post     = $event->get_post();
        $post_id  = $event->get_post_id();
        $thumb_id = get_post_thumbnail_id($post_id);

        // For recurring events, prefer the next upcoming instance over the base.
        // Otherwise an event whose base happened years ago but recurs forever
        // would display its original 2018 date in the popup — confusing.
        // Cutoff is start-of-today (site timezone) so today's instance still
        // counts as "next upcoming" even after its time has passed.
        $start_dt = $event->get_start_datetime();
        $end_dt   = $event->get_end_datetime();
        if ($event->is_recurring()) {
            $today_start = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
            $next = $event->get_next_instance_after($today_start);
            if ($next) {
                $tz = new DateTimeZone($event->get('timezone') ?: 'UTC');
                $start_dt = (new DateTime('@' . (int) $next['start']))->setTimezone($tz);
                $end_dt   = (new DateTime('@' . (int) $next['end']))->setTimezone($tz);
            }
        }

        return [
            'id'            => $post_id,
            'title'         => $post->post_title,
            'excerpt'       => self::build_excerpt($post),
            'url'           => get_permalink($post_id),
            'start'         => $start_dt->format('c'),
            'end'           => $end_dt->format('c'),
            'allDay'        => $event->is_allday(),
            'instant'       => $event->is_instant(),
            'recurring'     => $event->is_recurring(),
            'venue'         => $event->get('venue'),
            'city'          => $event->get('city'),
            'thumbnail'     => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : null,
            'thumbnailFull' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large')  : null,
            'isFree'        => $event->is_free(),
            'cost'          => $event->get('cost') ?: null,
            'ticketUrl'     => $event->get('ticket_url') ?: null,
        ];
    }

    /**
     * Excerpt fallback chain: explicit excerpt → trimmed content → null.
     */
    public static function build_excerpt(WP_Post $post): ?string {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }
        if (!empty($post->post_content)) {
            return wp_trim_words(wp_strip_all_tags($post->post_content), 30, '…');
        }
        return null;
    }

    const CACHE_GROUP = 'lrob_calendar';

    /**
     * Per-category color stored in our custom term-meta table.
     * Uses wp_cache_* — persistent on sites with a real object cache (Redis,
     * Memcached, etc.), falls back to per-request cache otherwise. Invalidated
     * by `Admin::save_category_meta()` on color change.
     */
    public static function get_category_color(int $term_id): ?string {
        $key = 'color:' . $term_id;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if ($cached !== false) {
            // Sentinel '__null__' lets us cache "no color set" without thrashing the DB on every hit.
            return $cached === '__null__' ? null : $cached;
        }

        global $wpdb;
        $table = LRob_Calendar_Database::get_category_meta_table();
        $color = $wpdb->get_var($wpdb->prepare(
            "SELECT color FROM {$table} WHERE term_id = %d",
            $term_id
        ));

        wp_cache_set($key, $color !== null ? $color : '__null__', self::CACHE_GROUP);
        return $color;
    }

    public static function invalidate_category_color(int $term_id): void {
        wp_cache_delete('color:' . $term_id, self::CACHE_GROUP);
    }

    /**
     * Prime WP object caches before rendering a batch of events. Without this,
     * each event triggers ~4 small queries (get_post, permalink, thumbnail meta,
     * attachment URL). With this, one bulk load → all subsequent helper calls
     * hit cache.
     */
    public static function prime_caches_for_events(array $events): void {
        if (empty($events)) {
            return;
        }
        $post_ids = [];
        foreach ($events as $event) {
            $post_ids[] = $event->get_post_id();
        }
        // Bulk-load posts + their meta (including _thumbnail_id).
        _prime_post_caches($post_ids, true, true);

        // Now collect thumbnail attachment IDs from the now-cached meta and bulk-load them.
        $thumb_ids = [];
        foreach ($post_ids as $pid) {
            $tid = get_post_thumbnail_id($pid);
            if ($tid) {
                $thumb_ids[] = (int) $tid;
            }
        }
        if (!empty($thumb_ids)) {
            _prime_post_caches($thumb_ids, false, true);
        }
    }

    /**
     * Render a single event "card" markup. Used by the events-list block and the
     * single-event block; both ship the same `assets/css/event-card.css` styles.
     *
     * @param array $atts Render attributes:
     *   - template:        'list' | 'grid' | 'compact' | 'minimal' | 'full'
     *   - showImages:      bool
     *   - showExcerpt:     bool
     *   - showCategories:  bool
     *   - imageDisplay:    'contain' | 'cover'                          (default 'contain')
     *   - imageHeight:     'small' | 'medium' | 'large' | 'auto'        (default 'medium')
     */
    public static function render_event_card(LRob_Calendar_Event $event, array $atts): string {
        $post            = $event->get_post();
        $template        = $atts['template']        ?? 'list';
        $show_images     = $atts['showImages']      ?? true;
        $show_excerpt    = $atts['showExcerpt']     ?? true;
        $show_categories = $atts['showCategories']  ?? true;
        $image_display   = in_array($atts['imageDisplay'] ?? 'contain', ['cover', 'contain'], true) ? $atts['imageDisplay'] : 'contain';
        $image_height    = in_array($atts['imageHeight'] ?? 'medium', ['small', 'medium', 'large', 'auto'], true) ? $atts['imageHeight'] : 'medium';
        // Whether thumbnail is clickable to open the fullscreen lightbox.
        // Always disabled in "natural" image-height mode — the full image is
        // already shown in the card so there's nothing to enlarge.
        $allow_lightbox  = ($atts['imageLightbox'] ?? true) && $image_height !== 'auto';

        $date_format   = get_option('date_format');
        $time_format   = get_option('time_format');
        $pages_enabled = LRob_Calendar::public_pages_enabled();
        $permalink     = $pages_enabled ? get_permalink($post->ID) : '';

        $thumb_id = get_post_thumbnail_id($post->ID);
        $full_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';

        $card_classes = [
            'lrob-event-card',
            'lrob-event-' . $template,
            'lrob-event-card--image-' . $image_display,
            'lrob-event-card--image-height-' . $image_height,
        ];

        ob_start();
        ?>
        <article class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
            <?php if ($show_images && has_post_thumbnail($post->ID) && $template !== 'minimal'):
                $thumb_attrs = ['alt' => esc_attr($post->post_title), 'loading' => 'lazy'];
                // $allow_lightbox computed above from attrs + image_height.
                ?>
                <?php if ($full_url && $allow_lightbox): ?>
                    <button class="lrob-event-thumbnail lrob-event-thumbnail--clickable"
                            type="button"
                            data-full-url="<?php echo esc_url($full_url); ?>">
                        <?php echo get_the_post_thumbnail($post->ID, 'medium', $thumb_attrs); ?>
                    </button>
                <?php else: ?>
                    <div class="lrob-event-thumbnail">
                        <?php echo get_the_post_thumbnail($post->ID, 'medium', $thumb_attrs); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="lrob-event-content">
                <h3 class="lrob-event-title">
                    <?php if ($pages_enabled): ?>
                        <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($post->post_title); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($post->post_title); ?>
                    <?php endif; ?>
                </h3>

                <div class="lrob-event-meta">
                    <span class="lrob-event-date">
                        <?php echo LRob_Calendar_Icons::get('calendar'); ?>
                        <?php echo esc_html($event->format_when($date_format, $time_format, ' - ')); ?>
                    </span>

                    <?php if ($event->has_location()): ?>
                        <span class="lrob-event-location">
                            <?php echo LRob_Calendar_Icons::get('location'); ?>
                            <?php
                            $location_parts = array_filter([
                                $event->get('venue'),
                                $event->get('city'),
                            ]);
                            echo esc_html(implode(', ', $location_parts));
                            ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($event->is_recurring()): ?>
                        <span class="lrob-event-recurring">
                            <?php echo LRob_Calendar_Icons::get('recurring'); ?>
                            <?php esc_html_e('Recurring', 'lrob-calendar'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php
                // Card excerpt: emit the full content (filtered + sanitized). CSS clamps
                // visually with a max-height fade; JS adds a "Show more" toggle for cards
                // whose content overflows the clamp.
                $excerpt_html = '';
                if ($show_excerpt && $template !== 'minimal') {
                    if (!empty($post->post_excerpt)) {
                        $excerpt_html = wp_kses_post($post->post_excerpt);
                    } elseif (!empty($post->post_content)) {
                        $excerpt_html = wp_kses_post($post->post_content);
                    }
                }
                if ($excerpt_html):
                    ?>
                    <div class="lrob-event-excerpt lrob-cal-clampable">
                        <?php echo $excerpt_html; ?>
                    </div>
                <?php endif; ?>

                <?php
                $categories = $event->get_categories();
                if ($show_categories && !empty($categories) && $template !== 'minimal'):
                    ?>
                    <div class="lrob-event-categories">
                        <?php foreach ($categories as $cat):
                            $color = self::get_category_color($cat->term_id);
                            $style = $color ? ' style="background-color: ' . esc_attr($color) . '"' : '';
                            if ($pages_enabled):
                                ?>
                                <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="lrob-category-badge"<?php echo $style; ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </a>
                            <?php else: ?>
                                <span class="lrob-category-badge"<?php echo $style; ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </span>
                                <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }
}
