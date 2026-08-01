/**
 * Auto colour-mode resolution.
 *
 * Blocks rendered in "auto" mode carry `.lrob-theme-auto` and no palette. This
 * script measures the page background behind each one and adds `.lrob-is-dark`
 * or `.lrob-is-light`, which is what actually selects the palette in
 * tokens.css. Auto is a chooser between the plugin's two palettes — nothing
 * here derives colours from the theme.
 *
 * Why measure rather than use `prefers-color-scheme`: that reports what the
 * OPERATING SYSTEM prefers, which is the wrong question. A site that ships a
 * dark theme to a visitor on a light-mode machine would get the light palette
 * — a white calendar on a dark page. The page itself is the authority; the OS
 * preference is only consulted when nothing measurable is found.
 *
 * Re-runs when <html>/<body> class, style or data-theme changes, so a theme's
 * own light/dark switcher is picked up without a reload.
 */
(function () {
    'use strict';

    /** Parse a computed background-color into [r, g, b, a]; null if unusable. */
    function parseColor(value) {
        if (!value) {
            return null;
        }
        var m = value.match(/^rgba?\(([^)]+)\)$/);
        if (!m) {
            return null;
        }
        // Modern browsers may serialise as "rgb(1 2 3 / 0.5)" — accept both.
        var parts = m[1].replace(/\//g, ' ').split(/[\s,]+/).filter(function (p) {
            return p !== '';
        });
        if (parts.length < 3) {
            return null;
        }
        var alpha = 1;
        if (parts.length > 3) {
            alpha = parseFloat(parts[3]);
            if (parts[3].indexOf('%') !== -1) {
                alpha = alpha / 100;
            }
        }
        var rgba = [parseFloat(parts[0]), parseFloat(parts[1]), parseFloat(parts[2]), alpha];
        for (var i = 0; i < 4; i++) {
            if (isNaN(rgba[i])) {
                return null;
            }
        }
        return rgba;
    }

    /** WCAG relative luminance, 0 (black) → 1 (white). */
    function luminance(rgb) {
        var channels = [rgb[0], rgb[1], rgb[2]].map(function (c) {
            c = c / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    }

    /**
     * Luminance of what is painted behind `el`.
     *
     * Walks up compositing each ancestor background over the next, so a
     * half-transparent white panel on a dark page reads as the mid-tone it
     * actually renders as, not as white. Returns null if nothing opaque is
     * found (e.g. the background is an image or gradient we can't sample).
     */
    function backgroundLuminance(el) {
        var stack = [];
        var node = el;
        while (node && node.nodeType === 1) {
            var rgba = parseColor(window.getComputedStyle(node).backgroundColor);
            if (rgba && rgba[3] > 0) {
                stack.push(rgba);
                if (rgba[3] >= 1) {
                    break;      // fully opaque — nothing below it matters
                }
            }
            node = node.parentElement;
        }
        if (!stack.length || stack[stack.length - 1][3] < 1) {
            return null;
        }
        // Composite from the bottom (opaque) layer upwards.
        var base = stack.pop();
        while (stack.length) {
            var top = stack.pop();
            var a = top[3];
            base = [
                top[0] * a + base[0] * (1 - a),
                top[1] * a + base[1] * (1 - a),
                top[2] * a + base[2] * (1 - a),
                1
            ];
        }
        return luminance(base);
    }

    function applyTo(el) {
        var lum = backgroundLuminance(el);

        if (lum === null) {
            // Nothing measurable. The OS preference is a weaker signal than the
            // page, but it beats leaving the block unmarked on a dark theme.
            lum = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 0 : 1;
        }

        var isDark = lum < 0.4;
        el.classList.toggle('lrob-is-dark', isDark);
        el.classList.toggle('lrob-is-light', !isDark);
    }

    function detect() {
        var blocks = document.querySelectorAll('.lrob-theme-auto');
        for (var i = 0; i < blocks.length; i++) {
            applyTo(blocks[i]);
        }
    }

    // Exposed so a theme (or our own AJAX-swapped markup) can re-resolve.
    window.lrobCalendarDetectTheme = detect;

    function init() {
        detect();

        // Site-level light/dark switchers almost always toggle a class, a
        // data-theme attribute or an inline style on <html> or <body>.
        if (typeof MutationObserver === 'function') {
            var observer = new MutationObserver(function () {
                detect();
            });
            var options = { attributes: true, attributeFilter: ['class', 'style', 'data-theme'] };
            observer.observe(document.documentElement, options);
            if (document.body) {
                observer.observe(document.body, options);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
