<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\RegularFileStat;

final class RegularFileStatTest extends TestCase {

	public function test_reads_native_facts_across_modes_and_link_counts(): void {
		$path = tempnam( sys_get_temp_dir(), 'ran-stat-' );
		self::assertIsString( $path );
		$link = $path . '-hardlink';
		try {
			$initial = RegularFileStat::read( $path );
			$native  = lstat( $path );
			self::assertIsArray( $initial );
			self::assertIsArray( $native );
			$keys = array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime' );
			self::assertSame( $keys, array_keys( $initial ) );
			foreach ( $keys as $key ) {
				self::assertSame( $native[ $key ], $initial[ $key ] );
			}
			self::assertSame( 0600, $initial['mode'] & 0777 );
			self::assertSame( 0, $initial['size'] );
			self::assertSame( 1, $initial['nlink'] );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write bytes into a native temporary fixture.
			self::assertSame( 3, file_put_contents( $path, 'zip' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Change the actual mode observed by the native snapshot.
			self::assertTrue( chmod( $path, 0400 ) );
			self::assertTrue( link( $path, $link ) );
			$current = RegularFileStat::read( $path );
			self::assertIsArray( $current );
			self::assertSame( $initial['dev'], $current['dev'] );
			self::assertSame( $initial['ino'], $current['ino'] );
			self::assertSame( 0400, $current['mode'] & 0777 );
			self::assertSame( 3, $current['size'] );
			self::assertSame( 2, $current['nlink'] );
			self::assertSame( $current, RegularFileStat::read( $link ) );
		} finally {
			$this->remove_fixtures( $link, $path );
		}
	}

	public function test_unavailable_and_non_regular_entries_return_null(): void {
		$path = tempnam( sys_get_temp_dir(), 'ran-stat-' );
		self::assertIsString( $path );
		$link     = $path . '-symlink';
		$dangling = $path . '-dangling';
		$missing  = $path . '-missing';
		try {
			self::assertTrue( symlink( $path, $link ) );
			self::assertTrue( symlink( $missing, $dangling ) );
			self::assertNull( RegularFileStat::read( $link ) );
			self::assertNull( RegularFileStat::read( $dangling ) );
			self::assertNull( RegularFileStat::read( dirname( $path ) ) );
			self::assertNull( RegularFileStat::read( $missing ) );
			self::assertNull( RegularFileStat::read( 'file://' . $path ) );
			self::assertNull( RegularFileStat::read( $path . "\0" ) );
			self::assertNull( RegularFileStat::read( '' ) );
		} finally {
			$this->remove_fixtures( $link, $dangling, $path );
		}
	}

	private function remove_fixtures( string ...$paths ): void {
		foreach ( $paths as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the native temporary entries created by this test.
				unlink( $path );
			}
		}
	}
}
