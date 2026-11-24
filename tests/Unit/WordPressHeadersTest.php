<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\RegularFileStat;
use RAN\UpdaterSupport\V1\WordPressHeaders;

final class WordPressHeadersTest extends TestCase {

	// Observations and overrides for the isolated native I/O probes.
	/** @var list<resource> */
	public static array $handles = array();
	/** @var list<string> */
	public static array $modes = array();
	/** @var list<int> */
	public static array $lengths  = array();
	public static bool $fail_open = false;
	public static bool $fail_read = false;

	public function test_plugin_php_retains_every_occurrence(): void {
		$source = "\xef\xbb\xbf<?PHP /** plugin name: Démo\r\n"
			. " * Version:0\r"
			. " * version: 0\n"
			. " * VERSION: 1.2.0 \t\r\n"
			. " * Requires PHP:   \n"
			. " * Update URI: https://example.org/plugin */ trailing text\n"
			. 'Theme Name: Unrequested';

		self::assertSame(
			array(
				'Name'        => array( 'Démo' ),
				'Version'     => array( '0', '0', '1.2.0' ),
				'RequiresPHP' => array( '' ),
				'UpdateURI'   => array( 'https://example.org/plugin' ),
			),
			WordPressHeaders::parse(
				$source,
				array(
					'Name'        => 'Plugin Name',
					'Version'     => 'Version',
					'RequiresPHP' => 'Requires PHP',
					'UpdateURI'   => 'Update URI',
					'Description' => 'Description',
				)
			)
		);
	}

	public function test_theme_css_and_caller_owned_value_validation(): void {
		self::assertSame(
			array(
				'Name'                => array( '0' ),
				'Template'            => array( 'parent' ),
				'Description'         => array( "\xff\0" ),
				'Custom label (R&D)?' => array( 'one', '' ),
			),
			WordPressHeaders::parse(
				"\xef\xbb\xbf/* Theme Name:0\n * Template: parent */\n"
				. "# Description: \xff\0 ?> trailing text\n"
				. "// Custom label (R&D)?: one\n"
				. "@ Custom label (R&D)?:\n"
				. "Other Template: mismatched label\n"
				. "Template : mismatched separator\n"
				. 'continuation must not be included',
				array(
					'Name'                => 'Theme Name',
					'Template'            => 'Template',
					'Description'         => 'Description',
					'Custom label (R&D)?' => 'Custom label (R&D)?',
				)
			)
		);
	}

	public function test_compact_opening_tag_keeps_duplicate_header(): void {
		self::assertSame(
			array( 'Name' => array( 'Compact', 'Standard' ) ),
			WordPressHeaders::parse(
				"<?php/** Plugin Name: Compact */\nPlugin Name: Standard",
				array( 'Name' => 'Plugin Name' )
			)
		);
	}

	public function test_header_window_counts_raw_bytes_including_bom(): void {
		$header  = 'Version:1';
		$padding = "\xef\xbb\xbf" . str_repeat( 'x', WordPressHeaders::MAX_BYTES - strlen( $header ) - 5 ) . "\r\n";
		$labels  = array( 'Version' => 'Version' );
		self::assertSame( array( 'Version' => array( '1' ) ), WordPressHeaders::parse( $padding . $header . "\nVersion:2", $labels ) );
		self::assertSame( array(), WordPressHeaders::parse( $padding . "ignored\n" . $header, $labels ) );
	}

	public function test_reads_the_current_local_header_within_the_byte_window(): void {
		$path = tempnam( sys_get_temp_dir(), 'ran-header-' );
		self::assertIsString( $path );
		$link   = $path . '-symlink';
		$fifo   = $path . '-fifo';
		$labels = array(
			'Name'    => 'Theme Name',
			'Version' => 'Version',
		);
		try {
			self::assertSame( array(), WordPressHeaders::read( $path, $labels ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write a local installed-header fixture.
			file_put_contents( $path, "/* Theme Name: Café */\nVersion: 0\n" . str_repeat( ' ', 8192 ) . "\nVersion: 1" );
			self::assertSame(
				array(
					'Name'    => array( 'Café' ),
					'Version' => array( '0' ),
				),
				WordPressHeaders::read( $path, $labels )
			);
			self::assertNull( WordPressHeaders::read( '', $labels ) );
			self::assertNull( WordPressHeaders::read( dirname( $path ), $labels ) );
			self::assertNull( WordPressHeaders::read( 'file://' . $path, $labels ) );
			self::assertNull( WordPressHeaders::read( $path . "\0", $labels ) );
			self::assertTrue( symlink( $path, $link ) );
			self::assertNull( WordPressHeaders::read( $link, $labels ) );
			if ( function_exists( 'posix_mkfifo' ) ) {
				self::assertTrue( posix_mkfifo( $fifo, 0600 ) );
				self::assertNull( WordPressHeaders::read( $fifo, $labels ) );
			}
		} finally {
			$this->remove_fixtures( $path, $link, $fifo );
		}
		self::assertNull( WordPressHeaders::read( $path, $labels ) );
	}

	/** @return array<string, array{bool, bool, int, list<int>, bool}> */
	public static function handle_cases(): array {
		return array(
			'readable regular file' => array( false, false, 1, array( WordPressHeaders::MAX_BYTES ), true ),
			'open fails'            => array( true, false, 0, array(), false ),
			'read fails'            => array( false, true, 1, array( WordPressHeaders::MAX_BYTES ), false ),
		);
	}

	/** @param list<int> $lengths Expected read requests. */
	#[DataProvider( 'handle_cases' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_read_opens_bounded_handle_and_closes_on_every_exit( bool $fail_open, bool $fail_read, int $opened_handles, array $lengths, bool $returns_headers ): void {
		require_once dirname( __DIR__ ) . '/fixtures/header-read.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-header-' );
		self::assertIsString( $path );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed the native header fixture.
			self::assertSame( 10, file_put_contents( $path, 'Version: 1' ) );
			self::$fail_open = $fail_open;
			self::$fail_read = $fail_read;

			self::assertSame(
				$returns_headers ? array( 'Version' => array( '1' ) ) : null,
				WordPressHeaders::read( $path, array( 'Version' => 'Version' ) )
			);
			self::assertSame( array( 'rb' ), self::$modes );
			self::assertCount( $opened_handles, self::$handles );
			foreach ( self::$handles as $handle ) {
				self::assertFalse( is_resource( $handle ) );
			}
			self::assertSame( $lengths, self::$lengths );
		} finally {
			$this->remove_fixtures( $path );
		}
	}

	public function test_read_needs_no_write_access_and_keeps_file_times(): void {
		$path = tempnam( sys_get_temp_dir(), 'ran-header-' );
		self::assertIsString( $path );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed the native header fixture.
			self::assertSame( 10, file_put_contents( $path, 'Version: 1' ) );
			foreach ( array( 0644, 0444 ) as $mode ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set the local fixture permissions under test.
				self::assertTrue( chmod( $path, $mode ) );
				$before = RegularFileStat::read( $path );
				self::assertIsArray( $before );
				self::assertSame( $mode, $before['mode'] & 07777 );
				self::assertSame( array( 'Version' => array( '1' ) ), WordPressHeaders::read( $path, array( 'Version' => 'Version' ) ) );
				self::assertSame( $before, RegularFileStat::read( $path ) );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore owner write access before removing the local fixture.
			chmod( $path, 0600 );
			$this->remove_fixtures( $path );
		}
	}

	private function remove_fixtures( string ...$fixtures ): void {
		foreach ( $fixtures as $fixture ) {
			if ( is_link( $fixture ) || file_exists( $fixture ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the local test fixtures.
				unlink( $fixture );
			}
		}
	}
}
