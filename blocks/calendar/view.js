/**
 * LRob Calendar - Public JavaScript
 */
(function($) {
    'use strict';

    $(function() {
        $('.lrob-calendar').each(function() {
            new LRobCalendar(this);
        });
    });

    function LRobCalendar(container) {
        this.$container = $(container);
        this.linkText = this.$container.data('link-text') || 'View event';
        this.view = this.$container.data('view') || 'month';
        // currentView is the RUNTIME view (toggleable via the in-header
        // switcher). It starts at the block's configured view and can be
        // changed at runtime (month ↔ week). Agenda blocks keep currentView
        // === 'agenda' and never render the switcher.
        this.currentView = this.view;
        this.weekAnchor  = new Date();
        
        // Parse events from data attribute
        var eventsData = this.$container.data('events');
        this.events = eventsData || [];
        
        // Config for dynamic loading
        var config = this.$container.data('config') || {};
        this.category = config.category || 0;
        this.tag = config.tag || 0;
        this.loadedStart = config.loadedStart || 0;
        this.loadedEnd = config.loadedEnd || 0;
        this.loading = false;

        // Display options (from block.json attributes)
        this.popupSize = config.popupSize || 'standard';
        this.popupImageDisplay = config.popupImageDisplay || 'contain';
        this.popupImageHeight = config.popupImageHeight || 'medium';
        this.popupShowImage = config.popupShowImage !== false;
        this.popupImageLightbox = config.popupImageLightbox !== false;

        // Mirror as CSS classes on the container so styles can react.
        this.$container.addClass('lrob-cal-popup-size-' + this.popupSize);
        this.$container.addClass('lrob-cal-popup-img-' + this.popupImageDisplay);
        this.$container.addClass('lrob-cal-popup-imgh-' + this.popupImageHeight);
        
        this.currentDate = new Date();
        this.currentMonth = this.currentDate.getMonth();
        this.currentYear = this.currentDate.getFullYear();
        
        // Localized names from WordPress
        this.monthNames = (typeof lrobCalendar !== 'undefined' && lrobCalendar.monthNames) 
            ? lrobCalendar.monthNames 
            : ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        
        this.dayNames = (typeof lrobCalendar !== 'undefined' && lrobCalendar.dayNames)
            ? lrobCalendar.dayNames
            : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        // First day of week: 0 = Sunday … 6 = Saturday
        this.startOfWeek = (typeof lrobCalendar !== 'undefined' && typeof lrobCalendar.startOfWeek !== 'undefined')
            ? parseInt(lrobCalendar.startOfWeek, 10)
            : 1;

        // BCP-47 locale used for Intl.DateTimeFormat. Falls back to the browser locale.
        this.siteLocale = (typeof lrobCalendar !== 'undefined' && lrobCalendar.siteLocale)
            ? lrobCalendar.siteLocale
            : undefined;

        // When false, single-event pages don't exist — popup hides its links and CTA.
        this.publicPagesEnabled = (typeof lrobCalendar !== 'undefined' && typeof lrobCalendar.publicPagesEnabled !== 'undefined')
            ? !!lrobCalendar.publicPagesEnabled
            : true;

        // Translatable labels used by the popup / lightbox
        this.i18n = (typeof lrobCalendar !== 'undefined' && lrobCalendar.i18n) ? lrobCalendar.i18n : {};
        // Pre-rendered SVG icons (raw markup, sourced from LRob_Calendar_Icons)
        this.icons = (typeof lrobCalendar !== 'undefined' && lrobCalendar.icons) ? lrobCalendar.icons : {};

        // REST API URL
        this.apiUrl = (typeof lrobCalendar !== 'undefined' && lrobCalendar.restUrl)
            ? lrobCalendar.restUrl
            : '/wp-json/lrob-calendar/v1/events';
        
        this.init();
    }

    LRobCalendar.prototype.init = function() {
        this.render();
        this.bindEvents();

        // The calendar block no longer inlines events in HTML — render.php only
        // ships the shell + config, and view.js fetches via REST on init.
        // (loadedEnd === 0 means nothing inlined yet.)
        if (!this.loadedEnd) {
            this.fetchInitialEvents();
        }
    };

    LRobCalendar.prototype.fetchInitialEvents = function() {
        var now = new Date();
        // Same ±2 months range the old inlined version used.
        var loadStart = Math.floor(new Date(now.getFullYear(), now.getMonth() - 2, 1).getTime() / 1000);
        var loadEnd   = Math.floor(new Date(now.getFullYear(), now.getMonth() + 3, 0, 23, 59, 59).getTime() / 1000);
        this.loadEvents(loadStart, loadEnd);
    };

    LRobCalendar.prototype.bindEvents = function() {
        var self = this;
        
        this.$container.on('click', '.lrob-cal-prev', function(e) {
            e.preventDefault();
            if (self.loading) return;
            if (self.currentView === 'week') {
                self.weekAnchor = new Date(self.weekAnchor.getFullYear(), self.weekAnchor.getMonth(), self.weekAnchor.getDate() - 7);
                self.currentMonth = self.weekAnchor.getMonth();
                self.currentYear  = self.weekAnchor.getFullYear();
            } else {
                self.currentMonth--;
                if (self.currentMonth < 0) {
                    self.currentMonth = 11;
                    self.currentYear--;
                }
            }
            self.checkAndLoadEvents();
        });

        this.$container.on('click', '.lrob-cal-next', function(e) {
            e.preventDefault();
            if (self.loading) return;
            if (self.currentView === 'week') {
                self.weekAnchor = new Date(self.weekAnchor.getFullYear(), self.weekAnchor.getMonth(), self.weekAnchor.getDate() + 7);
                self.currentMonth = self.weekAnchor.getMonth();
                self.currentYear  = self.weekAnchor.getFullYear();
            } else {
                self.currentMonth++;
                if (self.currentMonth > 11) {
                    self.currentMonth = 0;
                    self.currentYear++;
                }
            }
            self.checkAndLoadEvents();
        });

        // "Today" jumps the calendar to the current month/week.
        this.$container.on('click', '.lrob-cal-today', function(e) {
            e.preventDefault();
            var now = new Date();
            self.currentMonth = now.getMonth();
            self.currentYear  = now.getFullYear();
            self.weekAnchor   = now;
            self.checkAndLoadEvents();
        });

        // View switcher (Month / Week pill).
        this.$container.on('click', '.lrob-cal-view-btn', function(e) {
            e.preventDefault();
            var newView = $(this).attr('data-view');
            if (!newView || newView === self.currentView) return;
            self.currentView = newView;
            if (newView === 'week') {
                // Anchor the week to the currently-displayed month so the
                // user doesn't lose context.
                self.weekAnchor = new Date(self.currentYear, self.currentMonth, 1);
            }
            self.render();
        });

        // "+N more" pill — opens the day-list popup for the given date.
        this.$container.on('click', '.lrob-cal-more', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var dateStr = $(this).attr('data-date');
            if (dateStr) self.showDayList(dateStr, $(this).closest('td'));
        });
        
        // Event-item click. On mobile, events render as tiny dots so the
        // useful interaction is "open the list of events for this day";
        // on desktop the user can read the title in the cell, so tapping
        // it should jump straight to that event's full card.
        this.$container.on('click', '.lrob-cal-event-item', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (self.isMobileViewport()) {
                var $cell = $(this).closest('td');
                var dateStr = $cell.attr('data-date');
                if (dateStr) {
                    self.showDayList(dateStr, $cell.length ? $cell : self.$container);
                    return;
                }
            }
            var eventId = $(this).data('event-id');
            self.showPopup(eventId, $(this), e);
        });

        // Mobile-only: tapping the cell background (any area within a day
        // cell that has events) also opens the day-list. Skips empty cells.
        this.$container.on('click', '.lrob-cal-table td.lrob-cal-has-events', function(e) {
            if (!self.isMobileViewport()) return;
            var dateStr = $(this).attr('data-date');
            if (dateStr) self.showDayList(dateStr, $(this));
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.lrob-cal-popup, .lrob-cal-event-item, .lrob-cal-table td').length) {
                self.hidePopup();
            }
        });
        
        $(document).on('keyup', function(e) {
            if (e.key === 'Escape') self.hidePopup();
        });
    };

    LRobCalendar.prototype.checkAndLoadEvents = function() {
        var self = this;
        
        // Calculate timestamps for the current view month
        var viewStart = new Date(this.currentYear, this.currentMonth, 1).getTime() / 1000;
        var viewEnd = new Date(this.currentYear, this.currentMonth + 1, 0, 23, 59, 59).getTime() / 1000;
        
        // Check if we need to load more events
        if (viewStart < this.loadedStart || viewEnd > this.loadedEnd) {
            // Determine the range to load (6 months around the current view)
            var loadStart = new Date(this.currentYear, this.currentMonth - 3, 1).getTime() / 1000;
            var loadEnd = new Date(this.currentYear, this.currentMonth + 4, 0, 23, 59, 59).getTime() / 1000;
            
            this.loadEvents(loadStart, loadEnd);
        } else {
            this.render();
        }
    };

    LRobCalendar.prototype.loadEvents = function(rangeStart, rangeEnd) {
        var self = this;
        
        if (this.loading) return;
        this.loading = true;
        
        // Show loading state
        this.$container.find('.lrob-cal-table').css('opacity', '0.5');
        
        var url = this.apiUrl + '?range_start=' + Math.floor(rangeStart) + '&range_end=' + Math.floor(rangeEnd) + '&limit=500&include_past=1';
        if (this.category) url += '&category=' + this.category;
        if (this.tag) url += '&tag=' + this.tag;
        
        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                // Merge new events with existing ones (avoid duplicates)
                var existingIds = self.events.map(function(e) { return e.id; });
                
                data.forEach(function(event) {
                    if (existingIds.indexOf(event.id) === -1) {
                        self.events.push(event);
                    }
                });
                
                // Update loaded range
                if (rangeStart < self.loadedStart || self.loadedStart === 0) {
                    self.loadedStart = rangeStart;
                }
                if (rangeEnd > self.loadedEnd) {
                    self.loadedEnd = rangeEnd;
                }
                
                self.loading = false;
                self.render();
            },
            error: function() {
                self.loading = false;
                self.$container.find('.lrob-cal-table').css('opacity', '1');
            }
        });
    };

    LRobCalendar.prototype.render = function() {
        this.ensureShell();
        // Agenda is a block-level mode (no switcher). Month + Week can both
        // be reached at runtime via the in-header pill control; currentView
        // tracks which one is active.
        if (this.view === 'agenda') {
            this.renderAgenda();
        } else if (this.currentView === 'week') {
            this.renderWeek();
        } else {
            this.renderMonth();
        }
        this.consumePendingPopup();
    };

    /**
     * Make sure the calendar container holds two stable children:
     *   - .lrob-cal-grid (replaced on each render — month grid or agenda list)
     *   - .lrob-cal-popup (created once, persists; survives renders so navigating
     *     across months doesn't destroy an open popup)
     */
    LRobCalendar.prototype.ensureShell = function() {
        if (this.$container.find('.lrob-cal-grid').length === 0) {
            this.$container.html(
                '<div class="lrob-cal-grid"></div>' +
                '<div class="lrob-cal-popup"></div>'
            );
            // Bind swipe + ESC handlers ONCE on the persistent popup element.
            // (Re-binding on every showPopup would stack handlers — swipes
            // would fire navigation multiple times after a few opens.)
            this.bindPopupHandlers();
        }
    };

    /**
     * If navigatePopupTo asked for a popup to (re)open after the next render,
     * fulfil it now. Called at the end of render().
     */
    LRobCalendar.prototype.consumePendingPopup = function() {
        if (!this.pendingPopupId) return;
        var id = this.pendingPopupId;
        this.pendingPopupId = null;
        var $cell = this.$container.find('.lrob-cal-event-item[data-event-id="' + id + '"]').first();
        this.showPopup(id, $cell.length ? $cell : this.$container, null);
    };

    LRobCalendar.prototype.renderMonth = function() {
        var firstDay  = new Date(this.currentYear, this.currentMonth, 1);
        var lastDay   = new Date(this.currentYear, this.currentMonth + 1, 0);
        // Number of in-flow padding cells before the 1st, given the
        // configured week start. We now FILL these with the trailing days of
        // the previous month (rendered as .lrob-cal-day--out-of-month) so the
        // user can see which weekday the month starts on at a glance.
        var startDay  = (firstDay.getDay() - this.startOfWeek + 7) % 7;
        var totalDays = lastDay.getDate();
        var today     = new Date();

        var prevMonthLastDay = new Date(this.currentYear, this.currentMonth, 0).getDate();
        var prevMonth = this.currentMonth === 0 ? 11 : this.currentMonth - 1;
        var prevYear  = this.currentMonth === 0 ? this.currentYear - 1 : this.currentYear;
        var nextMonth = this.currentMonth === 11 ? 0 : this.currentMonth + 1;
        var nextYear  = this.currentMonth === 11 ? this.currentYear + 1 : this.currentYear;

        // Header
        var html = this.buildCalendarHeader();

        html += '<table class="lrob-cal-table"><thead><tr>';
        for (var d = 0; d < 7; d++) {
            html += '<th>' + this.dayNames[(this.startOfWeek + d) % 7] + '</th>';
        }
        html += '</tr></thead><tbody>';

        // Render the grid as a 6-week block, walking a cursor through prev/
        // current/next-month days so out-of-month cells get a real day number.
        var dayNum = 1;
        var totalCells = 6 * 7;
        for (var cell = 0; cell < totalCells; cell++) {
            var rowStart = cell % 7 === 0;
            var rowEnd   = cell % 7 === 6;
            if (rowStart) html += '<tr>';

            var cellMonth, cellYear, cellDay, isCurrentMonth;
            if (cell < startDay) {
                // Trailing days of the previous month
                cellDay   = prevMonthLastDay - (startDay - cell - 1);
                cellMonth = prevMonth;
                cellYear  = prevYear;
                isCurrentMonth = false;
            } else if (dayNum > totalDays) {
                // Leading days of the next month
                cellDay   = cell - (startDay + totalDays) + 1;
                cellMonth = nextMonth;
                cellYear  = nextYear;
                isCurrentMonth = false;
            } else {
                cellDay   = dayNum;
                cellMonth = this.currentMonth;
                cellYear  = this.currentYear;
                isCurrentMonth = true;
                dayNum++;
            }

            // Bail out early if we're past the last current-month day AND past
            // the row that contained it — saves rendering a blank trailing week.
            var dateStr = cellYear + '-' +
                String(cellMonth + 1).padStart(2, '0') + '-' +
                String(cellDay).padStart(2, '0');

            var dayEvents = isCurrentMonth ? this.getEventsForDate(dateStr) : [];
            var classes = 'lrob-cal-day';
            if (!isCurrentMonth)        classes += ' lrob-cal-day--out-of-month';
            if (dayEvents.length > 0)   classes += ' lrob-cal-has-events';
            if (isCurrentMonth &&
                cellDay   === today.getDate() &&
                cellMonth === today.getMonth() &&
                cellYear  === today.getFullYear()) {
                classes += ' lrob-cal-today';
            }

            html += '<td class="' + classes + '" data-date="' + dateStr + '">';
            html += '<span class="lrob-cal-day-num">' + cellDay + '</span>';

            // Classify each event for this cell. Multi-day events render as a
            // visible bar only in the start cell (or week-start after a wrap);
            // continuation cells get an invisible placeholder so single-day
            // events on those cells stack BELOW the bar's visual extension.
            var renderable = [];
            for (var k = 0; k < dayEvents.length; k++) {
                var ev0 = dayEvents[k];
                var span0 = this.classifyEventDay(ev0, dateStr, cell % 7);
                var isPlaceholder = span0.multiDay && span0.position !== 'start' && !span0.weekStart;
                renderable.push({ ev: ev0, span: span0, isPlaceholder: isPlaceholder });
            }

            if (renderable.length > 0) {
                html += '<div class="lrob-cal-events">';
                for (var e = 0; e < Math.min(renderable.length, 2); e++) {
                    var ev   = renderable[e].ev;
                    var span = renderable[e].span;

                    if (renderable[e].isPlaceholder) {
                        html += '<span class="lrob-cal-event-item lrob-cal-event-placeholder" aria-hidden="true">&nbsp;</span>';
                        continue;
                    }

                    var itemClasses = 'lrob-cal-event-item';
                    var styleAttr = '';
                    if (ev.color) {
                        styleAttr = ' style="--lrob-cal-event-color: ' + this.escapeHtml(ev.color) + '"';
                    }

                    if (span.multiDay) {
                        itemClasses += ' lrob-cal-event-multiday';
                        var spanInWeek = this.calcSpanInWeek(ev, dateStr, cell % 7);
                        if (spanInWeek > 1) {
                            var min = 'min-width: calc(' + (spanInWeek * 100) + '% + ' + ((spanInWeek - 1) * 8) + 'px)';
                            styleAttr = ev.color
                                ? ' style="--lrob-cal-event-color: ' + this.escapeHtml(ev.color) + '; ' + min + '"'
                                : ' style="' + min + '"';
                        }
                    }
                    html += '<span class="' + itemClasses + '" data-event-id="' + ev.id + '"' + styleAttr + '>';
                    html += '<span class="lrob-cal-event-dot" aria-hidden="true"></span>';
                    html += '<span class="lrob-cal-event-title">' + this.escapeHtml(ev.title) + '</span>';
                    html += '</span>';
                }
                if (renderable.length > 2) {
                    // Clickable "+N more" — opens the day-list popup.
                    html += '<button class="lrob-cal-more" type="button" data-date="' + dateStr + '">'
                          + '+' + (renderable.length - 2) + '</button>';
                }
                html += '</div>';
            }

            html += '</td>';

            if (rowEnd) {
                html += '</tr>';
                // Skip rendering rows that are entirely in the next month.
                if (dayNum > totalDays && cellMonth !== this.currentMonth) {
                    break;
                }
            }
        }

        html += '</tbody></table>';

        this.$container.find('.lrob-cal-grid').html(html);
    };

    /**
     * Calendar header — month/year title on the left (large, bold), grouped
     * prev/next chevrons, Today button, and the Month/Week segmented toggle.
     */
    LRobCalendar.prototype.buildCalendarHeader = function() {
        var chevLeft   = this.icons.chevronLeft  || '&lsaquo;';
        var chevRight  = this.icons.chevronRight || '&rsaquo;';
        var prevLabel  = this.i18n.prevMonth || 'Previous month';
        var nextLabel  = this.i18n.nextMonth || 'Next month';
        var todayLabel = this.i18n.today     || 'Today';
        var monthLbl   = this.i18n.monthView || 'Month';
        var weekLbl    = this.i18n.weekView  || 'Week';
        var titleText  = this.currentView === 'week'
            ? this.formatWeekTitle()
            : this.monthNames[this.currentMonth] + ' ' + this.currentYear;

        var html = '<div class="lrob-cal-header">';
        html += '<h2 class="lrob-cal-title">' + this.escapeHtml(titleText) + '</h2>';
        html += '<div class="lrob-cal-header-controls">';

        html += '<div class="lrob-cal-nav-group" role="group">';
        html += '<button class="lrob-cal-prev" type="button" aria-label="' + this.escapeHtml(prevLabel) + '"' + (this.loading ? ' disabled' : '') + '>' + chevLeft + '</button>';
        html += '<button class="lrob-cal-next" type="button" aria-label="' + this.escapeHtml(nextLabel) + '"' + (this.loading ? ' disabled' : '') + '>' + chevRight + '</button>';
        html += '</div>';

        html += '<button class="lrob-cal-today" type="button">' + this.escapeHtml(todayLabel) + '</button>';

        // View switcher — only shown when the block's `view` setting is one
        // of the switchable views (month/week). Agenda-only blocks stay
        // single-view and skip the toggle.
        if (this.view === 'month' || this.view === 'week') {
            html += '<div class="lrob-cal-view-switcher" role="tablist" aria-label="Calendar view">';
            html += '<button class="lrob-cal-view-btn' + (this.currentView === 'month' ? ' is-active' : '')
                  + '" type="button" data-view="month" role="tab" aria-selected="' + (this.currentView === 'month' ? 'true' : 'false') + '">'
                  + this.escapeHtml(monthLbl) + '</button>';
            html += '<button class="lrob-cal-view-btn' + (this.currentView === 'week' ? ' is-active' : '')
                  + '" type="button" data-view="week" role="tab" aria-selected="' + (this.currentView === 'week' ? 'true' : 'false') + '">'
                  + this.escapeHtml(weekLbl) + '</button>';
            html += '</div>';
        }

        html += '</div>';
        html += '</div>';
        return html;
    };

    /**
     * Render the week view — a 7-day vertical agenda. Each day is a row
     * with the weekday + date + events for that day. Compact, scannable,
     * good for "what's happening this week" use cases.
     */
    LRobCalendar.prototype.renderWeek = function() {
        var html = this.buildCalendarHeader();

        var start = this.weekStartDate();      // Date at 00:00 local
        var today = new Date();
        var dayLongFmt = new Intl.DateTimeFormat(this.siteLocale, { weekday: 'long' });
        var dayNumFmt  = new Intl.DateTimeFormat(this.siteLocale, { day: 'numeric', month: 'short' });
        var timeFmt    = new Intl.DateTimeFormat(this.siteLocale, { hour: '2-digit', minute: '2-digit' });

        html += '<div class="lrob-cal-week">';

        for (var i = 0; i < 7; i++) {
            var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            var dateStr = d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
            var dayEvents = this.getEventsForDate(dateStr).slice().sort(function (a, b) {
                return new Date(a.start) - new Date(b.start);
            });
            var isToday = d.getDate()  === today.getDate()
                       && d.getMonth() === today.getMonth()
                       && d.getYear()  === today.getYear();

            html += '<div class="lrob-cal-week-day' + (isToday ? ' lrob-cal-week-day--today' : '') + '" data-date="' + dateStr + '">';
            html += '<div class="lrob-cal-week-day-header">';
            html += '<span class="lrob-cal-week-day-name">' + this.escapeHtml(dayLongFmt.format(d)) + '</span>';
            html += '<span class="lrob-cal-week-day-date">' + this.escapeHtml(dayNumFmt.format(d)) + '</span>';
            html += '</div>';

            if (dayEvents.length === 0) {
                html += '<div class="lrob-cal-week-day-empty">·</div>';
            } else {
                html += '<ul class="lrob-cal-week-events">';
                for (var k = 0; k < dayEvents.length; k++) {
                    var ev = dayEvents[k];
                    var dotStyle = ev.color
                        ? ' style="--lrob-cal-event-color: ' + this.escapeHtml(ev.color) + '"'
                        : '';
                    var time = ev.allDay
                        ? ''
                        : timeFmt.format(new Date(ev.start));
                    html += '<li>'
                          + '<button class="lrob-cal-week-event lrob-cal-event-item" type="button" data-event-id="' + ev.id + '"' + dotStyle + '>'
                          + '<span class="lrob-cal-event-dot" aria-hidden="true"></span>'
                          + (time ? '<span class="lrob-cal-week-event-time">' + this.escapeHtml(time) + '</span>' : '')
                          + '<span class="lrob-cal-event-title">' + this.escapeHtml(ev.title) + '</span>'
                          + '</button>'
                          + '</li>';
                }
                html += '</ul>';
            }
            html += '</div>';
        }

        html += '</div>';

        this.$container.find('.lrob-cal-grid').html(html);
    };

    /**
     * Start-of-week date for the current state. Anchored on `currentDate`
     * when in week view; resets to today when the view first switches.
     */
    LRobCalendar.prototype.weekStartDate = function() {
        var anchor = this.weekAnchor instanceof Date ? this.weekAnchor : new Date();
        var dow = anchor.getDay();
        var diff = (dow - this.startOfWeek + 7) % 7;
        return new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() - diff);
    };

    LRobCalendar.prototype.formatWeekTitle = function() {
        var start = this.weekStartDate();
        var end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        var fmt = new Intl.DateTimeFormat(this.siteLocale, { day: 'numeric', month: 'short' });
        var yearFmt = new Intl.DateTimeFormat(this.siteLocale, { year: 'numeric' });
        return fmt.format(start) + ' – ' + fmt.format(end) + ' ' + yearFmt.format(end);
    };

    /**
     * Agenda layout: chronological list of events in the loaded range,
     * grouped by date. Each row is clickable → opens the popup. No month grid.
     */
    LRobCalendar.prototype.renderAgenda = function() {
        var nowTs = Math.floor(Date.now() / 1000);
        var sorted = this.events.slice().sort(function (a, b) {
            return new Date(a.start) - new Date(b.start);
        }).filter(function (e) {
            // Only show upcoming + currently-running events in agenda
            var endTs = e.end ? Math.floor(new Date(e.end).getTime() / 1000) : Math.floor(new Date(e.start).getTime() / 1000);
            return endTs >= nowTs;
        });

        var html = '<div class="lrob-cal-agenda">';

        if (sorted.length === 0) {
            html += '<p class="lrob-cal-agenda-empty">' +
                this.escapeHtml(this.i18n.noUpcoming || 'No upcoming events.') +
                '</p>';
        } else {
            var lastDateKey = null;
            var dateFmt = new Intl.DateTimeFormat(this.siteLocale, { weekday: 'long', day: 'numeric', month: 'long' });
            var timeFmt = new Intl.DateTimeFormat(this.siteLocale, { hour: '2-digit', minute: '2-digit' });

            for (var i = 0; i < sorted.length; i++) {
                var ev = sorted[i];
                var startDate = new Date(ev.start);
                var dateKey = startDate.toISOString().substring(0, 10);

                if (dateKey !== lastDateKey) {
                    html += '<h3 class="lrob-cal-agenda-date">' + this.escapeHtml(dateFmt.format(startDate)) + '</h3>';
                    lastDateKey = dateKey;
                }

                html += '<button class="lrob-cal-agenda-item lrob-cal-event-item" type="button" data-event-id="' + ev.id + '">';
                if (!ev.allDay) {
                    html += '<span class="lrob-cal-agenda-time">' + this.escapeHtml(timeFmt.format(startDate)) + '</span>';
                }
                html += '<span class="lrob-cal-agenda-title">' + this.escapeHtml(ev.title) + '</span>';
                if (ev.venue || ev.city) {
                    var loc = [];
                    if (ev.venue) loc.push(this.escapeHtml(ev.venue));
                    if (ev.city)  loc.push(this.escapeHtml(ev.city));
                    html += '<span class="lrob-cal-agenda-location">' + loc.join(', ') + '</span>';
                }
                html += '</button>';
            }
        }

        html += '</div>';

        this.$container.find('.lrob-cal-grid').html(html);
    };

    /**
     * Decide how to render an event item for a given day cell.
     *   - multiDay: true if start date != end date (ignores time-of-day)
     *   - position: 'start' | 'middle' | 'end' (only meaningful if multiDay)
     *   - weekStart / weekEnd: this cell is at the left/right edge of its week row.
     *     CSS uses these to round the bar at the edges of multi-week spans.
     *
     * @param {Object} event
     * @param {string} dateStr 'YYYY-MM-DD' for the cell being rendered
     * @param {number} weekCol 0..6 — column index in the week row
     */
    LRobCalendar.prototype.classifyEventDay = function(event, dateStr, weekCol) {
        var startStr = event.start.substring(0, 10);
        var endStr = (event.end || event.start).substring(0, 10);

        var multiDay = startStr !== endStr;
        var position = 'start';
        if (multiDay) {
            if (dateStr === startStr) position = 'start';
            else if (dateStr === endStr) position = 'end';
            else position = 'middle';
        }

        return {
            multiDay: multiDay,
            position: position,
            weekStart: weekCol === 0,
            weekEnd: weekCol === 6,
        };
    };

    /**
     * Number of days the event occupies in the current week row, starting from
     * `dateStr`. Capped at the remaining days in the week.
     */
    LRobCalendar.prototype.calcSpanInWeek = function(event, dateStr, weekCol) {
        var startStr = event.start.substring(0, 10);
        var endStr = (event.end || event.start).substring(0, 10);
        if (startStr === endStr) return 1;

        // Days remaining in this week after the current cell (inclusive)
        var daysLeftInWeek = 7 - weekCol;

        // Days from current cell to event end (inclusive)
        var current = new Date(dateStr + 'T00:00:00');
        var end = new Date(endStr + 'T00:00:00');
        var daysToEnd = Math.round((end - current) / 86400000) + 1;

        return Math.max(1, Math.min(daysLeftInWeek, daysToEnd));
    };

    LRobCalendar.prototype.getEventsForDate = function(dateStr) {
        return this.events.filter(function(event) {
            var eventStart = event.start.substring(0, 10);
            var eventEnd = event.end ? event.end.substring(0, 10) : eventStart;
            return dateStr >= eventStart && dateStr <= eventEnd;
        });
    };

    /**
     * Single source of truth for "is this a small-screen layout?" The number
     * is intentionally kept in sync with the mobile breakpoint used in
     * blocks/calendar/style.css (640px in v1.1.0).
     */
    LRobCalendar.prototype.isMobileViewport = function() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
    };

    /**
     * Build the day-list view HTML — used on mobile when the user taps a day
     * cell with multiple events. Lists every event for that date with a
     * tappable row that opens the full event card.
     */
    LRobCalendar.prototype.buildDayListHtml = function(dateStr) {
        var events = this.getEventsForDate(dateStr).slice().sort(function (a, b) {
            return new Date(a.start) - new Date(b.start);
        });

        var date = new Date(dateStr + 'T00:00:00');
        var titleFmt = new Intl.DateTimeFormat(this.siteLocale, {
            weekday: 'long', day: 'numeric', month: 'long'
        });
        var timeFmt = new Intl.DateTimeFormat(this.siteLocale, {
            hour: '2-digit', minute: '2-digit'
        });

        var closeLabel = this.i18n.close || 'Close';
        var closeIcon  = this.icons.close || '&times;';

        var html = '<div class="lrob-cal-popup-content" data-mode="day-list">';
        html += '<div class="lrob-cal-day-list-title">';
        html += '<span>' + this.escapeHtml(titleFmt.format(date)) + '</span>';
        html += '<button class="lrob-cal-popup-close" type="button" aria-label="' + this.escapeHtml(closeLabel) + '">' + closeIcon + '</button>';
        html += '</div>';

        html += '<ul class="lrob-cal-day-list">';
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            var dot = ev.color
                ? '<span class="lrob-cal-day-list-dot" style="--lrob-cal-event-color: ' + this.escapeHtml(ev.color) + '"></span>'
                : '<span class="lrob-cal-day-list-dot"></span>';
            var startDate = new Date(ev.start);
            var time = ev.allDay ? '' : timeFmt.format(startDate);
            html += '<li>'
                  + '<button class="lrob-cal-day-list-item" type="button" data-event-id="' + ev.id + '">'
                  + dot
                  + '<span class="lrob-cal-day-list-title-text">' + this.escapeHtml(ev.title) + '</span>'
                  + '<span class="lrob-cal-day-list-time">' + this.escapeHtml(time) + '</span>'
                  + '</button>'
                  + '</li>';
        }
        html += '</ul>';
        html += '</div>';
        return html;
    };

    /**
     * Open the popup in day-list mode for the given date string.
     * Tapping an event in the list re-opens the popup as a regular event card
     * (handled by the delegated `.lrob-cal-day-list-item` click in
     * bindPopupHandlers).
     */
    LRobCalendar.prototype.showDayList = function(dateStr, $trigger) {
        var events = this.getEventsForDate(dateStr);
        if (!events.length) return;

        // If exactly one event, skip the intermediate list and go straight
        // to the full card — a one-item list is just friction.
        if (events.length === 1) {
            this.showPopup(events[0].id, $trigger || this.$container, null);
            return;
        }

        this.currentPopupEventId = null;
        this.currentDayListDate = dateStr;

        var html = '<div class="lrob-cal-popup-stage">'
                 + this.buildDayListHtml(dateStr)
                 + '</div>';

        var $popup = this.$container.find('.lrob-cal-popup');
        $popup.html(html).show();
        $popup[0].offsetHeight; // reflow
        $popup.addClass('is-shown');
        document.body.classList.add('lrob-cal-popup-open');

        // Position only matters on desktop; mobile is fullscreen via CSS.
        if (!this.isMobileViewport()) {
            this.positionPopupNearTrigger($popup, $trigger || this.$container);
        }
    };

    /**
     * Build the HTML for prev/next arrow buttons. Returns '' if neither
     * sibling exists. Buttons carry `data-target-id` so the click handler
     * (delegated on $popup in bindPopupHandlers) knows where to go.
     * In v1.1.0 the arrows live INSIDE the popup header alongside the
     * close button — no longer floated outside the card.
     */
    LRobCalendar.prototype.buildArrowsHtml = function(siblings) {
        var prevLabel = this.i18n.prevEvent || 'Previous event';
        var nextLabel = this.i18n.nextEvent || 'Next event';
        var chevLeft  = this.icons.chevronLeft  || '&lsaquo;';
        var chevRight = this.icons.chevronRight || '&rsaquo;';
        var html = '';
        if (siblings.prev) {
            html += '<button class="lrob-cal-popup-nav lrob-cal-popup-nav--prev" type="button" '
                  + 'aria-label="' + this.escapeHtml(prevLabel) + '" '
                  + 'data-target-id="' + siblings.prev.id + '">' + chevLeft + '</button>';
        }
        if (siblings.next) {
            html += '<button class="lrob-cal-popup-nav lrob-cal-popup-nav--next" type="button" '
                  + 'aria-label="' + this.escapeHtml(nextLabel) + '" '
                  + 'data-target-id="' + siblings.next.id + '">' + chevRight + '</button>';
        }
        return html;
    };

    /**
     * Short uppercase month name for the date block (e.g. "JUIN", "JUN").
     * Pulled from the localized monthNames array, truncated to 3 chars.
     */
    LRobCalendar.prototype.shortMonthName = function(monthIndex) {
        var name = (this.monthNames && this.monthNames[monthIndex]) || '';
        return name.substring(0, 3).toUpperCase();
    };

    /**
     * Build the .lrob-cal-popup-content card HTML for a single event.
     * No event handlers attached here — clicks are delegated on the popup
     * (see bindPopupHandlers). The lightbox URL travels as `data-full-url`
     * on the thumbnail button so the delegated handler can read it without
     * re-binding per card.
     */
    LRobCalendar.prototype.buildCardHtml = function(event, siblings) {
        siblings = siblings || { prev: null, next: null };
        var when           = this.formatEventDateAndTime(event);
        var closeLabel     = this.i18n.close     || 'Close';
        var viewImageLabel = this.i18n.viewImage || 'View image';
        var fullUrl        = event.thumbnailFull || event.thumbnail || '';
        var closeIcon      = this.icons.close || '&times;';
        var arrowIcon      = this.icons.arrowRight || '&rarr;';

        var startDate = new Date(event.start);
        var dayNum    = startDate.getDate();
        var monthStr  = this.shortMonthName(startDate.getMonth());

        // Date-block color pair — same per-event hashed-or-category pastel
        // pair the events-list cards use (PHP-computed, shipped with the
        // event payload). Falls back to the primary-soft pair if absent.
        var pillStyle = '';
        if (event.pillBg || event.pillText) {
            pillStyle = ' style="background-color: ' + this.escapeHtml(event.pillBg || '')
                      + '; color: ' + this.escapeHtml(event.pillText || '') + '"';
        }

        var html = '<div class="lrob-cal-popup-content" data-event-id="' + event.id + '">';

        // Header: date block (left) + title (center) + nav/close (right).
        html += '<div class="lrob-cal-popup-header">';
        html += '<div class="lrob-cal-date-block" aria-hidden="true"' + pillStyle + '>';
        html += '<span class="lrob-cal-date-block-day">' + dayNum + '</span>';
        html += '<span class="lrob-cal-date-block-month">' + this.escapeHtml(monthStr) + '</span>';
        html += '</div>';

        if (this.publicPagesEnabled) {
            html += '<h4 class="lrob-cal-popup-title"><a href="' + event.url + '">' + this.escapeHtml(event.title) + '</a></h4>';
        } else {
            html += '<h4 class="lrob-cal-popup-title">' + this.escapeHtml(event.title) + '</h4>';
        }

        html += '<div class="lrob-cal-popup-actions">';
        html += this.buildArrowsHtml(siblings);
        html += '<button class="lrob-cal-popup-close" type="button" aria-label="' + this.escapeHtml(closeLabel) + '">' + closeIcon + '</button>';
        html += '</div>';
        html += '</div>';  // header

        // Body
        html += '<div class="lrob-cal-popup-body">';

        // Meta rows. Date and time stacked on two lines (no preposition).
        html += '<div class="lrob-cal-popup-meta-list">';
        html += '<p class="lrob-cal-popup-meta lrob-cal-popup-date">'
              + (this.icons.calendar || '')
              + '<span class="lrob-cal-popup-meta-stack">'
              + '<span class="lrob-cal-popup-date-date">' + this.escapeHtml(when.date) + '</span>'
              + (when.time
                  ? '<span class="lrob-cal-popup-date-time">' + this.escapeHtml(when.time) + '</span>'
                  : '')
              + '</span>'
              + '</p>';

        if (event.venue || event.city) {
            var location = [];
            if (event.venue) location.push(this.escapeHtml(event.venue));
            if (event.city)  location.push(this.escapeHtml(event.city));
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-location">'
                  + (this.icons.location || '')
                  + '<span>' + location.join(', ') + '</span>'
                  + '</p>';
        }

        if (event.recurring) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-recurring">'
                  + (this.icons.recurring || '')
                  + '<span>' + this.escapeHtml(this.i18n.recurring || 'Recurring') + '</span>'
                  + '</p>';
        }

        if (event.isFree) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-cost lrob-cal-popup-cost--free">'
                  + (this.icons.ticket || '')
                  + '<span>' + this.escapeHtml(this.i18n.free || 'Free') + '</span>'
                  + '</p>';
        } else if (event.cost) {
            html += '<p class="lrob-cal-popup-meta lrob-cal-popup-cost">'
                  + (this.icons.ticket || '')
                  + '<span>' + this.escapeHtml(event.cost) + '</span>'
                  + '</p>';
        }
        html += '</div>';  // meta list

        if (event.excerpt) {
            html += '<p class="lrob-cal-popup-excerpt">' + this.escapeHtml(event.excerpt) + '</p>';
        }

        // Featured image moved BELOW the meta. Treated as supporting content,
        // not the hero — the date block is the visual anchor instead.
        if (event.thumbnail && this.popupShowImage) {
            if (this.popupImageLightbox) {
                html += '<button class="lrob-cal-popup-thumb" type="button" '
                      + 'aria-label="' + this.escapeHtml(viewImageLabel) + '" '
                      + 'data-full-url="' + this.escapeHtml(fullUrl) + '" '
                      + 'data-event-title="' + this.escapeHtml(event.title || '') + '">';
                html += '<img src="' + event.thumbnail + '" alt="" loading="lazy">';
                html += '</button>';
            } else {
                html += '<div class="lrob-cal-popup-thumb lrob-cal-popup-thumb--static">';
                html += '<img src="' + event.thumbnail + '" alt="" loading="lazy">';
                html += '</div>';
            }
        }

        // CTA row — bottom of the body. Ticket link is the secondary button
        // (when set); the primary CTA is the "view event" link.
        html += '<div class="lrob-cal-popup-cta">';
        if (event.ticketUrl) {
            html += '<a href="' + event.ticketUrl + '" class="lrob-cal-popup-link lrob-cal-popup-link--ticket" target="_blank" rel="noopener">'
                  + this.escapeHtml(this.i18n.getTickets || 'Get tickets') + '</a>';
        }
        if (this.publicPagesEnabled) {
            html += '<a href="' + event.url + '" class="lrob-cal-popup-link">'
                  + this.escapeHtml(this.linkText) + arrowIcon + '</a>';
        }
        html += '</div>';

        html += '</div>';  // body
        html += '</div>';  // content
        return html;
    };

    LRobCalendar.prototype.showPopup = function(eventId, $trigger, clickEvent) {
        var event = this.events.find(function(e) { return e.id === eventId; });
        if (!event) return;

        this.currentPopupEventId = eventId;
        var siblings = this.getSortedEventsAroundId(eventId);

        // Build the popup body: just a stage holding the single card. Arrows
        // and the close button now live INSIDE the card header — no more
        // floating buttons outside the popup. The stage stays so two cards
        // can briefly coexist during a slide transition (see navigatePopupTo).
        var html = '<div class="lrob-cal-popup-stage">'
                 + this.buildCardHtml(event, siblings)
                 + '</div>';

        var $popup = this.$container.find('.lrob-cal-popup');
        $popup.html(html).show();
        // Force a reflow so the opacity transition picks up the display change.
        $popup[0].offsetHeight; // eslint-disable-line no-unused-expressions
        $popup.addClass('is-shown');

        // Body scroll lock on mobile (the CSS rule is scoped to ≤600px).
        document.body.classList.add('lrob-cal-popup-open');

        // Preload prev/next thumbnails so navigation feels instant.
        if (siblings.prev && siblings.prev.thumbnail) { (new Image()).src = siblings.prev.thumbnail; }
        if (siblings.next && siblings.next.thumbnail) { (new Image()).src = siblings.next.thumbnail; }

        // Desktop only: position the popup near the clicked cell. Mobile uses
        // CSS flex centering — overriding inline top/left via !important.
        if (!window.matchMedia || !window.matchMedia('(max-width: 600px)').matches) {
            this.positionPopupNearTrigger($popup, $trigger);
        }
    };

    /**
     * Desktop popup positioning: anchor the card to the clicked cell, then
     * adjust to keep it inside the calendar container. No-op on mobile.
     */
    LRobCalendar.prototype.positionPopupNearTrigger = function($popup, $trigger) {
        var containerOffset = this.$container.offset();
        var containerWidth  = this.$container.outerWidth();
        var containerHeight = this.$container.outerHeight();
        var triggerOffset   = $trigger.offset();
        var popupWidth      = $popup.outerWidth()  || 360;
        var popupHeight     = $popup.outerHeight() || 200;

        var top  = triggerOffset.top  - containerOffset.top  + $trigger.outerHeight() + 5;
        var left = triggerOffset.left - containerOffset.left;
        if (left + popupWidth > containerWidth - 10) left = containerWidth - popupWidth - 10;
        if (left < 10) left = 10;
        if (top + popupHeight > containerHeight - 10) {
            top = triggerOffset.top - containerOffset.top - popupHeight - 5;
        }
        if (top < 10) top = 10;

        $popup.css({ top: top, left: left });
    };

    /**
     * One-time popup-level handlers bound on the persistent .lrob-cal-popup
     * element. $popup.html() replaces inner content but keeps these listeners
     * alive — re-binding in showPopup would stack handlers and fire navigation
     * multiple times after a few opens. All click handlers use jQuery event
     * delegation so they survive content swaps (initial open AND two-card
     * navigation swap).
     */
    LRobCalendar.prototype.bindPopupHandlers = function() {
        var self = this;
        var $popup = this.$container.find('.lrob-cal-popup');

        // Close button — delegated.
        $popup.on('click', '.lrob-cal-popup-close', function (e) {
            e.preventDefault();
            self.hidePopup();
        });

        // Thumbnail → lightbox. Bound ONLY to the <button> variant; the
        // static <div> shares the class but should not be clickable.
        $popup.on('click', 'button.lrob-cal-popup-thumb', function (e) {
            e.preventDefault();
            var $btn  = $(this);
            var url   = $btn.attr('data-full-url')    || '';
            var title = $btn.attr('data-event-title') || '';
            self.openLightbox(url, title);
        });

        // Prev/next nav arrows — delegated; direction is derived from class.
        $popup.on('click', '.lrob-cal-popup-nav', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var targetId = parseInt($btn.attr('data-target-id'), 10);
            var dir = $btn.hasClass('lrob-cal-popup-nav--next') ? 'left' : 'right';
            self.navigatePopupTo(targetId, dir);
        });

        // Day-list row → open the full event card popup. We MUST stop the
        // click from bubbling up: showPopup() rebuilds $popup.html(), which
        // detaches this item from the DOM. By the time the document-level
        // outside-click handler runs, e.target.closest('.lrob-cal-popup')
        // returns null (the detached node has no popup ancestor), so the
        // outside-click handler fires hidePopup() and immediately closes the
        // popup that just opened.
        $popup.on('click', '.lrob-cal-day-list-item', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var targetId = parseInt($(this).attr('data-event-id'), 10);
            if (!targetId) return;
            self.showPopup(targetId, self.$container, null);
        });

        // Mobile swipe nav.
        var swipeStartX = 0;
        var swipeStartY = 0;
        var swipeTracking = false;

        $popup.on('touchstart.lrobSwipe', function (e) {
            if (!e.originalEvent.touches || e.originalEvent.touches.length !== 1) {
                swipeTracking = false;
                return;
            }
            var t = e.originalEvent.touches[0];
            swipeStartX = t.clientX;
            swipeStartY = t.clientY;
            swipeTracking = true;
        });
        $popup.on('touchend.lrobSwipe', function (e) {
            if (!swipeTracking) return;
            swipeTracking = false;
            var t = e.originalEvent.changedTouches[0];
            var dx = t.clientX - swipeStartX;
            var dy = t.clientY - swipeStartY;
            // Lower threshold + reject mostly-vertical motion.
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.3) {
                var siblings = self.getSortedEventsAroundId(self.currentPopupEventId);
                var target = dx < 0 ? siblings.next : siblings.prev;
                if (target) self.navigatePopupTo(target.id, dx < 0 ? 'left' : 'right');
            }
        });
    };

    LRobCalendar.prototype.hidePopup = function() {
        this.currentPopupEventId = null;
        var $popup = this.$container.find('.lrob-cal-popup');
        if (!$popup.hasClass('is-shown')) {
            $popup.hide();
            document.body.classList.remove('lrob-cal-popup-open');
            return;
        }
        $popup.removeClass('is-shown');
        // Match the CSS transition duration before fully hiding.
        setTimeout(function () {
            // Skip hide() if a new popup opened in the meantime (re-added .is-shown).
            if (!$popup.hasClass('is-shown')) {
                $popup.hide();
                document.body.classList.remove('lrob-cal-popup-open');
            }
        }, 200);
    };

    /**
     * All loaded events sorted by start date, plus the prev/next neighbours of `id`.
     */
    LRobCalendar.prototype.getSortedEventsAroundId = function(id) {
        var sorted = this.events.slice().sort(function (a, b) {
            return new Date(a.start) - new Date(b.start);
        });
        var idx = -1;
        for (var i = 0; i < sorted.length; i++) {
            if (sorted[i].id === id) { idx = i; break; }
        }
        if (idx === -1) return { prev: null, next: null };
        return {
            prev: idx > 0 ? sorted[idx - 1] : null,
            next: idx < sorted.length - 1 ? sorted[idx + 1] : null,
        };
    };

    /**
     * Open the popup for `targetId`. If the event's start date is outside the
     * currently rendered month, navigate the calendar to that month first.
     *
     * Cross-month case: set this.pendingPopupId; render()'s consumePendingPopup()
     * will reopen the popup after the grid has been (re)rendered — including
     * after an async loadEvents fetch. The popup element itself persists across
     * renders thanks to ensureShell(), so the existing popup just gets new content.
     */
    LRobCalendar.prototype.navigatePopupTo = function(targetId, direction) {
        var ev = this.events.find(function (e) { return e.id === targetId; });
        if (!ev) return;

        var self = this;
        var $popup = this.$container.find('.lrob-cal-popup');

        // direction: 'left' → NEXT (current slides off left, new in from right).
        //            'right' → PREV (current slides off right, new in from left).
        // Fallback when callers don't pass a direction: derive from index order.
        if (!direction) {
            var sorted = this.events.slice().sort(function (a, b) {
                return new Date(a.start) - new Date(b.start);
            });
            var currIdx = -1, targetIdx = -1;
            for (var i = 0; i < sorted.length; i++) {
                if (sorted[i].id === this.currentPopupEventId) currIdx = i;
                if (sorted[i].id === targetId) targetIdx = i;
            }
            direction = targetIdx > currIdx ? 'left' : 'right';
        }

        var $stage = $popup.find('.lrob-cal-popup-stage');
        if (!$stage.length) {
            // Defensive: stage missing means popup wasn't built by the current
            // showPopup. Fall back to a full rebuild (no slide).
            this.showPopup(targetId, this.$container, null);
            return;
        }

        // Cross-month navigation: kick off the grid re-render in the
        // BACKGROUND so the popup's two-card slide animation isn't sacrificed.
        // ensureShell() preserves the popup element across renders, and the
        // grid lives behind it visually, so the user sees a smooth card
        // transition while the month grid updates underneath.
        var d = new Date(ev.start);
        var sameMonth = d.getMonth() === this.currentMonth && d.getFullYear() === this.currentYear;
        if (!sameMonth) {
            this.currentMonth = d.getMonth();
            this.currentYear  = d.getFullYear();
            // Skip pendingPopupId — we're handling the popup ourselves with the
            // two-card slide, so consumePendingPopup() must NOT try to reopen.
            this.pendingPopupId = null;
            this.checkAndLoadEvents();
        }

        // Two-card slide: insert the new card as a SIBLING of the outgoing
        // card inside the stage. Both animate simultaneously (one out, one in)
        // so the user never sees an empty popup — that gap was the source of
        // the "blinking to black" flash.
        var siblings = this.getSortedEventsAroundId(targetId);
        var $newCard = $(this.buildCardHtml(ev, siblings)).addClass('is-incoming');
        $stage.append($newCard);
        // The outgoing card's header still has arrows pointing at the OLD
        // siblings. Disable them during the slide so a stray double-tap
        // doesn't navigate again mid-transition.
        $stage.find('.lrob-cal-popup-content:not(.is-incoming) .lrob-cal-popup-nav').attr('disabled', 'disabled');

        // Force reflow so both cards' initial transforms are committed before
        // the animation classes fire — otherwise the browser might collapse
        // the from→to states into a single frame and skip the animation.
        $stage[0].offsetHeight; // eslint-disable-line no-unused-expressions

        var navClass = direction === 'left' ? 'is-navigating-left' : 'is-navigating-right';
        $stage.addClass(navClass);

        // Update tracked event id immediately so subsequent swipes target the
        // newly-displayed card's siblings, not the outgoing card's.
        this.currentPopupEventId = targetId;

        // After the animation finishes, drop the outgoing card and clean up
        // the staging classes so the new card sits in normal flow.
        setTimeout(function () {
            $stage.find('.lrob-cal-popup-content').not('.is-incoming').remove();
            $newCard.removeClass('is-incoming');
            $stage.removeClass('is-navigating-left is-navigating-right');
        }, 260);

        // Preload thumbs for THIS card's siblings so the next swipe is also instant.
        if (siblings.prev && siblings.prev.thumbnail) { (new Image()).src = siblings.prev.thumbnail; }
        if (siblings.next && siblings.next.thumbnail) { (new Image()).src = siblings.next.thumbnail; }
    };

    /**
     * Format an event's date + time as TWO separate strings so the popup can
     * stack them on two lines (date on top, time below, no "at" / "à").
     * Returns { date, time }. Time is '' for all-day events.
     */
    LRobCalendar.prototype.formatEventDateAndTime = function(event) {
        var start = new Date(event.start);
        var end   = event.end ? new Date(event.end) : null;
        var dateOpts = { day: 'numeric', month: 'long', year: 'numeric' };
        var timeOpts = { hour: '2-digit', minute: '2-digit' };
        var dateFmt = new Intl.DateTimeFormat(this.siteLocale, dateOpts);
        var timeFmt = new Intl.DateTimeFormat(this.siteLocale, timeOpts);

        // Instant — start only
        if (event.instant || !end) {
            return {
                date: dateFmt.format(start),
                time: event.allDay ? '' : timeFmt.format(start),
            };
        }

        var sameDay = start.getFullYear() === end.getFullYear()
            && start.getMonth() === end.getMonth()
            && start.getDate() === end.getDate();

        if (event.allDay) {
            return {
                date: sameDay ? dateFmt.format(start) : dateFmt.format(start) + ' – ' + dateFmt.format(end),
                time: '',
            };
        }

        if (sameDay) {
            return {
                date: dateFmt.format(start),
                time: timeFmt.format(start) + ' – ' + timeFmt.format(end),
            };
        }
        return {
            // Multi-day non-all-day: keep the date range on the first line and
            // the start-time on the second (most useful piece of info).
            date: dateFmt.format(start) + ' – ' + dateFmt.format(end),
            time: timeFmt.format(start) + ' – ' + timeFmt.format(end),
        };
    };

    /**
     * Legacy single-line formatter — still used by the agenda view and any
     * older call sites. Internally just joins date+time with an en-dash.
     */
    LRobCalendar.prototype.formatEventWhen = function(event) {
        var start = new Date(event.start);
        var end = event.end ? new Date(event.end) : null;

        var dateOpts = { day: 'numeric', month: 'long', year: 'numeric' };
        var timeOpts = { hour: '2-digit', minute: '2-digit' };
        var fullOpts = Object.assign({}, dateOpts, timeOpts);

        var dateFmt = new Intl.DateTimeFormat(this.siteLocale, dateOpts);
        var timeFmt = new Intl.DateTimeFormat(this.siteLocale, timeOpts);
        var fullFmt = new Intl.DateTimeFormat(this.siteLocale, fullOpts);

        // Instant: just the start
        if (event.instant) {
            return event.allDay ? dateFmt.format(start) : fullFmt.format(start);
        }

        if (!end) {
            return event.allDay ? dateFmt.format(start) : fullFmt.format(start);
        }

        var sameDay = start.getFullYear() === end.getFullYear()
            && start.getMonth() === end.getMonth()
            && start.getDate() === end.getDate();

        if (event.allDay) {
            return sameDay ? dateFmt.format(start) : dateFmt.format(start) + ' — ' + dateFmt.format(end);
        }

        if (sameDay) {
            return fullFmt.format(start) + ' — ' + timeFmt.format(end);
        }
        return fullFmt.format(start) + ' — ' + fullFmt.format(end);
    };

    /**
     * Delegate to the shared lightbox module (assets/js/lightbox.js).
     * Loaded as a script dep of `lrob-calendar-view`.
     */
    LRobCalendar.prototype.openLightbox = function(imageUrl, title) {
        if (window.LRobLightbox && window.LRobLightbox.open) {
            window.LRobLightbox.open(imageUrl, title);
        }
    };

    LRobCalendar.prototype.escapeHtml = function(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    window.LRobCalendar = LRobCalendar;

})(jQuery);
