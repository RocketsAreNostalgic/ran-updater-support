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

			self::assertDecodedSegmentIsSafe( $segment, 0 === $index );
		}

		return implode( '/', $segments );
	}

	private static function assertDecodedSegmentIsSafe( string $segment, bool $firstSegment ): void {
		$decoded = $segment;

		for ( $pass = 0, $limit = strlen( $segment ); $pass < $limit; ++$pass ) {
			$next = rawurldecode( $decoded );

			if ( $next === $decoded ) {
				break;
			}

			$decoded = $next;

			if ( '.' === $decoded
				|| '..' === $decoded
				|| str_contains( $decoded, '/' )
				|| str_contains( $decoded, '\\' )
				|| ( $firstSegment && 1 === preg_match( '/^[A-Za-z]:/', $decoded ) )
				|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
				throw self::invalid();
			}
		}
	}

	private static function invalid(): InvalidArgumentException {
		return new InvalidArgumentException( 'The repository-relative path is invalid.' );
	}
}
