<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/PackageDirectory.php';
require_once dirname( __DIR__ ) . '/src/InvalidRepositoryPathException.php';
require_once dirname( __DIR__ ) . '/src/RepositoryRelativePath.php';
require_once __DIR__ . '/fixtures/core-invalid-package-subdirectory.php';
require_once __DIR__ . '/fixtures/core-package-subdirectory.php';
require_once __DIR__ . '/fixtures/branch-package-subdirectory.php';

use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\UpdaterSupport\V1\Tests\CorePackageSubdirectory;
use RAN\UpdaterSupport\V1\Tests\BranchPackageSubdirectory;

( static function (): void {
	$checks = 0;
	$check  = static function ( string $label, mixed $expected, mixed $actual ) use ( &$checks ): void {
		++$checks;
		if ( $expected !== $actual ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only synthetic contract labels enter this CLI diagnostic.
			throw new RuntimeException( 'Package directory contract failed: ' . $label );
		}
	};
	foreach ( array( 'plugin', 'MixedCaseTheme', 'A', '0', 'My.Plugin_2-', str_repeat( 'a', 100 ), str_repeat( 'A', 101 ), str_repeat( 'a', 191 ), str_repeat( 'A', 191 ) ) as $name ) {
		$check( 'accepted folder name', true, PackageDirectory::is_valid_name( $name ) );
	}
	foreach ( array( '', '.', '..', '.hidden', '_plugin', '-plugin', ' plugin', 'plugin ', 'two words', 'a/b', 'a\\b', 'a:b', '%41', "a\n", "\0a", "a\x7f", "caf\xc3\xa9", str_repeat( 'a', 192 ) ) as $name ) {
		$check( 'rejected folder name', false, PackageDirectory::is_valid_name( $name ) );
	}

	// Capture the outward value or fixed failure message, allowing Core's exception subtype.
	$outcome = static function ( callable $operation ): mixed {
		try {
			return array( 'value', $operation() );
		} catch ( InvalidArgumentException $exception ) {
			return array( 'invalid', $exception->getMessage() );
		}
	};
	$paths   = require __DIR__ . '/fixtures/repository-relative-path.php';
	$values  = array_merge(
		array( null, '', ' ', '   ', false, true, 0, 1, 1.5, array(), new stdClass(), "\t", "\n", "\0", 'MixedCase', 'packages/MyPlugin', ' packages/MyPlugin/ ', 'packages/two words', 'packages/%41', 'packages/caf' . "\xc3\xa9", str_repeat( 'a', 192 ) ),
		array_column( $paths['valid'], 0 ),
		array_values( $paths['invalid'] )
	);
	foreach ( array( CorePackageSubdirectory::class, BranchPackageSubdirectory::class ) as $reference ) {
		foreach ( $values as $value ) {
			foreach ( array( 'normalize', 'slug', 'normalize_slug' ) as $method ) {
				$check( $reference . '::' . $method, $outcome( static fn(): mixed => $reference::$method( $value ) ), $outcome( static fn(): mixed => PackageDirectory::$method( $value ) ) );
			}
			foreach ( $values as $provider_slug ) {
				foreach ( array( 'installation_slug', 'deployment_slug' ) as $method ) {
					$check( $reference . '::' . $method, $outcome( static fn(): mixed => $reference::$method( $provider_slug, $value ) ), $outcome( static fn(): mixed => PackageDirectory::$method( $provider_slug, $value ) ) );
				}
			}
		}
	}
	// Literal examples independently pin case, precedence and the new-install-only fold.
	$check( 'optional spaces', null, PackageDirectory::normalize( '   ' ) );
	$check( 'subdirectory case', 'packages/MyPlugin', PackageDirectory::normalize( ' packages/MyPlugin/ ' ) );
	$check( 'provider destination', 'MyPlugin', PackageDirectory::installation_slug( ' MyPlugin ', null ) );
	$check( 'subdirectory destination', 'MyPlugin', PackageDirectory::installation_slug( false, 'packages/MyPlugin' ) );
	$check( 'provider new install', 'myplugin', PackageDirectory::deployment_slug( 'MyPlugin', null ) );
	$check( 'subdirectory new install', 'myplugin', PackageDirectory::deployment_slug( false, 'packages/MyPlugin' ) );
	$check( 'fixed failure', array( 'invalid', 'The package subdirectory must be a normalized relative path.' ), $outcome( static fn(): ?string => PackageDirectory::normalize( '../private-input' ) ) );

	foreach ( array(
		array( 'plugin', 'installed-plugin/plugin.php', 'installed-plugin' ),
		array( 'plugin', 'MixedCasePlugin/Main.php', 'MixedCasePlugin' ),
		array( 'plugin', 'single-plugin.php', 'single-plugin' ),
		array( 'plugin', 'Single.Plugin.php', 'Single.Plugin' ),
		array( 'plugin', '/MixedCasePlugin/Main.php/', 'MixedCasePlugin' ),
		array( 'plugin', 'outer/Inner/Main.php', 'Inner' ),
		array( 'theme', 'installed-theme', 'installed-theme' ),
		array( 'theme', 'MixedCaseTheme', 'MixedCaseTheme' ),
	) as [$type, $identifier, $expected] ) {
		$check( 'installed identity derivation', $expected, PackageDirectory::installed_slug( $type, $identifier ) );
	}
	$check( 'installed folder is authoritative', 'myplugin', PackageDirectory::installed_slug( 'plugin', 'myplugin/Main.php' ) );
	$check( 'source case stays distinct', false, PackageDirectory::installed_slug( 'theme', 'myplugin' ) === PackageDirectory::installation_slug( '', 'packages/MyPlugin' ) );
	$check( 'invalid package type', array( 'invalid', 'The package type is invalid.' ), $outcome( static fn(): string => PackageDirectory::installed_slug( 'other', 'example' ) ) );

	// Identity: the installed folder is the source name exactly or in its lower-case new-install form.
	$non_ascii       = "caf\xc3\xa9";
	$non_ascii_upper = "CAF\xc3\x89";
	foreach ( array(
		array( 'MyPlugin', 'MyPlugin', true ),
		array( 'myplugin', 'MyPlugin', true ),
		array( 'myplugin', 'myplugin', true ),
		array( 'tnyGmaps', 'tnyGmaps', true ),
		array( 'example', 'Example', true ),
		array( 'MyPlugin', 'myplugin', false ),
		array( 'MYPLUGIN', 'MyPlugin', false ),
		array( 'tnyGmaps', 'tnygmaps', false ),
		array( 'example', 'forms', false ),
		array( 'my-plugin', 'my_plugin', false ),
		array( $non_ascii, $non_ascii_upper, false ),
		array( strtolower( $non_ascii_upper ), $non_ascii_upper, true ),
	) as [$installed, $source, $expected] ) {
		$check( 'installed folder match: ' . bin2hex( $installed . '|' . $source ), $expected, PackageDirectory::installed_folder_matches( $installed, $source ) );
	}

	// Collision: names that could be one folder on a case-insensitive filesystem.
	foreach ( array(
		array( 'MyPlugin', 'myplugin', true ),
		array( 'MyPlugin', 'MYPLUGIN', true ),
		array( 'a', 'a', true ),
		array( 'my-plugin', 'my_plugin', false ),
		array( 'plugin', 'plugin ', false ),
		array( 'my', 'myplugin', false ),
		array( $non_ascii, $non_ascii_upper, false ),
		array( 'a/b', 'A/c', false ),
	) as [$first, $second, $expected] ) {
		$check( 'names collide: ' . bin2hex( $first . '|' . $second ), $expected, PackageDirectory::names_collide( $first, $second ) );
		$check( 'names collide symmetrically: ' . bin2hex( $first . '|' . $second ), $expected, PackageDirectory::names_collide( $second, $first ) );
	}
	$check( 'collision key folds ASCII letters only', 'my.plugin_v2-x', PackageDirectory::collision_key( 'My.Plugin_V2-X' ) );
	$check( 'collision key leaves non-ASCII bytes', "caf\xc3\x89", PackageDirectory::collision_key( $non_ascii_upper ) );
	$check( 'collision key of a key is unchanged', 'my.plugin_v2-x', PackageDirectory::collision_key( PackageDirectory::collision_key( 'My.Plugin_V2-X' ) ) );
	// Locale independence: exactly bytes A-Z change, whatever locale the process uses.
	for ( $byte = 0; $byte < 256; ++$byte ) {
		$check( 'collision key byte ' . $byte, chr( $byte >= 65 && $byte <= 90 ? $byte + 32 : $byte ), PackageDirectory::collision_key( chr( $byte ) ) );
	}

	// Properties over a name corpus with case variants: names_collide() is strcasecmp() equality,
	// and installed_folder_matches() is the exact-or-new-install rule written in terms of deployment_slug().
	$corpus = array( 'plugin', 'Plugin', 'PLUGIN', 'MixedCaseTheme', 'mixedcasetheme', 'A', 'a', '0', 'My.Plugin_2-', 'my.plugin_2-', 'tnyGmaps', 'tnygmaps', 'TNYGMAPS', 'my-plugin', 'my_plugin', str_repeat( 'a', 191 ), str_repeat( 'A', 191 ), $non_ascii, $non_ascii_upper, '', ' plugin', 'a/b', 'A/B' );
	foreach ( $corpus as $first ) {
		foreach ( $corpus as $second ) {
			$label      = bin2hex( $first . '|' . $second );
			$case_equal = 0 === strcasecmp( $first, $second );
			$check( 'names collide as strcasecmp: ' . $label, $case_equal, PackageDirectory::names_collide( $first, $second ) );
			$check( 'collision key equality as names collide: ' . $label, PackageDirectory::names_collide( $first, $second ), PackageDirectory::collision_key( $first ) === PackageDirectory::collision_key( $second ) );
			if ( PackageDirectory::is_valid_name( $first ) && PackageDirectory::is_valid_name( $second ) ) {
				$check( 'installed folder match as deployment slug: ' . $label, in_array( $first, array( $second, PackageDirectory::deployment_slug( $second, null ) ), true ), PackageDirectory::installed_folder_matches( $first, $second ) );
				$check( 'installed folder match implies collision: ' . $label, true, ! PackageDirectory::installed_folder_matches( $first, $second ) || PackageDirectory::names_collide( $first, $second ) );
			}
		}
	}
	printf( "Package directory contract passed: %d comparisons.\n", (int) $checks );
} )();
