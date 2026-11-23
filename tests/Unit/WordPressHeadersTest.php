<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use Closure;
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
	/** @var list<int> */
	public static array $lengths = array();
	/** @var array{dev?:int,ino?:int,mode?:int,size?:int,mtime?:int,ctime?:int} */
	public static array $stat_overrides = array();
	public static bool $zero_inode      = false;
	public static bool $fail_read       = false;
	/** @var (Closure(): void)|null */
	public static ?Closure $after_read = null;

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
			self::assertNull( WordPressHeaders::read( dirname( $path ), $labels ) );
			self::assertNull( WordPressHeaders::read( 'file://' . $path, $labels ) );
			self::assertNull( WordPressHeaders::read( $path . "\0", $labels ) );
			self::assertTrue( symlink( $path, $link ) );
			self::assertNull( WordPressHeaders::read( $link, $labels ) );
		} finally {
			$this->remove_fixtures( $path, $link );
		}
		self::assertNull( WordPressHeaders::read( $path, $labels ) );
	}

	/** @return array<string, array{string, bool, bool}> */
	public static function handle_cases(): array {
		return array(
			'stable regular file'           => array( 'stable', true, true ),
			'opened device differs'         => array( 'device', false, false ),
			'opened inode differs'          => array( 'inode', false, false ),
			'opened type is nonregular'     => array( 'type', false, false ),
			'zero inode, matching metadata' => array( 'zero', true, true ),
			'zero inode, different size'    => array( 'zero-size', false, false ),
			'zero inode, different mtime'   => array( 'zero-mtime', false, false ),
			'zero inode, different ctime'   => array( 'zero-ctime', false, false ),
			'read fails'                    => array( 'read error', true, false ),
			'path replaced during read'     => array( 'replace', true, false ),
		);
	}

	#[DataProvider( 'handle_cases' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_read_verifies_and_closes_the_opened_handle( string $scenario, bool $reads, bool $returns_headers ): void {
		require_once dirname( __DIR__ ) . '/fixtures/header-read.php';

		$path = tempnam( sys_get_temp_dir(), 'ran-header-' );
		self::assertIsString( $path );
		$replacement = $path . '-replacement';
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seed the native header fixture before its first observation.
			self::assertSame( 10, file_put_contents( $path, 'Version: 1' ) );
			$before = RegularFileStat::read( $path );
			self::assertIsArray( $before );
			self::$zero_inode     = str_starts_with( $scenario, 'zero' );
			self::$stat_overrides = match ( $scenario ) {
				'device'     => array( 'dev' => $before['dev'] ^ 1 ),
				'inode'      => array( 'ino' => $before['ino'] ^ 1 ),
				'type'       => array( 'mode' => 0040000 ),
				'zero-size'  => array( 'size' => $before['size'] + 1 ),
				'zero-mtime' => array( 'mtime' => $before['mtime'] + 1 ),
				'zero-ctime' => array( 'ctime' => $before['ctime'] + 1 ),
				default      => array(),
			};
			self::$fail_read = 'read error' === $scenario;
			if ( 'replace' === $scenario ) {
				self::assertTrue( copy( $path, $replacement ) );
				self::$after_read = static function () use ( $path, $replacement ): void {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Rebind the local fixture path after reading from its still-open handle.
					self::assertTrue( rename( $replacement, $path ) );
				};
			}

			self::assertSame(
				$returns_headers ? array( 'Version' => array( '1' ) ) : null,
				WordPressHeaders::read( $path, array( 'Version' => 'Version' ) )
			);
			self::assertCount( 1, self::$handles );
			self::assertFalse( is_resource( self::$handles[0] ) );
			self::assertSame( $reads ? array( WordPressHeaders::MAX_BYTES ) : array(), self::$lengths );
		} finally {
			$this->remove_fixtures( $path, $replacement );
		}
	}

	private function remove_fixtures( string ...$fixtures ): void {
		foreach ( $fixtures as $fixture ) {
			if ( is_link( $fixture ) || is_file( $fixture ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the local test fixtures.
				unlink( $fixture );
			}
		}
	}
}
