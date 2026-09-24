<?php
/**
 * DOM-based Content Extraction and Cleaning for Shaheen Central MXChat Sync
 *
 * @package ShaheenCentralMXChatSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shaheen_Content_Extractor {

	/**
	 * Default sensitive keywords for review flagging.
	 *
	 * @return array
	 */
	public static function get_default_sensitive_keywords() {
		return array(
			'fee',
			'fees',
			'tuition',
			'admission deadline',
			'admission deadlines',
			'application deadline',
			'application deadlines',
			'cutoff',
			'cutoffs',
			'cut-off',
			'eligibility',
			'scholarship',
			'scholarships',
			'academic year',
			'academic years',
			'medical programme',
			'medical program',
			'programme duration',
			'program duration',
			'admission requirement',
			'admission requirements',
		);
	}

	/**
	 * Extract and clean content from raw HTML.
	 *
	 * @param string $html
	 * @param string $fallback_url
	 * @return array
	 */
	public static function extract( $html, $fallback_url = '' ) {
		if ( empty( $html ) ) {
			return array(
				'title'                  => '',
				'canonical_url'          => $fallback_url,
				'last_modified'          => null,
				'headings'               => array(),
				'cleaned_text'           => '',
				'faq_items'              => array(),
				'sensitive_flags'        => array(),
				'is_empty_or_low_quality' => true,
			);
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		// Convert to UTF-8 HTML entity handling
		$html_utf8 = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		@$dom->loadHTML( $html_utf8, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// 1. Extract Canonical URL from <link rel="canonical">
		$canonical_url = $fallback_url;
		$canonical_nodes = $xpath->query( '//link[@rel="canonical"]/@href' );
		if ( $canonical_nodes->length > 0 ) {
			$found_canonical = trim( (string) $canonical_nodes->item( 0 )->nodeValue );
			if ( ! empty( $found_canonical ) ) {
				$canonical_url = $found_canonical;
			}
		}

		// 2. Extract Last Modified Date from meta tags
		$last_modified = null;
		$date_queries = array(
			'//meta[@property="article:modified_time"]/@content',
			'//meta[@name="last-modified"]/@content',
			'//meta[@property="og:updated_time"]/@content',
			'//meta[@name="date"]/@content',
		);
		foreach ( $date_queries as $dq ) {
			$nodes = $xpath->query( $dq );
			if ( $nodes->length > 0 ) {
				$val = trim( (string) $nodes->item( 0 )->nodeValue );
				$ts = strtotime( $val );
				if ( false !== $ts ) {
					$last_modified = gmdate( 'Y-m-d H:i:s', $ts );
					break;
				}
			}
		}

		// 3. Extract Page Title
		$title = '';
		$h1_nodes = $xpath->query( '//h1' );
		if ( $h1_nodes->length > 0 ) {
			$title = trim( $h1_nodes->item( 0 )->textContent );
		}
		if ( empty( $title ) ) {
			$title_nodes = $xpath->query( '//title' );
			if ( $title_nodes->length > 0 ) {
				$title = trim( $title_nodes->item( 0 )->textContent );
				// Clean off site suffix like " - Shaheen Group"
				$parts = preg_split( '/[\-\|\–\—]/', $title );
				if ( ! empty( $parts[0] ) ) {
					$title = trim( $parts[0] );
				}
			}
		}

		// 4. Extract FAQ elements before stripping (e.g. schema.org FAQPage, details, accordion)
		$faq_items = self::extract_faqs( $xpath );

		// 5. Remove unwanted elements from the DOM
		self::strip_unwanted_elements( $xpath, $dom );

		// 6. Find Main Content container
		$main_container = self::find_main_container( $xpath );

		// 7. Extract Headings from main container
		$headings = array();
		if ( $main_container ) {
			$h_nodes = $xpath->query( './/h1 | .//h2 | .//h3 | .//h4', $main_container );
			foreach ( $h_nodes as $hn ) {
				$txt = trim( preg_replace( '/\s+/', ' ', $hn->textContent ) );
				if ( ! empty( $txt ) && ! in_array( $txt, $headings, true ) ) {
					$headings[] = $txt;
				}
			}
		}

		// 8. Convert content container to structured, clean text
		$cleaned_text = '';
		if ( $main_container ) {
			$cleaned_text = self::node_to_cleaned_text( $main_container );
		}

		// Clean multiple spaces and blank lines
		$cleaned_text = preg_replace( '/\n{3,}/', "\n\n", trim( $cleaned_text ) );

		// 9. Quality / emptiness check (< 30 words or empty)
		$word_count = str_word_count( strip_tags( $cleaned_text ) );
		$is_low_quality = ( $word_count < 30 );

		// 10. Scan for sensitive keywords
		$sensitive_flags = self::scan_sensitive_terms( $cleaned_text . ' ' . $title . ' ' . implode( ' ', $headings ) );

		return array(
			'title'                   => $title,
			'canonical_url'           => $canonical_url,
			'last_modified'           => $last_modified,
			'headings'                => $headings,
			'cleaned_text'            => $cleaned_text,
			'faq_items'               => $faq_items,
			'sensitive_flags'         => $sensitive_flags,
			'is_empty_or_low_quality' => $is_low_quality,
			'word_count'              => $word_count,
		);
	}

	/**
	 * Strip boilerplate, navigation, footers, scripts, styles, popups, cookie notices.
	 *
	 * @param DOMXPath    $xpath
	 * @param DOMDocument $dom
	 */
	private static function strip_unwanted_elements( DOMXPath $xpath, DOMDocument $dom ) {
		// Tag names to remove directly
		$unwanted_tags = array(
			'script',
			'style',
			'noscript',
			'iframe',
			'svg',
			'nav',
			'header',
			'footer',
			'aside',
			'form',
			'select',
			'button',
			'input',
			'textarea',
		);

		foreach ( $unwanted_tags as $tag ) {
			$nodes = $xpath->query( '//' . $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Class/ID patterns to remove: cookies, popups, banners, sidebars, social share, breadcrumbs, ads
		$unwanted_classes = array(
			'cookie',
			'consent',
			'gdpr',
			'popup',
			'modal',
			'overlay',
			'advertisement',
			'sidebar',
			'social-share',
			'share-button',
			'breadcrumb',
			'menu',
			'navigation',
			'widget-area',
		);

		foreach ( $unwanted_classes as $class_term ) {
			$query = "//*[contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{$class_term}') or contains(translate(@id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{$class_term}')]";
			$nodes = $xpath->query( $query );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				// Guard: do not remove <body> or <main> if they happen to have a matching class
				if ( $node && $node->parentNode && ! in_array( strtolower( $node->nodeName ), array( 'body', 'html', 'main' ), true ) ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Remove hidden elements
		$hidden_nodes = $xpath->query( '//*[@hidden or @aria-hidden="true" or contains(@style, "display:none") or contains(@style, "display: none")]' );
		for ( $i = $hidden_nodes->length - 1; $i >= 0; $i-- ) {
			$node = $hidden_nodes->item( $i );
			if ( $node && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Locate best main content node.
	 *
	 * @param DOMXPath $xpath
	 * @return DOMNode|null
	 */
	private static function find_main_container( DOMXPath $xpath ) {
		$candidate_queries = array(
			'//article',
			'//main',
			'//*[contains(@class, "entry-content")]',
			'//*[contains(@class, "post-content")]',
			'//*[contains(@class, "page-content")]',
			'//*[contains(@class, "content-area")]',
			'//*[@id="content"]',
			'//div[contains(@class, "content")]',
			'//body',
		);

		foreach ( $candidate_queries as $cq ) {
			$nodes = $xpath->query( $cq );
			if ( $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}

		return null;
	}

	/**
	 * Convert DOMNode subtree to clean markdown-like text preserving headings, lists, tables.
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private static function node_to_cleaned_text( DOMNode $node ) {
		$text = '';

		if ( $node->nodeType === XML_TEXT_NODE ) {
			$val = preg_replace( '/[ \t]+/', ' ', $node->nodeValue );
			return $val;
		}

		if ( $node->nodeType === XML_ELEMENT_NODE ) {
			$tag = strtolower( $node->nodeName );

			switch ( $tag ) {
				case 'h1':
					return "\n\n# " . trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) . "\n\n";
				case 'h2':
					return "\n\n## " . trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) . "\n\n";
				case 'h3':
					return "\n\n### " . trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) . "\n\n";
				case 'h4':
				case 'h5':
				case 'h6':
					return "\n\n#### " . trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) . "\n\n";
				case 'p':
					$inner = '';
					foreach ( $node->childNodes as $child ) {
						$inner .= self::node_to_cleaned_text( $child );
					}
					$inner = trim( preg_replace( '/\s+/', ' ', $inner ) );
					return ! empty( $inner ) ? "\n\n" . $inner . "\n\n" : '';
				case 'li':
					$inner = '';
					foreach ( $node->childNodes as $child ) {
						$inner .= self::node_to_cleaned_text( $child );
					}
					$inner = trim( preg_replace( '/\s+/', ' ', $inner ) );
					return ! empty( $inner ) ? "\n* " . $inner : '';
				case 'br':
					return "\n";
				case 'tr':
					$row = array();
					foreach ( $node->childNodes as $child ) {
						if ( $child->nodeType === XML_ELEMENT_NODE && in_array( strtolower( $child->nodeName ), array( 'td', 'th' ), true ) ) {
							$row[] = trim( preg_replace( '/\s+/', ' ', $child->textContent ) );
						}
					}
					return ! empty( $row ) ? "\n| " . implode( ' | ', $row ) . ' |' : '';
				default:
					$inner = '';
					foreach ( $node->childNodes as $child ) {
						$inner .= self::node_to_cleaned_text( $child );
					}
					return $inner;
			}
		}

		return '';
	}

	/**
	 * Extract FAQs from details tags or Q&A structures.
	 *
	 * @param DOMXPath $xpath
	 * @return array
	 */
	private static function extract_faqs( DOMXPath $xpath ) {
		$faqs = array();

		// HTML5 <details><summary>
		$details = $xpath->query( '//details' );
		foreach ( $details as $det ) {
			$summary = $xpath->query( './/summary', $det );
			if ( $summary->length > 0 ) {
				$q = trim( $summary->item( 0 )->textContent );
				$a = trim( str_replace( $q, '', $det->textContent ) );
				if ( ! empty( $q ) && ! empty( $a ) ) {
					$faqs[] = array(
						'question' => $q,
						'answer'   => preg_replace( '/\s+/', ' ', $a ),
					);
				}
			}
		}

		return $faqs;
	}

	/**
	 * Scan text for sensitive keywords.
	 *
	 * @param string $text
	 * @return array
	 */
	public static function scan_sensitive_terms( $text ) {
		$text_lower = strtolower( $text );
		$keywords = self::get_configured_sensitive_keywords();
		$matched = array();

		foreach ( $keywords as $kw ) {
			$kw_lower = strtolower( trim( $kw ) );
			if ( empty( $kw_lower ) ) {
				continue;
			}

			// Exact word boundary matching where feasible
			if ( preg_match( '/\b' . preg_quote( $kw_lower, '/' ) . '\b/i', $text_lower ) ) {
				$matched[] = $kw;
			}
		}

		return array_unique( $matched );
	}

	/**
	 * Get configured or default sensitive keywords.
	 *
	 * @return array
	 */
	public static function get_configured_sensitive_keywords() {
		$saved = get_option( 'shaheen_sync_sensitive_keywords', '' );
		if ( empty( $saved ) ) {
			return self::get_default_sensitive_keywords();
		}

		$lines = explode( "\n", str_replace( "\r", '', $saved ) );
		$list = array();
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( ! empty( $t ) ) {
				$list[] = $t;
			}
		}

		return ! empty( $list ) ? array_unique( $list ) : self::get_default_sensitive_keywords();
	}
}
