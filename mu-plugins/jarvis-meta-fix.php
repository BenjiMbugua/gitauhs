<?php
/**
 * Plugin Name: Jarvis Meta Fix — gitauhs.com
 * Description: Keeps SEO-plugin meta descriptions ≤ 160 chars (WEZ-638, WEZ-760).
 * Version:     1.1.0
 */

defined( 'ABSPATH' ) || exit;

// Yoast SEO
add_filter( 'wpseo_metadesc', 'gitauhs_fix_home_meta_desc' );

// RankMath
add_filter( 'rank_math/frontend/description', 'gitauhs_fix_home_meta_desc' );

// All in One SEO
add_filter( 'aioseo_description', 'gitauhs_fix_home_meta_desc' );

/**
 * None of the SEO plugins above are installed today — jarvis-seo.php emits the
 * tags. These filters are a guard for the day one of them is activated, so the
 * canonical copy and the 160-char budget stay owned by jarvis-seo.php.
 */
function gitauhs_fix_home_meta_desc( $desc ): string {
	$desc = is_front_page() ? JARVIS_SEO_DEFAULT_DESC : (string) $desc;

	return function_exists( 'jarvis_seo_clamp_description' )
		? jarvis_seo_clamp_description( $desc )
		: $desc;
}
