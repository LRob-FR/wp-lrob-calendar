/**
 * Events-list block — editor component. Server-rendered (save returns null).
 */
(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment, useState, useEffect } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, RangeControl, ToggleControl, Spinner } = wp.components;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;

    const data = window.lrobCalendarBlocks || { categories: [], tags: [] };
    const categoryOptions = [{ value: 0, label: __('All Categories', 'lrob-calendar') }, ...data.categories];
    const tagOptions      = [{ value: 0, label: __('All Tags', 'lrob-calendar') }, ...data.tags];

    registerBlockType('lrob-calendar/events-list', {
        edit: function (props) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps({ className: 'lrob-block-preview lrob-events-list-preview' });
            const [events, setEvents] = useState([]);
            const [loading, setLoading] = useState(true);

            useEffect(() => {
                setLoading(true);
                let path = '/lrob-calendar/v1/events?limit=' + attributes.limit;
                if (attributes.category) path += '&category=' + attributes.category;
                if (attributes.tag)      path += '&tag=' + attributes.tag;
                if (attributes.showPast) path += '&include_past=1';

                apiFetch({ path: path })
                    .then((data) => { setEvents(data); setLoading(false); })
                    .catch(() => { setLoading(false); });
            }, [attributes.limit, attributes.category, attributes.tag, attributes.showPast]);

            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: __('List Settings', 'lrob-calendar'), initialOpen: true },
                        el(RangeControl, {
                            label: attributes.pagination
                                ? __('Events per page', 'lrob-calendar')
                                : __('Number of Events', 'lrob-calendar'),
                            value: attributes.limit,
                            onChange: (value) => setAttributes({ limit: value }),
                            min: 1, max: 50
                        }),
                        el(ToggleControl, {
                            label: __('Enable pagination', 'lrob-calendar'),
                            checked: attributes.pagination,
                            onChange: (value) => setAttributes({ pagination: value })
                        }),
                        attributes.pagination && el(SelectControl, {
                            label: __('Pagination style', 'lrob-calendar'),
                            value: attributes.paginationStyle,
                            options: [
                                { value: 'arrows',   label: __('Arrows + page indicator', 'lrob-calendar') },
                                { value: 'numbered', label: __('Numbered pages',          'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ paginationStyle: value })
                        }),
                        el(SelectControl, {
                            label: __('Template', 'lrob-calendar'),
                            value: attributes.template,
                            options: [
                                { value: 'list',    label: __('List',    'lrob-calendar') },
                                { value: 'grid',    label: __('Grid',    'lrob-calendar') },
                                { value: 'minimal', label: __('Minimal', 'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ template: value })
                        }),
                        el(SelectControl, {
                            label: __('Order', 'lrob-calendar'),
                            value: attributes.order,
                            options: [
                                { value: 'auto', label: __('Auto (based on past-events toggle)', 'lrob-calendar') },
                                { value: 'ASC',  label: __('Ascending (oldest first)',           'lrob-calendar') },
                                { value: 'DESC', label: __('Descending (newest first)',          'lrob-calendar') }
                            ],
                            help: __('Auto: upcoming events soonest-first when past events are hidden; most-recent-first when shown.', 'lrob-calendar'),
                            onChange: (value) => setAttributes({ order: value })
                        }),
                        el(ToggleControl, {
                            label: __('Show Past Events', 'lrob-calendar'),
                            checked: attributes.showPast,
                            onChange: (value) => setAttributes({ showPast: value })
                        })
                    ),
                    el(PanelBody, { title: __('Display Options', 'lrob-calendar'), initialOpen: false },
                        el(ToggleControl, {
                            label: __('Show Images', 'lrob-calendar'),
                            checked: attributes.showImages,
                            onChange: (value) => setAttributes({ showImages: value })
                        }),
                        attributes.showImages && el(SelectControl, {
                            label: __('Image display', 'lrob-calendar'),
                            value: attributes.imageDisplay,
                            options: [
                                { value: 'contain', label: __('Show whole image', 'lrob-calendar') },
                                { value: 'cover',   label: __('Crop to fill',     'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ imageDisplay: value })
                        }),
                        attributes.showImages && el(SelectControl, {
                            label: __('Image height', 'lrob-calendar'),
                            value: attributes.imageHeight,
                            options: [
                                { value: 'small',  label: __('Small',   'lrob-calendar') },
                                { value: 'medium', label: __('Medium',  'lrob-calendar') },
                                { value: 'large',  label: __('Large',   'lrob-calendar') },
                                { value: 'auto',   label: __('Natural', 'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ imageHeight: value })
                        }),
                        el(ToggleControl, {
                            label: __('Show Excerpt', 'lrob-calendar'),
                            checked: attributes.showExcerpt,
                            onChange: (value) => setAttributes({ showExcerpt: value })
                        }),
                        el(ToggleControl, {
                            label: __('Show Categories', 'lrob-calendar'),
                            checked: attributes.showCategories,
                            onChange: (value) => setAttributes({ showCategories: value })
                        })
                    ),
                    el(PanelBody, { title: __('Filters', 'lrob-calendar'), initialOpen: false },
                        el(SelectControl, {
                            label: __('Category', 'lrob-calendar'),
                            value: attributes.category,
                            options: categoryOptions,
                            onChange: (value) => setAttributes({ category: parseInt(value, 10) })
                        }),
                        el(SelectControl, {
                            label: __('Tag', 'lrob-calendar'),
                            value: attributes.tag,
                            options: tagOptions,
                            onChange: (value) => setAttributes({ tag: parseInt(value, 10) })
                        })
                    )
                ),
                el('div', blockProps,
                    loading ? el(Spinner) : el(Fragment, null,
                        el('div', { className: 'lrob-block-label' },
                            __('Events List', 'lrob-calendar') + ' (' + events.length + ' ' + __('events', 'lrob-calendar') + ')'
                        ),
                        el('ul', { className: 'lrob-preview-list' },
                            events.slice(0, 3).map((event, i) =>
                                el('li', { key: i },
                                    el('strong', null, event.title),
                                    el('span', null, ' — ' + new Date(event.start).toLocaleDateString())
                                )
                            )
                        ),
                        events.length > 3 && el('div', { className: 'lrob-preview-more' },
                            '+ ' + (events.length - 3) + ' ' + __('more events', 'lrob-calendar')
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
