<?php
/**
 * Content Classifier for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Classifier {

	/**
	 * Allowed classification categories.
	 */
	const TYPE_PAGE       = 'page';
	const TYPE_PROGRAMME  = 'programme';
	const TYPE_ADMISSIONS = 'admissions';
	const TYPE_FAQ        = 'FAQ';
	const TYPE_POST       = 'post';

	/**
	 * Classify a record based on URL, title, headings, FAQ presence, and sitemap type.
	 *
	 * @param string $url
	 * @param string $title
	 * @param array  $headings
	 * @param array  $faq_items
	 * @param string $source_sitemap
	 * @return string One of page, programme, admissions, FAQ, post
	 */
	public static function classify( $url, $title = '', $headings = array(), $faq_items = array(), $source_sitemap = '' ) {
		$url_lower     = strtolower( $url );
		$title_lower   = strtolower( $title );
		$heading_text  = strtolower( implode( ' ', (array) $headings ) );
		$sitemap_lower = strtolower( $source_sitemap );

		// 1. Check for FAQ structure or FAQ keywords
		if ( ! empty( $faq_items ) ||
			strpos( $url_lower, '/faq' ) !== false ||
			strpos( $url_lower, '/faqs' ) !== false ||
			strpos( $title_lower, 'faq' ) !== false ||
			strpos( $title_lower, 'frequently asked questions' ) !== false ||
			strpos( $heading_text, 'frequently asked questions' ) !== false
		) {
			return self::TYPE_FAQ;
		}

		// 2. Check for Admissions keywords
		$admissions_keywords = array( 'admission', 'admissions', 'apply', 'enroll', 'enrollment', 'how-to-apply', 'eligibility-criteria' );
		foreach ( $admissions_keywords as $kw ) {
			if (
				strpos( $url_lower, '/' . $kw ) !== false ||
				strpos( $url_lower, '-' . $kw ) !== false ||
				strpos( $title_lower, $kw ) !== false ||
				preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $heading_text )
			) {
				return self::TYPE_ADMISSIONS;
			}
		}

		// 3. Check for Programme / Course keywords
		$programme_keywords = array( 'programme', 'program', 'courses', 'course', 'curriculum', 'academics', 'degrees', 'syllabus' );
		foreach ( $programme_keywords as $kw ) {
			if (
				strpos( $url_lower, '/' . $kw ) !== false ||
				strpos( $url_lower, '-' . $kw ) !== false ||
				strpos( $title_lower, $kw ) !== false ||
				preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $heading_text )
			) {
				return self::TYPE_PROGRAMME;
			}
		}

		// 4. Check for Post / Blog / News
		if (
			strpos( $sitemap_lower, 'posts-post' ) !== false ||
			strpos( $sitemap_lower, 'post-sitemap' ) !== false ||
			strpos( $url_lower, '/news/' ) !== false ||
			strpos( $url_lower, '/blog/' ) !== false ||
			preg_match( '#/[0-9]{4}/[0-9]{2}/#', $url_lower ) // Year/month WP post permalink
		) {
			return self::TYPE_POST;
		}

		// Default fallback
		return self::TYPE_PAGE;
	}
}
