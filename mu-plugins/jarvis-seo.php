<?php
/**
 * Plugin Name: Jarvis SEO — Meta, OG & JSON-LD
 * Description: Adds meta description, Open Graph, Twitter Card, and JSON-LD structured data site-wide.
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

function jarvis_seo_json_ld(): void {
    $site_name = get_bloginfo( 'name' ) ?: 'Gitau Healthcare Services';
    $site_url  = home_url( '/' );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';
    $schemas   = [];

    if ( is_home() || is_front_page() ) {
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type'    => [ 'LocalBusiness', 'MedicalOrganization' ],
            'name'     => $site_name,
            'description' => 'Personalised, compassionate senior care in a secure, welcoming adult family home environment in Lakewood, WA.',
            'url'      => $site_url,
            'telephone' => '(253) 905-7452',
            'image'    => $logo_url,
            'address'  => [
                '@type'           => 'PostalAddress',
                'addressLocality' => 'Lakewood',
                'addressRegion'   => 'WA',
                'addressCountry'  => 'US',
            ],
            'areaServed' => [
                '@type' => 'State',
                'name'  => 'Washington',
            ],
        ];
    }

    // BreadcrumbList for all pages except the front page.
    if ( ! is_front_page() ) {
        $items   = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Home',
                'item'     => $site_url,
            ],
        ];
        $position = 2;

        if ( is_singular() ) {
            $ancestors = get_post_ancestors( get_the_ID() );
            foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => get_the_title( $ancestor_id ),
                    'item'     => get_permalink( $ancestor_id ),
                ];
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => get_the_title(),
                'item'     => get_permalink(),
            ];

            if ( is_singular( 'post' ) ) {
                $author_id = (int) get_post_field( 'post_author' );
                $schemas[] = [
                    '@context'      => 'https://schema.org',
                    '@type'         => 'Article',
                    'headline'      => get_the_title(),
                    'url'           => get_permalink(),
                    'datePublished' => get_the_date( 'c' ),
                    'dateModified'  => get_the_modified_date( 'c' ),
                    'author'        => [
                        '@type' => 'Person',
                        'name'  => get_the_author_meta( 'display_name', $author_id ) ?: $site_name,
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name'  => $site_name,
                        'logo'  => [ '@type' => 'ImageObject', 'url' => $logo_url ],
                    ],
                    'image' => get_the_post_thumbnail_url( null, 'large' ) ?: $logo_url,
                ];
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term    = get_queried_object();
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $term ? $term->name : 'Archive',
                'item'     => $term ? (string) get_term_link( $term ) : home_url( '/' ),
            ];
        }

        $schemas[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    foreach ( $schemas as $schema ) {
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }
}
add_action( 'wp_head', 'jarvis_seo_json_ld', 6 );
