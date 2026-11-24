<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

use RAN\UpdaterSupport\V1\Tests\WordPressHeadersTest;

/**
 * Record native handles and modes in the isolated header-reader test process.
 *
 * @return resource|false
 */
function fopen( string $path, string $mode ) {
	WordPressHeadersTest::$modes[] = $mode;
	if ( WordPressHeadersTest::$fail_open ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open the native test fixture and record the handle for the close assertion.
	$handle = \fopen( $path, $mode );
	if ( false !== $handle ) {
		WordPressHeadersTest::$handles[] = $handle;
	}
	return $handle;
}

/**
 * Record each read length and optionally simulate a read error.
 *
 * @param resource     $handle Open fixture handle.
 * @param positive-int $length Requested byte count.
 */
function fread( $handle, int $length ): string|false {
	WordPressHeadersTest::$lengths[] = $length;
	if ( WordPressHeadersTest::$fail_read ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the native test fixture through the supplied handle.
	return \fread( $handle, $length );
}
