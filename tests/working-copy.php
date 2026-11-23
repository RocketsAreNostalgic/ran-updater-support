<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/TargetWorkingCopy.php';

use RAN\UpdaterSupport\V1\TargetWorkingCopy;

( static function (): void {
	if ( TargetWorkingCopy::is_working_copy( '' ) ) {
		throw new RuntimeException( 'An empty working-copy target must be refused.' );
	}
	$ran_root = sys_get_temp_dir() . '/ran-working-copy-' . bin2hex( random_bytes( 8 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated CLI filesystem fixture requires native operations.
	mkdir( $ran_root );
	try {
		$ran_target = $ran_root . '/target';
		if ( TargetWorkingCopy::is_working_copy( $ran_target ) ) {
			throw new RuntimeException( 'Missing target detected as a working copy.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated CLI filesystem fixture requires native operations.
		mkdir( $ran_target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated CLI filesystem fixture requires native operations.
		file_put_contents( $ran_target . '/composer.json', '{}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated CLI filesystem fixture requires native operations.
		mkdir( $ran_root . '/.git' );
		if ( TargetWorkingCopy::is_working_copy( $ran_target ) ) {
			throw new RuntimeException( 'Parent marker or composer manifest detected.' );
		}
		foreach ( array( '.git', '.svn', '.hg', '.bzr' ) as $ran_marker ) {
			$ran_marker_path = $ran_target . '/' . $ran_marker;
			foreach ( array( 'directory', 'file', 'symlink', 'dangling' ) as $ran_marker_type ) {
				if ( 'directory' === $ran_marker_type ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated CLI filesystem fixture requires native operations.
					mkdir( $ran_marker_path );
				} elseif ( 'file' === $ran_marker_type ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated CLI filesystem fixture requires native operations.
					file_put_contents( $ran_marker_path, 'marker' );
				} else {
					symlink( 'dangling' === $ran_marker_type ? $ran_root . '/missing' : $ran_root, $ran_marker_path );
				}
				if ( ! TargetWorkingCopy::is_working_copy( $ran_target ) ) {
					throw new RuntimeException( 'Marker not detected.' );
				}
				if ( 'directory' === $ran_marker_type ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI filesystem fixture requires native operations.
					rmdir( $ran_marker_path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated CLI filesystem fixture requires native operations.
					unlink( $ran_marker_path );
				}
			}
		}
	} finally {
		foreach ( array( '.git', '.svn', '.hg', '.bzr', 'composer.json' ) as $ran_name ) {
			$ran_fixture_path = $ran_target . '/' . $ran_name;
			if ( is_link( $ran_fixture_path ) || is_file( $ran_fixture_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated CLI filesystem fixture requires native operations.
				unlink( $ran_fixture_path );
			} elseif ( is_dir( $ran_fixture_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI filesystem fixture requires native operations.
				rmdir( $ran_fixture_path );
			}
		}
		if ( is_dir( $ran_target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI filesystem fixture requires native operations.
			rmdir( $ran_target );
		}
		if ( is_dir( $ran_root . '/.git' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI filesystem fixture requires native operations.
			rmdir( $ran_root . '/.git' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI filesystem fixture requires native operations.
		rmdir( $ran_root );
	}
} )();
