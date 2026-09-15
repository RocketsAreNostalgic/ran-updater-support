<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/ArchiveSafety.php';
require_once dirname( __DIR__ ) . '/src/RepositoryRelativePath.php';

use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

$fixture = require __DIR__ . '/fixtures/archive-safety.php';
foreach ( $fixture['paths'] as $name => $case ) {
	[$input, $expected] = $case;
	if ( ArchiveSafety::normalizePath( $input ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "path fixture failed: {$name}" );
	}
}
foreach ( $fixture['metadata'] as $name => $case ) {
	[$origin, $attributes, $directory, $expected] = $case;
	if ( ArchiveSafety::entryTypeFailure( $origin, $attributes, $directory ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "metadata fixture failed: {$name}" );
	}
}
foreach ( $fixture['collisions'] as $name => $case ) {
	[$entries, $expected] = $case;
	if ( ArchiveSafety::collisionFailure( $entries ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "collision fixture failed: {$name}" );
	}
}

$repositoryPaths = require __DIR__ . '/fixtures/repository-relative-path.php';
foreach ( $repositoryPaths['valid'] as $name => $case ) {
	[$input, $expected] = $case;
	if ( RepositoryRelativePath::normalize( $input ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "repository-relative path fixture failed: {$name}" );
	}
}
foreach ( $repositoryPaths['invalid'] as $name => $input ) {
	try {
		RepositoryRelativePath::normalize( $input );
	} catch ( InvalidArgumentException ) {
		continue;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
	throw new RuntimeException( "unsafe repository-relative path fixture was accepted: {$name}" );
}
