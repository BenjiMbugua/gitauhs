<?php
/**
 * Plugin Name: Jarvis SEO — Meta, OG & JSON-LD
 * Description: Adds meta description, Open Graph, Twitter Card, and JSON-LD structured data site-wide.
 * Version: 1.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// SERP truncation budgets (WEZ-751).
const JARVIS_SEO_TITLE_MAX    = 65;
const JARVIS_SEO_DESC_MAX     = 160;
// Below this, the site-name suffix is dropped rather than starving the page title.
const JARVIS_SEO_TITLE_MIN    = 20;
const JARVIS_SEO_TITLE_SEP    = ' – ';

// Per-page custom meta descriptions (keyed by post ID).
$GLOBALS['jarvis_page_descriptions'] = [
    20 => 'Personalized senior care at Gitau Healthcare\'s adult family home in Lakewood, WA — skilled, compassionate staff and individualized care plans tailored to every resident.',
    21 => 'Explore Gitau Healthcare\'s comprehensive services: Memory Care, High Acuity Care, Medication Management, specialised dining, wheelchair-accessible rooms, and memory-care amenities. Compassionate care tailored to every resident. Call (253) 905-7452.',
];

/**
 * Collapse entities and whitespace, then clip to $max chars on a word boundary.
 * Multibyte-safe: titles and excerpts on this site contain accented characters,
 * so a byte-wise substr would cut mid-codepoint.
 */
function jarvis_seo_clip( string $text, int $max ): string {
    $text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    // html_entity_decode turns &nbsp; into U+00A0, which \s does not match.
    $text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );

    if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
        return $text;
    }

    // Reserve one char for the ellipsis.
    $clipped = mb_substr( $text, 0, $max - 1, 'UTF-8' );
    $space   = mb_strrpos( $clipped, ' ', 0, 'UTF-8' );
    if ( false !== $space && $space >= (int) ( $max / 2 ) ) {
        $clipped = mb_substr( $clipped, 0, $space, 'UTF-8' );
    }

    return rtrim( $clipped, " \t\n\r\0\x0B.,;:–—-" ) . '…';
}

/**
 * Build a "<page> – <site>" title that fits JARVIS_SEO_TITLE_MAX.
 * Drops the site-name suffix entirely when keeping it would leave the page
 * title unreadably short.
 */
function jarvis_seo_build_title( string $page_title, string $site_name ): string {
    $page_title = jarvis_seo_clip( $page_title, JARVIS_SEO_TITLE_MAX );
    $site_name  = jarvis_seo_clip( $site_name, JARVIS_SEO_TITLE_MAX );

    if ( '' === $site_name ) {
        return $page_title;
    }

    $suffix_len = mb_strlen( JARVIS_SEO_TITLE_SEP . $site_name, 'UTF-8' );
    $budget     = JARVIS_SEO_TITLE_MAX - $suffix_len;

    if ( $budget < JARVIS_SEO_TITLE_MIN ) {
        return $page_title;
    }

    return jarvis_seo_clip( $page_title, $budget ) . JARVIS_SEO_TITLE_SEP . $site_name;
}

/**
 * WP core builds the <title> from the raw post title, which overflows SERP on
 * long posts. Rebuild it through the same budget used for og:title.
 */
function jarvis_seo_document_title( $title ) {
    $site_name = (string) get_bloginfo( 'name' );

    if ( is_front_page() || is_home() ) {
        $tagline = (string) get_bloginfo( 'description' );
        return $tagline
            ? jarvis_seo_build_title( $site_name, $tagline )
            : jarvis_seo_clip( $site_name, JARVIS_SEO_TITLE_MAX );
    }

    if ( is_singular() ) {
        return jarvis_seo_build_title( (string) get_the_title(), $site_name );
    }

    if ( is_category() || is_tag() || is_tax() ) {
        $term = get_queried_object();
        if ( $term && ! empty( $term->name ) ) {
            return jarvis_seo_build_title( (string) $term->name, $site_name );
        }
    }

    return is_string( $title ) ? jarvis_seo_clip( $title, JARVIS_SEO_TITLE_MAX ) : $title;
}
add_filter( 'pre_get_document_title', 'jarvis_seo_document_title', 20 );

function jarvis_seo_meta_tags(): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = home_url( '/' );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';

    if ( is_singular() ) {
        $post_id     = get_the_ID();
        $custom_desc = $GLOBALS['jarvis_page_descriptions'][ $post_id ] ?? '';
        $title       = jarvis_seo_build_title( (string) get_the_title(), $site_name );
        $description = $custom_desc
            ?: ( has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30, '...' ) );
        $url         = get_permalink();
        $image       = get_the_post_thumbnail_url( null, 'large' ) ?: $logo_url;
        $type        = 'article';

    } elseif ( is_category() || is_tag() || is_tax() ) {
        $term        = get_queried_object();
        $term_name   = $term ? $term->name : '';
        $term_desc   = $term ? wp_strip_all_tags( $term->description ) : '';
        $title       = $term_name
            ? jarvis_seo_build_title( $term_name, $site_name )
            : jarvis_seo_clip( $site_name, JARVIS_SEO_TITLE_MAX );
        $description = $term_desc
            ?: ( get_bloginfo( 'description' )
                ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.' );
        $url         = $term ? get_term_link( $term ) : esc_url( home_url( $_SERVER['REQUEST_URI'] ) );
        $image       = $logo_url;
        $type        = 'website';

    } elseif ( is_home() || is_front_page() ) {
        $title       = jarvis_seo_build_title( $site_name, (string) get_bloginfo( 'description' ) );
        $description = get_bloginfo( 'description' )
            ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.';
        $url         = $site_url;
        $image       = $logo_url;
        $type        = 'website';

    } else {
        $title       = jarvis_seo_clip( wp_get_document_title() ?: $site_name, JARVIS_SEO_TITLE_MAX );
        $description = get_bloginfo( 'description' )
            ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.';
        $url         = esc_url( ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
        $image       = $logo_url;
        $type        = 'website';
    }

    $description = esc_attr( jarvis_seo_clip( (string) $description, JARVIS_SEO_DESC_MAX ) );
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

    $organization = [
        '@context'    => 'https://schema.org',
        '@type'       => [ 'MedicalOrganization', 'LocalBusiness' ],
        '@id'         => $site_url . '#organization',
        'name'        => $site_name,
        'description' => 'Personalised, compassionate senior care in a secure, welcoming adult family home environment in Lakewood, WA.',
        'url'         => $site_url,
        'telephone'   => '(253) 905-7452',
        'image'       => $logo_url,
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Lakewood',
            'addressRegion'   => 'WA',
            'addressCountry'  => 'US',
        ],
        'areaServed'  => [
            '@type' => 'State',
            'name'  => 'Washington',
        ],
    ];

    if ( is_home() || is_front_page() ) {
        $schemas[] = $organization;
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            '@id'         => $site_url . '#website',
            'name'        => $site_name,
            'url'         => $site_url,
            'description' => get_bloginfo( 'description' )
                ?: 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.',
            'publisher'   => [ '@id' => $site_url . '#organization' ],
        ];

    } elseif ( is_category() || is_tag() || is_tax() ) {
        $term      = get_queried_object();
        $term_name = $term ? $term->name : 'Archive';
        $term_desc = $term ? wp_strip_all_tags( $term->description ) : '';
        $term_url  = $term ? (string) get_term_link( $term ) : home_url( '/' );

        // CollectionPage gives search engines a typed anchor for the archive.
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => $term_name . ' | ' . $site_name,
            'description' => $term_desc
                ?: 'Articles and resources about ' . $term_name . ' from ' . $site_name . '.',
            'url'         => $term_url,
            'publisher'   => [
                '@type' => 'Organization',
                'name'  => $site_name,
                'logo'  => [ '@type' => 'ImageObject', 'url' => $logo_url ],
            ],
        ];

        // Organization on archive pages helps GEO citation.
        $schemas[] = $organization;

    } elseif ( is_page( 'about' ) || is_page( 'about-us' ) ) {
        $schemas[] = $organization;
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'AboutPage',
            '@id'         => get_permalink() . '#webpage',
            'url'         => get_permalink(),
            'name'        => get_the_title() . ' | ' . $site_name,
            'description' => $GLOBALS['jarvis_page_descriptions'][ get_the_ID() ]
                ?? 'Learn about Gitau Healthcare and its adult family home care services in Lakewood, Washington.',
            'isPartOf'    => [ '@id' => $site_url . '#website' ],
            'about'       => [ '@id' => $site_url . '#organization' ],
        ];

    } elseif ( is_page( 'services' ) ) {
        $schemas[] = $organization;
        $schemas[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Service',
            'name'        => 'Adult Family Home Care Services',
            'url'         => get_permalink(),
            'description' => $GLOBALS['jarvis_page_descriptions'][ get_the_ID() ]
                ?? 'Memory Care, High Acuity Care, Medication Management, specialised dining, wheelchair-accessible rooms, and memory-care amenities from Gitau Healthcare.',
            'provider'    => [ '@id' => $site_url . '#organization' ],
            'areaServed'  => [
                '@type' => 'State',
                'name'  => 'Washington',
            ],
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name'  => 'Gitau Healthcare Services',
                'itemListElement' => [
                    jarvis_seo_service_offer( 'Memory Care', 'Personalised care for residents with memory-care needs.' ),
                    jarvis_seo_service_offer( 'High Acuity Care', 'Support for residents with higher daily care needs.' ),
                    jarvis_seo_service_offer( 'Medication Management', 'Medication support as part of individualised resident care plans.' ),
                    jarvis_seo_service_offer( 'Specialised Dining', 'Dining support and meal accommodations for resident needs.' ),
                ],
            ],
        ];

    } elseif ( is_singular( 'post' ) ) {
        $schemas[] = $organization;
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
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => $site_name,
                'logo'  => [ '@type' => 'ImageObject', 'url' => $logo_url ],
            ],
            'image'         => get_the_post_thumbnail_url( null, 'large' ) ?: $logo_url,
        ];
    }

    // BreadcrumbList for every page except the front page.
    if ( ! is_front_page() ) {
        $items    = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Home',
                'item'     => $site_url,
            ],
        ];
        $position = 2;

        if ( is_singular() ) {
            foreach ( array_reverse( get_post_ancestors( get_the_ID() ) ) as $ancestor_id ) {
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
        echo '<script type="application/ld+json">'
            . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            . '</script>' . "\n";
    }
}
add_action( 'wp_head', 'jarvis_seo_json_ld', 6 );

function jarvis_seo_service_offer( string $name, string $description ): array {
    return [
        '@type'       => 'Offer',
        'itemOffered' => [
            '@type'       => 'Service',
            'name'        => $name,
            'description' => $description,
        ],
    ];
}

function jarvis_seo_inject_h1_css(): void {
    if ( ! ( is_page( 'contact-us' ) || is_page( 'contact' ) || is_page( 'about' ) || is_page( 'about-us' ) ) ) {
        return;
    }

    echo '<style>.jarvis-sr-h1{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}</style>' . "\n";
}
add_action( 'wp_head', 'jarvis_seo_inject_h1_css', 7 );

function jarvis_seo_inject_h1_content( string $content ): string {
    if (
        ! is_main_query()
        || ! in_the_loop()
        || ! ( is_page( 'contact-us' ) || is_page( 'contact' ) || is_page( 'about' ) || is_page( 'about-us' ) )
    ) {
        return $content;
    }

    $h1 = '<h1 class="jarvis-sr-h1">' . esc_html( get_the_title() ) . '</h1>';
    return $h1 . $content;
}
add_filter( 'the_content', 'jarvis_seo_inject_h1_content' );
