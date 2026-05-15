/**
 * Calendar block — editor component.
 * Frontend rendering happens server-side (render.php); save returns null.
 */
(function (wp) {
    const { registerBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, TextControl } = wp.components;
    const { __ } = wp.i18n;

    const data = window.lrobCalendarBlocks || { categories: [], tags: [] };
    const categoryOptions = [{ value: 0, label: __('All Categories', 'lrob-calendar') }, ...data.categories];
    const tagOptions      = [{ value: 0, label: __('All Tags', 'lrob-calendar') }, ...data.tags];

    registerBlockType('lrob-calendar/calendar', {
        edit: function (props) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps();

            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Calendar Settings', 'lrob-calendar'), initialOpen: true },
                        el(SelectControl, {
                            label: __('Layout', 'lrob-calendar'),
                            value: attributes.view,
                            options: [
                                { value: 'month',  label: __('Month grid',  'lrob-calendar') },
                                { value: 'agenda', label: __('Agenda list', 'lrob-calendar') }
                            ],
                            onChange: (value) => setAttributes({ view: value }),
                            __nextHasNoMarginBottom: true
                        }),
                        el(SelectControl, {
                            label: __('Filter by Category', 'lrob-calendar'),
                            value: attributes.category,
                            options: categoryOptions,
                            onChange: (value) => setAttributes({ category: parseInt(value, 10) }),
                            __nextHasNoMarginBottom: true
                        }),
                        el(SelectControl, {
                            label: __('Filter by Tag', 'lrob-calendar'),
                            value: attributes.tag,
                            options: tagOptions,
                            onChange: (value) => setAttributes({ tag: parseInt(value, 10) }),
                            __nextHasNoMarginBottom: true
                        }),
                        el(TextControl, {
                            label: __('Link Text', 'lrob-calendar'),
                            value: attributes.linkText,
                            placeholder: __('View event', 'lrob-calendar'),
                            onChange: (value) => setAttributes({ linkText: value }),
                            help: __('Text for the event link in popup', 'lrob-calendar'),
                            __nextHasNoMarginBottom: true
                        })
                    )
                ),
                el('div', { ...blockProps, className: blockProps.className + ' lrob-block-preview lrob-calendar-preview' },
                    el('div', { className: 'lrob-block-icon' },
                        el('span', { className: 'dashicons dashicons-calendar-alt' })
                    ),
                    el('div', { className: 'lrob-block-label' }, __('Event Calendar', 'lrob-calendar')),
                    el('div', { className: 'lrob-block-info' },
                        __('Layout:', 'lrob-calendar') + ' ' + (
                            attributes.view === 'agenda'
                                ? __('Agenda list', 'lrob-calendar')
                                : __('Month grid', 'lrob-calendar')
                        )
                    )
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp);
