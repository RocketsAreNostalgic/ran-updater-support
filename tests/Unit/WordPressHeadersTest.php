<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\WordPressHeaders;

final class WordPressHeadersTest extends TestCase {

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
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the local header fixture.
			unlink( $path );
		}
		self::assertNull( WordPressHeaders::read( $path, $labels ) );
	}
}
