<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test fixture variables model isolated CLI or WordPress state; declaration prefixes remain checked.


declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/ArchiveSafety.php';
require_once dirname( __DIR__ ) . '/src/InvalidRepositoryPathException.php';
require_once dirname( __DIR__ ) . '/src/RepositoryRelativePath.php';

use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\UpdaterSupport\V1\InvalidRepositoryPathException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

$fixture = require __DIR__ . '/fixtures/archive-safety.php';
foreach ( $fixture['paths'] as $name => $case ) {
	[$input, $expected] = $case;
	if ( ArchiveSafety::normalize_path( $input ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "path fixture failed: {$name}" );
	}
}
foreach ( $fixture['metadata'] as $name => $case ) {
	[$origin, $attributes, $directory, $expected] = $case;
	if ( ArchiveSafety::entry_type_failure( origin_os: $origin, attributes: $attributes, directory: $directory ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "metadata fixture failed: {$name}" );
	}
}
foreach ( $fixture['collisions'] as $name => $case ) {
	[$entries, $expected] = $case;
	if ( ArchiveSafety::collision_failure( $entries ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "collision fixture failed: {$name}" );
	}
}

$repository_paths = require __DIR__ . '/fixtures/repository-relative-path.php';
foreach ( $repository_paths['valid'] as $name => $case ) {
	[$input, $expected] = $case;
	if ( RepositoryRelativePath::normalize( $input ) !== $expected ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
		throw new RuntimeException( "repository-relative path fixture failed: {$name}" );
	}
}
foreach ( $repository_paths['invalid'] as $name => $input ) {
	try {
		RepositoryRelativePath::normalize( $input );
	} catch ( InvalidArgumentException ) {
		continue;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
	throw new RuntimeException( "unsafe repository-relative path fixture was accepted: {$name}" );
}

// Reasons: closed vocabularies, one fixture per reason, and unchanged accept/reject behaviour.
$fail    = static function ( string $message ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- dependency-free CLI contract failure only.
	throw new RuntimeException( $message );
};
$reasons = require __DIR__ . '/fixtures/path-reasons.php';

// Archive path reasons: each fixture yields exactly its reason and every reason is exercised.
foreach ( $reasons['archive_paths'] as $reason => $archive_names ) {
	foreach ( $archive_names as $name ) {
		if ( ArchiveSafety::path_failure( $name ) !== $reason || null !== ArchiveSafety::normalize_path( $name ) ) {
			$fail( "path reason fixture failed: {$reason}" );
		}
	}
}
if ( array_keys( $reasons['archive_paths'] ) !== ArchiveSafety::PATH_FAILURES ) {
	$fail( 'path reason vocabulary and fixtures differ' );
}

// Legacy normalize_path, frozen verbatim as the equivalence reference.
$legacy_normalize_path = static function ( string $name ): ?array {
	if (
		'' === $name || strlen( $name ) > 4096 || str_starts_with( $name, '/' )
		|| 1 === preg_match( '/[\\x00-\\x1f\\x7f]/', $name )
		|| 1 === preg_match( '/[^\\x20-\\x7e]|[\\\\:<>"|?*]/', $name )
	) {
		return null;
	}
	$directory = str_ends_with( $name, '/' );
	$path      = $directory ? substr( $name, 0, -1 ) : $name;
	if ( '' === $path || str_contains( $path, '//' ) ) {
		return null;
	}
	foreach ( explode( '/', $path ) as $component ) {
		if (
			'' === $component || '.' === $component || '..' === $component
			|| strlen( $component ) > 255
			|| str_ends_with( $component, '.' ) || str_ends_with( $component, ' ' )
			|| 1 === preg_match( '/\\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\\.|\\z)/iD', $component )
		) {
			return null;
		}
	}
	return array(
		'path'      => $path,
		'directory' => $directory,
	);
};

$archive_bytes = static function ( int $length ): string {
	$parts = array();
	while ( $length > 255 ) {
		$parts[] = str_repeat( 'a', 255 );
		$length -= 256;
	}
	$parts[] = str_repeat( 'a', max( $length, 0 ) );
	return implode( '/', $parts );
};
$tokens        = array(
	'',
	'a',
	'A',
	'/',
	'//',
	'.',
	'..',
	'a.',
	'a ',
	' ',
	'con',
	'CON.txt',
	'Nul.',
	'aux.x.y',
	'com1',
	'com0',
	'lpt9.x',
	'lpt10',
	'console',
	'a\\b',
	'\\',
	'a:b',
	'<',
	'>',
	'"',
	'|',
	'?',
	'*',
	"\x00",
	"\n",
	"\x1f",
	"\x7f",
	"\x80",
	"\xc3\xa9",
	"\xc3\x28",
	"\xff",
	'%2e',
	'%',
	str_repeat( 'a', 254 ),
	str_repeat( 'a', 255 ),
	str_repeat( 'a', 256 ),
);
$archive_names = array();
$count         = count( $tokens );
for ( $one = 0; $one < $count; ++$one ) {
	$archive_names[] = $tokens[ $one ];
	for ( $two = 0; $two < $count; ++$two ) {
		$archive_names[] = $tokens[ $one ] . $tokens[ $two ];
		for ( $three = 0; $three < $count; ++$three ) {
			$archive_names[] = $tokens[ $one ] . $tokens[ $two ] . $tokens[ $three ];
		}
	}
}
$short = array_slice( $tokens, 0, 30 );
foreach ( $short as $one ) {
	foreach ( $short as $two ) {
		foreach ( $short as $three ) {
			foreach ( array( '/', 'x', '.' ) as $last ) {
				$archive_names[] = $one . $three . $two . $last;
				$archive_names[] = $one . '/' . $two . '/' . $three . $last;
			}
		}
	}
}
// Byte-length boundaries around the whole-name and component limits.
foreach ( array( 4094, 4095, 4096, 4097, 4098 ) as $length ) {
	$archive_names[] = $archive_bytes( $length );
	$archive_names[] = $archive_bytes( $length - 1 ) . '/';
	$archive_names[] = '/' . $archive_bytes( $length - 1 );
}
// Deterministic pseudo-random names: the same corpus on every run.
$pick = static fn ( string $seed, int $limit ): int => hexdec( substr( md5( 'ran-updater-support-54:' . $seed ), 0, 8 ) ) % $limit;
$pool = array_merge( $tokens, array( 'x', '/', '/', '.', '\\', "\x00" ) );
for ( $round = 0; $round < 40000; ++$round ) {
	$name = '';
	for ( $piece = $pick( "n{$round}", 8 ); $piece > 0; --$piece ) {
		$name .= $pool[ $pick( "p{$round}.{$piece}", count( $pool ) ) ];
	}
	$archive_names[] = $name;
	$archive_names[] = chr( $pick( "a{$round}", 256 ) ) . $name . chr( $pick( "z{$round}", 256 ) );
}

$seen_reasons = array();
foreach ( $archive_names as $name ) {
	$legacy  = $legacy_normalize_path( $name );
	$current = ArchiveSafety::normalize_path( $name );
	$reason  = ArchiveSafety::path_failure( $name );
	if ( $legacy !== $current ) {
		$fail( 'normalize_path differs from the legacy rule for a corpus name' );
	}
	if ( ( null === $reason ) !== ( null !== $legacy ) ) {
		$fail( 'path_failure disagrees with normalize_path for a corpus name' );
	}
	if ( null !== $reason ) {
		if ( ! in_array( $reason, ArchiveSafety::PATH_FAILURES, true ) ) {
			$fail( 'path_failure returned a value outside the closed vocabulary' );
		}
		$seen_reasons[ $reason ] = true;
	}
}
if ( count( $archive_names ) < 100000 || count( $seen_reasons ) !== count( ArchiveSafety::PATH_FAILURES ) ) {
	$fail( 'path corpus is too small or misses a reason' );
}

// Entry type reasons: fixtures and unchanged legacy codes (equality with the frozen reference also pins the two-code set).
foreach ( $reasons['entry_types'] as $reason => $cases ) {
	foreach ( $cases as [$origin, $attributes, $directory] ) {
		if ( ArchiveSafety::entry_type_reason( $origin, $attributes, $directory ) !== $reason ) {
			$fail( "entry type reason fixture failed: {$reason}" );
		}
	}
}
if ( array_keys( $reasons['entry_types'] ) !== ArchiveSafety::ENTRY_TYPE_REASONS ) {
	$fail( 'entry type reason vocabulary and fixtures differ' );
}

// Legacy entry_type_failure, frozen verbatim as the equivalence reference.
$legacy_entry_type_failure = static function ( ?int $origin_os, ?int $attributes, bool $directory ): ?string {
	if ( null === $origin_os || null === $attributes ) {
		return 'entry_metadata_invalid';
	}
	if ( 3 === $origin_os ) {
		$type = ( $attributes >> 16 ) & 0170000;
		if ( ! in_array( $type, array( 0, 0040000, 0100000 ), true ) ) {
			return 'entry_type_unsupported';
		}
		return 0 === $type || ( 0040000 === $type ) === $directory ? null : 'entry_metadata_invalid';
	}
	if ( 0 !== $origin_os ) {
		return 'entry_type_unsupported';
	}
	$flags = $attributes & 0xff;
	if ( 0 !== ( $flags & 0x08 ) ) {
		return 'entry_type_unsupported';
	}
	return 0 === $flags || ( 0 !== ( $flags & 0x10 ) ) === $directory ? null : 'entry_metadata_invalid';
};
$attribute_values          = array( null, 0, 1, 0x08, 0x10, 0x18, 0x20, 0xff, 0x100, 0x1ff, -1 );
foreach ( array( 0, 0010000, 0020000, 0040000, 0060000, 0100000, 0120000, 0140000, 0170000 ) as $mode_bits ) {
	foreach ( array( 0, 0x10, 0x08, 0xff ) as $low ) {
		$attribute_values[] = ( $mode_bits << 16 ) | $low;
	}
}
$seen_entry_reasons = array();
foreach ( array( null, -1, 0, 1, 2, 3, 4, 10, 19, 255, 256 ) as $origin ) {
	foreach ( $attribute_values as $attributes ) {
		foreach ( array( false, true ) as $directory ) {
			$legacy          = $legacy_entry_type_failure( $origin, $attributes, $directory );
			$reason          = ArchiveSafety::entry_type_reason( $origin, $attributes, $directory );
			$current_failure = ArchiveSafety::entry_type_failure( $origin, $attributes, $directory );
			if ( $current_failure !== $legacy ) {
				$fail( 'entry_type_failure differs from the legacy rule' );
			}
			if ( ( null === $reason ) !== ( null === $legacy ) ) {
				$fail( 'entry_type_reason disagrees with entry_type_failure' );
			}
			if ( null !== $reason ) {
				if ( ! in_array( $reason, ArchiveSafety::ENTRY_TYPE_REASONS, true ) ) {
					$fail( 'entry_type_reason returned a value outside the closed vocabulary' );
				}
				$seen_entry_reasons[ $reason ] = true;
			}
		}
	}
}
if ( count( $seen_entry_reasons ) !== count( ArchiveSafety::ENTRY_TYPE_REASONS ) ) {
	$fail( 'entry type grid misses a reason' );
}

// Repository path reasons.
foreach ( $reasons['repository_paths'] as $reason => $inputs ) {
	foreach ( $inputs as $input ) {
		try {
			RepositoryRelativePath::normalize( $input );
		} catch ( InvalidRepositoryPathException $error ) {
			if ( $error->reason() !== $reason ) {
				$fail( "repository path reason fixture failed: {$reason}" );
			}
			continue;
		}
		$fail( "repository path reason fixture was accepted: {$reason}" );
	}
}
if ( array_keys( $reasons['repository_paths'] ) !== InvalidRepositoryPathException::REASONS ) {
	$fail( 'repository path reason vocabulary and fixtures differ' );
}

// Legacy RepositoryRelativePath::normalize, frozen as the accept/reject reference.
$legacy_repository_path = static function ( string $value ): ?string {
	$drive   = '/^[A-Za-z]:/';
	$control = '/[\x00-\x1F\x7F]/';
	if ( 1 === preg_match( $control, $value ) ) {
		return null;
	}
	$value = trim( $value );
	if ( '' === $value || str_starts_with( $value, '/' ) || str_contains( $value, '\\' ) || 1 === preg_match( $drive, $value ) ) {
		return null;
	}
	if ( str_ends_with( $value, '/' ) ) {
		$value = substr( $value, 0, -1 );
	}
	$segments = explode( '/', $value );
	foreach ( $segments as $index => $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment ) {
			return null;
		}
		$decoded = $segment;
		$done    = false;
		for ( $pass = 0; $pass < 8; ++$pass ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				$done = true;
				break;
			}
			$decoded = $next;
			if (
				'.' === $decoded || '..' === $decoded || str_contains( $decoded, '/' ) || str_contains( $decoded, '\\' )
				|| ( 0 === $index && 1 === preg_match( $drive, $decoded ) ) || 1 === preg_match( $control, $decoded )
			) {
				return null;
			}
		}
		if ( ! $done && rawurldecode( $decoded ) !== $decoded ) {
			return null;
		}
	}
	return implode( '/', $segments );
};
$repository_tokens      = array(
	'',
	' ',
	'a',
	'/',
	'.',
	'..',
	'%',
	'%25',
	'%2e',
	'%2E',
	'%252e',
	'%2f',
	'%252f',
	'%5c',
	'%255c',
	'%00',
	'%0a',
	'%41',
	'%3A',
	'%253A',
	'%43',
	'C:',
	'c%3A',
	'\\',
	"\0",
	"\t",
	"\n",
	"\x7f",
	"\xc3\xa9",
	str_repeat( '%25', 7 ),
	str_repeat( '%25', 8 ),
	str_repeat( '%25', 9 ),
	'%' . str_repeat( '25', 7 ) . '41',
	'%' . str_repeat( '25', 8 ) . '41',
);
$repository_inputs      = array();
$repository_count       = count( $repository_tokens );
for ( $one = 0; $one < $repository_count; ++$one ) {
	for ( $two = 0; $two < $repository_count; ++$two ) {
		for ( $three = 0; $three < $repository_count; ++$three ) {
			$repository_inputs[] = $repository_tokens[ $one ] . $repository_tokens[ $two ] . $repository_tokens[ $three ];
			$repository_inputs[] = $repository_tokens[ $one ] . '/' . $repository_tokens[ $two ] . '/' . $repository_tokens[ $three ];
		}
	}
}
$seen_repository_reasons = array();
foreach ( $repository_inputs as $input ) {
	$legacy = $legacy_repository_path( $input );
	try {
		$current = RepositoryRelativePath::normalize( $input );
	} catch ( InvalidArgumentException $error ) {
		$current = null;
		if ( ! $error instanceof InvalidRepositoryPathException || 'The repository-relative path is invalid.' !== $error->getMessage()
			|| ! in_array( $error->reason(), InvalidRepositoryPathException::REASONS, true ) ) {
			$fail( 'repository path rejection is not a fixed-message closed-reason exception' );
		}
		$seen_repository_reasons[ $error->reason() ] = true;
	}
	if ( $legacy !== $current ) {
		$fail( 'RepositoryRelativePath differs from the legacy accept/reject rule' );
	}
}
if ( count( $repository_inputs ) < 60000 || count( $seen_repository_reasons ) !== count( InvalidRepositoryPathException::REASONS ) ) {
	$fail( 'repository path corpus is too small or misses a reason: ' . implode( ',', array_diff( InvalidRepositoryPathException::REASONS, array_keys( $seen_repository_reasons ) ) ) );
}

// Screening: no input text, credential, URL, digest or local path reaches the message or reason.
$sensitive_inputs = array(
	'https://user:s3cr3tT0ken@example.com/../x',
	'/Users/someone/Local Sites/site/wp-config.php',
	'packages/ghp_0123456789abcdefghijklmnopqrstuvwxyz\\x',
	'C:\\Users\\someone\\.ssh\\id_rsa',
	'sha256:' . str_repeat( 'ab', 32 ),
	"packages/token=abc123\0",
);
foreach ( $sensitive_inputs as $input ) {
	try {
		RepositoryRelativePath::normalize( $input );
	} catch ( InvalidArgumentException $error ) {
		$text = $error->getMessage() . '|' . ( $error instanceof InvalidRepositoryPathException ? $error->reason() : '' ) . '|' . $error->getPrevious()?->getMessage();
		foreach ( array( 's3cr3tT0ken', 'ghp_', '/Users', 'someone', 'id_rsa', 'wp-config', 'token=', 'abab', '://' ) as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				$fail( 'repository path exception exposes input text' );
			}
		}
	}
	$archive_reason = ArchiveSafety::path_failure( $input );
	if ( null !== $archive_reason && ! in_array( $archive_reason, ArchiveSafety::PATH_FAILURES, true ) ) {
		$fail( 'archive path reason is outside the closed vocabulary' );
	}
}
foreach ( array_merge( ArchiveSafety::PATH_FAILURES, ArchiveSafety::ENTRY_TYPE_REASONS, InvalidRepositoryPathException::REASONS ) as $identifier ) {
	if ( 1 !== preg_match( '/\A[a-z]+(?:_[a-z]+)+\z/', $identifier ) ) {
		$fail( 'reason identifier is not a fixed lowercase snake_case word list' );
	}
}

// Subclass compatibility: an existing catch of InvalidArgumentException still works.
$caught = false;
try {
	RepositoryRelativePath::normalize( '../x' );
} catch ( InvalidArgumentException $error ) {
	$caught = 'The repository-relative path is invalid.' === $error->getMessage() && 0 === $error->getCode() && null === $error->getPrevious();
}
if ( ! $caught ) {
	$fail( 'a plain InvalidArgumentException catch no longer receives the fixed message' );
}
