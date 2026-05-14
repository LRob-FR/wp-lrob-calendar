/**
 * LRob Calendar - Admin JS
 */
(function($) {
    'use strict';

    $(function() {
        // Initialize WordPress color picker
        $('.lrob-color-picker').wpColorPicker();

        // Event type radio drives both: hiding the time inputs (all-day) and
        // hiding the End row (instant). Single source of truth — mutual exclusivity
        // is enforced by the radio itself.
        function syncEventType() {
            var val = $('input[name="lrob_event_type"]:checked').val() || 'standard';
            $('.lrob-time-input').toggle(val !== 'allday');
            $('.lrob-end-row').toggle(val !== 'instant');
            updateDatePreviews();
        }
        $('input[name="lrob_event_type"]').on('change', syncEventType);
        syncEventType();

        // Live preview of how the date will render on the site (in the site's
        // locale, regardless of the user's OS locale used by the native pickers).
        function updateDatePreviews() {
            updateOnePreview('lrob_start_date', 'lrob_start_time');
            updateOnePreview('lrob_end_date', 'lrob_end_time');
        }

        function updateOnePreview(dateId, timeId) {
            if (typeof wp === 'undefined' || !wp.date || !wp.date.dateI18n) return;

            var $date = $('#' + dateId);
            var $time = $('#' + timeId);
            var $preview = $date.siblings('.lrob-date-formatted').first();
            if (!$date.length || !$preview.length) return;

            var dateVal = $date.val();
            if (!dateVal) return;

            var allDay = $('input[name="lrob_event_type"]:checked').val() === 'allday';
            var timeVal = allDay ? '00:00' : ($time.val() || '00:00');

            // Construct a local Date object — wp.date.dateI18n then formats it
            // using the site's date_format/time_format and locale.
            var parts = dateVal.split('-');
            var timeParts = timeVal.split(':');
            if (parts.length !== 3 || timeParts.length < 2) return;

            var d = new Date(
                parseInt(parts[0], 10),
                parseInt(parts[1], 10) - 1,
                parseInt(parts[2], 10),
                parseInt(timeParts[0], 10),
                parseInt(timeParts[1], 10)
            );
            if (isNaN(d.getTime())) return;

            var fmt = allDay
                ? lrobCalendarAdmin.dateFormat
                : lrobCalendarAdmin.dateFormat + ' ' + lrobCalendarAdmin.timeFormat;
            $preview.text(wp.date.dateI18n(fmt, d));
        }

        $('#lrob_start_date, #lrob_start_time, #lrob_end_date, #lrob_end_time').on('change input', updateDatePreviews);

        // Recurrence UI
        $('#lrob_repeat').on('change', function() {
            var val = $(this).val();
            $('.lrob-recurrence-options').toggle(val !== '');
            $('.lrob-weekly-options').toggle(val === 'WEEKLY');
        }).trigger('change');

        $('#lrob_repeat_end').on('change', function() {
            var val = $(this).val();
            $('#lrob_count').toggle(val === 'count');
            $('#lrob_until').toggle(val === 'until');
        }).trigger('change');

        // Free event disables (but does not clear) the cost field.
        // Triggered on load so an already-Free event opens with the field correctly muted.
        $('#lrob_is_free').on('change', function() {
            $('#lrob_cost').prop('disabled', this.checked);
        }).trigger('change');

        // Map options require coordinates. Disable "Show map" / "Show coordinates"
        // until both lat and lng have values; sync live as the user types.
        function syncMapOptions() {
            var hasCoords = $.trim($('#lrob_latitude').val()) !== '' && $.trim($('#lrob_longitude').val()) !== '';
            $('input[name="lrob_show_map"], input[name="lrob_show_coordinates"]').prop('disabled', !hasCoords);
        }
        $('#lrob_latitude, #lrob_longitude').on('input change', syncMapOptions);
        syncMapOptions();

        // Media library integration
        $(document).on('click', '.lrob-select-image', function(e) {
            e.preventDefault();
            var $button = $(this);
            var targetInput = $button.data('target');
            var previewDiv = $button.data('preview');

            var frame = wp.media({
                title: lrobCalendarAdmin.selectImageTitle || 'Select Image',
                button: { text: lrobCalendarAdmin.useImageText || 'Use this image' },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#' + targetInput).val(attachment.id);
                var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                $('#' + previewDiv).html('<img src="' + url + '" style="max-width:150px;height:auto;">');
                $button.siblings('.lrob-remove-image').show();
            });

            frame.open();
        });

        $(document).on('click', '.lrob-remove-image', function(e) {
            e.preventDefault();
            var $button = $(this);
            $('#' + $button.data('target')).val('');
            $('#' + $button.data('preview')).empty();
            $button.hide();
        });
    });

})(jQuery);
