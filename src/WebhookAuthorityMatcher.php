<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/**
 * Stateless authorization matcher over non-secret canonical webhook profile metadata.
 *
 * Callers MUST verify provider identity before calling these methods and pass
 * only non-secret profile metadata (`scope`, `target`, `authority_id`).
 */
final class WebhookAuthorityMatcher {

	/**
	 * Match a webhook delivery's repository authority ID and repository locator
	 * against a set of authorized profiles.
	 *
	 * Callers MUST verify provider identity before calling this method and pass
	 * only non-secret profile metadata (`scope`, `target`, `authority_id`).
	 * Fails closed when `$repository_authority_id` is empty and skips malformed
	 * profile rows or rows missing string `scope`, `target`, or `authority_id` keys.
	 *
	 * @param array<mixed> $profiles Authorized profile rows with string `scope`, `target`, and `authority_id` keys.
	 */
	public static function matches_authorized_profiles( array $profiles, string $repository_authority_id, string $repository ): bool {
		if ( '' === $repository_authority_id ) {
			return false;
		}

		$repository = strtolower( trim( $repository, '/' ) );
		$owner      = explode( '/', $repository, 2 )[0];

		foreach ( $profiles as $profile ) {
			if (
				! is_array( $profile )
				|| ! is_string( $profile['scope'] ?? null )
				|| ! is_string( $profile['target'] ?? null )
				|| ! is_string( $profile['authority_id'] ?? null )
			) {
				continue;
			}

			$scope  = strtolower( trim( $profile['scope'] ) );
			$target = strtolower( trim( $profile['target'], " \t\n\r\0\x0B/" ) );

			if (
				( 'owner' === $scope && '' !== $target && $target === $owner )
				|| ( 'repository' === $scope && '' !== $profile['authority_id'] && hash_equals( $profile['authority_id'], $repository_authority_id ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare a configured repository target against a repository locator,
	 * trimming leading and trailing slashes and folding ASCII case.
	 */
	public static function repository_target_matches( string $target, string $repository_locator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repository_locator, '/' ) );
	}
}
