<?php
/**
 * Frontend Shortcode: [nextcloud-list]
 *
 * Renders the files of a Nextcloud folder as a plain HTML list with
 * download links (no JavaScript). Folders are not listed.
 *
 * Usage:
 *   [nextcloud-list path="Documents"]                     — <ul> of all files
 *   [nextcloud-list path="Documents" type="ol"]           — <ol> instead of <ul>
 *   [nextcloud-list path="Documents" extensions="pdf,docx"]
 *   [nextcloud-list path="Reports" orderby="modified" order="desc" limit="5"]
 *
 * Links go through the WordPress download proxy using time-limited tokens
 * scoped to `path`, so Nextcloud credentials are never exposed. As with
 * [nextcloud], a `path` is required for non-admin visitors.
 *
 * Additional list types can be registered via the `wpnc_list_types` filter
 * by mapping a type key to a class extending NC_List.
 *
 * @package WPNC
 */

namespace WPNC;

defined( 'ABSPATH' ) || exit;

class NC_List_Shortcode {

    const SHORTCODE  = 'nextcloud-list';
    const CAPABILITY = 'manage_options';

    public function __construct() {
        add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
    }

    /**
     * Render the [nextcloud-list] shortcode.
     *
     * @param array|string $atts  Shortcode attributes.
     * @return string  HTML output.
     */
    public function render( $atts ): string {
        $can_manage = current_user_can( self::CAPABILITY );

        $atts = shortcode_atts( array(
            'path'       => '',
            'type'       => 'ul',
            'orderby'    => 'name',
            'order'      => 'asc',
            'limit'      => 0,
            'extensions' => '',
            'show_size'  => 'false',
            'class'      => '',
            'start'      => '',
            'reversed'   => 'false',
        ), $atts, self::SHORTCODE );

        $path = trim( sanitize_text_field( $atts['path'] ), '/' );

        if ( ! self::is_safe_path( $path ) ) {
            return $this->notice( __( 'Invalid path.', 'wp-nc-bridge' ) );
        }

        // Never expose the whole Nextcloud account publicly — require an explicit path.
        if ( ! $can_manage && $path === '' ) {
            return $this->notice( __( 'You do not have permission to access this file list.', 'wp-nc-bridge' ) );
        }

        $renderer = $this->get_renderer( $atts );
        if ( ! $renderer ) {
            return $can_manage
                ? $this->notice( sprintf( __( 'Unknown list type "%s".', 'wp-nc-bridge' ), $atts['type'] ) )
                : '';
        }

        $client = Nextcloud_Client::from_settings();
        $items  = is_wp_error( $client ) ? $client : $client->list_folder( $path );

        if ( is_wp_error( $items ) ) {
            return $this->notice( $can_manage
                ? $items->get_error_message()
                : __( 'The file list is currently unavailable.', 'wp-nc-bridge' ) );
        }

        $items = $this->prepare_items( $items, $atts );
        if ( empty( $items ) ) {
            return '<p class="wpnc-list-empty">' . esc_html__( 'No documents found.', 'wp-nc-bridge' ) . '</p>';
        }

        $nonce = $can_manage ? wp_create_nonce( NC_Ajax::NONCE_ACTION ) : '';
        foreach ( $items as &$item ) {
            $item['url'] = $this->download_url( $path === '' ? $item['name'] : $path . '/' . $item['name'], $path, $nonce );
        }
        unset( $item );

        return $renderer->render( $items );
    }

    /**
     * Resolve the renderer for the requested list type.
     *
     * @param array $atts  Shortcode attributes.
     * @return NC_List|null
     */
    protected function get_renderer( array $atts ) {
        /**
         * Filter the available list types.
         *
         * @param array $types  Map of type key => class name extending NC_List.
         */
        $types = apply_filters( 'wpnc_list_types', array(
            'ul' => NC_Unordered_List::class,
            'ol' => NC_Ordered_List::class,
        ) );

        $type  = strtolower( sanitize_key( $atts['type'] ) );
        $class = $types[ $type ] ?? null;

        if ( ! $class || ! class_exists( $class ) || ! is_subclass_of( $class, NC_List::class ) ) {
            return null;
        }

        return new $class( array_merge( $atts, array(
            'show_size' => filter_var( $atts['show_size'], FILTER_VALIDATE_BOOLEAN ),
        ) ) );
    }

    /**
     * Keep files only, filter by extension, sort and limit.
     *
     * @param array $items  Items from Nextcloud_Client::list_folder().
     * @param array $atts   Shortcode attributes.
     * @return array
     */
    protected function prepare_items( array $items, array $atts ): array {
        $extensions = array_filter( array_map(
            function ( $ext ) {
                return strtolower( ltrim( trim( $ext ), '.' ) );
            },
            explode( ',', (string) $atts['extensions'] )
        ) );

        $items = array_values( array_filter( $items, function ( $item ) use ( $extensions ) {
            if ( ( $item['type'] ?? '' ) !== 'file' ) {
                return false;
            }
            if ( empty( $extensions ) ) {
                return true;
            }
            return in_array( strtolower( pathinfo( $item['name'], PATHINFO_EXTENSION ) ), $extensions, true );
        } ) );

        $orderby = strtolower( $atts['orderby'] );
        usort( $items, function ( $a, $b ) use ( $orderby ) {
            switch ( $orderby ) {
                case 'modified':
                    return strtotime( $a['modified'] ?? '' ) <=> strtotime( $b['modified'] ?? '' );
                case 'size':
                    return (int) ( $a['size'] ?? 0 ) <=> (int) ( $b['size'] ?? 0 );
                default:
                    return strnatcasecmp( $a['name'], $b['name'] );
            }
        } );

        if ( strtolower( $atts['order'] ) === 'desc' ) {
            $items = array_reverse( $items );
        }

        $limit = absint( $atts['limit'] );
        if ( $limit > 0 ) {
            $items = array_slice( $items, 0, $limit );
        }

        return $items;
    }

    /**
     * Build a download-proxy URL for a file.
     *
     * Visitors get a token scoped to the shortcode path. Admins additionally
     * get a nonce, because the proxy verifies a nonce for them instead.
     */
    protected function download_url( string $file_path, string $scope, string $nonce ): string {
        if ( $nonce === '' ) {
            return NC_Ajax::public_download_url( $file_path, $scope );
        }

        return admin_url( 'admin-ajax.php' ) . '?' . http_build_query( array(
            'action' => 'wpnc_download_proxy',
            'path'   => sanitize_text_field( ltrim( $file_path, '/' ) ),
            '_nonce' => $nonce,
        ), '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Reject `.` / `..` segments so the list cannot escape its folder.
     */
    protected static function is_safe_path( string $path ): bool {
        foreach ( preg_split( '#[/\\\\]#', $path ) as $segment ) {
            if ( $segment === '.' || $segment === '..' ) {
                return false;
            }
        }
        return true;
    }

    private function notice( string $message ): string {
        return '<p class="wpnc-noaccess">' . esc_html( $message ) . '</p>';
    }
}
