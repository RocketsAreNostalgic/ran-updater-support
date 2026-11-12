<?php
declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/**
 * Decides whether untrusted failure text may be shown or recorded.
 *
 * One policy, implemented once, for Core and both updaters. The whole text is
 * screened first and only then bounded. A text that matches any rule is dropped
 * whole; nothing is ever redacted, rewritten or partly kept. The result is the
 * accepted printable-ASCII text or null, and there is no other outcome.
 *
 * The screen is by shape, deliberately broad, and a safety net rather than a
 * proof: false positives are accepted, false negatives are not. Sinks must still
 * prefer fixed or provider-public text, and consumers must escape the result on
 * output. See the README for the policy, the rule list and the dropped benign
 * shapes. `tests/fixtures/failure-text-corpus.php` is the cross-package contract.
 *
 * Every row of RULES and every `// rule:` line in `hostile()` is one named rule.
 * `tests/failure-text-mutation.php` removes each in turn and requires the corpus
 * to notice, so a rule added here needs a corpus entry that only it rejects.
 */
final class FailureText {

	/** The longest text, in bytes, that is screened. Longer text is dropped, not truncated. */
	public const MAX_INPUT_LENGTH = 4096;

	/**
	 * Single-shape rules, applied to the whole printable-ASCII text. Credential
	 * words match inside a longer word (`usertoken`) and across an underscore
	 * (`client_secret`), but not before a letter (`tokenizer`).
	 *
	 * @var array<string, string>
	 */
	private const RULES = array(
		'angle_bracket'            => '#[<>]#',
		'ampersand'                => '#&#',
		'bracket_or_brace'         => '#[{}\[\]]#',
		'pipe'                     => '#\|#',
		'semicolon'                => '#;#',
		'backtick'                 => '#`#',
		'equals'                   => '#=#',
		'at_sign'                  => '#@#',
		'percent_escape'           => '#%[0-9a-f]{2}#i',
		'slash'                    => '#/#',
		'backslash'                => '#\\\\#',
		'tilde'                    => '#~#',
		'colon_attached'           => '#:(?!\s|\z)#',
		'question_attached'        => '#\?(?!\s|\z)#',
		'url_scheme_word'          => '#(?<![a-z0-9])(?:https?|ftps?|sftp|ssh|wss?|ldaps?|smb|javascript|vbscript|mailto)\s*:#i',
		'wordpress_path_word'      => '#wp-(?:content|config|admin|includes)|\.ht(?:access|passwd)|public_html#i',
		'session_cookie_name'      => '#wordpress_(?:logged_in|sec|test_cookie)|wp-settings-|phpsessid#i',
		'private_key_material'     => '#private[ _.-]?key|-----begin|ssh-(?:rsa|ed25519)#i',
		'credential_password'      => '#(?:passw[o0]rds?|passwd|pwd|pass[ _-]?(?:phrase|code))(?![a-z])\s*\S#i',
		'credential_secret'        => '#secrets?(?![a-z])\s*\S#i',
		'credential_token'         => '#tokens?(?![a-z])\s*\S#i',
		'credential_api_key'       => '#api[ _.-]?keys?(?![a-z])\s*\S#i',
		'credential_authorization' => '#authorization(?![a-z])\s*\S#i',
		'credential_signature'     => '#signatures?(?![a-z])\s*\S#i',
		'credential_nonce'         => '#nonces?(?![a-z])\s*\S#i',
		'credential_cookie'        => '#cookies?(?![a-z])\s*\S#i',
		'credential_generic'       => '#credentials?(?![a-z])\s*\S#i',
		'bearer_or_basic'          => '#(?<![a-z0-9])(?:bearer|basic)\s+\S#i',
		'prefix_github'            => '#gh[pousr][_-]|github_pat_#i',
		'prefix_gitlab'            => '#gl(?:pat|dt|rt|cbt|ptt|soat)-#i',
		'prefix_slack'             => '#xox[abprs]-|xapp-#i',
		'prefix_npm'               => '#(?<![a-z0-9])npm_#i',
		'prefix_payment'           => '#(?<![a-z0-9])(?:(?:sk|pk|rk)_(?:live|test)_|sk-)#i',
		'prefix_aws'               => '#(?<![A-Za-z0-9])(?:AKIA|ASIA)[A-Z0-9]#',
		'prefix_jwt'               => '#(?<![A-Za-z0-9])eyJ#',
		'prefix_bitbucket'         => '#(?<![A-Za-z0-9])AT(?:BB|CTT3|ATT3)#',
		'prefix_google'            => '#(?<![A-Za-z0-9])AIza|(?<![a-z0-9])ya29\.#',
		'prefix_other_vendor'      => '#shp(?:at|ca|pa|ss)_|dop_v1_#i',
		'digest_prefix'            => '#(?<![a-z0-9])(?:sha[ _-]?\d|md5|blake2)#i',
	);

	/**
	 * Returns the screened text, bounded to $max_length bytes, or null.
	 *
	 * Null means drop the whole text: it was empty, longer than MAX_INPUT_LENGTH,
	 * not printable ASCII, matched a rule, or $max_length was outside 1 to
	 * MAX_INPUT_LENGTH. The whole text is screened before it is bounded, so a
	 * secret past or across the bound drops the text. Surrounding spaces are
	 * trimmed; nothing else is changed.
	 *
	 * A validating sink that must reject over-long text instead of truncating it
	 * compares: `FailureText::screen( $text, $max ) === $text`.
	 *
	 * @param string $text       Untrusted failure text.
	 * @param int    $max_length Longest result to return, in bytes (1 to MAX_INPUT_LENGTH).
	 */
	public static function screen( string $text, int $max_length ): ?string {
		if ( $max_length < 1 || $max_length > self::MAX_INPUT_LENGTH || strlen( $text ) > self::MAX_INPUT_LENGTH ) {
			return null;
		}
		// Non-ASCII, control, format and invisible characters are never accepted, and are never normalized away.
		if ( 1 !== preg_match( '#\A[\x20-\x7E]+\z#', $text ) ) { // rule: printable_ascii_only
			return null;
		}
		$text = trim( $text, ' ' );
		if ( '' === $text || self::hostile( $text ) ) {
			return null;
		}

		return rtrim( substr( $text, 0, $max_length ) );
	}

	/**
	 * Returns the text only when it is exactly a lowercase 40- or 64-digit hex commit id.
	 *
	 * This is the narrow opt-in for resolved commit ids, which `screen()` always
	 * drops because they have the shape of a digest. Nothing else is allowed: no
	 * other length, case, surrounding text or whitespace.
	 *
	 * @param string $text Candidate commit id.
	 */
	public static function commit_id( string $text ): ?string {
		return 1 === preg_match( '#\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z#', $text ) ? $text : null;
	}

	/**
	 * True when the printable-ASCII text matches any rule.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function hostile( string $text ): bool {
		return self::matches_table( $text )
			|| self::hex_run( $text ) // rule: hex_run
			|| self::hex_word( $text ) // rule: hex_word
			|| self::long_mixed_run( $text ) // rule: long_mixed_run
			|| self::long_word( $text ) // rule: long_word
			|| self::dense_chunks( $text ); // rule: dense_chunks
	}

	/**
	 * Applies the single-shape rules.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function matches_table( string $text ): bool {
		foreach ( self::RULES as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Thirty-two or more hex digits once every separator, space and punctuation mark is removed.
	 *
	 * Catches digests and UUIDs however they are broken up.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function hex_run( string $text ): bool {
		return 1 === preg_match( '#[0-9a-f]{32}#i', (string) preg_replace( '#[^0-9A-Za-z]+#', '', $text ) );
	}

	/**
	 * Sixteen or more hex digits in one word once punctuation is removed.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function hex_word( string $text ): bool {
		return 1 === preg_match( '#[0-9a-f]{16}#i', (string) preg_replace( '#[^0-9A-Za-z\s]+#', '', $text ) );
	}

	/**
	 * A 32+ character word, with punctuation removed, that holds a digit or a capital letter.
	 *
	 * Base64, base64url and mixed tokens. A lowercase-only snake_case code is not matched.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function long_mixed_run( string $text ): bool {
		return 1 === preg_match( '#(?<![0-9A-Za-z])(?=[0-9A-Za-z]*[0-9A-Z])[0-9A-Za-z]{32}#', (string) preg_replace( '#[^0-9A-Za-z\s]+#', '', $text ) );
	}

	/**
	 * A 25+ character run of letters and digits, which no ordinary word or identifier part reaches.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function long_word( string $text ): bool {
		return 1 === preg_match( '#[0-9A-Za-z]{25}#', $text );
	}

	/**
	 * Consecutive space-separated chunks that each mix letters and digits, 24+ characters in all.
	 *
	 * Words of 32 or more characters belong to long_mixed_run. Catches an encoded secret split by whitespace.
	 *
	 * @param string $text Text already confined to printable ASCII.
	 */
	private static function dense_chunks( string $text ): bool {
		$run = 0;
		foreach ( (array) preg_split( '#\s+#', (string) preg_replace( '#[^0-9A-Za-z\s]+#', '', $text ) ) as $word ) {
			$word = (string) $word;
			if ( strlen( $word ) < 32 && 1 === preg_match( '#\d#', $word ) && 1 === preg_match( '#[A-Za-z]#', $word ) ) {
				$run += strlen( $word );
				if ( $run >= 24 ) {
					return true;
				}
			} else {
				$run = 0;
			}
		}

		return false;
	}
}
