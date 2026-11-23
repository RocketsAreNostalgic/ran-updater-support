<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Owns a temporary archive for the duration of a synchronous operation. */
final class TemporaryArchive {

	/**
	 * Acquire, fingerprint and use a bounded local archive, then remove it.
	 *
	 * The trusted allocator creates a fresh empty file in a host-controlled
	 * directory. The callbacks receive a resolved absolute path. The writer
	 * fills that file in place, enforces the supplied limit while writing, and
	 * closes it before returning. The consumer
	 * may inspect or consume the file during the callback. It must not retain the
	 * path for later use. A supplied size or digest is compared before handoff.
	 * Cleanup failure is reported even if acquisition or consumption threw.
	 * Where the host reports no inode number, cleanup removes the path only if
	 * it still matches the last verified metadata and digest (or the untouched
	 * empty allocation). Otherwise it is left in place and reported.
	 *
	 * @template Result
	 * @param callable(): (string|false)            $allocate Creates a fresh local temporary file.
	 * @param callable(string, int): void           $acquire  Writes the archive using the supplied byte limit.
	 * @param callable(string, string, int): Result $consume  Receives path, lowercase SHA-256 and byte size.
	 * @return Result
	 */
	public static function with_archive(
		callable $allocate,
		callable $acquire,
		callable $consume,
		int $maximum_artifact_bytes,
		?string $expected_sha256 = null,
		?int $expected_bytes = null
	): mixed {
		if (
			$maximum_artifact_bytes < 1
			|| ( null !== $expected_bytes && ( $expected_bytes < 1 || $expected_bytes > $maximum_artifact_bytes ) )
			|| ( null !== $expected_sha256 && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $expected_sha256 ) )
		) {
			throw new InvalidArgumentException( 'The temporary archive bounds or digest are invalid.' );
		}

		$temporary = $allocate();
		$allocated = false === $temporary ? null : RegularFileStat::read( $temporary );
		$path      = null === $allocated || false === $temporary ? false : realpath( $temporary );
		if ( false === $path || null === $allocated || 0 !== $allocated['size'] ) {
			throw new RuntimeException( 'A fresh temporary archive file could not be allocated.' );
		}

		$cause        = null;
		$owned        = $allocated;
		$owned_sha256 = null;
		try {
			$acquire( $path, $maximum_artifact_bytes );
			$written = RegularFileStat::read( $path );
			if (
				null === $written
				|| $written['dev'] !== $allocated['dev'] || $written['ino'] !== $allocated['ino']
				|| $written['size'] < 1 || $written['size'] > $maximum_artifact_bytes
				|| ( null !== $expected_bytes && $written['size'] !== $expected_bytes )
			) {
				throw new RuntimeException( 'The acquired archive file or size is invalid.' );
			}

			$digest   = self::digest( $path, $written['size'] );
			$verified = RegularFileStat::read( $path );
			if ( $verified !== $written ) {
				throw new RuntimeException( 'The acquired archive could not be verified.' );
			}
			$owned        = $written;
			$owned_sha256 = $digest;
			if ( null !== $expected_sha256 && ! hash_equals( $expected_sha256, $digest ) ) {
				throw new RuntimeException( 'The acquired archive could not be verified.' );
			}

			return $consume( $path, $digest, $written['size'] );
		} catch ( Throwable $failure ) {
			$cause = $failure;
			throw $failure;
		} finally {
			self::remove( $path, $owned, $owned_sha256, $cause );
		}
	}

	private static function digest( string $path, int $size ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open the admitted local file for a bounded hash pass.
		$input = @fopen( $path, 'rb' );
		if ( false === $input ) {
			throw new RuntimeException( 'The temporary archive could not be opened.' );
		}
		try {
			$hash = hash_init( 'sha256' );
			$read = hash_update_stream( $hash, $input, $size );
			if ( $read !== $size ) {
				throw new RuntimeException( 'The temporary archive could not be read in full.' );
			}
			return hash_final( $hash );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the native read handle before handoff or cleanup.
			fclose( $input );
		}
	}

	/**
	 * Remove the path only while it still holds the owned file.
	 *
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $owned  Last verified snapshot.
	 * @param string|null                                                                            $sha256 Verified digest, or null for the empty allocation.
	 */
	private static function remove( string $path, array $owned, ?string $sha256, ?Throwable $cause ): void {
		$current = RegularFileStat::read( $path );
		if ( null !== $current && self::owns( $path, $current, $owned, $sha256 ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the allocated entry. The consumer may already have consumed it.
			if ( @unlink( $path ) ) {
				return;
			}
		}

		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The fixed message carries no archive data. The previous exception preserves the caller's failure for programmatic handling.
			throw new RuntimeException( 'The temporary archive could not be removed.', 0, $cause );
		}
	}

	/**
	 * Without an inode number, a matching device does not identify the file.
	 * Require the last verified metadata and content instead.
	 *
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $current Current snapshot.
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $owned   Last verified snapshot.
	 */
	private static function owns( string $path, array $current, array $owned, ?string $sha256 ): bool {
		if ( $current['dev'] !== $owned['dev'] || $current['ino'] !== $owned['ino'] ) {
			return false;
		}
		if ( 0 !== $owned['ino'] ) {
			return true;
		}
		if ( $current !== $owned ) {
			return false;
		}
		if ( null === $sha256 ) {
			// The untouched allocation is empty, so its metadata describes all of it.
			return true;
		}
		try {
			return hash_equals( $sha256, self::digest( $path, $current['size'] ) ) && RegularFileStat::read( $path ) === $current;
		} catch ( RuntimeException ) {
			return false;
		}
	}
}
