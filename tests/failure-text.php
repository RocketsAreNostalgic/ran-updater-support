<?php
/**
 * Contract, corpus and property tests for the shared failure-text screener.
 *
 * @package RAN\UpdaterSupport\V1
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/FailureText.php';

use RAN\UpdaterSupport\V1\FailureText;

( static function (): void {
	$fail = static function ( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( 'failure-text: ' . $message );
	};

	/** @var array{hostile: array<string, string>, benign: array<string, string>} $corpus */
	$corpus  = require __DIR__ . '/fixtures/failure-text-corpus.php';
	$bounds  = array( 1, 2, 8, 24, 64, 120, 255, 1024, FailureText::MAX_INPUT_LENGTH );
	$hostile = $corpus['hostile'];
	$benign  = $corpus['benign'];

	// The release updater vendors a namespace-rewritten copy, which requires exactly one namespace occurrence,
	// and the class must stay free of any WordPress dependency.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local screener source only.
	$source = file_get_contents( dirname( __DIR__ ) . '/src/FailureText.php' );
	if ( ! is_string( $source ) || 1 !== substr_count( $source, 'RAN\\UpdaterSupport\\V1' ) || 1 === preg_match( '#\b(?:wp_|add_action|add_filter|apply_filters|ABSPATH|__\()#', $source ) ) {
		$fail( 'the screener source is not a single-namespace, WordPress-free file' );
	}

	if ( array() !== array_intersect( $hostile, $benign ) ) {
		$fail( 'a string is both hostile and benign' );
	}

	// Hostile corpus: dropped whole at every bound, because the whole text is screened before it is bounded.
	foreach ( $hostile as $label => $text ) {
		foreach ( $bounds as $bound ) {
			if ( null !== FailureText::screen( $text, $bound ) ) {
				$fail( "hostile text was returned: {$label} (bound {$bound})" );
			}
		}
	}

	// Benign corpus: returned verbatim, and as an exact bounded prefix at smaller bounds.
	foreach ( $benign as $label => $text ) {
		if ( FailureText::screen( $text, FailureText::MAX_INPUT_LENGTH ) !== $text ) {
			$fail( "benign text was dropped: {$label}" );
		}
		foreach ( $bounds as $bound ) {
			$expected = rtrim( substr( $text, 0, $bound ) );
			if ( FailureText::screen( $text, $bound ) !== $expected ) {
				$fail( "benign text was not an exact prefix: {$label} (bound {$bound})" );
			}
		}
	}

	// Caller misuse fails closed.
	foreach ( array( 0, -1, FailureText::MAX_INPUT_LENGTH + 1, PHP_INT_MAX, PHP_INT_MIN ) as $bound ) {
		if ( null !== FailureText::screen( 'Plugin Name', $bound ) ) {
			$fail( "an invalid bound was accepted: {$bound}" );
		}
	}

	// The validating idiom: equality shows the text was safe, within the bound and untrimmed.
	if ( FailureText::screen( 'Plugin Name', 11 ) !== 'Plugin Name' || FailureText::screen( 'Plugin Name', 10 ) === 'Plugin Name' ) {
		$fail( 'the validating idiom does not distinguish a bounded result' );
	}
	if ( FailureText::screen( '  Plugin Name  ', 40 ) !== 'Plugin Name' ) {
		$fail( 'surrounding spaces are not trimmed' );
	}
	if ( 'Plugin' !== FailureText::screen( 'Plugin Name', 7 ) ) {
		$fail( 'a bounded result keeps a trailing space' );
	}

	// Commit ids: the narrow opt-in. Screening still drops them.
	$sha1   = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
	$sha256 = hash( 'sha256', 'x' );
	foreach ( array( $sha1, $sha256 ) as $id ) {
		if ( FailureText::commit_id( $id ) !== $id || null !== FailureText::screen( $id, 255 ) ) {
			$fail( 'a commit id is not opt-in only' );
		}
	}
	$not_ids = array(
		'empty'          => '',
		'short'          => substr( $sha1, 0, 39 ),
		'forty one'      => $sha1 . 'a',
		'sixty three'    => substr( $sha256, 0, 63 ),
		'sixty five'     => $sha256 . 'a',
		'uppercase'      => strtoupper( $sha1 ),
		'non hex'        => str_repeat( 'g', 40 ),
		'trailing lf'    => $sha1 . "\n",
		'leading space'  => ' ' . $sha1,
		'trailing space' => $sha1 . ' ',
		'prefixed'       => 'sha1:' . $sha1,
		'two ids'        => $sha1 . $sha1,
		'path'           => '/' . $sha1,
	);
	foreach ( $not_ids as $label => $text ) {
		if ( null !== FailureText::commit_id( $text ) ) {
			$fail( "commit_id accepted: {$label}" );
		}
	}

	// Deterministic pseudo-random stream: no global random state, the same cases on every run.
	$state = 20261112;
	$next  = static function ( int $limit ) use ( &$state ): int {
		$state = ( $state * 1103515245 + 12345 ) & 0x7FFFFFFF;

		return ( $state >> 8 ) % $limit;
	};

	$printable = '';
	for ( $code = 0x20; $code <= 0x7E; ++$code ) {
		$printable .= chr( $code );
	}
	$pad = static function () use ( $next, $printable ): string {
		$out = '';
		for ( $length = $next( 40 ); $length > 0; --$length ) {
			$out .= $printable[ $next( strlen( $printable ) ) ];
		}

		return $out;
	};

	// Property 1: a hostile shape set off by spaces from any surroundings is always dropped, at any bound.
	$hostile_values = array_values( array_filter( $hostile, static fn( string $value ): bool => '' !== trim( $value ) ) );
	for ( $case = 0; $case < 3000; ++$case ) {
		$text  = $pad() . ' ' . $hostile_values[ $next( count( $hostile_values ) ) ] . ' ' . $pad();
		$bound = $bounds[ $next( count( $bounds ) ) ];
		if ( null !== FailureText::screen( $text, $bound ) ) {
			$fail( 'a hostile shape inside other text was returned' );
		}
	}

	// Property 2: whatever is returned is deterministic, bounded, printable ASCII, a prefix of the trimmed input,
	// idempotent, and free of the structural characters and long hex runs, judged by an independent oracle.
	$words    = array( 'plugin', 'archive', 'failed', 'Version', 'Requires', 'PHP', '8.2', 'http_404', 'package', 'missing', 'header', 'invalid', 'zip' );
	$accepted = 0;
	for ( $case = 0; $case < 6000; ++$case ) {
		if ( 0 === $case % 2 ) {
			$text = $pad();
		} else {
			$parts = array();
			for ( $count = 1 + $next( 14 ); $count > 0; --$count ) {
				$parts[] = 0 === $next( 6 ) ? $pad() : $words[ $next( count( $words ) ) ];
			}
			$text = implode( ' ', $parts );
		}
		$bound  = 1 + $next( 160 );
		$result = FailureText::screen( $text, $bound );
		if ( FailureText::screen( $text, $bound ) !== $result ) {
			$fail( 'the screen is not deterministic' );
		}
		if ( null === $result ) {
			continue;
		}
		++$accepted;
		$trimmed = trim( $text, ' ' );
		if ( 1 !== preg_match( '#\A[\x20-\x7E]+\z#', $result ) || strlen( $result ) > $bound || trim( $result, ' ' ) !== $result ) {
			$fail( 'the result breaks the charset, bound or trim contract' );
		}
		if ( ! str_starts_with( $trimmed, $result ) ) {
			$fail( 'the result is not a prefix of the screened text' );
		}
		if ( FailureText::screen( $result, $bound ) !== $result ) {
			$fail( 'the screen is not idempotent' );
		}
		if ( 1 === preg_match( '#[<>&{}\[\]|;`=@/\\\\~]|%[0-9a-fA-F]{2}|:\S|\?\S#', $result ) ) {
			$fail( 'the result holds a structural character' );
		}
		if ( 1 === preg_match( '#[0-9a-f]{32}#i', (string) preg_replace( '#[^0-9A-Za-z]+#', '', $result ) ) ) {
			$fail( 'the result holds a long hex run' );
		}
	}
	if ( $accepted < 200 ) {
		$fail( 'the generated population accepted too little text to prove anything' );
	}

	// Property 3: bounding never changes what is accepted, only its length.
	foreach ( $benign as $label => $text ) {
		$limit = strlen( $text ) + 2;
		for ( $bound = 1; $bound <= $limit; ++$bound ) {
			$result = FailureText::screen( $text, $bound );
			if ( null === $result || ! str_starts_with( $text, $result ) || strlen( $result ) > $bound ) {
				$fail( "bounding changed acceptance: {$label} (bound {$bound})" );
			}
		}
	}

	echo 'failure-text: ' . count( $hostile ) . ' hostile, ' . count( $benign ) . ' benign, ' . $accepted . " accepted generated cases\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI evidence line, never HTML.
} )();
