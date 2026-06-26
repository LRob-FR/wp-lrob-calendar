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

// The list always fills with upcoming events (recurring expanded to their next
// dates). Past events are an OPTIONAL, separately-capped addition so they can
// never crowd out the upcoming ones — controlled by the "Show past events"
// toggle and its count.
$today         = (new DateTimeImmutable('today', wp_timezone()))->getTimestamp();
$show_past     = !empty($attributes['showPast']);
$past_limit    = $show_past ? max(0, (int) ($attributes['pastLimit'] ?? 1)) : 0;
$max_per_event = max(1, (int) apply_filters('lrob_calendar_events_list_max_per_event', 3));
// Order applies to the merged list: chronological (ASC) by default; 'desc' flips.
$order_attr      = strtolower((string) ($attributes['order'] ?? 'auto'));
$effective_order = ($order_attr === 'desc') ? 'DESC' : 'ASC';

$common = [];
if ($attributes['category']) {
    $common['category'] = (int) $attributes['category'];
}
if ($attributes['tag']) {
    $common['tag'] = (int) $attributes['tag'];
}

// Upcoming query (paginated). Cutoff is local midnight so events still running
// today count as upcoming.
$up_args = array_merge($common, ['start' => $today, 'order' => 'ASC']);
if ($pagination) {
    $current_page = isset($_GET[LROB_CALENDAR_PAGE_PARAM]) ? max(1, (int) $_GET[LROB_CALENDAR_PAGE_PARAM]) : 1;
    $total        = LRob_Calendar_Event::count_events($up_args);
    $total_pages  = max(1, (int) ceil($total / $per_page));
    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }
    $up_args['limit']  = $per_page;
    $up_args['offset'] = ($current_page - 1) * $per_page;
} else {
    $current_page    = 1;
    $up_args['limit'] = $per_page;
}

$list_items = [];
foreach (LRob_Calendar_Event::get_events($up_args) as $event) {
    if ($event->is_recurring()) {
        $occurrences = $event->get_instances_in_range($today, null, $max_per_event);
        if (!empty($occurrences)) {
            foreach ($occurrences as $occ) {
                $list_items[] = ['event' => $event, 'start' => (int) $occ['start'], 'end' => (int) $occ['end']];
            }
            continue;
        }
    }
    $list_items[] = ['event' => $event, 'start' => (int) $event->get('start'), 'end' => (int) $event->get('end')];
}

// Most-recent past events — extra rows that never displace the upcoming set.
// First page only (they belong at the top of the list).
if ($past_limit > 0 && $current_page === 1) {
    $past_args = array_merge($common, ['end' => $today - 1, 'order' => 'DESC', 'limit' => $past_limit * 3 + 5]);
    $past_items = [];
    foreach (LRob_Calendar_Event::get_events($past_args) as $event) {
        // Recurring events are covered by the upcoming set; keep only truly ended.
        if ($event->is_recurring() || (int) $event->get('end') >= $today) {
            continue;
        }
        $past_items[] = ['event' => $event, 'start' => (int) $event->get('start'), 'end' => (int) $event->get('end')];
        if (count($past_items) >= $past_limit) {
            break;
        }
    }
    $list_items = array_merge($past_items, $list_items);
}

usort($list_items, static function ($a, $b) use ($effective_order) {
    return $effective_order === 'DESC' ? ($b['start'] <=> $a['start']) : ($a['start'] <=> $b['start']);
});

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

if ($pagination && !empty($list_items) && $total_pages > 1):
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
<?php if ($needs_popup && !empty($list_items)): ?>
    <div class="lrob-cal-popup lrob-events-list-popup"
         role="dialog"
         aria-modal="true"
         aria-hidden="true"
         style="display: none;"></div>
<?php endif; ?>
<?php echo LRob_Calendar_Block_Helpers::render_credit(); ?>
</div>
