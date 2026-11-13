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

	/** @var array{hostile: array<string, string>, strict_only: array<string, string>, display_only: array<string, string>, benign: array<string, string>, identifiers: array<string, string>, not_identifiers: array<string, string>} $corpus */
	$corpus       = require __DIR__ . '/fixtures/failure-text-corpus.php';
	$bounds       = array( 1, 2, 8, 24, 64, 120, 255, 1024, FailureText::MAX_INPUT_LENGTH );
	$hostile      = $corpus['hostile'];
	$strict_only  = $corpus['strict_only'];
	$display_only = $corpus['display_only'];
	$benign       = $corpus['benign'];
	$modes        = array(
		'strict'  => static fn( string $text, int $bound ): ?string => FailureText::strict( $text, $bound ),
		'display' => static fn( string $text, int $bound ): ?string => FailureText::display( $text, $bound ),
	);

	// The release updater vendors a namespace-rewritten copy, which requires exactly one namespace occurrence,
	// and the class must stay free of any WordPress dependency.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local screener source only.
	$source = file_get_contents( dirname( __DIR__ ) . '/src/FailureText.php' );
	if ( ! is_string( $source ) || 1 !== substr_count( $source, 'RAN\\UpdaterSupport\\V1' ) || 1 === preg_match( '#\b(?:wp_|add_action|add_filter|apply_filters|ABSPATH|__\()#', $source ) ) {
		$fail( 'the screener source is not a single-namespace, WordPress-free file' );
	}

	foreach ( array( 'hostile', 'strict_only', 'display_only', 'benign' ) as $name ) {
		foreach ( array( 'hostile', 'strict_only', 'display_only', 'benign' ) as $other ) {
			if ( $name < $other && array() !== array_intersect( $corpus[ $name ], $corpus[ $other ] ) ) {
				$fail( "a string is in both {$name} and {$other}" );
			}
		}
	}

	// Hostile corpus: dropped whole at every bound, because the whole text is screened before it is bounded.
	foreach ( $modes as $mode => $screen ) {
		foreach ( $hostile as $label => $text ) {
			foreach ( $bounds as $bound ) {
				if ( null !== $screen( $text, $bound ) ) {
					$fail( "hostile text was returned by {$mode}: {$label} (bound {$bound})" );
				}
			}
		}
	}

	// Prose: dropped by STRICT only. DISPLAY returns curated sentences and prose mentions verbatim.
	foreach ( $strict_only as $label => $text ) {
		foreach ( $bounds as $bound ) {
			if ( null !== FailureText::strict( $text, $bound ) ) {
				$fail( "strict returned a credential-word text: {$label} (bound {$bound})" );
			}
		}
		if ( FailureText::display( $text, FailureText::MAX_INPUT_LENGTH ) !== $text ) {
			$fail( "display dropped a text that only strict drops: {$label}" );
		}
	}
	foreach ( $display_only as $label => $text ) {
		if ( FailureText::display( $text, FailureText::MAX_INPUT_LENGTH ) !== $text || null !== FailureText::strict( $text, FailureText::MAX_INPUT_LENGTH ) ) {
			$fail( "a curated sentence is not display-only: {$label}" );
		}
	}

	// Identifiers: exact closed snake_case ids pass; everything else, including hostile shapes in id form, drops.
	foreach ( $corpus['identifiers'] as $label => $text ) {
		if ( FailureText::identifier( $text ) !== $text ) {
			$fail( "identifier was dropped: {$label}" );
		}
	}
	foreach ( $corpus['not_identifiers'] as $label => $text ) {
		if ( null !== FailureText::identifier( $text ) ) {
			$fail( "identifier accepted: {$label}" );
		}
	}

	// Benign corpus: returned verbatim, and as an exact bounded prefix at smaller bounds.
	foreach ( $modes as $mode => $screen ) {
		foreach ( $benign as $label => $text ) {
			if ( $screen( $text, FailureText::MAX_INPUT_LENGTH ) !== $text ) {
				$fail( "benign text was dropped by {$mode}: {$label}" );
			}
			foreach ( $bounds as $bound ) {
				$expected = rtrim( substr( $text, 0, $bound ) );
				$cut      = $screen( $text, $bound );
				$wanted   = $expected === $screen( $expected, FailureText::MAX_INPUT_LENGTH ) ? $expected : null;
				if ( $wanted !== $cut ) {
					$fail( "benign text was not an exact prefix in {$mode}: {$label} (bound {$bound})" );
				}
			}
		}
	}

	// A cut that leaves a credential word beside other text is screened again: strict drops it, display keeps it.
	if ( null !== FailureText::strict( 'a passenger rides', 6 ) || 'a pass' !== FailureText::display( 'a passenger rides', 6 ) ) {
		$fail( 'a bounded cut was not screened again' );
	}

	// A cut that forms an application password shape is dropped in both modes.
	foreach ( $modes as $mode => $screen ) {
		if ( null !== $screen( 'abcd efgh ijkl mnop qrst uvwxyz', 29 ) || 'abcd efgh ijkl mnop qrst uvwxyz' !== $screen( 'abcd efgh ijkl mnop qrst uvwxyz', 31 ) ) {
			$fail( "a bounded cut forming an application password shape was not dropped by {$mode}" );
		}
	}

	// A null input is null in every mode.
	if ( null !== FailureText::strict( null, 10 ) || null !== FailureText::display( null, 10 ) || null !== FailureText::identifier( null ) || null !== FailureText::commit_id( null ) ) {
		$fail( 'a null input was not dropped' );
	}

	// Caller misuse fails closed.
	foreach ( $modes as $mode => $screen ) {
		foreach ( array( 0, -1, FailureText::MAX_INPUT_LENGTH + 1, PHP_INT_MAX, PHP_INT_MIN ) as $bound ) {
			if ( null !== $screen( 'Plugin Name', $bound ) ) {
				$fail( "an invalid bound was accepted by {$mode}: {$bound}" );
			}
		}
	}

	// The validating idiom: equality shows the text was safe, within the bound and untrimmed.
	if ( FailureText::strict( 'Plugin Name', 11 ) !== 'Plugin Name' || FailureText::strict( 'Plugin Name', 10 ) === 'Plugin Name' ) {
		$fail( 'the validating idiom does not distinguish a bounded result' );
	}
	if ( FailureText::strict( '  Plugin Name  ', 40 ) !== 'Plugin Name' ) {
		$fail( 'surrounding spaces are not trimmed' );
	}
	if ( 'Plugin' !== FailureText::strict( 'Plugin Name', 7 ) ) {
		$fail( 'a bounded result keeps a trailing space' );
	}

	// Commit ids: the narrow opt-in. Screening still drops them.
	$sha1   = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
	$sha256 = hash( 'sha256', 'x' );
	foreach ( array( $sha1, $sha256 ) as $id ) {
		if ( FailureText::commit_id( $id ) !== $id || null !== FailureText::strict( $id, 255 ) || null !== FailureText::display( $id, 255 ) ) {
			$fail( 'a commit id is not opt-in only (strict and display must drop it)' );
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
	$non_empty = static fn( array $values ): array => array_values( array_filter( $values, static fn( string $value ): bool => '' !== trim( $value ) ) );
	$injected  = array(
		'strict'  => $non_empty( array_merge( $hostile, $strict_only ) ),
		'display' => $non_empty( $hostile ),
	);
	foreach ( $modes as $mode => $screen ) {
		for ( $case = 0; $case < 3000; ++$case ) {
			$text  = $pad() . ' ' . $injected[ $mode ][ $next( count( $injected[ $mode ] ) ) ] . ' ' . $pad();
			$bound = $bounds[ $next( count( $bounds ) ) ];
			if ( null !== $screen( $text, $bound ) ) {
				$fail( "a hostile shape inside other text was returned by {$mode}" );
			}
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
		$bound   = 1 + $next( 160 );
		$result  = FailureText::strict( $text, $bound );
		$display = FailureText::display( $text, $bound );
		if ( FailureText::strict( $text, $bound ) !== $result || FailureText::display( $text, $bound ) !== $display ) {
			$fail( 'the screen is not deterministic' );
		}
		if ( null !== $result && $display !== $result ) {
			$fail( 'strict accepted text that display dropped' );
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
		if ( FailureText::strict( $result, $bound ) !== $result ) {
			$fail( 'the screen is not idempotent' );
		}
		if ( 1 === preg_match( '#[<>&{}\[\]|`=@/\\\\~]|%[0-9a-fA-F]{2}|[:;?]\S#', $result ) ) {
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
			$result = FailureText::strict( $text, $bound );
			$prefix = rtrim( substr( $text, 0, $bound ) );
			$again  = FailureText::strict( $prefix, FailureText::MAX_INPUT_LENGTH );
			$wanted = $again === $prefix ? $prefix : null;
			if ( $wanted !== $result ) {
				$fail( "bounding changed acceptance: {$label} (bound {$bound})" );
			}
		}
	}

	// A regular-expression engine failure is never evidence of safety: under a starved engine every hostile
	// text is still dropped, and nothing is ever returned altered.
	$saved = array( ini_get( 'pcre.backtrack_limit' ), ini_get( 'pcre.jit' ) );
	ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only engine limits, restored below.
	foreach ( array( '1', '5', '50' ) as $limit ) {
		ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only engine limits, restored below.
		foreach ( array_merge( $hostile, $strict_only ) as $label => $text ) {
			if ( null !== FailureText::strict( $text, 120 ) ) {
				$fail( "strict returned hostile text under backtrack limit {$limit}: {$label}" );
			}
		}
		foreach ( $hostile as $label => $text ) {
			if ( null !== FailureText::display( $text, 120 ) ) {
				$fail( "display returned hostile text under backtrack limit {$limit}: {$label}" );
			}
		}
		foreach ( $corpus['not_identifiers'] as $label => $text ) {
			if ( null !== FailureText::identifier( $text ) ) {
				$fail( "identifier accepted text under backtrack limit {$limit}: {$label}" );
			}
		}
		foreach ( $benign as $label => $text ) {
			foreach ( array( FailureText::strict( $text, 4096 ), FailureText::display( $text, 4096 ) ) as $result ) {
				if ( null !== $result && $result !== $text ) {
					$fail( "an engine failure altered benign text: {$label}" );
				}
			}
		}
	}
	ini_set( 'pcre.backtrack_limit', (string) $saved[0] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the saved limit.
	ini_set( 'pcre.jit', (string) $saved[1] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the saved setting.

	echo 'failure-text: ' . count( $hostile ) . ' hostile, ' . count( $benign ) . ' benign, ' . $accepted . " accepted generated cases\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI evidence line, never HTML.
} )();
