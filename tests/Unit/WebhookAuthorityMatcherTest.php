<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\UpdaterSupport\V1\GitReferenceSyntax;
use RAN\UpdaterSupport\V1\WebhookAuthorityMatcher;
use stdClass;

final class WebhookAuthorityMatcherTest extends TestCase {

	public function test_matches_owner_scope_with_case_slash_and_whitespace_normalization(): void {
		$profiles = array(
			array(
				'scope'        => '  OWNER  ',
				'target'       => " \t/Acme-Org/\r\n ",
				'authority_id' => '',
			),
		);

		self::assertTrue(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'repo-authority-123',
				'/ACME-ORG/My-Plugin/'
			)
		);
		self::assertTrue(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'repo-authority-123',
				'acme-org'
			)
		);
		self::assertFalse(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'repo-authority-123',
				'other-org/My-Plugin'
			)
		);
		self::assertFalse(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'repo-authority-123',
				' acme-org/My-Plugin'
			)
		);
	}

	public function test_matches_repository_scope_by_exact_authority_id_even_after_locator_change(): void {
		$profiles = array(
			array(
				'scope'        => 'Repository',
				'target'       => 'old-owner/old-repo',
				'authority_id' => '{11111111-2222-3333-4444-555555555555}',
			),
		);

		self::assertTrue(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'{11111111-2222-3333-4444-555555555555}',
				'new-owner/renamed-repo'
			)
		);
		self::assertTrue(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'{11111111-2222-3333-4444-555555555555}',
				''
			)
		);
	}

	public function test_rejects_repository_scope_authority_id_mismatch_even_when_locator_matches(): void {
		$profiles = array(
			array(
				'scope'        => 'repository',
				'target'       => 'acme/plugin',
				'authority_id' => 'Repo-Authority-1',
			),
		);

		self::assertFalse(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'Repo-Authority-2',
				'acme/plugin'
			)
		);
		self::assertFalse(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				'repo-authority-1',
				'acme/plugin'
			)
		);
		self::assertFalse(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				$profiles,
				' Repo-Authority-1 ',
				'acme/plugin'
			)
		);
	}

	public function test_rejects_empty_repository_authority_id_even_with_matching_owner_scope(): void {
		$owner_profile = array(
			array(
				'scope'        => 'owner',
				'target'       => 'acme',
				'authority_id' => '',
			),
		);
		$repo_profile  = array(
			array(
				'scope'        => 'repository',
				'target'       => 'acme/plugin',
				'authority_id' => '',
			),
		);

		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $owner_profile, '', 'acme/plugin' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $repo_profile, '', 'acme/plugin' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $repo_profile, 'repo-1', 'acme/plugin' ) );
	}

	public function test_rejects_empty_owner_or_empty_target(): void {
		$empty_targets = array(
			array(
				'scope'        => 'owner',
				'target'       => '',
				'authority_id' => '',
			),
			array(
				'scope'        => 'owner',
				'target'       => '   ',
				'authority_id' => '',
			),
			array(
				'scope'        => 'owner',
				'target'       => '///',
				'authority_id' => '',
			),
			array(
				'scope'        => 'owner',
				'target'       => " / \t\n/ ",
				'authority_id' => '',
			),
		);

		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $empty_targets, 'repo-1', '' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $empty_targets, 'repo-1', '///' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $empty_targets, 'repo-1', 'acme/plugin' ) );

		$valid_owner = array(
			array(
				'scope'        => 'owner',
				'target'       => 'acme',
				'authority_id' => '',
			),
		);
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $valid_owner, 'repo-1', '' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $valid_owner, 'repo-1', '///' ) );
	}

	public function test_rejects_unknown_scope_and_malformed_profiles(): void {
		$malformed = array(
			null,
			'owner',
			123,
			new stdClass(),
			array(),
			array(
				'scope'  => 'owner',
				'target' => 'acme',
			),
			array(
				'scope'        => 'owner',
				'authority_id' => '',
			),
			array(
				'target'       => 'acme',
				'authority_id' => '',
			),
			array(
				'scope'        => 123,
				'target'       => 'acme',
				'authority_id' => '',
			),
			array(
				'scope'        => 'owner',
				'target'       => array( 'acme' ),
				'authority_id' => '',
			),
			array(
				'scope'        => 'owner',
				'target'       => 'acme',
				'authority_id' => null,
			),
			array(
				'scope'        => 'organization',
				'target'       => 'acme',
				'authority_id' => 'repo-1',
			),
			array(
				'scope'        => 'workspace',
				'target'       => 'acme',
				'authority_id' => 'repo-1',
			),
			array(
				'scope'        => '',
				'target'       => 'acme',
				'authority_id' => 'repo-1',
			),
		);

		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( array(), 'repo-1', 'acme/plugin' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $malformed, 'repo-1', 'acme/plugin' ) );
	}

	public function test_iterates_past_malformed_and_non_matching_profiles(): void {
		$profiles = array(
			'malformed-row',
			array(
				'scope'  => 'owner',
				'target' => 'acme',
			),
			array(
				'scope'        => 'repository',
				'target'       => 'acme/plugin',
				'authority_id' => 'repo-other',
			),
			array(
				'scope'        => 'owner',
				'target'       => 'other-org',
				'authority_id' => '',
			),
			array(
				'scope'        => 'repository',
				'target'       => 'old/name',
				'authority_id' => 'repo-match',
			),
		);

		self::assertTrue( WebhookAuthorityMatcher::matches_authorized_profiles( $profiles, 'repo-match', 'acme/plugin' ) );
		self::assertFalse( WebhookAuthorityMatcher::matches_authorized_profiles( $profiles, 'repo-miss', 'acme/plugin' ) );
	}

	#[DataProvider( 'repository_target_cases' )]
	public function test_repository_target_matches_preserves_slash_case_and_whitespace_semantics(
		string $target,
		string $repository_locator,
		bool $expected
	): void {
		self::assertSame(
			$expected,
			WebhookAuthorityMatcher::repository_target_matches( $target, $repository_locator )
		);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function repository_target_cases(): array {
		return array(
			'exact match'                  => array( 'acme/plugin', 'acme/plugin', true ),
			'ASCII case insensitive'       => array( 'Acme/Plugin', 'acme/plugin', true ),
			'leading and trailing slashes' => array( '///Acme/Plugin///', '/acme/plugin/', true ),
			'leading whitespace rejected'  => array( ' acme/plugin', 'acme/plugin', false ),
			'trailing whitespace rejected' => array( 'acme/plugin ', 'acme/plugin', false ),
			'locator whitespace rejected'  => array( 'acme/plugin', ' acme/plugin ', false ),
			'matching whitespace both'     => array( ' acme/plugin ', ' ACME/PLUGIN ', true ),
			'interior double slash'        => array( 'acme//plugin', 'acme/plugin', false ),
			'different repository'         => array( 'acme/plugin-a', 'acme/plugin-b', false ),
			'different owner'              => array( 'acme/plugin', 'other/plugin', false ),
			'empty and slash-only match'   => array( '', '///', true ),
			'non-empty vs empty'           => array( 'acme/plugin', '', false ),
		);
	}

	public function test_executes_standalone_without_wordpress_or_core_symbols(): void {
		self::assertFalse( defined( 'ABSPATH' ) );
		self::assertFalse( function_exists( 'add_action' ) );
		self::assertFalse( class_exists( 'RAN\\RepositoryProvider\\GitReferenceSyntax', false ) );
		self::assertFalse( class_exists( 'RAN\\Booster\\Webhook\\WebhookPolicy', false ) );

		self::assertTrue( GitReferenceSyntax::is_valid_named_reference( 'release/v1.0.0' ) );
		self::assertTrue(
			WebhookAuthorityMatcher::matches_authorized_profiles(
				array(
					array(
						'scope'        => 'owner',
						'target'       => 'acme',
						'authority_id' => '',
					),
				),
				'repo-42',
				'acme/plugin'
			)
		);
	}
}
