<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

// Frozen from ran-booster 309645d34818120968034154b3bfb82ba2864a40, RAN/PackageSubdirectory.php.
// Only namespace/class names change; the Core exception remains a distinct subtype.
use InvalidArgumentException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

/**
 * Normalize and validate a repository-relative package directory.
 */
final class CorePackageSubdirectory {

	/**
	 * Normalize an optional repository-relative directory.
	 *
	 * @throws InvalidArgumentException When the value is not a safe relative path.
	 */
	public static function normalize( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			throw self::invalid();
		}

		if ( '' === trim( $value ) ) {
			if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw self::invalid();
			}

			return null;
		}

		try {
			return RepositoryRelativePath::normalize( $value );
		} catch ( InvalidArgumentException ) {
			throw self::invalid();
		}
	}

	/**
	 * Return the final directory segment from a validated path.
	 */
	public static function slug( mixed $value ): string {
		$path = self::normalize( $value );

		if ( null === $path ) {
			throw self::invalid();
		}

		$segments = explode( '/', $path );

		return (string) end( $segments );
	}

	/**
	 * Validate one provider-supplied destination directory name.
	 */
	public static function normalize_slug( mixed $value ): string {
		$slug = self::normalize( $value );

		if ( null === $slug
			|| ! is_string( $value )
			|| str_ends_with( trim( $value ), '/' )
			|| str_contains( $slug, '/' ) ) {
			throw self::invalid();
		}

		return $slug;
	}

	/**
	 * Derive the one destination slug shared by package resolution and execution.
	 */
	public static function installation_slug( mixed $provider_slug, mixed $subdirectory ): string {
		$path = self::normalize( $subdirectory );

		return null === $path ? self::normalize_slug( $provider_slug ) : self::slug( $path );
	}

	/**
	 * Derive the canonical destination used only for a new deployment.
	 */
	public static function deployment_slug( mixed $provider_slug, mixed $subdirectory ): string {
		return strtolower( self::installation_slug( $provider_slug, $subdirectory ) );
	}

	private static function invalid(): CoreInvalidPackageSubdirectory {
		return new CoreInvalidPackageSubdirectory( 'The package subdirectory must be a normalized relative path.' );
	}
}
