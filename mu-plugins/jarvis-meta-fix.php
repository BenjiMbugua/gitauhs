<?php
/**
 * Plugin Name: Jarvis Meta Fix — gitauhs.com
 * Description: Enforces homepage meta description ≤ 160 chars (WEZ-638).
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

const GITAUHS_HOME_META_DESC = 'Compassionate Adult Family Home care by Gitau Healthcare Services. Personalized support for your loved ones in a safe, nurturing environment.';

// Yoast SEO
add_filter( 'wpseo_metadesc', 'gitauhs_fix_home_meta_desc' );

// RankMath
add_filter( 'rank_math/frontend/description', 'gitauhs_fix_home_meta_desc' );

// All in One SEO
add_filter( 'aioseo_description', 'gitauhs_fix_home_meta_desc' );

function gitauhs_fix_home_meta_desc( string $desc ): string {
	return is_front_page() ? GITAUHS_HOME_META_DESC : $desc;
}
