<?php
/**
 * Emit the existing BOM and long HTML preamble before an analysis fixture.
 *
 * @package RanUpdaterSupport
 */

declare(strict_types=1);

if ( ! isset( $argv[1] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone test CLI failures go directly to STDERR without loading a WordPress runtime.
	fwrite( STDERR, "Template opening argument is required.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberately emit the exact invalid executable-template fixture bytes to an owned file, never rendered HTML.
echo "\xEF\xBB\xBF<section>", str_repeat( 'x', 4096 ), $argv[1];
