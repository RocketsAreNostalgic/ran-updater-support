<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\GitReferenceSyntax;

final class GitReferenceSyntaxTest extends TestCase {

	#[DataProvider( 'valid_references' )]
	public function test_accepts_valid_named_references( string $reference ): void {
		self::assertTrue( GitReferenceSyntax::is_valid_named_reference( $reference ) );
	}

	/** @return array<string, array{string}> */
	public static function valid_references(): array {
		return array(
			'simple branch'               => array( 'main' ),
			'slash separated'             => array( 'feature/named-reference' ),
			'deeply nested slash path'    => array( 'release/2026/11/v1.2.3' ),
			'underscore dot plus'         => array( 'release_1.2.3+build.4' ),
			'hyphen after first char'     => array( 'v1.0.0-beta.1' ),
			'segment starting with dash'  => array( 'feature/-dash' ),
			'embedded at sign'            => array( 'release/v1@2026' ),
			'closing bracket'             => array( 'feature/tag]1' ),
			'lock substring not suffix'   => array( 'feature/lockfile.locked' ),
			'255 ASCII bytes'             => array( str_repeat( 'a', 255 ) ),
			'127 two-byte UTF-8 chars'    => array( str_repeat( "\xC3\xA9", 127 ) ),
			'255 bytes mixed UTF-8 ASCII' => array( str_repeat( "\xC3\xA9", 127 ) . 'a' ),
		);
	}

	#[DataProvider( 'invalid_references' )]
	public function test_rejects_invalid_named_references( string $reference ): void {
		self::assertFalse( GitReferenceSyntax::is_valid_named_reference( $reference ) );
	}

	/** @return array<string, array{string}> */
	public static function invalid_references(): array {
		return array(
			'empty'                                => array( '' ),
			'leading space'                        => array( ' main' ),
			'trailing space'                       => array( 'main ' ),
			'leading tab'                          => array( "\tmain" ),
			'trailing newline'                     => array( "main\n" ),
			'trailing carriage return'             => array( "main\r" ),
			'256 ASCII bytes'                      => array( str_repeat( 'a', 256 ) ),
			'128 two-byte UTF-8 chars (256 bytes)' => array( str_repeat( "\xC3\xA9", 128 ) ),
			'bare at sign'                         => array( '@' ),
			'leading hyphen'                       => array( '-main' ),
			'bare hyphen'                          => array( '-' ),
			'leading hyphen nested'                => array( '-feature/branch' ),
			'leading slash'                        => array( '/main' ),
			'bare slash'                           => array( '/' ),
			'trailing slash'                       => array( 'main/' ),
			'nested trailing slash'                => array( 'feature/branch/' ),
			'trailing dot'                         => array( 'main.' ),
			'nested trailing dot'                  => array( 'release/v1.' ),
			'double dot'                           => array( 'a..b' ),
			'only double dot'                      => array( '..' ),
			'nested double dot'                    => array( 'feature/a..b' ),
			'reflog syntax'                        => array( 'main@{0}' ),
			'bare reflog syntax'                   => array( '@{' ),
			'double slash'                         => array( 'feature//branch' ),
			'only double slash'                    => array( '//' ),
			'null byte'                            => array( "main\x00branch" ),
			'unit separator control byte'          => array( "main\x1Fbranch" ),
			'embedded space'                       => array( 'my branch' ),
			'DEL byte'                             => array( "main\x7Fbranch" ),
			'tilde'                                => array( 'main~1' ),
			'caret'                                => array( 'main^2' ),
			'colon'                                => array( 'refs:main' ),
			'question mark'                        => array( 'main?1' ),
			'asterisk'                             => array( 'release/*' ),
			'opening bracket'                      => array( 'feature/[a]' ),
			'backslash'                            => array( 'feature\\branch' ),
			'percent sign (product unsupported)'   => array( 'feature%20branch' ),
			'bare percent sign'                    => array( '100%' ),
			'dot-prefixed root segment'            => array( '.hidden' ),
			'bare dot segment'                     => array( '.' ),
			'dot-prefixed nested segment'          => array( 'feature/.hidden' ),
			'dot-prefixed middle segment'          => array( 'a/.b/c' ),
			'lowercase lock suffix root'           => array( 'main.lock' ),
			'uppercase LOCK suffix root'           => array( 'main.LOCK' ),
			'mixed-case Lock suffix root'          => array( 'main.Lock' ),
			'lowercase lock suffix nested'         => array( 'feature/main.lock' ),
			'uppercase LOCK suffix nested'         => array( 'feature/main.LOCK' ),
			'lowercase lock suffix parent segment' => array( 'feature.lock/branch' ),
			'uppercase LOCK suffix parent segment' => array( 'feature.LOCK/branch' ),
			'dot lock alone'                       => array( '.lock' ),
		);
	}

	public function test_rejects_every_ascii_control_byte_space_and_del(): void {
		for ( $byte = 0x00; $byte <= 0x20; ++$byte ) {
			self::assertFalse(
				GitReferenceSyntax::is_valid_named_reference( 'a' . chr( $byte ) . 'b' ),
				sprintf( 'Expected byte 0x%02X to be rejected.', $byte )
			);
		}

		self::assertFalse( GitReferenceSyntax::is_valid_named_reference( "a\x7Fb" ) );
	}
}
