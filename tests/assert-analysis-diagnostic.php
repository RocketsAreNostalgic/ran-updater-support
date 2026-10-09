<?php
/**
 * Require the expected diagnostic for a selected file in the real checker report.
 *
 * @package RanUpdaterSupport
 */

declare(strict_types=1);

if ( ! isset( $argv[1], $argv[2], $argv[3], $argv[4] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone test CLI failures go directly to STDERR without loading a WordPress runtime.
	fwrite( STDERR, "Report, selected file, diagnostic identifier and message fragment are required.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the actual local checker report without a WordPress runtime; failed reads must fail the contract.
$ran_updater_support_report_source = file_get_contents( $argv[1] );
if ( false === $ran_updater_support_report_source ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone test CLI failures go directly to STDERR without loading a WordPress runtime.
	fwrite( STDERR, "Cannot read the checker report.\n" );
	exit( 1 );
}
$ran_updater_support_report   = json_decode( $ran_updater_support_report_source, true, 512, JSON_THROW_ON_ERROR );
$ran_updater_support_selected = realpath( $argv[2] );
if ( false === $ran_updater_support_selected ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone test CLI failures go directly to STDERR without loading a WordPress runtime.
	fwrite( STDERR, "Selected diagnostic file does not exist.\n" );
	exit( 1 );
}
foreach ( $ran_updater_support_report['files'][ $ran_updater_support_selected ]['messages'] ?? array() as $ran_updater_support_message ) {
	if ( ( $ran_updater_support_message['identifier'] ?? '' ) === $argv[3] && str_contains( $ran_updater_support_message['message'], $argv[4] ) ) {
		exit( 0 );
	}
}
exit( 1 );
