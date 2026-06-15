<?php
defined( 'ABSPATH' ) || exit;

/**
 * KaliCart_MCP_Markdown
 *
 * Converts rendered post HTML into clean, LLM-friendly Markdown.
 * Self-contained: DOMDocument only, no external libraries, no network.
 */
class KaliCart_MCP_Markdown {

	public static function from_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$doc->loadHTML(
			'<?xml encoding="UTF-8"><div id="kcmcp-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $doc->getElementById( 'kcmcp-root' );
		if ( ! $root ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		return trim( self::tidy( self::walk_children( $root ) ) );
	}

	/**
	 * Normalise verbose / page-builder markup: strip per-line indentation,
	 * collapse inner whitespace runs, drop trailing spaces and triple newlines.
	 * Fenced code blocks are left byte-for-byte. Structural, not theme-specific.
	 */
	private static function tidy( string $md ): string {
		$parts = preg_split( '/(```.*?```)/s', $md, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && 0 === strpos( $part, '```' ) ) {
				continue;
			}
			$part        = preg_replace( '/^[ \t]+/m', '', $part );
			$part        = preg_replace( '/[ \t]{2,}/', ' ', $part );
			$part        = preg_replace( '/[ \t]+$/m', '', $part );
			$parts[ $i ] = $part;
		}
		$md = implode( '', $parts );
		return preg_replace( "/\n{3,}/", "\n\n", $md );
	}

	private static function walk_children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node_to_md( $child );
		}
		return $out;
	}

	private static function node_to_md( \DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return preg_replace( '/\s+/', ' ', $node->nodeValue );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'script':
			case 'style':
			case 'noscript':
			case 'template':
				return '';

			case 'h1': return "\n\n# "      . trim( self::walk_children( $node ) ) . "\n\n";
			case 'h2': return "\n\n## "     . trim( self::walk_children( $node ) ) . "\n\n";
			case 'h3': return "\n\n### "    . trim( self::walk_children( $node ) ) . "\n\n";
			case 'h4': return "\n\n#### "   . trim( self::walk_children( $node ) ) . "\n\n";
			case 'h5': return "\n\n##### "  . trim( self::walk_children( $node ) ) . "\n\n";
			case 'h6': return "\n\n###### " . trim( self::walk_children( $node ) ) . "\n\n";

			case 'p':
				return "\n\n" . trim( self::walk_children( $node ) ) . "\n\n";

			case 'br':
				return "\n";

			case 'hr':
				return "\n\n---\n\n";

			case 'strong':
			case 'b':
				$t = trim( self::walk_children( $node ) );
				return '' === $t ? '' : '**' . $t . '**';

			case 'em':
			case 'i':
				$t = trim( self::walk_children( $node ) );
				return '' === $t ? '' : '*' . $t . '*';

			case 'code':
				$t = trim( self::walk_children( $node ) );
				return '' === $t ? '' : '`' . $t . '`';

			case 'pre':
				return "\n\n```\n" . trim( $node->textContent ) . "\n```\n\n";

			case 'blockquote':
				$inner = trim( self::walk_children( $node ) );
				$q     = array();
				foreach ( preg_split( "/\n/", $inner ) as $line ) {
					$q[] = '> ' . $line;
				}
				return "\n\n" . implode( "\n", $q ) . "\n\n";

			case 'a':
				$text = trim( self::walk_children( $node ) );
				$href = $node instanceof \DOMElement ? trim( (string) $node->getAttribute( 'href' ) ) : '';
				if ( '' === $text ) {
					return '';
				}
				if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) ) {
					return $text;
				}
				return '[' . $text . '](' . $href . ')';

			case 'img':
				if ( ! $node instanceof \DOMElement ) {
					return '';
				}
				$src = trim( (string) $node->getAttribute( 'src' ) );
				$alt = trim( (string) $node->getAttribute( 'alt' ) );
				return '' === $src ? '' : '![' . $alt . '](' . $src . ')';

			case 'ul':
				return "\n\n" . self::list_items( $node, false ) . "\n";

			case 'ol':
				return "\n\n" . self::list_items( $node, true ) . "\n";

			case 'table':
				return "\n\n" . self::table_to_md( $node ) . "\n\n";

			default:
				return self::walk_children( $node );
		}
	}

	private static function list_items( \DOMNode $list, bool $ordered ): string {
		$out = '';
		$i   = 1;
		foreach ( $list->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
				$marker = $ordered ? ( $i . '. ' ) : '- ';
				$text   = trim( preg_replace( "/\s*\n\s*/", ' ', self::walk_children( $child ) ) );
				$out   .= $marker . $text . "\n";
				$i++;
			}
		}
		return $out;
	}

	/**
	 * HTML table -> GitHub-flavoured Markdown pipe table. The first row (a thead
	 * row when present, otherwise the first body row) becomes the header, as GFM
	 * requires a header + separator. Cell content is flattened to one line and
	 * pipes are escaped.
	 */
	private static function table_to_md( \DOMNode $table ): string {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $c ) {
				if ( XML_ELEMENT_NODE !== $c->nodeType ) {
					continue;
				}
				$tag = strtolower( $c->nodeName );
				if ( 'td' !== $tag && 'th' !== $tag ) {
					continue;
				}
				$text    = trim( preg_replace( '/\s+/', ' ', self::walk_children( $c ) ) );
				$text    = str_replace( '|', '\|', $text );
				$cells[] = $text;
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}
		if ( empty( $rows ) ) {
			return '';
		}

		$cols = 0;
		foreach ( $rows as $r ) {
			$cols = max( $cols, count( $r ) );
		}

		$out    = array();
		$header = array_pad( array_shift( $rows ), $cols, '' );
		$out[]  = '| ' . implode( ' | ', $header ) . ' |';
		$out[]  = '| ' . implode( ' | ', array_fill( 0, $cols, '---' ) ) . ' |';
		foreach ( $rows as $r ) {
			$out[] = '| ' . implode( ' | ', array_pad( $r, $cols, '' ) ) . ' |';
		}
		return implode( "\n", $out );
	}
}
