<?php
/**
 * Inline SVG icons used across event renderers (cards, single-event page, popup).
 *
 * All icons share the same class hooks (`lrob-icon`) and viewBox so they can be
 * sized via CSS (currentColor for fill). One source of truth — adding a new
 * pictogram or changing a glyph means editing one place.
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
            '<svg class="%s" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr($class),
            $path
        );
    }

    private static function svg_calendar(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/>');
    }

    private static function svg_clock(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm.5-13H11v6l5.2 3.1.8-1.3-4.5-2.7z"/>');
    }

    private static function svg_location(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>');
    }

    private static function svg_recurring(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M12 6v3l4-4-4-4v3c-4.42 0-8 3.58-8 8 0 1.57.46 3.03 1.24 4.26L6.7 14.8c-.45-.83-.7-1.79-.7-2.8 0-3.31 2.69-6 6-6zm6.76 1.74L17.3 9.2c.44.84.7 1.79.7 2.8 0 3.31-2.69 6-6 6v-3l-4 4 4 4v-3c4.42 0 8-3.58 8-8 0-1.57-.46-3.03-1.24-4.26z"/>');
    }

    private static function svg_ticket(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M22 10V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2 2 2 0 0 1-2 2v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a2 2 0 0 1-2-2 2 2 0 0 1 2-2zm-2-1.46a4 4 0 0 0 0 6.92V18H4v-2.54a4 4 0 0 0 0-6.92V6h16z"/>');
    }

    private static function svg_person(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0-8a3 3 0 1 1-3 3 3 3 0 0 1 3-3zm0 10c-3.34 0-10 1.67-10 5v3h20v-3c0-3.33-6.66-5-10-5zm8 6H4v-1c0-.81 3.83-3 8-3s8 2.19 8 3z"/>');
    }

    private static function svg_email(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/>');
    }

    private static function svg_phone(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24 11.4 11.4 0 0 0 3.6.58 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .58 3.6 1 1 0 0 1-.24 1z"/>');
    }

    private static function svg_link(string $cls): string {
        return self::wrap($cls, '<path fill="currentColor" d="M3.9 12a3.1 3.1 0 0 1 3.1-3.1h4V7H7a5 5 0 1 0 0 10h4v-1.9H7A3.1 3.1 0 0 1 3.9 12zM8 13h8v-2H8zm9-6h-4v1.9h4a3.1 3.1 0 0 1 0 6.2h-4V17h4a5 5 0 0 0 0-10z"/>');
    }
}
