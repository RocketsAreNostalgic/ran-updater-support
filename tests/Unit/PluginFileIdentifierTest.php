<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\PluginFileIdentifier;

final class PluginFileIdentifierTest extends TestCase {

	#[DataProvider( 'identifiers' )]
	public function test_validates_relative_path_shape( string $value, bool $expected ): void {
		self::assertSame( $expected, PluginFileIdentifier::is_valid( $value ) );
	}

	/** @return array<string, array{string, bool}> */
	public static function identifiers(): array {
		return array(
			'root level'          => array( 'main.php', true ),
			'single directory'    => array( 'Package/main.PHP', true ),
			'nested mixed case'   => array( 'Package-1/src_v2/includes/Boot.Main.pHp', true ),
			'numeric segments'    => array( '0/0.php', true ),
			'empty'               => array( '', false ),
			'absolute'            => array( '/pkg/main.php', false ),
			'drive path'          => array( 'C:/pkg/main.php', false ),
			'stream wrapper'      => array( 'file://pkg/main.php', false ),
			'backslash'           => array( 'pkg\main.php', false ),
			'empty segment'       => array( 'pkg//main.php', false ),
			'dot segment'         => array( 'pkg/./main.php', false ),
			'parent segment'      => array( 'pkg/../main.php', false ),
			'encoded separator'   => array( 'pkg/%2Fmain.php', false ),
			'extension only'      => array( 'pkg/.php', false ),
			'other extension'     => array( 'pkg/main.php.txt', false ),
			'trailing slash'      => array( 'pkg/main.PHP/', false ),
			'trailing newline'    => array( "pkg/main.php\n", false ),
			'embedded null byte'  => array( "pkg/main\0.php", false ),
			'whitespace'          => array( 'pkg /main.php', false ),
			'non-ASCII directory' => array( 'páckage/main.php', false ),
		);
	}
}
