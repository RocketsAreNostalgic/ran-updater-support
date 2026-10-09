<?php
/**
 * Expose native PHP comments from deliberately valid and malformed fixture data.
 *
 * @package RanUpdaterSupport
 */

declare(strict_types=1);

$ran_updater_support_source = stream_get_contents( STDIN );
if ( false === $ran_updater_support_source ) {
	throw new RuntimeException( 'Cannot read the comment fixture input.' );
}
$ran_updater_support_tokens = token_get_all( $ran_updater_support_source );
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.OutputNotEscaped -- Preserve the native JSON_THROW_ON_ERROR/stdout comment-token protocol for the Node harness without executing malformed fixture data or loading WordPress.
echo json_encode(
	array_values(
		array_filter(
			$ran_updater_support_tokens,
			static fn( $token ) => is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
		)
	),
	JSON_THROW_ON_ERROR
);
