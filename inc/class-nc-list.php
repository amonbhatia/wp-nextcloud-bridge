<?php
/**
 * Base renderer for [nextcloud-list] output.
 *
 * Turns a list of Nextcloud file items into an HTML list with download
 * links. Subclasses decide the list element (<ul>, <ol>, …) and may add
 * list-level attributes or customise how a single item is rendered.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

abstract class NC_List {

    /** @var array Normalised shortcode attributes. */
    protected $atts;

    /**
     * @param array $atts  Shortcode attributes (already merged with defaults).
     */
    public function __construct( array $atts ) {
        $this->atts = $atts;
    }

    /**
     * HTML tag name of the list element, e.g. "ul" or "ol".
     */
    abstract protected function get_tag(): string;

    /**
     * Extra attributes for the list element (name => value).
     * A value of `true` renders a boolean attribute.
     *
     * @return array
     */
    protected function get_list_attributes(): array {
        return array();
    }

    /**
     * Render the full list.
     *
     * @param array $items  Items, each with at least `name` and `url`; optional `size`.
     * @return string  HTML.
     */
    public function render( array $items ): string {
        $tag     = tag_escape( $this->get_tag() );
        $classes = array( 'wpnc-list', 'wpnc-list-' . $tag );
        if ( ! empty( $this->atts['class'] ) ) {
            $classes = array_merge( $classes, array_map( 'sanitize_html_class', preg_split( '/\s+/', $this->atts['class'] ) ) );
        }

        $attributes = array_merge(
            array( 'class' => implode( ' ', array_filter( $classes ) ) ),
            $this->get_list_attributes()
        );

        $html = '<' . $tag . $this->build_attributes( $attributes ) . '>';
        foreach ( $items as $item ) {
            $html .= $this->render_item( $item );
        }
        $html .= '</' . $tag . '>';

        return $html;
    }

    /**
     * Render a single list item.
     *
     * @param array $item  Item with `name`, `url` and optional `size`.
     * @return string  HTML.
     */
    protected function render_item( array $item ): string {
        $html  = '<li class="wpnc-list-item">';
        $html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';

        if ( ! empty( $this->atts['show_size'] ) && isset( $item['size'] ) ) {
            $html .= ' <span class="wpnc-list-size">(' . esc_html( size_format( (int) $item['size'] ) ) . ')</span>';
        }

        return $html . '</li>';
    }

    /**
     * Build an escaped HTML attribute string.
     */
    protected function build_attributes( array $attributes ): string {
        $out = '';
        foreach ( $attributes as $name => $value ) {
            if ( $value === false || $value === null ) {
                continue;
            }
            $out .= ' ' . esc_attr( $name );
            if ( $value !== true ) {
                $out .= '="' . esc_attr( (string) $value ) . '"';
            }
        }
        return $out;
    }
}
