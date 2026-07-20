<?php
/**
 * Plugin Name: Jarvis SEO — Meta, OG & JSON-LD
 * Description: Adds meta description, Open Graph, Twitter Card, and JSON-LD structured data site-wide.
 * Version: 1.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Below this length a meta description reads as thin to search engines (WEZ-759).
const JARVIS_SEO_DESC_MIN = 70;

// Ceiling for a padded description, so padding can never overshoot.
const JARVIS_SEO_DESC_MAX = 160;

// Site-wide fallback copy, reused as padding for thin posts.
const JARVIS_SEO_DEFAULT_DESC = 'Gitau Healthcare — personalised, compassionate senior care in a secure, welcoming environment.';

// Per-page custom meta descriptions (keyed by post ID).
$GLOBALS['jarvis_page_descriptions'] = [
    20 => 'Personalized senior care at Gitau Healthcare\'s adult family home in Lakewood, WA — skilled, compassionate staff and individualized care plans tailored to every resident.',
    21 => 'Explore Gitau Healthcare\'s comprehensive services: Memory Care, High Acuity Care, Medication Management, specialised dining, wheelchair-accessible rooms, and memory-care amenities. Compassionate care tailored to every resident. Call (253) 905-7452.',
];

/**
 * Pad a derived description up to a usable length.
 *
 * A post with no excerpt and a short body (e.g. /test-post-title-2/, 17 chars)
 * otherwise emits that body verbatim as the meta description. Append the site
 * blurb when the text is too thin, then cap on a word boundary so padding can
 * never push it past the 160-char budget.
 */
function jarvis_seo_pad_description( string $description ): string {
    $description = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $description ) ) );

    if ( mb_strlen( $description ) < JARVIS_SEO_DESC_MIN ) {
        $description = $description === ''
            ? JARVIS_SEO_DEFAULT_DESC
            : rtrim( $description, ' .…' ) . '. ' . JARVIS_SEO_DEFAULT_DESC;
    }

    if ( mb_strlen( $description ) <= JARVIS_SEO_DESC_MAX ) {
        return $description;
    }

    // Rebuild word by word: mb_strrpos has no core polyfill, and byte offsets
    // from strrpos would cut multibyte characters (the blurb contains an em dash).
    $capped = '';
    foreach ( explode( ' ', $description ) as $word ) {
        $candidate = $capped === '' ? $word : $capped . ' ' . $word;
        if ( mb_strlen( $candidate ) > JARVIS_SEO_DESC_MAX - 1 ) {
            break;
        }
        $capped = $candidate;
    }

    return rtrim( $capped, ' ,;:—-' ) . '…';
}

function jarvis_seo_meta_tags(): void {
    $site_name = get_bloginfo( 'name' );
    $site_url  = home_url( '/' );
    $logo_url  = get_template_directory_uri() . '/assets/images/logo.png';

    if ( is_singular() ) {
        $post_id     = get_the_ID();
        $custom_desc = $GLOBALS['jarvis_page_descriptions'][ $post_id ] ?? '';
        $title       = get_the_title() . ' | ' . $site_name;
        $description = $custom_desc
            ?: jarvis_seo_pad_description(
                has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30, '...' )
            );
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
