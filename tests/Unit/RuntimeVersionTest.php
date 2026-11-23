<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\RuntimeVersion;

final class RuntimeVersionTest extends TestCase {

	#[DataProvider( 'php_versions' )]
	public function test_normalizes_php( string $observed, ?string $expected ): void {
		self::assertSame( $expected, RuntimeVersion::php( $observed ) );
		if ( null !== $expected ) {
			self::assertSame( $expected, RuntimeVersion::php( $expected ) );
		}
	}

	/** @return array<string, array{string, ?string}> */
	public static function php_versions(): array {
		return array(
			'legacy'                   => array( '7.4.33', '7.4.33' ),
			'Ubuntu'                   => array( '8.2.0-ubuntu1', '8.2.0' ),
			'Debian'                   => array( '8.2.31-1~deb12u1', '8.2.31' ),
			'distribution build'       => array( '8.3.7-1ubuntu2.4', '8.3.7' ),
			'two components'           => array( '8.3', '8.3.0' ),
			'patch release plus build' => array( '8.4.11+vendor.1', '8.4.11' ),
			'PHP prerelease'           => array( '8.5.0RC1', '8.5.0-rc.1' ),
			'prerelease with build'    => array( '8.5.0-beta2+packager1', '8.5.0-beta.2' ),
			'development build'        => array( '8.5.1-dev', '8.5.1-dev.0' ),
			'empty value'              => array( '', null ),
			'leading zero'             => array( '8.02.0', null ),
			'prerelease leading zero'  => array( '8.5.0-RC.01', null ),
			'malformed stage'          => array( '8.5.0-rc.foo', null ),
			'unseparated vendor'       => array( '8.2.0ubuntu1', null ),
			'trailing newline'         => array( "8.2.0\n", null ),
			'trailing separator'       => array( '8.2.0-ubuntu1+', null ),
			'overlong build'           => array( '8.5.0-' . str_repeat( 'x', 100 ), null ),
		);
	}

	#[DataProvider( 'wordpress_versions' )]
	public function test_normalizes_wordpress( string $observed, ?string $expected ): void {
		self::assertSame( $expected, RuntimeVersion::wordpress( $observed ) );
		if ( null !== $expected ) {
			self::assertSame( $expected, RuntimeVersion::wordpress( $expected ) );
		}
	}

	/** @return array<string, array{string, ?string}> */
	public static function wordpress_versions(): array {
		return array(
			'two components'       => array( '6.8', '6.8.0' ),
			'stable patch release' => array( '6.8.1', '6.8.1' ),
			'rc'                   => array( '6.8-RC1', '6.8.0-rc.1' ),
			'numbered alpha'       => array( '6.8-alpha1', '6.8.0-alpha.1' ),
			'branch revision'      => array( '6.8.1-alpha-59789', '6.8.1-alpha.0' ),
			'prerelease src'       => array( '6.8-beta2-59789-src', '6.8.0-beta.2' ),
			'standalone src'       => array( '6.8-src', '6.8.0-src' ),
			'canonical stage'      => array( '6.8.0-rc.10', '6.8.0-rc.10' ),
			'empty value'          => array( '', null ),
			'prefix'               => array( 'v6.8', null ),
			'leading space'        => array( ' 6.8', null ),
			'trailing newline'     => array( "6.8\n", null ),
			'patch leading zero'   => array( '6.8.01', null ),
			'stage leading zero'   => array( '6.8-RC01', null ),
			'unknown suffix'       => array( '6.8.0-ubuntu1', null ),
			'extra component'      => array( '6.8.1.2', null ),
			'duplicate src'        => array( '6.8-RC1-src-src', null ),
		);
	}

	public function test_native_comparison_preserves_prerelease_order(): void {
		$php_stable = RuntimeVersion::php( '8.2.0-ubuntu1' );
		$php_rc     = RuntimeVersion::php( '8.2.0RC1' );
		$wp_stable  = RuntimeVersion::wordpress( '6.8' );
		$wp_rc      = RuntimeVersion::wordpress( '6.8-RC1' );
		$wp_rc10    = RuntimeVersion::wordpress( '6.8-RC10' );
		$wp_beta    = RuntimeVersion::wordpress( '6.8-beta2' );
		self::assertIsString( $php_stable );
		self::assertIsString( $php_rc );
		self::assertIsString( $wp_stable );
		self::assertIsString( $wp_rc );
		self::assertIsString( $wp_rc10 );
		self::assertIsString( $wp_beta );
		self::assertTrue( version_compare( $php_stable, '8.2.0', '==' ) );
		self::assertTrue( version_compare( $php_rc, $php_stable, '<' ) );
		self::assertTrue( version_compare( $wp_rc, $wp_stable, '<' ) );
		self::assertTrue( version_compare( $wp_rc, $wp_beta, '>' ) );
		self::assertTrue( version_compare( $wp_rc10, $wp_rc, '>' ) );
	}
}
