<?php
/**
 * Single-event page rendering.
 *
 * Injects event metadata (date, location, cost, contact) above the post content
 * on single lrob_event pages. Theme-agnostic — works with any theme's single.php
 * fallback. Themes can still override with a custom `single-lrob_event.php`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Single_Event {

    public function __construct() {
        // If event pages are disabled site-wide, single-event templates are
        // unreachable — no need to register the filter.
        if (LRob_Calendar::public_pages_enabled()) {
            add_filter('the_content', [$this, 'inject_event_meta']);
        }
    }

    public function inject_event_meta(string $content): string {
        if (!is_singular(LRob_Calendar_Post_Types::POST_TYPE) || !is_main_query() || !in_the_loop()) {
            return $content;
        }

        $event = new LRob_Calendar_Event(get_the_ID());
        if (!$event->get_post()) {
            return $content;
        }

        return $this->render_meta($event) . $content;
    }

    private function render_meta(LRob_Calendar_Event $event): string {
        ob_start();
        ?>
        <div class="lrob-event-single">
            <div class="lrob-event-single-grid">
                <?php $this->render_when($event); ?>
                <?php $this->render_where($event); ?>
                <?php $this->render_cost($event); ?>
                <?php $this->render_contact($event); ?>
            </div>
            <?php $this->render_map($event); ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_when(LRob_Calendar_Event $event): void {
        ?>
        <div class="lrob-event-block lrob-event-when">
            <h2 class="lrob-event-block-title">
                <?php echo LRob_Calendar_Icons::get('calendar'); ?>
                <?php esc_html_e('When', 'lrob-calendar'); ?>
            </h2>
            <p class="lrob-event-when-main"><?php echo esc_html($event->format_when()); ?></p>
            <?php if ($event->is_recurring()) : ?>
                <p class="lrob-event-recurring-note">
                    <?php echo LRob_Calendar_Icons::get('recurring'); ?>
                    <?php esc_html_e('This event recurs.', 'lrob-calendar'); ?>
                </p>
                <?php $this->render_upcoming_instances($event); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_upcoming_instances(LRob_Calendar_Event $event): void {
        $instances = $event->get_instances(5);
        if (empty($instances)) {
            return;
        }

        $now = time();
        $upcoming = array_filter($instances, fn($i) => (int) $i['start'] >= $now);
        $upcoming = array_slice($upcoming, 0, 3);
        if (empty($upcoming)) {
            return;
        }

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        ?>
        <ul class="lrob-event-next-dates">
            <?php foreach ($upcoming as $i) :
                $ts = (int) $i['start'];
                ?>
                <li><?php
                    echo esc_html(
                        $event->is_allday()
                            ? wp_date($date_format, $ts)
                            : wp_date($date_format . ' ' . $time_format, $ts)
                    );
                ?></li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    private function render_where(LRob_Calendar_Event $event): void {
        if (!$event->has_location()) {
            return;
        }
        ?>
        <div class="lrob-event-block lrob-event-where">
            <h2 class="lrob-event-block-title">
                <?php echo LRob_Calendar_Icons::get('location'); ?>
                <?php esc_html_e('Where', 'lrob-calendar'); ?>
            </h2>
            <?php if ($venue = $event->get('venue')) : ?>
                <p class="lrob-event-venue"><?php echo esc_html($venue); ?></p>
            <?php endif; ?>
            <?php
            $address_parts = array_filter([
                $event->get('address'),
                trim(($event->get('postal_code') ?: '') . ' ' . ($event->get('city') ?: '')),
                $event->get('province'),
                $event->get('country'),
            ]);
            if (!empty($address_parts)) :
                ?>
                <address class="lrob-event-address">
                    <?php echo nl2br(esc_html(implode("\n", $address_parts))); ?>
                </address>
            <?php endif; ?>
            <?php if ($event->get('show_coordinates') && $event->get('latitude') && $event->get('longitude')) : ?>
                <p class="lrob-event-coords">
                    <?php echo esc_html($event->get('latitude') . ', ' . $event->get('longitude')); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_cost(LRob_Calendar_Event $event): void {
        $is_free   = $event->is_free();
        $cost      = $event->get('cost');
        $ticket    = $event->get('ticket_url');

        if (!$is_free && !$cost && !$ticket) {
            return;
        }
        ?>
        <div class="lrob-event-block lrob-event-cost">
            <h2 class="lrob-event-block-title">
                <?php echo LRob_Calendar_Icons::get('ticket'); ?>
                <?php esc_html_e('Cost', 'lrob-calendar'); ?>
            </h2>
            <p class="lrob-event-cost-value">
                <?php
                if ($is_free) {
                    esc_html_e('Free', 'lrob-calendar');
                } elseif ($cost) {
                    echo esc_html($cost);
                }
                ?>
            </p>
            <?php if ($ticket) : ?>
                <p>
                    <a href="<?php echo esc_url($ticket); ?>" class="lrob-event-ticket-link" rel="noopener">
                        <?php esc_html_e('Get tickets', 'lrob-calendar'); ?> &rarr;
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_contact(LRob_Calendar_Event $event): void {
        $name  = $event->get('contact_name');
        $email = $event->get('contact_email');
        $phone = $event->get('contact_phone');
        $url   = $event->get('contact_url');

        if (!$name && !$email && !$phone && !$url) {
            return;
        }
        ?>
        <div class="lrob-event-block lrob-event-contact">
            <h2 class="lrob-event-block-title">
                <?php echo LRob_Calendar_Icons::get('person'); ?>
                <?php esc_html_e('Contact', 'lrob-calendar'); ?>
            </h2>
            <?php if ($name) : ?>
                <p class="lrob-event-contact-name"><?php echo esc_html($name); ?></p>
            <?php endif; ?>
            <?php if ($email) : ?>
                <p>
                    <?php echo LRob_Calendar_Icons::get('email'); ?>
                    <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
                </p>
            <?php endif; ?>
            <?php if ($phone) : ?>
                <p>
                    <?php echo LRob_Calendar_Icons::get('phone'); ?>
                    <a href="tel:<?php echo esc_attr(preg_replace('/[^+\d]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a>
                </p>
            <?php endif; ?>
            <?php if ($url) : ?>
                <p>
                    <?php echo LRob_Calendar_Icons::get('link'); ?>
                    <a href="<?php echo esc_url($url); ?>" rel="noopener"><?php echo esc_html($url); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render an OpenStreetMap embed when show_map is enabled and coordinates are present.
     * No API key required.
     */
    private function render_map(LRob_Calendar_Event $event): void {
        if (!$event->get('show_map')) {
            return;
        }
        $lat = $event->get('latitude');
        $lon = $event->get('longitude');
        if (!$lat || !$lon) {
            return;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;
        $bbox = sprintf('%F,%F,%F,%F', $lon - 0.01, $lat - 0.006, $lon + 0.01, $lat + 0.006);
        $src  = sprintf(
            'https://www.openstreetmap.org/export/embed.html?bbox=%s&layer=mapnik&marker=%F,%F',
            $bbox,
            $lat,
            $lon
        );
        $link = sprintf('https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=16/%F/%F', $lat, $lon, $lat, $lon);
        ?>
        <div class="lrob-event-map">
            <iframe
                src="<?php echo esc_url($src); ?>"
                width="100%"
                height="320"
                style="border:0"
                loading="lazy"
                title="<?php esc_attr_e('Event location map', 'lrob-calendar'); ?>"></iframe>
            <p class="lrob-event-map-link">
                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('View larger map', 'lrob-calendar'); ?> &rarr;
                </a>
            </p>
        </div>
        <?php
    }
}
