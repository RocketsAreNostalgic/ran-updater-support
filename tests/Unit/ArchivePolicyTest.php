<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\ArchiveSafety;
use ZipArchive;

final class ArchivePolicyTest extends TestCase {

	public function test_count_and_dynamic_budget_boundaries(): void {
		self::assertSame( 'archive_entry_limit', ArchiveSafety::entry_count_failure( 0 ) );
		self::assertNull( ArchiveSafety::entry_count_failure( 1 ) );
		self::assertNull( ArchiveSafety::entry_count_failure( 10000 ) );
		self::assertSame( 'archive_entry_limit', ArchiveSafety::entry_count_failure( 10001 ) );
		self::assertSame( 209715200, ArchiveSafety::maximum_expanded_bytes( 52428800 ) );
		self::assertSame( PHP_INT_MAX - 3, ArchiveSafety::maximum_expanded_bytes( intdiv( PHP_INT_MAX, 4 ) ) );

		$this->expectException( InvalidArgumentException::class );
		ArchiveSafety::maximum_expanded_bytes( intdiv( PHP_INT_MAX, 4 ) + 1 );
	}

	public function test_invalid_budget_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		ArchiveSafety::maximum_expanded_bytes( 0 );
	}

	public function test_policy_reasons_and_size_floor(): void {
		$stat = array(
			'size'              => 131072,
			'comp_size'         => 1311,
			'encryption_method' => 0,
		);

		self::assertNull( ArchiveSafety::entry_policy_failure( $stat, false, 131072 ) );
		self::assertNull(
			ArchiveSafety::entry_policy_failure(
				array(
					'size'      => 131071,
					'comp_size' => 1,
				) + $stat,
				false,
				131072
			)
		);
		self::assertNull(
			ArchiveSafety::entry_policy_failure(
				array(
					'size'      => 0,
					'comp_size' => 0,
				) + $stat,
				true,
				0
			)
		);

		$reasons = array(
			ArchiveSafety::entry_policy_failure( array_diff_key( $stat, array( 'encryption_method' => true ) ), false, 131072 ),
			ArchiveSafety::entry_policy_failure( array( 'encryption_method' => 1 ) + $stat, false, 131072 ),
			ArchiveSafety::entry_policy_failure( array( 'size' => -1 ) + $stat, false, 131072 ),
			ArchiveSafety::entry_policy_failure( $stat, true, 131072 ),
			ArchiveSafety::entry_policy_failure( array( 'comp_size' => 1310 ) + $stat, false, 131072 ),
			ArchiveSafety::entry_policy_failure( $stat, false, 131071 ),
		);
		self::assertSame( ArchiveSafety::ENTRY_POLICY_FAILURES, $reasons );
		self::assertSame( 'entry_policy_metadata_invalid', ArchiveSafety::entry_policy_failure( array( 'encryption_method' => '0' ) + $stat, false, 131072 ) );
		self::assertSame( 'entry_policy_metadata_invalid', ArchiveSafety::entry_policy_failure( $stat, false, -1 ) );
	}

	public function test_reads_native_zip_metadata_with_a_localized_path(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			self::markTestSkipped( 'The optional ZIP extension is unavailable.' );
		}
		$path = tempnam( sys_get_temp_dir(), 'ran-policy-' );
		self::assertIsString( $path );
		$zip = new ZipArchive();
		try {
			self::assertTrue( $zip->open( $path, ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'plugin/画像.svg', '<svg/>' ) );
			self::assertTrue( $zip->close() );
			self::assertTrue( $zip->open( $path, ZipArchive::RDONLY ) );

			try {
				$stat = $zip->statIndex( 0 );
				self::assertIsArray( $stat );
				self::assertSame( 'plugin/画像.svg', $stat['name'] );
				self::assertNull( ArchiveSafety::path_failure( $stat['name'] ) );
				self::assertSame( ZipArchive::EM_NONE, $stat['encryption_method'] );
				self::assertNull( ArchiveSafety::entry_policy_failure( $stat, false, ArchiveSafety::maximum_expanded_bytes( 2 ) ) );
			} finally {
				$zip->close();
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the plain temporary ZIP fixture.
			unlink( $path );
		}
	}
}
