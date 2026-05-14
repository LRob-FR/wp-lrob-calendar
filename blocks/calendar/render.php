<?php
/**
 * Server-side render for the Calendar block.
 *
 * Outputs only the calendar shell + config. Events are fetched by view.js
 * via the REST endpoint on init (and again on month navigation as needed).
 * Keeps the rendered HTML tiny and cache-friendly — the page can be HTML-cached
 * regardless of event data.
 *
 * @var array    $attributes Block attributes as defined in block.json.
 * @var string   $content    Inner content (empty — this block is dynamic).
 * @var WP_Block $block      Block instance.
 */

if (!defined('ABSPATH')) {
    exit;
}

$calendar_id = 'lrob-calendar-' . uniqid();
$link_text   = $attributes['linkText'] ?: __('View event', 'lrob-calendar');

$wrapper_classes = ['wp-block-lrob-calendar-calendar'];
if (!empty($attributes['align'])) {
    $wrapper_classes[] = 'align' . $attributes['align'];
}

$config = [
    'category'            => $attributes['category'] ?: 0,
    'tag'                 => $attributes['tag'] ?: 0,
    // loadedStart=0 / loadedEnd=0 signals view.js to do an initial REST fetch.
    'loadedStart'         => 0,
    'loadedEnd'           => 0,
    'popupSize'           => in_array($attributes['popupSize'] ?? 'standard', ['compact', 'standard', 'spacious'], true) ? $attributes['popupSize'] : 'standard',
    'popupImageDisplay'   => in_array($attributes['popupImageDisplay'] ?? 'contain', ['contain', 'cover'], true) ? $attributes['popupImageDisplay'] : 'contain',
    'popupImageHeight'    => in_array($attributes['popupImageHeight'] ?? 'medium', ['small', 'medium', 'large'], true) ? $attributes['popupImageHeight'] : 'medium',
    'popupShowImage'      => !empty($attributes['popupShowImage']),
    'popupImageLightbox'  => !isset($attributes['popupImageLightbox']) || !empty($attributes['popupImageLightbox']),
];
?>
<div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>">
    <div id="<?php echo esc_attr($calendar_id); ?>"
         class="lrob-calendar"
         data-view="<?php echo esc_attr($attributes['view']); ?>"
         data-link-text="<?php echo esc_attr($link_text); ?>"
         data-config="<?php echo esc_attr(wp_json_encode($config)); ?>"></div>
</div>
