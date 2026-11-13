<?php
/**
 * Mutation check for the shared failure-text screener.
 *
 * Removes each named rule from a private copy of `src/FailureText.php`, and
 * weakens each structural guard, then requires the shared corpus or a targeted
 * probe to notice. A rule that no corpus entry depends on survives and fails
 * this script, so every rule keeps at least one entry only it rejects.
 *
 * Prints one line per mutant: the evidence that is also recorded in pull requests.
 *
 * @package RAN\UpdaterSupport\V1
 */

declare(strict_types=1);

( static function (): void {
	$fail = static function ( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( 'failure-text-mutation: ' . $message );
	};

	$say = static function ( string $line ): void {
		echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI evidence line, never HTML.
	};

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local screener source only.
	$source = file_get_contents( dirname( __DIR__ ) . '/src/FailureText.php' );
	if ( ! is_string( $source ) ) {
		$fail( 'cannot read the screener source' );
	}
	/** @var array{hostile: array<string, string>, strict_only: array<string, string>, display_only: array<string, string>, benign: array<string, string>, identifiers: array<string, string>, not_identifiers: array<string, string>} $corpus */
	$corpus = require __DIR__ . '/fixtures/failure-text-corpus.php';

	// Mutants: name => array( source replacement pairs, probe name ).
	$mutants = array();
	preg_match_all( "#^\t\t'([a-z_]+)'\s+=> '#m", $source, $table );
	foreach ( $table[1] as $name ) {
		$mutants[ $name ] = array( 'drop_line' => "#^\t\t'" . $name . "'\s+=> '.*\n#m" );
	}
	preg_match_all( '#self::([a-z_]+)\( \$text \);? // rule: ([a-z_]+)#', $source, $calls );
	foreach ( $calls[2] as $index => $name ) {
		$mutants[ $name ] = array( 'replace' => array( 'self::' . $calls[1][ $index ] . '( $text )' => 'false' ) );
	}
	$mutants['printable_ascii_only']    = array( 'replace' => array( "! self::test( '#\\A[\\x20-\\x7E]+\\z#', \$text )" => 'false' ) );
	$mutants['bound_before_screening']  = array( 'replace' => array( "'' === \$text || self::hostile( \$text, \$prose )" => "'' === \$text || self::hostile( substr( \$text, 0, \$max_length ), \$prose )" ) );
	$mutants['strict_applies_prose']    = array( 'replace' => array( 'return self::screen( $text, $max_length, true );' => 'return self::screen( $text, $max_length, false );' ) );
	$mutants['display_skips_prose']     = array( 'replace' => array( 'return self::screen( $text, $max_length, false );' => 'return self::screen( $text, $max_length, true );' ) );
	$mutants['identifier_shape']        = array( 'replace' => array( "self::test( '#\\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\\z#', \$text ) && " => '' ) );
	$mutants['identifier_length_limit'] = array( 'replace' => array( ' || strlen( $text ) > self::MAX_IDENTIFIER_LENGTH' => '' ) );
	$mutants['identifier_shape_rules']  = array( 'replace' => array( ' && ! self::hostile( $text, false )' => '' ) );
	$mutants['input_length_cap']        = array( 'replace' => array( ' || strlen( $text ) > self::MAX_INPUT_LENGTH' => '' ) );
	$mutants['bound_lower_limit']       = array( 'replace' => array( '$max_length < 1 || ' => '' ) );
	$mutants['bound_upper_limit']       = array( 'replace' => array( '$max_length > self::MAX_INPUT_LENGTH || ' => '' ) );
	$mutants['empty_result_check']      = array( 'replace' => array( "'' === \$text || self::hostile( \$text, \$prose )" => 'self::hostile( $text, $prose )' ) );
	$mutants['commit_id_end_anchor']    = array( 'replace' => array( ')\z#' => ')$#' ) );
	$mutants['commit_id_start_anchor']  = array( 'replace' => array( "'#\\A(?:[0-9a-f]{40}" => "'#(?:[0-9a-f]{40}" ) );
	$mutants['commit_id_lowercase']     = array( 'replace' => array( '[0-9a-f]{40}|[0-9a-f]{64}' => '[0-9a-fA-F]{40}|[0-9a-fA-F]{64}' ) );
	$mutants['commit_id_exact_lengths'] = array( 'replace' => array( '[0-9a-f]{40}|[0-9a-f]{64}' => '[0-9a-f]{32,64}' ) );

	// Branch and threshold mutants, one alternation branch or one limit each.
	/** @var list<array{string, string, string}> $branch_mutants */
	$branch_mutants = require __DIR__ . '/fixtures/failure-text-mutants.php';
	foreach ( $branch_mutants as $branch_mutant ) {
		$mutants[ $branch_mutant[0] ] = array( 'replace' => array( $branch_mutant[1] => $branch_mutant[2] ) );
	}

	// Equivalent mutants: no input can tell them apart from the original, with the reason. Everything else must die.
	$equivalent = array(
		'pcre strip fails open' => 'no backtrack limit lets the table rules run and then fails preg_replace(); the limit already fails the table rules first, so the guard is defensive',
		'pcre split fails open' => 'preg_split() cannot fail where the preg_replace() just before it, on the same text and limits, succeeded; the guard is defensive',
	);

	$probe = static function ( string $mutant, string $name ) use ( $corpus ): ?string {
		$call = static fn( string $method, string $text, int ...$bound ): mixed => ( new ReflectionMethod( $mutant, $method ) )->invoke( null, $text, ...$bound );
		if ( str_starts_with( $name, 'commit_id_' ) ) {
			$sha = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
			foreach ( array( $sha . "\n", ' ' . $sha, strtoupper( $sha ), $sha . 'a', substr( $sha, 0, 39 ), 'x' . $sha, $sha . $sha ) as $index => $text ) {
				if ( null !== $call( 'commit_id', $text ) ) {
					return 'commit_id probe ' . $index;
				}
			}

			return null;
		}
		if ( in_array( $name, array( 'bound_lower_limit', 'bound_upper_limit' ), true ) ) {
			foreach ( array( 'strict', 'display' ) as $method ) {
				foreach ( array( 0, -1, 4097 ) as $bound ) {
					if ( null !== $call( $method, 'Plugin Name', $bound ) ) {
						return $method . ' bound probe ' . $bound;
					}
				}
			}

			return null;
		}
		if ( 'bound re-screen' === $name ) {
			return null === $call( 'strict', 'a passenger rides', 6 ) ? null : 'a cut credential word';
		}
		if ( 'display_skips_prose' === $name ) {
			foreach ( $corpus['display_only'] as $label => $text ) {
				if ( null === $call( 'display', $text, 255 ) ) {
					return $label;
				}
			}

			return null;
		}
		if ( str_starts_with( $name, 'identifier_' ) ) {
			foreach ( $corpus['not_identifiers'] as $label => $text ) {
				if ( null !== $call( 'identifier', $text ) ) {
					return $label;
				}
			}

			return null;
		}
		if ( str_starts_with( $name, 'pcre ' ) ) {
			$saved     = array( ini_get( 'pcre.backtrack_limit' ), ini_get( 'pcre.jit' ) );
			$killed_by = null;
			ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only engine limits, restored below.
			foreach ( array( '1', '5' ) as $limit ) {
				ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only engine limits, restored below.
				foreach ( array_merge( $corpus['hostile'], $corpus['strict_only'] ) as $label => $text ) {
					try {
						if ( null !== $call( 'strict', $text, 120 ) || ( isset( $corpus['hostile'][ $label ] ) && null !== $call( 'display', $text, 120 ) ) ) {
							$killed_by = 'engine failure: ' . $label;
							break 2;
						}
					} catch ( Throwable $error ) {
						$killed_by = 'engine failure raised ' . $error::class . ': ' . $label;
						break 2;
					}
				}
			}
			ini_set( 'pcre.backtrack_limit', (string) $saved[0] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the saved limit.
			ini_set( 'pcre.jit', (string) $saved[1] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the saved setting.

			return $killed_by;
		}
		// Over-blocking mutants: text that must pass is dropped.
		foreach ( $corpus['benign'] as $label => $text ) {
			foreach ( array( 'strict', 'display' ) as $method ) {
				if ( $call( $method, $text, 4096 ) !== $text ) {
					return 'over-blocked by ' . $method . ': ' . $label;
				}
			}
		}
		foreach ( $corpus['display_only'] as $label => $text ) {
			if ( $call( 'display', $text, 4096 ) !== $text ) {
				return 'over-blocked by display: ' . $label;
			}
		}
		foreach ( $corpus['identifiers'] as $label => $text ) {
			if ( $call( 'identifier', $text ) !== $text ) {
				return 'over-blocked identifier: ' . $label;
			}
		}
		foreach ( array( 120, 255 ) as $bound ) {
			foreach ( $corpus['hostile'] as $label => $text ) {
				if ( null !== $call( 'display', $text, $bound ) ) {
					return 'display: ' . $label;
				}
			}
			foreach ( array_merge( $corpus['hostile'], $corpus['strict_only'] ) as $label => $text ) {
				if ( null !== $call( 'strict', $text, $bound ) ) {
					return 'strict: ' . $label;
				}
			}
		}

		return null;
	};

	// The unmutated copy must be killed by nothing, which proves the harness itself loads and runs.
	$directory = sys_get_temp_dir() . '/ran-failure-text-' . bin2hex( random_bytes( 8 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private unique temporary directory for the mutated copies.
	if ( ! mkdir( $directory, 0700 ) ) {
		$fail( 'cannot create the temporary directory' );
	}
	$files    = array();
	$survived = array();
	$count    = 0;
	$run      = static function ( string $name, string $code ) use ( &$files, $directory, $probe, $fail, &$count ): ?string {
		++$count;
		$class = 'FailureTextMutant' . $count;
		$code  = str_replace(
			array( 'namespace RAN\UpdaterSupport\V1;', 'final class FailureText ' ),
			array( 'namespace RAN\UpdaterSupport\V1\Mutants;', 'final class ' . $class . ' ' ),
			$code,
			$replaced
		);
		if ( 2 !== $replaced ) {
			$fail( 'cannot rename the mutant class' );
		}
		$file = $directory . '/' . $class . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private unique temporary file for one mutated copy.
		if ( false === file_put_contents( $file, $code ) ) {
			$fail( 'cannot write a mutant' );
		}
		$files[] = $file;
		require $file;

		return $probe( 'RAN\UpdaterSupport\V1\Mutants\\' . $class, $name );
	};

	if ( null !== $run( 'unmutated', $source ) ) {
		$fail( 'the unmutated screener failed its own probe' );
	}

	foreach ( $mutants as $name => $mutation ) {
		$code = $source;
		if ( isset( $mutation['drop_line'] ) ) {
			$code = (string) preg_replace( $mutation['drop_line'], '', $code, 1, $replaced );
			if ( 1 !== $replaced ) {
				$fail( "cannot remove rule {$name}" );
			}
		} else {
			foreach ( $mutation['replace'] as $search => $replacement ) {
				if ( 1 !== substr_count( $code, $search ) ) {
					$fail( "mutation target for {$name} is not unique" );
				}
				$code = str_replace( $search, $replacement, $code );
			}
		}
		$killed_by = $run( $name, $code );
		if ( null === $killed_by && isset( $equivalent[ $name ] ) ) {
			$say( "equivalent {$name}: {$equivalent[ $name ]}" );
			continue;
		}
		if ( null === $killed_by ) {
			$survived[] = $name;
			$say( "SURVIVED {$name}" );
			continue;
		}
		$say( "killed {$name} by: {$killed_by}" );
	}

	foreach ( $files as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique temporary files written above.
		unlink( $file );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the unique temporary directory created above.
	rmdir( $directory );

	if ( array() !== $survived ) {
		$fail( 'surviving mutants: ' . implode( ', ', $survived ) );
	}
	$say( 'failure-text-mutation: ' . ( count( $mutants ) - count( $equivalent ) ) . ' of ' . count( $mutants ) . ' mutants killed, ' . count( $equivalent ) . ' documented equivalent' );
} )();
