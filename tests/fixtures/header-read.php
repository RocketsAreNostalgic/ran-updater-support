<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

use RAN\UpdaterSupport\V1\Tests\WordPressHeadersTest;

/** @return array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}|false */
function lstat( string $path ): array|false {
	$stat = \lstat( $path );
	return false !== $stat && WordPressHeadersTest::$zero_inode ? array( 'ino' => 0 ) + $stat : $stat;
}

/**
 * Record native handles in the isolated header-reader test process.
 *
 * @return resource|false
 */
function fopen( string $path, string $mode ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open the native test fixture and record the handle for the close assertion.
	$handle = \fopen( $path, $mode );
	if ( false !== $handle ) {
		WordPressHeadersTest::$handles[] = $handle;
	}
	return $handle;
}

/**
 * @param resource $handle Open fixture handle.
 * @return array{dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int}|false
 */
function fstat( $handle ): array|false {
	$stat = \fstat( $handle );
	if ( false === $stat ) {
		return false;
	}
	if ( WordPressHeadersTest::$zero_inode ) {
		$stat['ino'] = 0;
	}
	return WordPressHeadersTest::$stat_overrides + $stat;
}

/**
 * @param resource     $handle Open fixture handle.
 * @param positive-int $length Requested byte count.
 */
function fread( $handle, int $length ): string|false {
	WordPressHeadersTest::$lengths[] = $length;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the native test fixture through the supplied handle.
	$contents = WordPressHeadersTest::$fail_read ? false : \fread( $handle, $length );
	if ( null !== WordPressHeadersTest::$after_read ) {
		( WordPressHeadersTest::$after_read )();
	}
	return $contents;
}
