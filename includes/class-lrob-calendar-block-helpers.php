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

        // Per-event tint: take the first category's color (if any). Powers
        // the dot in month-grid event pills, the multi-day bar shading, the
        // day-list dots, AND the date-pill background in the events-list —
        // JS reads this as `event.color`.
        $color = null;
        $terms = get_the_terms($post_id, LRob_Calendar_Post_Types::TAX_CATEGORY);
        if (is_array($terms) && !empty($terms)) {
            $color = self::get_category_color((int) $terms[0]->term_id);
        }

        // Color-pair for the date pill on the events-list rows. Soft tint as
        // background, deeper hue as text. Available to JS and to the
        // server-rendered cards (passed back so render_event_card uses the
        // same pair).
        $pill_colors = self::date_pill_colors($color, $post_id);

        // Full post content rendered through `the_content` filter (shortcodes,
        // autop, oEmbeds, …). Sent in every event payload so the calendar
        // popup and the events-list popup display identical full descriptions
        // — previously the calendar fell back to the trimmed `excerpt` because
        // descriptionHtml was only added by the events-list code path.
        $description_html = '';
        if (!empty($post->post_content)) {
            $description_html = apply_filters('the_content', $post->post_content);
        }

        return [
            'id'            => $post_id,
            'title'         => $post->post_title,
            'excerpt'       => self::build_excerpt($post),
            'descriptionHtml' => $description_html,
            'url'           => get_permalink($post_id),
            'start'         => $start_dt->format('c'),
            'end'           => $end_dt->format('c'),
            'allDay'        => $event->is_allday(),
            'instant'       => $event->is_instant(),
            'recurring'     => $event->is_recurring(),
            // Full location set — popup renders multi-line address, list rows
            // can opt into 'compact' (venue + city only) or 'full' display.
            'venue'         => $event->get('venue'),
            'address'       => $event->get('address'),
            'city'          => $event->get('city'),
            'postalCode'    => $event->get('postal_code'),
            'province'      => $event->get('province'),
            'country'       => $event->get('country'),
            // Full contact set.
            'contactName'   => $event->get('contact_name'),
            'contactEmail'  => $event->get('contact_email'),
            'contactPhone'  => $event->get('contact_phone'),
            'contactUrl'    => $event->get('contact_url'),
            // The popup uses `thumbnail` directly as its <img src>, so 'large'
            // (≤1024px) keeps it crisp on the ~400-460px popup at any DPR.
            // `thumbnailFull` is the lightbox-grade size — actually larger.
            'thumbnail'     => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : null,
            'thumbnailFull' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'full')  : null,
            'isFree'        => $event->is_free(),
            'cost'          => $event->get('cost') ?: null,
            'ticketUrl'     => $event->get('ticket_url') ?: null,
            'color'         => $color,
            'pillBg'        => $pill_colors['bg'],
            'pillText'      => $pill_colors['text'],
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
     * Background + text colors for the events-list date pill.
     *
     * Logic:
     *  - If the event has a category color set, derive a SOFT background tint
     *    of that hue and a deep matching text color. Result reads as "this is
     *    the category color, but readable."
     *  - Otherwise, hash the event ID into a deterministic hue and generate
     *    a soft pastel pair — same event always gets the same color, but a
     *    page of mixed events gets visual variety.
     *
     * Returns ['bg' => 'hsl(...)', 'text' => 'hsl(...)'].
     */
    public static function date_pill_colors(?string $category_color, int $event_id): array {
        // 1. Category color always wins, regardless of admin settings.
        if ($category_color && preg_match('/^#([0-9a-f]{6})$/i', $category_color, $m)) {
            return self::pill_pair_from_hex($m[1]);
        }

        // 2. Uncategorized events: admin chooses random / primary / custom.
        $mode = (string) get_option('lrob_calendar_uncategorized_pill_mode', 'random');

        if ($mode === 'primary') {
            $primary = (string) get_option('lrob_calendar_primary_color', '');
            if ($primary === '') $primary = '#3b82f6'; // default brand blue
            if (preg_match('/^#([0-9a-f]{6})$/i', $primary, $m)) {
                return self::pill_pair_from_hex($m[1]);
            }
        }

        if ($mode === 'custom') {
            $custom = (string) get_option('lrob_calendar_uncategorized_pill_color', '');
            if (preg_match('/^#([0-9a-f]{6})$/i', $custom, $m)) {
                return self::pill_pair_from_hex($m[1]);
            }
            // Fall through to random if the custom color isn't set / invalid.
        }

        // 3. Random per-event (default). Deterministic hue from event id — same
        // event always gets the same color, but a page of mixed events gets
        // visual variety. 137° steps spread well across the circle.
        $hue = ($event_id * 137) % 360;
        return [
            'bg'   => 'hsl(' . $hue . ', 65%, 92%)',
            'text' => 'hsl(' . $hue . ', 55%, 28%)',
        ];
    }

    /**
     * Given a 6-char hex (no `#`), return the soft-tint / deep-text HSL pair
     * used by every date pill in the plugin. Single source of truth — both
     * the category-color path and the admin-configurable uncategorized
     * paths run through this.
     */
    private static function pill_pair_from_hex(string $hex): array {
        [$h, $s, $_l] = self::hex_to_hsl($hex);
        $sat = max(45, $s);
        return [
            'bg'   => 'hsl(' . $h . ', ' . $sat . '%, 92%)',
            'text' => 'hsl(' . $h . ', ' . $sat . '%, 28%)',
        ];
    }

    /**
     * Naive hex → HSL conversion. Input: 6-char hex without leading '#'.
     * Returns [hue 0-360, sat %, lum %] as ints. Good enough for picking
     * tints; not colour-science accurate.
     */
    private static function hex_to_hsl(string $hex): array {
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0, 0, (int) round($l * 100)];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if      ($max === $r) { $h = (($g - $b) / $d) + ($g < $b ? 6 : 0); }
        elseif  ($max === $g) { $h = (($b - $r) / $d) + 2; }
        else                  { $h = (($r - $g) / $d) + 4; }
        $h /= 6;
        return [
            (int) round($h * 360),
            (int) round($s * 100),
            (int) round($l * 100),
        ];
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
        // descriptionMode supersedes the older boolean showExcerpt:
        //   inline (default) — show the description in the row
        //   button — hide it; render a "View details" button that opens a popup
        //   none   — hide it, no button
        // For backwards compat: showExcerpt=false maps to descriptionMode=none.
        $description_mode = $atts['descriptionMode'] ?? null;
        if ($description_mode === null) {
            $description_mode = (($atts['showExcerpt'] ?? true) === false) ? 'none' : 'inline';
        }
        if (!in_array($description_mode, ['inline', 'button', 'none'], true)) {
            $description_mode = 'inline';
        }
        $show_excerpt    = ($description_mode === 'inline');
        $show_details_btn = ($description_mode === 'button');
        $show_categories = $atts['showCategories']  ?? true;
        $image_display   = in_array($atts['imageDisplay'] ?? 'contain', ['cover', 'contain'], true) ? $atts['imageDisplay'] : 'contain';
        $image_height    = in_array($atts['imageHeight'] ?? 'medium', ['small', 'medium', 'large', 'auto'], true) ? $atts['imageHeight'] : 'medium';
        // Where the featured image sits in list/full templates.
        //   right (default) — vertical column on the right (good for portrait images)
        //   left            — vertical column on the left (between date block and content)
        //   below           — full-width below the content (the classic banner)
        // Grid template ignores this (image is always on top with date badge overlay).
        $image_position  = in_array($atts['imagePosition'] ?? 'right', ['right', 'left', 'below'], true) ? $atts['imagePosition'] : 'right';
        // How verbosely to show location + contact in the ROW (not the popup —
        // the popup always shows everything available). 'full' is the default
        // because if a user entered the info, they want it seen.
        $location_display = in_array($atts['locationDisplay'] ?? 'full', ['full', 'compact', 'none'], true) ? $atts['locationDisplay'] : 'full';
        $contact_display  = in_array($atts['contactDisplay']  ?? 'full', ['full', 'compact', 'none'], true) ? $atts['contactDisplay']  : 'full';

        $date_format   = get_option('date_format');
        $time_format   = get_option('time_format');
        $pages_enabled = LRob_Calendar::public_pages_enabled();
        $permalink     = $pages_enabled ? get_permalink($post->ID) : '';

        $has_thumb     = $show_images && has_post_thumbnail($post->ID);

        // Date block: large day number stacked over uppercase short month.
        // wp_date() respects the site locale so "JUN" becomes "JUIN" on a
        // French install. Empty start (shouldn't happen) falls back to today.
        $start_dt    = $event->get_start_datetime();
        $start_ts    = $start_dt ? $start_dt->getTimestamp() : time();
        $date_day    = wp_date('j', $start_ts);
        $date_month  = mb_strtoupper(wp_date('M', $start_ts));

        // Date-pill colors — category color if set, deterministic hashed
        // pastel otherwise. Same pair regardless of template, so the same
        // event always looks "the same shade" across list/grid/minimal.
        $cat_color   = null;
        $categories  = $event->get_categories();
        if (!empty($categories)) {
            $cat_color = self::get_category_color((int) $categories[0]->term_id);
        }
        $pill_colors = self::date_pill_colors($cat_color, $post->ID);
        $pill_style  = ' style="background-color: ' . esc_attr($pill_colors['bg'])
                     . '; color: ' . esc_attr($pill_colors['text']) . '"';

        // CTA link target — falls back to the permalink if no ticket URL is set.
        $ticket_url  = $event->get('ticket_url') ?: '';

        $card_classes = [
            'lrob-event-card',
            'lrob-event-' . $template,
            'lrob-event-card--image-' . $image_display,
            'lrob-event-card--image-height-' . $image_height,
            'lrob-event-card--image-position-' . $image_position,
        ];

        // Build the date-block markup once — used by every template, just
        // styled differently per layout (left column / corner badge / pill).
        ob_start();
        ?>
        <div class="lrob-event-card__date-block" aria-hidden="true"<?php echo $pill_style; ?>>
            <span class="lrob-event-card__date-day"><?php echo esc_html($date_day); ?></span>
            <span class="lrob-event-card__date-month"><?php echo esc_html($date_month); ?></span>
        </div>
        <?php
        $date_block_html = (string) ob_get_clean();

        // ─── Minimal template: single-line, no excerpt/image/categories ──
        if ($template === 'minimal') {
            // Minimal rows show no description/image inline, so they'd otherwise
            // have no path to the full event details. When the caller wired up
            // the popup infrastructure (events-list sets enablePopup for minimal
            // rows), emit a compact icon-only "i" trigger that opens the shared
            // popup card — no full "View details" button, just the icon.
            $enable_popup = !empty($atts['enablePopup']);
            ob_start();
            ?>
            <article class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
                <span class="lrob-event-card__date-pill"<?php echo $pill_style; ?>>
                    <?php echo esc_html($date_day . ' ' . $date_month); ?>
                </span>
                <span class="lrob-event-card__title">
                    <?php if ($pages_enabled): ?>
                        <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($post->post_title); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($post->post_title); ?>
                    <?php endif; ?>
                </span>
                <?php if (!$event->is_allday() && !$event->is_instant()): ?>
                    <span class="lrob-event-card__time"><?php echo esc_html(wp_date($time_format, $start_ts)); ?></span>
                <?php endif; ?>
                <?php if ($enable_popup):
                    $details_label   = __('View details', 'lrob-calendar');
                    $event_json_attr = esc_attr(wp_json_encode(self::event_for_popup_json($event)));
                    ?>
                    <button class="lrob-event-btn lrob-event-btn--ghost lrob-event-details-btn lrob-event-details-btn--icon"
                            type="button"
                            data-popup-for="<?php echo (int) $post->ID; ?>"
                            data-event="<?php echo $event_json_attr; ?>"
                            aria-expanded="false"
                            aria-label="<?php echo esc_attr($details_label); ?>">
                        <?php echo LRob_Calendar_Icons::get('info'); ?>
                    </button>
                <?php endif; ?>
            </article>
            <?php
            return (string) ob_get_clean();
        }

        // Build excerpt once for non-minimal templates.
        $excerpt_html = '';
        if ($show_excerpt) {
            if (!empty($post->post_excerpt)) {
                $excerpt_html = wp_kses_post($post->post_excerpt);
            } elseif (!empty($post->post_content)) {
                $excerpt_html = wp_kses_post($post->post_content);
            }
        }

        // Thumbnail block — emitted once, positioned per-template via CSS.
        // When descriptionMode = button AND the row has an image, the image
        // itself BECOMES the popup trigger: it carries data-popup-for +
        // data-event so a click opens the shared popup (same as the
        // "View details" button). Otherwise it's a static <div>.
        $event_json_attr   = esc_attr(wp_json_encode(self::event_for_popup_json($event)));
        $image_is_trigger  = $show_details_btn && $has_thumb;
        $image_trigger_attr = $image_is_trigger
            ? ' data-popup-for="' . (int) $post->ID . '" data-event="' . $event_json_attr . '"'
            : '';
        ob_start();
        if ($has_thumb) {
            $thumb_attrs = ['alt' => esc_attr($post->post_title), 'loading' => 'lazy'];
            if ($image_is_trigger) {
                ?>
                <button class="lrob-event-thumbnail lrob-event-thumbnail--clickable"
                        type="button"<?php echo $image_trigger_attr; ?>>
                    <?php echo get_the_post_thumbnail($post->ID, 'medium', $thumb_attrs); ?>
                    <?php if ($template === 'grid'): /* Grid template overlays the date block on the image */ ?>
                        <span class="lrob-event-card__date-badge" aria-hidden="true"<?php echo $pill_style; ?>>
                            <span class="lrob-event-card__date-day"><?php echo esc_html($date_day); ?></span>
                            <span class="lrob-event-card__date-month"><?php echo esc_html($date_month); ?></span>
                        </span>
                    <?php endif; ?>
                </button>
                <?php
            } else {
                ?>
                <div class="lrob-event-thumbnail">
                    <?php echo get_the_post_thumbnail($post->ID, 'medium', $thumb_attrs); ?>
                    <?php if ($template === 'grid'): ?>
                        <span class="lrob-event-card__date-badge" aria-hidden="true"<?php echo $pill_style; ?>>
                            <span class="lrob-event-card__date-day"><?php echo esc_html($date_day); ?></span>
                            <span class="lrob-event-card__date-month"><?php echo esc_html($date_month); ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <?php
            }
        } elseif ($template === 'grid') {
            // Grid template with no thumbnail — turn the image area INTO the
            // date display rather than floating a small badge in the middle.
            // The placeholder uses the per-event pill colors so it reads as
            // an intentional "no image, here's a colorful date card" layout.
            ?>
            <div class="lrob-event-thumbnail lrob-event-thumbnail--placeholder" aria-hidden="true"<?php echo $pill_style; ?>>
                <span class="lrob-event-card__date-day"><?php echo esc_html($date_day); ?></span>
                <span class="lrob-event-card__date-month"><?php echo esc_html($date_month); ?></span>
            </div>
            <?php
        }
        $thumb_html = (string) ob_get_clean();

        // ─── List + Full templates: date-block on left, content on right ─
        ob_start();
        ?>
        <article class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
            <?php
            // GRID: image on top (carries its own date badge), content below.
            // LIST / FULL: date block on the left + content column. The image
            // (if any) becomes a SIBLING column (left or right) when image
            // position is left/right; only "below" keeps it inside the content.
            if ($template === 'grid') {
                echo $thumb_html;
            } else {
                echo $date_block_html;

                if ($has_thumb && $image_position === 'left') {
                    echo $thumb_html;
                }
            }
            ?>

            <div class="lrob-event-content">
                <h3 class="lrob-event-title">
                    <?php if ($pages_enabled): ?>
                        <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($post->post_title); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($post->post_title); ?>
                    <?php endif; ?>
                </h3>

                <div class="lrob-event-meta">
                    <?php $when_parts = $event->format_date_and_time($date_format, $time_format); ?>
                    <span class="lrob-event-date">
                        <?php echo LRob_Calendar_Icons::get('calendar'); ?>
                        <span class="lrob-event-meta-stack">
                            <span><?php echo esc_html($when_parts['date']); ?></span>
                            <?php if ($when_parts['time']): ?>
                                <span class="lrob-event-time"><?php echo esc_html($when_parts['time']); ?></span>
                            <?php endif; ?>
                        </span>
                    </span>

                    <?php
                    // Location — three modes:
                    //   full    → multi-line address (default, shows what the
                    //             editor filled in)
                    //   compact → "venue, city" on one line (old behaviour)
                    //   none    → hidden
                    if ($location_display !== 'none' && $event->has_location()):
                        if ($location_display === 'full') {
                            $loc_lines = array_filter([
                                $event->get('venue'),
                                $event->get('address'),
                                trim(($event->get('postal_code') ?: '') . ' ' . ($event->get('city') ?: '')),
                                $event->get('province'),
                                $event->get('country'),
                            ]);
                            ?>
                            <span class="lrob-event-location lrob-event-location--full">
                                <?php echo LRob_Calendar_Icons::get('location'); ?>
                                <span class="lrob-event-meta-stack">
                                    <?php foreach ($loc_lines as $line): ?>
                                        <span><?php echo esc_html($line); ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </span>
                            <?php
                        } else {
                            $compact_parts = array_filter([$event->get('venue'), $event->get('city')]);
                            ?>
                            <span class="lrob-event-location">
                                <?php echo LRob_Calendar_Icons::get('location'); ?>
                                <span><?php echo esc_html(implode(', ', $compact_parts)); ?></span>
                            </span>
                            <?php
                        }
                    endif;
                    ?>

                    <?php if ($event->is_recurring()): ?>
                        <span class="lrob-event-recurring">
                            <?php echo LRob_Calendar_Icons::get('recurring'); ?>
                            <span><?php esc_html_e('Recurring', 'lrob-calendar'); ?></span>
                        </span>
                    <?php endif; ?>

                    <?php if ($event->is_free()): ?>
                        <span class="lrob-event-cost lrob-event-cost--free">
                            <?php echo LRob_Calendar_Icons::get('ticket'); ?>
                            <span><?php esc_html_e('Free', 'lrob-calendar'); ?></span>
                        </span>
                    <?php elseif ($event->get('cost')): ?>
                        <span class="lrob-event-cost">
                            <?php echo LRob_Calendar_Icons::get('ticket'); ?>
                            <span><?php echo esc_html($event->get('cost')); ?></span>
                        </span>
                    <?php endif; ?>

                    <?php
                    // Contact — three modes. All contact items wrap in a
                    // single .lrob-event-contact-group so they cluster
                    // visually (tighter sub-gap) rather than scattering
                    // among the other meta items.
                    if ($contact_display !== 'none'):
                        $c_name  = $event->get('contact_name');
                        $c_email = $event->get('contact_email');
                        $c_phone = $event->get('contact_phone');
                        $c_url   = $event->get('contact_url');
                        if ($c_name || $c_email || $c_phone || $c_url): ?>
                            <span class="lrob-event-contact-group">
                                <?php if ($c_name): ?>
                                    <span class="lrob-event-contact-item">
                                        <?php echo LRob_Calendar_Icons::get('person'); ?>
                                        <span><?php echo esc_html($c_name); ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($contact_display === 'full' && $c_email): ?>
                                    <span class="lrob-event-contact-item">
                                        <?php echo LRob_Calendar_Icons::get('email'); ?>
                                        <span><a href="mailto:<?php echo esc_attr($c_email); ?>"><?php echo esc_html($c_email); ?></a></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($contact_display === 'full' && $c_phone): ?>
                                    <span class="lrob-event-contact-item">
                                        <?php echo LRob_Calendar_Icons::get('phone'); ?>
                                        <span><a href="tel:<?php echo esc_attr(preg_replace('/[^+\d]/', '', $c_phone)); ?>"><?php echo esc_html($c_phone); ?></a></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($contact_display === 'full' && $c_url): ?>
                                    <span class="lrob-event-contact-item">
                                        <?php echo LRob_Calendar_Icons::get('link'); ?>
                                        <span><a href="<?php echo esc_url($c_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($c_url); ?></a></span>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif;
                    endif;
                    ?>
                </div>

                <?php if ($excerpt_html): ?>
                    <div class="lrob-event-excerpt lrob-cal-clampable">
                        <?php echo $excerpt_html; ?>
                    </div>
                <?php endif; ?>

                <?php
                // Grid template: button goes inside the content column (the
                // card stacks vertically, so a right-edge button has nothing
                // sensible to sit beside). List/full emit it as a row-level
                // sibling below — see after </article>. Both variants carry
                // the full event JSON in data-event so the shared popup
                // module can render the card with zero extra fetches.
                if ($show_details_btn && $template === 'grid'):
                    $details_label   = __('View details', 'lrob-calendar');
                    $event_json_attr = esc_attr(wp_json_encode(self::event_for_popup_json($event)));
                    ?>
                    <button class="lrob-event-btn lrob-event-btn--ghost lrob-event-details-btn"
                            type="button"
                            data-popup-for="<?php echo (int) $post->ID; ?>"
                            data-event="<?php echo $event_json_attr; ?>"
                            aria-expanded="false">
                        <?php echo LRob_Calendar_Icons::get('info'); ?>
                        <span class="lrob-event-details-btn__label"><?php echo esc_html($details_label); ?></span>
                    </button>
                <?php endif; ?>

                <?php
                // LIST + FULL only: image below the content WHEN image
                // position is "below" (the full-width banner mode). For
                // left/right positions, the image lives outside this column
                // as a sibling — see above.
                if ($template !== 'grid' && $has_thumb && $image_position === 'below') {
                    echo $thumb_html;
                }
                ?>

                <?php
                $categories = $event->get_categories();
                if ($show_categories && !empty($categories)):
                    ?>
                    <div class="lrob-event-categories">
                        <?php foreach ($categories as $cat):
                            $color = self::get_category_color($cat->term_id);
                            $style = $color ? ' style="--lrob-cal-badge-color: ' . esc_attr($color) . '"' : '';
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

                <?php if ($pages_enabled || $ticket_url): ?>
                    <div class="lrob-event-cta">
                        <?php if ($ticket_url): ?>
                            <a href="<?php echo esc_url($ticket_url); ?>"
                               class="lrob-event-btn lrob-event-btn--secondary"
                               target="_blank" rel="noopener">
                                <?php esc_html_e('Get tickets', 'lrob-calendar'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($pages_enabled): ?>
                            <a href="<?php echo esc_url($permalink); ?>" class="lrob-event-btn lrob-event-btn--primary">
                                <?php esc_html_e('View event', 'lrob-calendar'); ?>
                                <?php echo LRob_Calendar_Icons::get('arrow-right'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            // Right-column image — sibling of .lrob-event-content. The CSS
            // (.lrob-event-card--image-position-right) flexes it to a fixed
            // share of the row, suitable for portrait images.
            if ($template !== 'grid' && $has_thumb && $image_position === 'right') {
                echo $thumb_html;
            }

            // Row-level action: the "View details" trigger lives on the right
            // edge of the row (sibling of content / image), vertically centered.
            // Cleaner than parking it inside the content column — the button
            // was getting orphaned space below the meta block.
            if ($show_details_btn && $template !== 'grid'):
                $details_label   = __('View details', 'lrob-calendar');
                $event_json_attr = esc_attr(wp_json_encode(self::event_for_popup_json($event)));
                ?>
                <button class="lrob-event-btn lrob-event-btn--ghost lrob-event-details-btn"
                        type="button"
                        data-popup-for="<?php echo (int) $post->ID; ?>"
                        data-event="<?php echo $event_json_attr; ?>"
                        aria-expanded="false"
                        aria-label="<?php echo esc_attr($details_label); ?>">
                    <?php echo LRob_Calendar_Icons::get('info'); ?>
                    <span class="lrob-event-details-btn__label"><?php echo esc_html($details_label); ?></span>
                </button>
                <?php
            endif;
            ?>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Small "Powered by LRob Calendar" attribution rendered at the bottom of
     * each block. Disabled site-wide from the Appearance settings page.
     * Returns the HTML (or '' when disabled) so callers can echo directly.
     */
    public static function render_credit(): string {
        if (!get_option('lrob_calendar_show_branding', true)) {
            return '';
        }
        $url   = defined('LROB_CALENDAR_PLUGIN_URL') ? LROB_CALENDAR_PLUGIN_URL : 'https://www.lrob.fr/';
        $label = esc_html__('Calendar by LRob', 'lrob-calendar');
        return '<p class="lrob-cal-credit">'
             . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">© ' . $label . '</a>'
             . '</p>';
    }

    /**
     * Serialize an event into the JSON shape consumed by the shared
     * event-popup module (assets/js/event-popup.js). Currently identical
     * to format_event_for_client() since v1.1.3 — descriptionHtml is
     * always shipped, so calendar popup and events-list popup show the
     * same content. The wrapper stays for naming clarity at call sites.
     */
    public static function event_for_popup_json(LRob_Calendar_Event $event): array {
        return self::format_event_for_client($event);
    }
}
