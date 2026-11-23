<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/** Extract header occurrences from WordPress plugin PHP and theme CSS bytes. */
final class WordPressHeaders {

	public const MAX_BYTES = 8192;

	/**
	 * Scan the first 8 KiB using WordPress-style, case-insensitive header labels.
	 *
	 * Each requested key maps to its occurrences in file order. Absent keys are
	 * omitted. Empty values, "0", and identical duplicates are retained.
	 *
	 * @param string                $contents Source bytes.
	 * @param array<string, string> $labels   Result keys mapped to their on-disk labels.
	 * @return array<string, non-empty-list<string>>
	 */
	public static function parse( string $contents, array $labels ): array {
		$contents = substr( $contents, 0, self::MAX_BYTES );
		if ( "\xef\xbb\xbf" === substr( $contents, 0, 3 ) ) {
			$contents = substr( $contents, 3 );
		}
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );

		$headers = array();
		foreach ( $labels as $key => $label ) {
			$pattern = '/^[ \t]*(?:<\?(?:php)?)?[ \t\/*#@]*'
				. preg_quote( $label, '/' ) . ':(.*?)(?:\*\/|\?>|$)/mi';
			preg_match_all( $pattern, $contents, $matches );
			$values = $matches[1] ?? array();
			if ( array() !== $values ) {
				$headers[ $key ] = array_map(
					static fn ( string $value ): string => trim( $value, " \t\v\f" ),
					$values
				);
			}
		}

		return $headers;
	}
}
