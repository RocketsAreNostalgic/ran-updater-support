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
	 * directory. The trusted writer fills that file in place, enforces the
	 * supplied limit while writing, and closes it before returning. The consumer
	 * may inspect or consume the file during the callback. It must not retain the
	 * path for later use. A supplied size or digest is compared before handoff.
	 * Cleanup failure is reported even if acquisition or consumption threw.
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

		$path      = $allocate();
		$allocated = false === $path ? null : RegularFileStat::read( $path );
		if ( false === $path || null === $allocated || 0 !== $allocated['size'] ) {
			throw new RuntimeException( 'A fresh temporary archive file could not be allocated.' );
		}

		$cause = null;
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
			if (
				$verified !== $written
				|| ( null !== $expected_sha256 && ! hash_equals( $expected_sha256, $digest ) )
			) {
				throw new RuntimeException( 'The acquired archive could not be verified.' );
			}

			return $consume( $path, $digest, $written['size'] );
		} catch ( Throwable $failure ) {
			$cause = $failure;
			throw $failure;
		} finally {
			self::remove( $path, $allocated['dev'], $allocated['ino'], $cause );
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

	private static function remove( string $path, int $device, int $inode, ?Throwable $cause ): void {
		$current = RegularFileStat::read( $path );
		if ( null !== $current && $device === $current['dev'] && $inode === $current['ino'] ) {
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
}
