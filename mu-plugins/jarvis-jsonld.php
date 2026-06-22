<?php
/**
 * Plugin Name: Jarvis SEO — JSON-LD Structured Data
 * Description: Adds schema.org JSON-LD markup (Organization, MedicalBusiness, BreadcrumbList) site-wide.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function jarvis_json_ld(): void {
    $site_url  = home_url( '/' );
    $site_name = get_bloginfo( 'name' ) ?: 'Gitau Healthcare Services';

    $org = [
        '@context' => 'https://schema.org',
        '@type'    => 'MedicalBusiness',
        '@id'      => $site_url . '#organization',
        'name'     => $site_name,
        'url'      => $site_url,
        'telephone' => '+12539057452',
        'address'  => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Lakewood',
            'addressRegion'   => 'WA',
            'addressCountry'  => 'US',
        ],
        'description' => 'Gitau Healthcare Services is a licensed adult family home in Lakewood, WA, providing personalised, compassionate senior care including Memory Care, High Acuity Care, and Medication Management.',
        'medicalSpecialty' => 'Nursing',
        'image' => get_template_directory_uri() . '/assets/images/logo.png',
    ];

    $graphs = [ $org ];

    // Services page (post ID 21): add hasOfferCatalog + BreadcrumbList.
    if ( is_page( 21 ) ) {
        $org['hasOfferCatalog'] = [
            '@type' => 'OfferCatalog',
            'name'  => 'Senior Care Services',
            'itemListElement' => array_map(
                fn( $name ) => [
                    '@type'        => 'Offer',
                    'itemOffered'  => [ '@type' => 'Service', 'name' => $name ],
                ],
                [
                    'Memory Care',
                    'High Acuity Care',
                    'Medication Management',
                    'Specialised Senior Dining',
                    'Wheelchair-Accessible Accommodation',
                    'Memory-Care Amenities',
                ]
            ),
        ];
        $graphs[0] = $org;

        $graphs[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Home',
                    'item'     => $site_url,
                ],
                [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => 'Services',
                    'item'     => $site_url . 'services/',
                ],
            ],
        ];
    }

    // Contact page: add ContactPoint to org + BreadcrumbList.
    if ( is_page( 'contact-us' ) || is_page( 'contact' ) ) {
        $graphs[0]['contactPoint'] = [
            '@type'             => 'ContactPoint',
            'telephone'         => '+12539057452',
            'contactType'       => 'customer service',
            'areaServed'        => 'US',
            'availableLanguage' => 'English',
        ];

        $graphs[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Home',
                    'item'     => $site_url,
                ],
                [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => 'Contact Us',
                    'item'     => $site_url . 'contact-us/',
                ],
            ],
        ];
    }

    // Homepage / front page: add BreadcrumbList for root.
    if ( is_home() || is_front_page() ) {
        $graphs[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => 'Home',
                    'item'     => $site_url,
                ],
            ],
        ];
    }

    foreach ( $graphs as $graph ) {
        echo '<script type="application/ld+json">' . "\n"
            . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
            . "\n</script>\n";
    }
}
add_action( 'wp_head', 'jarvis_json_ld', 6 );
