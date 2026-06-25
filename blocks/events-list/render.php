<?php
/**
 * Server-side render for the Events List block.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if (!defined('ABSPATH')) {
    exit;
}

// URL param scoped to the plugin to avoid collision with other lrob_* plugins.
const LROB_CALENDAR_PAGE_PARAM = 'lrob_calendar_page';

$per_page    = max(1, (int) $attributes['limit']);
$pagination  = !empty($attributes['pagination']);
$style       = in_array($attributes['paginationStyle'] ?? 'arrows', ['arrows', 'numbered'], true)
    ? $attributes['paginationStyle']
    : 'arrows';

// Resolve effective sort direction:
//   - 'auto' picks ASC when hiding past (show soonest upcoming first)
//     and DESC when showing past (most recent first, oldest at bottom).
//   - Explicit 'ASC' / 'DESC' override the auto behavior.
$order_attr = strtolower((string) ($attributes['order'] ?? 'auto'));
if ($order_attr === 'asc' || $order_attr === 'desc') {
    $effective_order = strtoupper($order_attr);
} else {
    $effective_order = $attributes['showPast'] ? 'DESC' : 'ASC';
}

$base_args = [
    'order' => $effective_order,
];

if (!$attributes['showPast']) {
    // "Today's events" — events that have already ended today should still
    // count as upcoming. Cutoff is midnight at the start of the site's local
    // day, not the precise current second.
    $base_args['start'] = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
}
if ($attributes['category']) {
    $base_args['category'] = (int) $attributes['category'];
}
if ($attributes['tag']) {
    $base_args['tag'] = (int) $attributes['tag'];
}

if ($pagination) {
    $current_page = isset($_GET[LROB_CALENDAR_PAGE_PARAM]) ? max(1, (int) $_GET[LROB_CALENDAR_PAGE_PARAM]) : 1;
    $total        = LRob_Calendar_Event::count_events($base_args);
    $total_pages  = max(1, (int) ceil($total / $per_page));
    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }

    $args = array_merge($base_args, [
        'limit'  => $per_page,
        'offset' => ($current_page - 1) * $per_page,
    ]);
} else {
    $args = array_merge($base_args, ['limit' => $per_page]);
}

$events = LRob_Calendar_Event::get_events($args);

// Expand recurring events into their upcoming occurrences so the list shows the
// next dates, not the (often past) base date — capped per event so a daily or
// never-ending recurrence can't flood the list. Skipped when showing past
// events (the base rows are kept as-is then).
$occ_cutoff    = isset($base_args['start']) ? (int) $base_args['start'] : null;
$max_per_event = max(1, (int) apply_filters('lrob_calendar_events_list_max_per_event', 3));

$list_items = [];
foreach ($events as $event) {
    if ($occ_cutoff !== null && $event->is_recurring()) {
        $occurrences = $event->get_instances_in_range($occ_cutoff, null, $max_per_event);
        if (!empty($occurrences)) {
            foreach ($occurrences as $occ) {
                $list_items[] = ['event' => $event, 'start' => (int) $occ['start'], 'end' => (int) $occ['end']];
            }
            continue;
        }
    }
    $list_items[] = ['event' => $event, 'start' => (int) $event->get('start'), 'end' => (int) $event->get('end')];
}
usort($list_items, static function ($a, $b) use ($effective_order) {
    return $effective_order === 'DESC' ? ($b['start'] <=> $a['start']) : ($a['start'] <=> $b['start']);
});
$list_items = array_slice($list_items, 0, $per_page);

// "View details" popup mode requires the shared event-popup CSS + JS
// module plus the events-list trigger script. The minimal template also
// needs it: its rows carry a compact "i" trigger (their only path to the
// full details). Enqueued conditionally so vanilla list pages don't pay
// the cost.
$popup_mode  = (($attributes['descriptionMode'] ?? 'inline') === 'button');
$minimal     = (($attributes['template'] ?? 'list') === 'minimal');
$needs_popup = $popup_mode || $minimal;
if ($needs_popup) {
    wp_enqueue_style('lrob-calendar-event-popup');
    wp_enqueue_script('lrob-calendar-event-list-popup');
}
// Tell render_event_card to emit the minimal "i" trigger (only meaningful
// for the minimal template, ignored otherwise).
$attributes['enablePopup'] = $needs_popup;
?>
<div class="lrob-cal-events-list-wrapper">
<?php if (empty($list_items)): ?>
    <p class="lrob-no-events"><?php esc_html_e('No events found.', 'lrob-calendar'); ?></p>
<?php else: ?>
    <div class="lrob-events-list lrob-template-<?php echo esc_attr($attributes['template']); ?>">
        <?php foreach ($list_items as $list_item):
            // Render the card at this specific occurrence's date.
            $list_item['event']->set('start', $list_item['start']);
            $list_item['event']->set('end', $list_item['end']);
            echo LRob_Calendar_Block_Helpers::render_event_card($list_item['event'], $attributes);
        endforeach; ?>
    </div>
<?php endif;

if ($pagination && !empty($events) && $total_pages > 1):
    $prev_url = $current_page > 1
        ? esc_url(add_query_arg(LROB_CALENDAR_PAGE_PARAM, $current_page - 1))
        : null;
    $next_url = $current_page < $total_pages
        ? esc_url(add_query_arg(LROB_CALENDAR_PAGE_PARAM, $current_page + 1))
        : null;
?>
    <nav class="lrob-cal-events-pagination lrob-cal-events-pagination--<?php echo esc_attr($style); ?>"
         aria-label="<?php esc_attr_e('Events list pagination', 'lrob-calendar'); ?>">

        <?php if ($style === 'arrows'): ?>
            <?php if ($prev_url): ?>
                <a href="<?php echo $prev_url; ?>" class="lrob-cal-page-arrow lrob-cal-page-arrow--prev"
                   aria-label="<?php esc_attr_e('Previous page', 'lrob-calendar'); ?>">&lsaquo;</a>
            <?php else: ?>
                <span class="lrob-cal-page-arrow lrob-cal-page-arrow--prev lrob-cal-page-arrow--disabled" aria-disabled="true">&lsaquo;</span>
            <?php endif; ?>

            <span class="lrob-cal-page-indicator">
                <?php
                /* translators: 1: current page number, 2: total pages */
                printf(esc_html__('Page %1$d / %2$d', 'lrob-calendar'), $current_page, $total_pages);
                ?>
            </span>

            <?php if ($next_url): ?>
                <a href="<?php echo $next_url; ?>" class="lrob-cal-page-arrow lrob-cal-page-arrow--next"
                   aria-label="<?php esc_attr_e('Next page', 'lrob-calendar'); ?>">&rsaquo;</a>
            <?php else: ?>
                <span class="lrob-cal-page-arrow lrob-cal-page-arrow--next lrob-cal-page-arrow--disabled" aria-disabled="true">&rsaquo;</span>
            <?php endif; ?>

        <?php else:
            echo paginate_links([
                'base'      => add_query_arg(LROB_CALENDAR_PAGE_PARAM, '%#%'),
                'format'    => '?' . LROB_CALENDAR_PAGE_PARAM . '=%#%',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&lsaquo; ' . __('Previous', 'lrob-calendar'),
                'next_text' => __('Next', 'lrob-calendar') . ' &rsaquo;',
            ]);
        endif; ?>
    </nav>
<?php endif; ?>
<?php if ($needs_popup && !empty($events)): ?>
    <div class="lrob-cal-popup lrob-events-list-popup"
         role="dialog"
         aria-modal="true"
         aria-hidden="true"
         style="display: none;"></div>
<?php endif; ?>
<?php echo LRob_Calendar_Block_Helpers::render_credit(); ?>
</div>
