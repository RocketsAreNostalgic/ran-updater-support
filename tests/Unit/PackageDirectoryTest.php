<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\UpdaterSupport\V1\PackageDirectory;

final class PackageDirectoryTest extends TestCase {

	public function test_is_valid_name_rejects_windows_reserved_names_and_trailing_dots(): void {
		$reserved_bases = array( 'con', 'prn', 'aux', 'nul' );
		for ( $index = 1; $index <= 9; ++$index ) {
			$reserved_bases[] = 'com' . $index;
			$reserved_bases[] = 'lpt' . $index;
		}

		foreach ( $reserved_bases as $base ) {
			$variants = array(
				$base,
				strtoupper( $base ),
				ucfirst( $base ),
				$base . '.php',
				strtoupper( $base ) . '.tar.gz',
				$base . '.',
			);
			foreach ( $variants as $candidate ) {
				self::assertFalse( PackageDirectory::is_valid_name( $candidate ), $candidate );
				self::assertNotNull( ArchiveSafety::path_failure( $candidate ), $candidate );
			}
		}

		foreach ( array( 'a.', 'plugin.', 'My.Plugin.', 'my.plugin..', '0.', str_repeat( 'a', 190 ) . '.' ) as $trailing_dot ) {
			self::assertFalse( PackageDirectory::is_valid_name( $trailing_dot ), $trailing_dot );
			self::assertSame( 'component_trailing_dot_or_space', ArchiveSafety::path_failure( $trailing_dot ), $trailing_dot );
		}
	}

	public function test_is_valid_name_agrees_with_archive_safety_for_admitted_names(): void {
		$admitted = array(
			'a',
			'0',
			'plugin',
			'MixedCaseTheme',
			'My.Plugin_2-',
			'com0',
			'lpt0',
			'com10',
			'lpt10',
			'console',
			'null',
			'auxiliary',
			'con-plugin',
			'con_plugin',
			'aux1',
			'my.con',
			'plugin.nul',
			str_repeat( 'a', 191 ),
		);

		foreach ( $admitted as $name ) {
			self::assertTrue( PackageDirectory::is_valid_name( $name ), $name );
			self::assertNull( ArchiveSafety::path_failure( $name ), $name );
			self::assertNull( ArchiveSafety::path_failure( $name . '/' ), $name );
		}
	}

	#[DataProvider( 'installed_slug_cases' )]
	public function test_installed_slug_returns_top_level_folder( string $package_type, string $identifier, string $expected ): void {
		self::assertSame( $expected, PackageDirectory::installed_slug( $package_type, $identifier ) );
	}

	/** @return array<string, array{string, string, string}> */
	public static function installed_slug_cases(): array {
		return array(
			'single-file plugin'               => array( 'plugin', 'single-plugin.php', 'single-plugin' ),
			'dotted single-file plugin'        => array( 'plugin', 'Single.Plugin.php', 'Single.Plugin' ),
			'one-level plugin'                 => array( 'plugin', 'MixedCasePlugin/Main.php', 'MixedCasePlugin' ),
			'two-level nested plugin'          => array( 'plugin', 'a/b/main.php', 'a' ),
			'three-level nested plugin'        => array( 'plugin', 'Outer/Mid/Inner/Main.php', 'Outer' ),
			'trimmed slashes on nested plugin' => array( 'plugin', '/Outer/Inner/Main.php/', 'Outer' ),
			'theme identifier kept verbatim'   => array( 'theme', 'MixedCaseTheme', 'MixedCaseTheme' ),
		);
	}

	public function test_installed_slug_rejects_unknown_package_type(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The package type is invalid.' );
		PackageDirectory::installed_slug( 'mu-plugin', 'example/main.php' );
	}
}
