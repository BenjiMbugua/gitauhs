<?php
/**
 * Plugin Name: Jarvis SEO — Meta & OG Tags
 * Description: Adds meta description, Open Graph, Twitter Card tags, and JSON-LD structured data site-wide.
 * Version: 1.3
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

function jarvis_json_ld(): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = rtrim( home_url( '/' ), '/' );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';

    $schemas = [];

    // NursingHome/Organization entity — present on every page for GEO entity disambiguation
    $schemas[] = [
        '@context'    => 'https://schema.org',
        '@type'       => 'NursingHome',
        '@id'         => $site_url . '/#organization',
        'name'        => $site_name,
        'url'         => $site_url . '/',
        'logo'        => [
            '@type' => 'ImageObject',
            'url'   => $logo_url,
        ],
        'description' => 'Gitau Healthcare is a licensed adult family home in Lakewood, WA providing personalised Memory Care, High Acuity Care, and Medication Management.',
        'telephone'   => '(253) 905-7452',
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Lakewood',
            'addressRegion'   => 'WA',
            'addressCountry'  => 'US',
        ],
    ];

    if ( is_home() || is_front_page() ) {
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            '@id'         => $site_url . '/#website',
            'url'         => $site_url . '/',
            'name'        => $site_name,
            'description' => get_bloginfo( 'description' ) ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.',
            'publisher'   => [ '@id' => $site_url . '/#organization' ],
        ];

    } elseif ( is_singular() ) {
        $permalink = get_permalink();
        $title     = get_the_title();

        $schemas[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Home',
                    'item'     => $site_url . '/',
                ],
                [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => $title,
                    'item'     => $permalink,
                ],
            ],
        ];

        if ( is_page( 'about' ) ) {
            $desc = $GLOBALS['jarvis_page_descriptions'][ get_the_ID() ] ?? '';
            $schemas[] = [
                '@context'    => 'https://schema.org',
                '@type'       => 'AboutPage',
                '@id'         => $permalink . '#webpage',
                'url'         => $permalink,
                'name'        => $title . ' | ' . $site_name,
                'description' => $desc,
                'isPartOf'    => [ '@id' => $site_url . '/#website' ],
                'about'       => [ '@id' => $site_url . '/#organization' ],
            ];
        }
    }

    foreach ( $schemas as $schema ) {
        echo '<script type="application/ld+json">' . "\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe JSON
        echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        echo "\n</script>\n";
    }
}
add_action( 'wp_head', 'jarvis_json_ld', 6 );
