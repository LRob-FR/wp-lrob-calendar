/**
 * Single Event block — editor component with searchable event picker.
 */
(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment, useState, useEffect, useMemo } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, Spinner, TextControl, Button } = wp.components;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;

    registerBlockType('lrob-calendar/single-event', {
        edit: function (props) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps({ className: 'lrob-block-preview lrob-single-event-preview' });
            const [events, setEvents] = useState([]);
            const [selectedEvent, setSelectedEvent] = useState(null);
            const [loading, setLoading] = useState(true);
            const [searchTerm, setSearchTerm] = useState('');

            useEffect(() => {
                apiFetch({ path: '/lrob-calendar/v1/events?limit=500&include_past=1' }).then((data) => {
                    const sorted = data.sort((a, b) => new Date(b.start) - new Date(a.start));
                    setEvents(sorted);
                    setLoading(false);
                }).catch(() => { setLoading(false); });
            }, []);

            useEffect(() => {
                if (attributes.eventId && events.length > 0) {
                    const event = events.find(e => e.id === attributes.eventId);
                    setSelectedEvent(event || null);
                }
            }, [attributes.eventId, events]);

            const filteredEvents = useMemo(() => {
                if (!searchTerm) return events.slice(0, 20);
                const term = searchTerm.toLowerCase();
                return events.filter(e =>
                    e.title.toLowerCase().includes(term) ||
                    new Date(e.start).toLocaleDateString().includes(term)
                ).slice(0, 20);
            }, [events, searchTerm]);

            const formatEventLabel = (event) => {
                const date = new Date(event.start);
                return date.toLocaleDateString() + ' — ' + event.title;
            };

            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Event Selection', 'lrob-calendar'), initialOpen: true },
                        el(TextControl, {
                            label: __('Search events', 'lrob-calendar'),
                            value: searchTerm,
                            onChange: setSearchTerm,
                            placeholder: __('Type to search...', 'lrob-calendar'),
                            __nextHasNoMarginBottom: true
                        }),
                        el('div', {
                            className: 'lrob-event-selector',
                            style: {
                                maxHeight: '200px',
                                overflowY: 'auto',
                                border: '1px solid #ddd',
                                borderRadius: '4px',
                                marginTop: '8px',
                                marginBottom: '16px'
                            }
                        },
                            loading ? el(Spinner) : (
                                filteredEvents.length === 0
                                    ? el('div', { style: { padding: '8px', color: '#666' } }, __('No events found', 'lrob-calendar'))
                                    : filteredEvents.map(event =>
                                        el(Button, {
                                            key: event.id,
                                            onClick: () => setAttributes({ eventId: event.id }),
                                            style: {
                                                display: 'block',
                                                width: '100%',
                                                textAlign: 'left',
                                                padding: '8px 12px',
                                                borderBottom: '1px solid #eee',
                                                background: attributes.eventId === event.id ? '#0073aa' : 'transparent',
                                                color: attributes.eventId === event.id ? '#fff' : 'inherit',
                                                borderRadius: 0,
                                                height: 'auto',
                                                whiteSpace: 'normal',
                                                lineHeight: '1.4'
                                            }
                                        }, formatEventLabel(event))
                                    )
                            )
                        ),
                        selectedEvent && el('div', {
                            style: {
                                padding: '8px',
                                background: '#f0f0f0',
                                borderRadius: '4px',
                                marginBottom: '16px'
                            }
                        },
                            el('strong', null, __('Selected:', 'lrob-calendar')),
                            el('div', null, selectedEvent.title)
                        ),
                        el(SelectControl, {
                            label: __('Template', 'lrob-calendar'),
                            value: attributes.template,
                            options: [
                                { value: 'full',    label: __('Full',    'lrob-calendar') },
                                { value: 'list',    label: __('Compact', 'lrob-calendar') },
                                { value: 'minimal', label: __('Minimal', 'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ template: value }),
                            __nextHasNoMarginBottom: true
                        }),
                        el(SelectControl, {
                            label: __('Image display', 'lrob-calendar'),
                            value: attributes.imageDisplay,
                            options: [
                                { value: 'contain', label: __('Show whole image', 'lrob-calendar') },
                                { value: 'cover',   label: __('Crop to fill',     'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ imageDisplay: value })
                        }),
                        el(SelectControl, {
                            label: __('Image height', 'lrob-calendar'),
                            value: attributes.imageHeight,
                            options: [
                                { value: 'small',  label: __('Small',   'lrob-calendar') },
                                { value: 'medium', label: __('Medium',  'lrob-calendar') },
                                { value: 'large',  label: __('Large',   'lrob-calendar') },
                                { value: 'auto',   label: __('Natural', 'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ imageHeight: value })
                        })
                    )
                ),
                el('div', blockProps,
                    loading ? el(Spinner) : (
                        selectedEvent ? el(Fragment, null,
                            selectedEvent.thumbnail && el('img', {
                                src: selectedEvent.thumbnail,
                                alt: selectedEvent.title,
                                className: 'lrob-preview-thumb'
                            }),
                            el('div', { className: 'lrob-preview-content' },
                                el('strong', null, selectedEvent.title),
                                el('span', null, new Date(selectedEvent.start).toLocaleDateString()),
                                selectedEvent.venue && el('span', null, selectedEvent.venue)
                            )
                        ) : el('p', { className: 'lrob-preview-placeholder' },
                            __('Select an event in the sidebar.', 'lrob-calendar')
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
