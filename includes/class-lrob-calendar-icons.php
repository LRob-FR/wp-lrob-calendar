<?php
/**
 * Inline SVG icons used across event renderers (cards, single-event page, popup).
 *
 * All icons share the same class hooks (`lrob-icon`) and viewBox so they can be
 * sized via CSS (currentColor for stroke). One source of truth — adding a new
 * pictogram or changing a glyph means editing one place.
 *
 * Style: Lucide-flavoured stroke icons. No fill, currentColor stroke,
 * stroke-width 2, round caps and joins. Renders at 18×18 by default; CSS may
 * override the size via .lrob-icon { width / height }.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LRob_Calendar_Icons {

    /**
     * Render an icon by key. Returns raw SVG markup safe for direct echo.
     * Unknown keys return an empty string (silent — never break rendering).
     */
    public static function get(string $key, string $extra_class = ''): string {
        $method = 'svg_' . str_replace('-', '_', $key);
        if (!method_exists(self::class, $method)) {
            return '';
        }
        $cls = trim('lrob-icon lrob-icon-' . $key . ' ' . $extra_class);
        return self::$method($cls);
    }

    private static function wrap(string $class, string $path): string {
        return sprintf(
            '<svg class="%s" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr($class),
            $path
        );
    }

    private static function svg_calendar(string $cls): string {
        // Two-page calendar with hook bindings on top.
        return self::wrap($cls, '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>');
    }

    private static function svg_clock(string $cls): string {
        return self::wrap($cls, '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>');
    }

    private static function svg_location(string $cls): string {
        // Pin with circle.
        return self::wrap($cls, '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>');
    }

    private static function svg_recurring(string $cls): string {
        // Two arrows in a loop.
        return self::wrap($cls, '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>');
    }

    private static function svg_ticket(string $cls): string {
        return self::wrap($cls, '<path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M13 5v2M13 11v2M13 17v2"/>');
    }

    private static function svg_person(string $cls): string {
        return self::wrap($cls, '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>');
    }

    private static function svg_email(string $cls): string {
        return self::wrap($cls, '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>');
    }

    private static function svg_phone(string $cls): string {
        return self::wrap($cls, '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>');
    }

    private static function svg_link(string $cls): string {
        return self::wrap($cls, '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/>');
    }

    private static function svg_chevron_left(string $cls): string {
        return self::wrap($cls, '<path d="m15 18-6-6 6-6"/>');
    }

    private static function svg_chevron_right(string $cls): string {
        return self::wrap($cls, '<path d="m9 18 6-6-6-6"/>');
    }

    private static function svg_x(string $cls): string {
        return self::wrap($cls, '<path d="M18 6 6 18M6 6l12 12"/>');
    }

    private static function svg_arrow_right(string $cls): string {
        return self::wrap($cls, '<path d="M5 12h14M12 5l7 7-7 7"/>');
    }

    private static function svg_info(string $cls): string {
        return self::wrap($cls, '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>');
    }
}
