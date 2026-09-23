<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

use InvalidArgumentException;

/**
 * Normalize one required repository-relative path.
 *
 * Consumer packages retain optional-value policy, exception mapping, and
 * package-specific slug semantics. This class owns only the shared path-safety
 * rule.
 */
final class RepositoryRelativePath {

	private const MAX_DECODE_PASSES = 8;

	/**
	 * Normalize one non-empty repository-relative path.
	 *
	 * @throws InvalidArgumentException When the path is unsafe or empty.
	 */
	public static function normalize( string $value ): string {
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw self::invalid();
		}

		$value = trim( $value );

		if ( '' === $value
			|| str_starts_with( $value, '/' )
			|| str_contains( $value, '\\' )
			|| 1 === preg_match( '/^[A-Za-z]:/', $value ) ) {
			throw self::invalid();
		}

		if ( str_ends_with( $value, '/' ) ) {
			$value = substr( $value, 0, -1 );
		}

		$segments = explode( '/', $value );
		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw self::invalid();
			}

			self::assert_decoded_segment_is_safe( $segment, 0 === $index );
		}

		return implode( '/', $segments );
	}

	private static function assert_decoded_segment_is_safe( string $segment, bool $first_segment ): void {
		$decoded = $segment;

		for ( $pass = 0; $pass < self::MAX_DECODE_PASSES; ++$pass ) {
			$next = rawurldecode( $decoded );

			if ( $next === $decoded ) {
				return;
			}

			$decoded = $next;

			if ( '.' === $decoded
				|| '..' === $decoded
				|| str_contains( $decoded, '/' )
				|| str_contains( $decoded, '\\' )
				|| ( $first_segment && 1 === preg_match( '/^[A-Za-z]:/', $decoded ) )
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
				throw self::invalid();
			}
		}

		if ( rawurldecode( $decoded ) !== $decoded ) {
			throw self::invalid();
		}
	}

	private static function invalid(): InvalidArgumentException {
		return new InvalidArgumentException( 'The repository-relative path is invalid.' );
	}
}
