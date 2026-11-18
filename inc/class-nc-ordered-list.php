<?php
/**
 * Ordered (<ol>) renderer for [nextcloud-list type="ol"].
 *
 * Supports the `start` and `reversed` shortcode attributes.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Ordered_List extends NC_List {

    protected function get_tag(): string {
        return 'ol';
    }

    protected function get_list_attributes(): array {
        $attributes = array();

        $start = (int) ( $this->atts['start'] ?? 0 );
        if ( $start !== 0 && $start !== 1 ) {
            $attributes['start'] = $start;
        }

        if ( filter_var( $this->atts['reversed'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
            $attributes['reversed'] = true;
        }

        return $attributes;
    }
}
