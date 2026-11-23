<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use Closure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\TemporaryArchive;
use RuntimeException;

final class TemporaryArchiveTest extends TestCase {

	public function test_verified_archive_is_available_only_during_use(): void {
		$path     = self::allocate();
		$hash     = hash( 'sha256', 'archive data' );
		$resolved = realpath( $path );
		self::assertIsString( $resolved );
		try {
			$value = TemporaryArchive::with_archive(
				static fn (): string => $path,
				static function ( string $destination, int $limit ) use ( $resolved ): void {
					self::assertSame( $resolved, $destination );
					self::assertSame( 20, $limit );
					self::write( $destination, 'archive data' );
				},
				static function ( string $archive, string $digest, int $size ) use ( $resolved, $hash ): int {
					self::assertSame( $resolved, $archive );
					self::assertFileExists( $archive );
					self::assertSame( $hash, $digest );
					return $size;
				},
				20,
				$hash,
				12
			);
			self::assertSame( 12, $value );
			self::assertFileDoesNotExist( $path );
		} finally {
			self::remove( $path );
		}
	}

	public function test_bad_size_or_digest_stops_handoff_and_removes_archive(): void {
		$cases = array(
			array( 'archive data', 11, null, null, 'The acquired archive file or size is invalid.' ),
			array( 'archive data', 20, 11, null, 'The acquired archive file or size is invalid.' ),
			array( 'archive data', 20, 12, hash( 'sha256', 'other data' ), 'The acquired archive could not be verified.' ),
			array( '', 20, null, null, 'The acquired archive file or size is invalid.' ),
		);
		foreach ( $cases as [ $contents, $limit, $size, $digest, $message ] ) {
			$path = self::allocate();
			try {
				TemporaryArchive::with_archive(
					static fn (): string => $path,
					static function ( string $destination ) use ( $contents ): void {
						self::write( $destination, $contents );
					},
					static fn (): string => 'handed off',
					$limit,
					$digest,
					$size
				);
				self::fail( 'An invalid archive was accepted.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( $message, $failure->getMessage() );
				self::assertFileDoesNotExist( $path );
			} finally {
				self::remove( $path );
			}
		}
	}

	public function test_acquisition_and_consumer_exceptions_preserve_cleanup(): void {
		$expected = new RuntimeException( 'Test callback failed.' );
		foreach ( array( true, false ) as $fail_acquire ) {
			$path = self::allocate();
			try {
				TemporaryArchive::with_archive(
					static fn (): string => $path,
					static function ( string $destination ) use ( $fail_acquire, $expected ): void {
						self::write( $destination, 'archive data' );
						if ( $fail_acquire ) {
							throw $expected;
						}
					},
					static function ( string $archive, string $digest, int $size ) use ( $fail_acquire, $expected ): void {
						self::assertFalse( $fail_acquire );
						self::assertFileExists( $archive );
						self::assertSame( hash( 'sha256', 'archive data' ), $digest );
						if ( 12 === $size ) {
							throw $expected;
						}
					},
					20
				);
				self::fail( 'The callback exception was lost.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( $expected, $failure );
				self::assertFileDoesNotExist( $path );
			} finally {
				self::remove( $path );
			}
		}
	}

	public function test_consumer_can_remove_the_archive(): void {
		$path = self::allocate();
		try {
			TemporaryArchive::with_archive(
				static fn (): string => $path,
				static function ( string $destination ): void {
					self::write( $destination, 'archive data' );
				},
				self::remove( ... ),
				20
			);
			self::assertFileDoesNotExist( $path );
		} finally {
			self::remove( $path );
		}
	}

	public function test_relative_allocation_is_cleaned_up_after_a_working_directory_change(): void {
		$directory = getcwd();
		self::assertIsString( $directory );
		$path     = self::allocate();
		$resolved = realpath( $path );
		self::assertIsString( $resolved );

		try {
			self::assertTrue( chdir( dirname( $path ) ) );
			TemporaryArchive::with_archive(
				static fn (): string => basename( $path ),
				static function ( string $destination ) use ( $directory, $resolved ): void {
					self::assertSame( $resolved, $destination );
					self::assertTrue( chdir( $directory ) );
					self::write( $destination, 'archive data' );
				},
				static function ( string $archive ) use ( $resolved ): void {
					self::assertSame( $resolved, $archive );
					self::assertFileExists( $archive );
				},
				20
			);
			self::assertFileDoesNotExist( $path );
		} finally {
			chdir( $directory );
			self::remove( $path );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cleanup_without_file_identity_removes_only_the_verified_archive(): void {
		require_once dirname( __DIR__ ) . '/fixtures/coarse-file-identity.php';
		$failure  = new RuntimeException( 'Test callback failed.' );
		$replace  = static function ( string $contents, int $mode_bits = 0 ): Closure {
			return static function ( string $archive ) use ( $contents, $mode_bits ): void {
				$mode = fileperms( $archive );
				self::assertIsInt( $mode );
				self::remove( $archive );
				self::write( $archive, $contents );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Match the replaced file's metadata, or differ only by the given bits.
				self::assertTrue( chmod( $archive, ( $mode & 07777 ) ^ $mode_bits ) );
			};
		};
		$write    = static function ( string $destination ): void {
			self::write( $destination, 'archive data' );
		};
		$fail     = static function () use ( $failure ): never {
			throw $failure;
		};
		$retained = 'The temporary archive could not be removed.';

		// Acquire, consume, limit, digest, expected message, previous exception, file left behind.
		$cases = array(
			'verified archive is removed'     => array( $write, static fn (): string => 'used', null, null, null, null ),
			'same-size replacement is kept'   => array( $write, $replace( 'replacement!' ), null, $retained, null, 'replacement!' ),
			'other-size replacement is kept'  => array( $write, $replace( 'replacement' ), null, $retained, null, 'replacement' ),
			'other-mode replacement is kept'  => array( $write, $replace( 'archive data', 0040 ), null, $retained, null, 'archive data' ),
			'consumer failure still removes'  => array( $write, $fail, null, 'Test callback failed.', $failure, null ),
			'digest mismatch still removes'   => array( $write, $fail, hash( 'sha256', 'other data' ), 'The acquired archive could not be verified.', null, null ),
			'unwritten allocation is removed' => array( $fail, $fail, null, 'Test callback failed.', $failure, null ),
			'unverified write is kept'        => array(
				static function ( string $destination ) use ( $write, $fail ): void {
					$write( $destination );
					$fail();
				},
				$fail,
				null,
				$retained,
				$failure,
				'archive data',
			),
		);
		foreach ( $cases as $label => [ $acquire, $consume, $digest, $message, $previous, $left ] ) {
			$path = self::allocate();
			try {
				$result = TemporaryArchive::with_archive( static fn (): string => $path, $acquire, $consume, 20, $digest );
				self::assertNull( $message, $label );
				self::assertSame( 'used', $result, $label );
			} catch ( RuntimeException $caught ) {
				self::assertSame( $message, $caught->getMessage(), $label );
				self::assertSame( $previous, $caught === $previous ? $caught : $caught->getPrevious(), $label );
			} finally {
				if ( null === $left ) {
					self::assertFileDoesNotExist( $path, $label );
				} else {
					self::assertStringEqualsFile( $path, $left, $label );
				}
				self::remove( $path );
			}
		}
	}

	private static function allocate(): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-archive-' );
		self::assertIsString( $path );
		return $path;
	}

	private static function write( string $path, string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write the acquisition bytes to the allocated test file.
		self::assertSame( strlen( $contents ), file_put_contents( $path, $contents ) );
	}

	private static function remove( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the allocated test file.
			unlink( $path );
		}
	}
}
