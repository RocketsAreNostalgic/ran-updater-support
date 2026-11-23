<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/**
 * Report local entries as a host without inode numbers or timestamps would.
 *
 * Loaded only in an isolated test process, so matching device, inode and times
 * cannot distinguish one file from another at the same path.
 *
 * @return array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}|false
 */
function lstat( string $path ): array|false {
	$stat = \lstat( $path );
	return false === $stat ? false : array(
		'ino'   => 0,
		'mtime' => 0,
		'ctime' => 0,
	) + $stat;
}
