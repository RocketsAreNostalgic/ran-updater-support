<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

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
