<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\InvalidRepositoryPathException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

final class RepositoryRelativePathTest extends TestCase {

	public function test_accepts_exact_path_and_component_byte_limits(): void {
		$max_component = 'packages/' . str_repeat( 'a', RepositoryRelativePath::MAX_COMPONENT_BYTES );
		self::assertSame( $max_component, RepositoryRelativePath::normalize( $max_component ) );

		$path_4096 = str_repeat( str_repeat( 'a', 255 ) . '/', 15 ) . str_repeat( 'a', 254 ) . '/a';
		self::assertSame( RepositoryRelativePath::MAX_PATH_BYTES, strlen( $path_4096 ) );
		self::assertSame( $path_4096, RepositoryRelativePath::normalize( $path_4096 ) );

		$path_4096_with_slash = str_repeat( str_repeat( 'a', 255 ) . '/', 16 );
		self::assertSame( RepositoryRelativePath::MAX_PATH_BYTES, strlen( $path_4096_with_slash ) );
		self::assertSame( substr( $path_4096_with_slash, 0, -1 ), RepositoryRelativePath::normalize( $path_4096_with_slash ) );
	}

	public function test_rejects_oversized_paths_and_components_with_fixed_reasons(): void {
		$oversized_path = str_repeat( str_repeat( 'a', 255 ) . '/', 16 ) . 'a';
		self::assertSame( RepositoryRelativePath::MAX_PATH_BYTES + 1, strlen( $oversized_path ) );
		$this->assert_rejected_with_reason( $oversized_path, 'path_too_long' );

		$space_padded_4097 = ' ' . str_repeat( str_repeat( 'a', 255 ) . '/', 15 ) . str_repeat( 'a', 255 ) . ' ';
		self::assertSame( RepositoryRelativePath::MAX_PATH_BYTES + 1, strlen( $space_padded_4097 ) );
		$this->assert_rejected_with_reason( $space_padded_4097, 'path_too_long' );

		$oversized_segment = 'packages/' . str_repeat( 'b', RepositoryRelativePath::MAX_COMPONENT_BYTES + 1 );
		$this->assert_rejected_with_reason( $oversized_segment, 'component_too_long' );
	}

	public function test_length_limits_precede_later_scanning_and_decoding_rules(): void {
		$oversized_with_control = str_repeat( 'a', RepositoryRelativePath::MAX_PATH_BYTES ) . "\0";
		$this->assert_rejected_with_reason( $oversized_with_control, 'path_too_long' );

		$oversized_encoded_segment = 'packages/' . str_repeat( 'a', RepositoryRelativePath::MAX_COMPONENT_BYTES - 2 ) . '%2f';
		self::assertSame( RepositoryRelativePath::MAX_COMPONENT_BYTES + 1, strlen( substr( $oversized_encoded_segment, 9 ) ) );
		$this->assert_rejected_with_reason( $oversized_encoded_segment, 'component_too_long' );
	}

	private function assert_rejected_with_reason( string $input, string $expected_reason ): void {
		try {
			RepositoryRelativePath::normalize( $input );
			self::fail( 'Expected InvalidRepositoryPathException for reason: ' . $expected_reason );
		} catch ( InvalidRepositoryPathException $exception ) {
			self::assertSame( InvalidRepositoryPathException::MESSAGE, $exception->getMessage() );
			self::assertSame( $expected_reason, $exception->reason() );
		}
	}
}
