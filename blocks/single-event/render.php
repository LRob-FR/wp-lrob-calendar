<?php
/**
 * Server-side render for the Single Event block.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if (!defined('ABSPATH')) {
    exit;
}

$post_id = (int) ($attributes['eventId'] ?? 0);

if (!$post_id) {
    echo '<p class="lrob-no-events">' . esc_html__('No event selected.', 'lrob-calendar') . '</p>';
    return;
}

$event = new LRob_Calendar_Event($post_id);
if (!$event->get_post()) {
    echo '<p class="lrob-no-events">' . esc_html__('Event not found.', 'lrob-calendar') . '</p>';
    return;
}

echo LRob_Calendar_Block_Helpers::render_event_card($event, [
    'template'        => $attributes['template'],
    'showImages'      => true,
    'showExcerpt'     => true,
    'showCategories'  => true,
    'imageDisplay'    => $attributes['imageDisplay']    ?? 'contain',
    'imageHeight'     => $attributes['imageHeight']     ?? 'medium',
    'locationDisplay' => $attributes['locationDisplay'] ?? 'full',
    'contactDisplay'  => $attributes['contactDisplay']  ?? 'full',
]);
echo LRob_Calendar_Block_Helpers::render_credit();
