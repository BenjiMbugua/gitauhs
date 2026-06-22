<?php
/**
 * Plugin Name: Jarvis SEO — Meta & OG Tags
 * Description: Adds meta description, Open Graph, and Twitter Card tags site-wide.
 * Version: 1.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Per-page custom meta descriptions (keyed by post ID).
$GLOBALS['jarvis_page_descriptions'] = [
    20 => 'Personalized senior care at Gitau Healthcare\'s adult family home in Lakewood, WA — skilled, compassionate staff and individualized care plans tailored to every resident.',
    21 => 'Explore Gitau Healthcare\'s comprehensive services: Memory Care, High Acuity Care, Medication Management, specialised dining, wheelchair-accessible rooms, and memory-care amenities. Compassionate care tailored to every resident. Call (253) 905-7452.',
];

function jarvis_seo_meta_tags(): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = home_url( '/' );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';

    if ( is_singular() ) {
        $post_id     = get_the_ID();
        $custom_desc = $GLOBALS['jarvis_page_descriptions'][ $post_id ] ?? '';
        $title       = get_the_title() . ' | ' . $site_name;
        $description = $custom_desc
            ?: ( has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30, '...' ) );
        $url         = get_permalink();
        $image       = get_the_post_thumbnail_url( null, 'large' ) ?: $logo_url;
        $type        = 'article';

    } elseif ( is_category() || is_tag() || is_tax() ) {
        $term        = get_queried_object();
        $term_name   = $term ? $term->name : '';
        $term_desc   = $term ? wp_strip_all_tags( $term->description ) : '';
        $title       = ( $term_name ? $term_name . ' | ' : '' ) . $site_name;
        $description = $term_desc
            ?: ( get_bloginfo( 'description' )
                ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.' );
        $url         = $term ? get_term_link( $term ) : esc_url( home_url( $_SERVER['REQUEST_URI'] ) );
        $image       = $logo_url;
        $type        = 'website';

    } elseif ( is_home() || is_front_page() ) {
        $title       = $site_name . ' | ' . get_bloginfo( 'description' );
        $description = get_bloginfo( 'description' )
            ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.';
        $url         = $site_url;
        $image       = $logo_url;
        $type        = 'website';

    } else {
        $title       = wp_get_document_title() ?: $site_name;
        $description = get_bloginfo( 'description' )
            ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.';
        $url         = esc_url( ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
        $image       = $logo_url;
        $type        = 'website';
    }

    $description = esc_attr( wp_strip_all_tags( $description ) );
    $title       = esc_attr( $title );
    $url         = esc_url( is_string( $url ) ? $url : '' );
    $image       = esc_url( $image );

    echo '<meta name="description" content="' . $description . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
    echo '<meta property="og:title" content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:url" content="' . $url . '">' . "\n";
    echo '<meta property="og:image" content="' . $image . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '">' . "\n";
    echo '<meta name="twitter:image" content="' . $image . '">' . "\n";
}
add_action( 'wp_head', 'jarvis_seo_meta_tags', 5 );

/**
 * Inject a screen-reader-only H1 on pages that lack one.
 * Targets the Contact Us page (slug: contact-us) which the Jarvis
 * scanner found has h1_count = 0.
 */
function jarvis_inject_h1_css(): void {
    if ( ! ( is_page( 'contact-us' ) || is_page( 'contact' ) ) ) {
        return;
    }
    echo '<style>.jarvis-sr-h1{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}</style>' . "\n";
}
add_action( 'wp_head', 'jarvis_inject_h1_css', 7 );

function jarvis_inject_h1_content( string $content ): string {
    if (
        ! is_main_query()
        || ! in_the_loop()
        || ! ( is_page( 'contact-us' ) || is_page( 'contact' ) )
    ) {
        return $content;
    }
    $h1 = '<h1 class="jarvis-sr-h1">' . esc_html( get_the_title() ) . '</h1>';
    return $h1 . $content;
}
add_filter( 'the_content', 'jarvis_inject_h1_content' );
