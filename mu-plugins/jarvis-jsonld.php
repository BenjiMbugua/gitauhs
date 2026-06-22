<?php
/**
 * Plugin Name: Jarvis SEO — JSON-LD Structured Data
 * Description: Adds schema.org JSON-LD markup (Organization, LocalBusiness, WebPage, BreadcrumbList, Article) for rich results and GEO citation.
 * Version: 1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function jarvis_jsonld_output(): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = trailingslashit( home_url( '/' ) );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';

    $org_id  = $site_url . '#organization';
    $address = [
        '@type'           => 'PostalAddress',
        'addressLocality' => 'Lakewood',
        'addressRegion'   => 'WA',
        'addressCountry'  => 'US',
    ];

    $org = [
        '@context'  => 'https://schema.org',
        '@type'     => 'Organization',
        '@id'       => $org_id,
        'name'      => $site_name,
        'url'       => $site_url,
        'logo'      => [
            '@type' => 'ImageObject',
            'url'   => $logo_url,
        ],
        'telephone' => '(253) 905-7452',
        'address'   => $address,
    ];

    $schemas = [];

    if ( is_front_page() || is_home() ) {
        $schemas[] = [
            '@context'     => 'https://schema.org',
            '@type'        => 'LocalBusiness',
            '@id'          => $site_url . '#localbusiness',
            'name'         => $site_name,
            'description'  => 'Personalized senior care at Gitau Healthcare\'s adult family home in Lakewood, WA — skilled, compassionate staff and individualized care plans tailored to every resident.',
            'url'          => $site_url,
            'telephone'    => '(253) 905-7452',
            'image'        => $logo_url,
            'logo'         => $logo_url,
            'address'      => $address,
            'priceRange'   => '$$',
            'openingHours' => 'Mo-Su 00:00-24:00',
        ];
        $schemas[] = $org;

    } elseif ( is_singular() ) {
        $post     = get_post();
        $type     = ( get_post_type() === 'post' ) ? 'Article' : 'WebPage';
        $page_url = (string) get_permalink();
        $title    = wp_strip_all_tags( (string) get_the_title() );
        $excerpt  = has_excerpt()
            ? wp_strip_all_tags( (string) get_the_excerpt() )
            : wp_strip_all_tags( (string) wp_trim_words( get_the_content(), 30, '...' ) );

        $page_schema = [
            '@context'    => 'https://schema.org',
            '@type'       => $type,
            'name'        => $title,
            'url'         => $page_url,
            'description' => $excerpt,
            'inLanguage'  => 'en-US',
            'isPartOf'    => [
                '@type' => 'WebSite',
                'name'  => $site_name,
                'url'   => $site_url,
            ],
            'publisher'   => [ '@id' => $org_id ],
        ];

        if ( $type === 'Article' ) {
            $page_schema['datePublished'] = get_the_date( 'c' );
            $page_schema['dateModified']  = get_the_modified_date( 'c' );
            $page_schema['author']        = [ '@id' => $org_id ];
        }

        $schemas[] = $page_schema;

        // BreadcrumbList
        $crumbs = [
            [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site_url ],
        ];
        if ( $post && $post->post_parent ) {
            $parent   = get_post( $post->post_parent );
            $crumbs[] = [ '@type' => 'ListItem', 'position' => 2, 'name' => wp_strip_all_tags( (string) get_the_title( $parent ) ), 'item' => (string) get_permalink( $parent ) ];
            $crumbs[] = [ '@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => $page_url ];
        } else {
            $crumbs[] = [ '@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $page_url ];
        }

        $schemas[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $crumbs,
        ];

    } elseif ( is_category() || is_tag() || is_tax() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'CollectionPage',
                'name'     => $term->name,
                'url'      => (string) get_term_link( $term ),
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name'  => $site_name,
                    'url'   => $site_url,
                ],
            ];
        }
        $schemas[] = $org;

    } else {
        $schemas[] = $org;
    }

    foreach ( $schemas as $schema ) {
        echo '<script type="application/ld+json">'
            . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            . "</script>\n";
    }
}
add_action( 'wp_head', 'jarvis_jsonld_output', 6 );
