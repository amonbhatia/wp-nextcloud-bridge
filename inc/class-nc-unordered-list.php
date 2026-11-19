<?php
/**
 * Unordered (<ul>) renderer for [nextcloud-list type="ul"].
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_Unordered_List extends NC_List {

    protected function get_tag(): string {
        return 'ul';
    }
}
