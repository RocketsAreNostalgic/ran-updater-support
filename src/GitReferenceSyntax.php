<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/** Pure syntax validator for named Git references (branches and tags). */
final class GitReferenceSyntax {

	public const MAX_REFERENCE_BYTES = 255;

	/**
	 * Validate a named Git reference (branch or tag name).
	 *
	 * Enforces Git ref-format syntax together with product-level bounds: a
	 * 255-byte cap, no leading hyphen, no percent sign, and ASCII
	 * case-insensitive rejection of `.lock` path segments.
	 */
	public static function is_valid_named_reference( string $reference ): bool {
		if (
			'' === $reference
			|| trim( $reference ) !== $reference
			|| strlen( $reference ) > self::MAX_REFERENCE_BYTES
			|| '@' === $reference
			|| str_starts_with( $reference, '-' )
			|| str_starts_with( $reference, '/' )
			|| str_ends_with( $reference, '/' )
			|| str_ends_with( $reference, '.' )
			|| str_contains( $reference, '..' )
			|| str_contains( $reference, '@{' )
			|| str_contains( $reference, '//' )
			|| 0 !== preg_match( '/[\x00-\x20\x7F~^:?*\[\\\\%]/', $reference )
		) {
			return false;
		}

		foreach ( explode( '/', $reference ) as $segment ) {
			if (
				'' === $segment
				|| str_starts_with( $segment, '.' )
				|| str_ends_with( strtolower( $segment ), '.lock' )
			) {
				return false;
			}
		}

		return true;
	}
}
