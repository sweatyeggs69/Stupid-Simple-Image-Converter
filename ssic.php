<?php
/*
Plugin Name: Stupid Simple Image Converter
Description: Automatically convert uploaded PNG, JPG, and GIF images to WebP format.
Version: 1.3.1
Author: Dynamic Technologies
Author URI: https://bedynamic.tech
Plugin URI: https://github.com/bedynamictech/Stupid-Simple-Image-Converter
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Constants ────────────────────────────────────────────────────────────────

define( 'SSIC_QUALITY_OPTIONS', [ 50, 85, 100 ] );
define( 'SSIC_DEFAULT_QUALITY', 85 );
define( 'SSIC_META_KEY',        'ssic_converted' );
define( 'SSIC_MENU_SLUG',       'stupidsimple' );
define( 'SSIC_PAGE_SLUG',       'ssic-settings' );

// ─── Settings ─────────────────────────────────────────────────────────────────

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ssic_action_links' );
function ssic_action_links( $links ) {
    array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=' . SSIC_PAGE_SLUG ) . '">Settings</a>' );
    return $links;
}

add_action( 'admin_init', 'ssic_register_settings' );
function ssic_register_settings() {
    register_setting( 'ssic_settings_group', 'ssic_quality', [
        'type'              => 'integer',
        'default'           => SSIC_DEFAULT_QUALITY,
        'sanitize_callback' => function( $val ) {
            return in_array( (int) $val, SSIC_QUALITY_OPTIONS, true ) ? (int) $val : SSIC_DEFAULT_QUALITY;
        },
    ] );
}

// ─── Admin Menu ───────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'ssic_add_menu' );
function ssic_add_menu() {
    global $menu;

    $parent_exists = false;
    foreach ( $menu as $item ) {
        if ( ! empty( $item[2] ) && $item[2] === SSIC_MENU_SLUG ) {
            $parent_exists = true;
            break;
        }
    }

    if ( ! $parent_exists ) {
        add_menu_page( 'Stupid Simple', 'Stupid Simple', 'manage_options', SSIC_MENU_SLUG, 'ssic_settings_page', 'dashicons-hammer', 99 );
    }

    add_submenu_page( SSIC_MENU_SLUG, 'Image Converter', 'Image Converter', 'manage_options', SSIC_PAGE_SLUG, 'ssic_settings_page' );
}

// ─── Conversion ───────────────────────────────────────────────────────────────

add_filter( 'wp_generate_attachment_metadata', 'ssic_on_metadata', 10, 2 );
function ssic_on_metadata( $metadata, $attachment_id ) {
    ssic_convert_to_webp( $attachment_id );
    return $metadata;
}

add_action( 'add_attachment', 'ssic_convert_to_webp' );
function ssic_convert_to_webp( $attachment_id ) {
    if ( get_post_meta( $attachment_id, SSIC_META_KEY, true ) ) return;
    if ( ! wp_attachment_is_image( $attachment_id ) ) return;

    $file = get_attached_file( $attachment_id );
    $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

    if ( $ext === 'webp' ) return;

    $dest = preg_replace( '/\.[^.]+$/', '.webp', $file );

    if ( file_exists( $dest ) ) {
        update_post_meta( $attachment_id, SSIC_META_KEY, time() );
        return;
    }

    $quality = (int) get_option( 'ssic_quality', SSIC_DEFAULT_QUALITY );
    $ok      = false;

    if ( class_exists( 'Imagick' ) ) {
        try {
            $imagick = new Imagick( $file );
            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( $quality );
            $ok = $imagick->writeImage( $dest );
            $imagick->clear();
            $imagick->destroy();
        } catch ( Exception $e ) {}
    }

    if ( ! $ok && function_exists( 'imagewebp' ) ) {
        $img = match( $ext ) {
            'jpg', 'jpeg' => imagecreatefromjpeg( $file ),
            'png'         => imagecreatefrompng( $file ),
            'gif'         => imagecreatefromgif( $file ),
            default       => false,
        };

        if ( $img ) {
            if ( function_exists( 'imagepalettetotruecolor' ) ) {
                imagepalettetotruecolor( $img );
            }
            $ok = imagewebp( $img, $dest, $quality );
            imagedestroy( $img );
        }
    }

    if ( $ok ) {
        update_post_meta( $attachment_id, SSIC_META_KEY, time() );
    }
}

// ─── Serve WebP URLs ──────────────────────────────────────────────────────────

add_filter( 'wp_get_attachment_url',       'ssic_serve_webp',   10, 2 );
add_filter( 'wp_get_attachment_image_src', 'ssic_src_webp',     10, 4 );
add_filter( 'wp_calculate_image_srcset',   'ssic_srcset_webp',  10, 5 );

function ssic_webp_url( $url ) {
    $upload   = wp_get_upload_dir();
    $path     = str_replace( $upload['baseurl'], $upload['basedir'], $url );
    $webp     = preg_replace( '/\.[^.]+$/', '.webp', $path );
    return file_exists( $webp ) ? preg_replace( '/\.[^.]+$/', '.webp', $url ) : $url;
}

function ssic_serve_webp( $url, $id ) {
    $file = get_attached_file( $id );
    $webp = preg_replace( '/\.[^.]+$/', '.webp', $file );
    return file_exists( $webp ) ? preg_replace( '/\.[^.]+$/', '.webp', $url ) : $url;
}

function ssic_src_webp( $image, $id, $size, $icon ) {
    if ( ! empty( $image[0] ) ) {
        $image[0] = ssic_webp_url( $image[0] );
    }
    return $image;
}

function ssic_srcset_webp( $sources, $size, $src, $meta, $id ) {
    foreach ( $sources as $w => $s ) {
        $sources[ $w ]['url'] = ssic_webp_url( $s['url'] );
    }
    return $sources;
}

// ─── Bulk Convert ─────────────────────────────────────────────────────────────

add_action( 'admin_post_ssic_mass_convert', 'ssic_handle_mass_convert' );
function ssic_handle_mass_convert() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ssic_mass_convert' ) ) {
        wp_die( 'Unauthorized request.' );
    }

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'numberposts'    => -1,
        'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif' ],
    ] );

    $processed = 0;
    foreach ( $attachments as $att ) {
        if ( get_post_meta( $att->ID, SSIC_META_KEY, true ) ) continue;
        ssic_convert_to_webp( $att->ID );
        $processed++;
    }

    wp_safe_redirect( add_query_arg( 'ssic_converted', $processed, wp_get_referer() ) );
    exit;
}

// ─── Admin Notices ────────────────────────────────────────────────────────────

add_action( 'admin_notices', 'ssic_bulk_convert_notice' );
function ssic_bulk_convert_notice() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, SSIC_PAGE_SLUG ) === false ) return;
    if ( ! isset( $_GET['ssic_converted'] ) ) return;

    $n = intval( $_GET['ssic_converted'] );
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        sprintf( esc_html__( 'Done — converted %d image(s) to WebP.', 'ssic' ), $n )
    );
}

// ─── Settings Page ────────────────────────────────────────────────────────────

function ssic_settings_page() {
    $quality      = (int) get_option( 'ssic_quality', SSIC_DEFAULT_QUALITY );
    $opts         = SSIC_QUALITY_OPTIONS;
    $slider_index = array_search( $quality, $opts ) !== false ? array_search( $quality, $opts ) : 1;
    $total        = wp_count_posts( 'attachment' );
    $convertible  = (int) ( $total->inherit ?? 0 );
    ?>
    <div class="wrap ssic-wrap">

        <h1>Image Converter</h1>

        <div class="ssic-grid">

            <div class="ssic-card">
                <h2>Conversion Quality</h2>
                <p class="ssic-card-desc">Select the WebP output quality. Lower values produce smaller file sizes; 100% is lossless.</p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'ssic_settings_group' ); ?>
                    <div class="ssic-slider-wrap">
                        <div class="ssic-slider-labels">
                            <span>50%</span><span>85%</span><span>100%</span>
                        </div>
                        <input type="range" id="ssic_quality_slider" min="0" max="2" step="1" value="<?php echo esc_attr( $slider_index ); ?>" aria-label="Image quality" />
                        <input type="hidden" name="ssic_quality" id="ssic_quality" value="<?php echo esc_attr( $quality ); ?>" />
                    </div>
                    <?php submit_button( 'Save Quality', 'primary ssic-btn', 'submit', false ); ?>
                </form>
            </div>

            <div class="ssic-card">
                <h2>Bulk Convert</h2>
                <p class="ssic-card-desc">Convert all existing JPG, PNG, and GIF images in your media library that haven't been converted yet.</p>
                <div class="ssic-stat">
                    <span class="ssic-stat-num"><?php echo esc_html( $convertible ); ?></span>
                    <span class="ssic-stat-label">attachments in library</span>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ssic_mass_convert' ); ?>
                    <input type="hidden" name="action" value="ssic_mass_convert" />
                    <?php submit_button( 'Convert Existing Images', 'secondary ssic-btn', 'submit', false ); ?>
                </form>
            </div>

        </div>

        <div style="position:fixed;bottom:24px;right:24px;"><a href="https://ko-fi.com/T6T61SZTST" target="_blank"><img height="36" style="border:0;height:36px;" src="https://storage.ko-fi.com/cdn/kofi6.png?v=6" alt="Buy Me a Coffee at ko-fi.com" /></a></div>
    </div>

    <style>
    .ssic-wrap { max-width: 860px; margin-top: 24px; }

    .ssic-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .ssic-card {
        background: #fff;
        border: 1px solid #dcdcdc;
        border-radius: 6px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,.05);
    }
    .ssic-card h2       { margin: 0 0 8px; font-size: 15px; }
    .ssic-card-desc     { color: #666; font-size: 13px; margin: 0 0 20px; line-height: 1.5; }

    .ssic-slider-wrap   { margin-bottom: 20px; }
    .ssic-slider-labels { display: flex; justify-content: space-between; font-size: 12px; color: #888; margin-bottom: 4px; }

    #ssic_quality_slider {
        width: 100%;
        accent-color: #2271b1;
        cursor: pointer;
    }

    .ssic-stat {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 20px;
    }
    .ssic-stat-num   { font-size: 28px; font-weight: 700; color: #2271b1; line-height: 1; }
    .ssic-stat-label { font-size: 13px; color: #666; }

    .ssic-btn { margin-top: 0 !important; }

    @media ( max-width: 700px ) {
        .ssic-grid { grid-template-columns: 1fr; }
    }
    </style>

    <script>
    (function () {
        var opts   = <?php echo json_encode( $opts ); ?>;
        var slider = document.getElementById( 'ssic_quality_slider' );
        var hidden = document.getElementById( 'ssic_quality' );

        slider.addEventListener( 'input', function () {
            hidden.value = opts[ this.value ];
        } );
    } )();
    </script>
    <?php
}