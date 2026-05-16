<?php
/**
 * Demo events generator — fills the calendar with a handful of realistic
 * sample events spread across the current + upcoming month. Useful for
 * site previews, "what does this look like?" client demos, screenshots.
 *
 * Every string goes through gettext (`__()`) so the demo events render in
 * the site's locale, English by default, French (or any other shipped
 * locale) when available. The descriptions all include a clear disclaimer
 * that the event is a demo and a link to lrob.fr; titles stay clean (no
 * "[DEMO]" prefix) so previews don't look ugly.
 *
 * Triggered from the Settings page (a button + handler in admin.php).
 * Warns if the calendar already has events, requires an explicit
 * confirmation checkbox to proceed in that case.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Demo_Events {

    /**
     * Insert all demo events. Returns the number actually created.
     * Caller is responsible for the existing-events-warning UX (this
     * method does NOT check).
     */
    public static function insert_all(): int {
        $created = 0;
        foreach (self::definitions() as $spec) {
            if (self::insert_one($spec)) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * How many lrob_event posts already exist on this site (any status,
     * minus auto-drafts). Used to gate the destructive-feeling confirmation.
     */
    public static function existing_count(): int {
        $counts = wp_count_posts(LRob_Calendar_Post_Types::POST_TYPE);
        if (!$counts) return 0;
        $total = 0;
        foreach ((array) $counts as $status => $n) {
            if ($status === 'auto-draft' || $status === 'trash') continue;
            $total += (int) $n;
        }
        return $total;
    }

    /**
     * Build the spec array. Dates are computed relative to "now" so the
     * demo events always land in the current + upcoming month.
     */
    private static function definitions(): array {
        $tz       = wp_timezone();
        $now      = new DateTimeImmutable('now', $tz);
        $tomorrow = $now->modify('+1 day')->setTime(0, 0);

        $next_wednesday = self::next_weekday($now, 3); // Wed
        $next_saturday  = self::next_weekday($now, 6);
        $two_weeks_out  = $now->modify('+14 days');
        $three_weeks    = $now->modify('+21 days');
        $end_of_month   = (int) $now->format('t');
        $current_year   = (int) $now->format('Y');
        $current_month  = (int) $now->format('n');

        // LRob HQ — Orléans, France. Used for in-person events; online events
        // use the dedicated $online block below.
        $hq = [
            'venue'       => __('LRob HQ',                    'lrob-calendar'),
            'address'     => __('14 rue Jeanne d\'Arc',       'lrob-calendar'),
            'city'        => __('Orléans',                    'lrob-calendar'),
            'postal_code' => '45000',
            'country'     => __('France',                     'lrob-calendar'),
        ];
        $university = [
            'venue'       => __('Université d\'Orléans — Château de la Source', 'lrob-calendar'),
            'address'     => __('Rue de Chartres',            'lrob-calendar'),
            'city'        => __('Orléans',                    'lrob-calendar'),
            'postal_code' => '45067',
            'country'     => __('France',                     'lrob-calendar'),
        ];
        $online = [
            'venue'   => __('Online', 'lrob-calendar'),
            'address' => '',
            'city'    => '',
            'postal_code' => '',
            'country' => '',
        ];
        $contact = [
            'name'  => __('LRob — WordPress hosting (Orléans)', 'lrob-calendar'),
            'email' => 'contact@lrob.fr',
            'phone' => '+33 2 38 00 00 00',
            'url'   => 'https://www.lrob.fr/',
        ];

        return [
            [
                'title'   => __('WordPress beginners workshop', 'lrob-calendar'),
                'desc'    => self::desc(__('Hands-on introduction to WordPress: installation, first steps with Gutenberg, picking a theme, managing media. Beginner-friendly, no technical background required. Held at LRob HQ in central Orléans.', 'lrob-calendar')),
                'start'   => $next_wednesday->setTime(14, 0)->getTimestamp(),
                'end'     => $next_wednesday->setTime(17, 0)->getTimestamp(),
                'allday'  => false,
                'loc'     => $hq,
                'contact' => $contact,
                'cost'    => '20 €',
            ],
            [
                'title'   => __('Webinar: green hosting & WordPress performance', 'lrob-calendar'),
                'desc'    => self::desc(__('How to choose an eco-responsible host without sacrificing WordPress performance. Tour of key indicators (PUE, certifications), live speed-test demos, real-world feedback from LRob\'s Orléans datacenter.', 'lrob-calendar')),
                'start'   => $two_weeks_out->setTime(19, 0)->getTimestamp(),
                'end'     => $two_weeks_out->setTime(20, 30)->getTimestamp(),
                'allday'  => false,
                'loc'     => $online,
                'contact' => $contact,
                'free'    => true,
                'ticket'  => 'https://www.lrob.fr/',
            ],
            [
                'title'   => __('WordCamp Orléans', 'lrob-calendar'),
                'desc'    => self::desc(__('Annual gathering of the Orléans-area WordPress community: talks, workshops, networking. Held over a weekend at the Université d\'Orléans. Full programme available online.', 'lrob-calendar')),
                'start'   => $next_saturday->setTime(9, 0)->getTimestamp(),
                'end'     => $next_saturday->modify('+1 day')->setTime(18, 0)->getTimestamp(),
                'allday'  => false,
                'loc'     => $university,
                'contact' => $contact,
                'cost'    => __('Two-day pass: 25 €', 'lrob-calendar'),
                'ticket'  => 'https://www.lrob.fr/',
            ],
            [
                'title'   => __('Hosting support office hours', 'lrob-calendar'),
                'desc'    => self::desc(__('Weekly office hours for LRob hosting customers: technical questions, optimisation tips, troubleshooting. By appointment, on-site in Orléans or remote.', 'lrob-calendar')),
                'start'   => $tomorrow->modify('+2 days')->setTime(10, 0)->getTimestamp(),
                'end'     => $tomorrow->modify('+2 days')->setTime(12, 0)->getTimestamp(),
                'allday'  => false,
                'loc'     => $hq,
                'contact' => $contact,
                'recur'   => 'FREQ=WEEKLY;COUNT=8',
            ],
            [
                'title'   => __('New hosting infrastructure launch', 'lrob-calendar'),
                'desc'    => self::desc(__('Go-live of LRob\'s new hosting infrastructure — faster, greener, hosted in Orléans. Public announcement on social channels at the same time.', 'lrob-calendar')),
                'start'   => $three_weeks->setTime(11, 0)->getTimestamp(),
                'end'     => $three_weeks->setTime(11, 0)->getTimestamp(),
                'allday'  => false,
                'instant' => true,
                'loc'     => $online,
                'contact' => $contact,
            ],
            [
                'title'   => __('Scheduled server maintenance', 'lrob-calendar'),
                'desc'    => self::desc(__('Maintenance window for OS + kernel updates on the Orléans servers. Expected interruption: 5 to 15 minutes per site.', 'lrob-calendar')),
                'start'   => self::clamp_to_month($now->modify('+10 days'), $current_year, $current_month, $end_of_month)->setTime(4, 0)->getTimestamp(),
                'end'     => self::clamp_to_month($now->modify('+10 days'), $current_year, $current_month, $end_of_month)->setTime(6, 0)->getTimestamp(),
                'allday'  => false,
                'loc'     => $online,
                'contact' => $contact,
            ],
        ];
    }

    /**
     * Compose a description: the event-specific body, a blank line, then a
     * generic "this is a demo event" disclaimer with a link to lrob.fr.
     * Both halves go through gettext so French (or any other locale) renders
     * naturally end to end.
     */
    private static function desc(string $body): string {
        return $body
            . "\n\n"
            . __('This event is a sample inserted by the LRob Calendar plugin for demonstration purposes.', 'lrob-calendar')
            . ' '
            . __('Learn more about the plugin and WordPress hosting from Orléans:', 'lrob-calendar')
            . ' https://www.lrob.fr/';
    }

    /**
     * Next occurrence of a given weekday (0 = Sunday, ..., 6 = Saturday)
     * after $from. Returns a clone of $from set to 00:00 on that day.
     */
    private static function next_weekday(DateTimeImmutable $from, int $weekday): DateTimeImmutable {
        $weekday = max(0, min(6, $weekday));
        $cur     = (int) $from->format('w');
        $diff    = ($weekday - $cur + 7) % 7;
        if ($diff === 0) $diff = 7; // never "today" — always the NEXT one
        return $from->modify('+' . $diff . ' days')->setTime(0, 0);
    }

    /**
     * Keep a candidate datetime inside the current month — if it overflows,
     * snap to the last day of the current month. Used so the "maintenance"
     * demo lands within current-month even on short months.
     */
    private static function clamp_to_month(DateTimeImmutable $candidate, int $year, int $month, int $end_of_month): DateTimeImmutable {
        if ((int) $candidate->format('Y') === $year && (int) $candidate->format('n') === $month) {
            return $candidate;
        }
        return $candidate->setDate($year, $month, $end_of_month)->setTime(0, 0);
    }

    /**
     * Create one event from a spec. Returns true on success.
     */
    private static function insert_one(array $spec): bool {
        $post_id = wp_insert_post([
            'post_type'    => LRob_Calendar_Post_Types::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $spec['title'],
            'post_content' => $spec['desc'],
            'post_author'  => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }

        $event = new LRob_Calendar_Event($post_id);
        $event->set('start', (int) $spec['start']);
        $event->set('end',   (int) $spec['end']);
        $event->set('allday',        !empty($spec['allday'])  ? 1 : 0);
        $event->set('instant_event', !empty($spec['instant']) ? 1 : 0);
        $event->set('timezone', wp_timezone_string());

        // Location
        $loc = $spec['loc'] ?? [];
        foreach (['venue', 'address', 'city', 'postal_code', 'country'] as $k) {
            $event->set($k, $loc[$k] ?? '');
        }

        // Contact
        $c = $spec['contact'] ?? [];
        $event->set('contact_name',  $c['name']  ?? '');
        $event->set('contact_email', $c['email'] ?? '');
        $event->set('contact_phone', $c['phone'] ?? '');
        $event->set('contact_url',   $c['url']   ?? '');

        // Cost
        if (!empty($spec['free'])) {
            $event->set('is_free', 1);
        }
        if (!empty($spec['cost'])) {
            $event->set('cost', $spec['cost']);
        }
        if (!empty($spec['ticket'])) {
            $event->set('ticket_url', $spec['ticket']);
        }

        // Recurrence (string in RRULE form, e.g. FREQ=WEEKLY;COUNT=8)
        if (!empty($spec['recur'])) {
            $event->set('recurrence_rules', $spec['recur']);
        }

        return $event->save();
    }
}
