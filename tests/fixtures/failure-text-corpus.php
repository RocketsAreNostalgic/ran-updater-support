<?php
/**
 * Cross-package corpus for the shared failure-text screener.
 *
 * Returns six maps of unique label to string:
 *
 * - `hostile`: dropped (null) by `FailureText::strict()` and `display()` at every
 *   bound from 1 to 4096.
 * - `strict_only`: dropped by `strict()` (a credential word followed by text) but
 *   returned by `display()`, which applies shape rules only.
 * - `display_only`: curated sentences returned by `display()` and dropped by `strict()`.
 * - `benign`: returned unchanged by `strict( $value, 4096 )` and `display()`, and as
 *   an exact bounded prefix at any smaller bound.
 * - `identifiers`: returned unchanged by `FailureText::identifier()`.
 * - `not_identifiers`: dropped by `identifier()`.
 *
 * A consumer's tests load this file and assert both lists against the shared
 * screener, so the policy cannot drift between packages. All values are
 * synthetic shapes; none is a real credential. Keep this file ASCII: control and
 * non-ASCII characters are written as escapes.
 *
 * @package RAN\UpdaterSupport\V1
 */

declare(strict_types=1);

return ( static function (): array {
	$sha1   = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
	$sha256 = hash( 'sha256', 'x' );
	$sha512 = hash( 'sha512', 'x' );
	$md5    = hash( 'md5', 'x' );
	$groups = str_split( $sha256, 8 );

	$hostile = array(
		// Markup and entities.
		'markup tag'                      => '<script>alert(1)</script>',
		'markup attribute'                => '<img src=x onerror=alert(1)>',
		'markup close only'               => 'a > b',
		'entity named'                    => '&lt;script&gt;',
		'entity numeric'                  => '&#60;script&#62;',
		'entity hex'                      => '&#x3c;b&#x3e;',
		'ampersand without semicolon'     => '&lt script',
		'ampersand prose'                 => 'fish & chips',

		// Characters that carry structure, paths, or assignments.
		'brackets'                        => 'see [ref] now',
		'braces'                          => 'value {name}',
		'bracketed path'                  => '[/etc/passwd]',
		'pipe'                            => 'a | b',
		'piped path'                      => 'x|/etc/passwd',
		'semicolon'                       => 'one; two',
		'backtick'                        => 'run `cmd`',
		'assignment'                      => 'a=b',
		'assignment path'                 => 'x=/etc/passwd',
		'assignment nonce'                => 'nonce=abc',
		'assignment cookie'               => 'wordpress_logged_in_x=123',
		'dsn'                             => 'mysql:host=db;dbname=x',
		'query credential'                => '?access_token=abc',
		'query credential in text'        => 'failed at step?access_token=abc',
		'query word attached'             => 'what?now',
		'digest assignment'               => 'sha256=' . $sha256,
		'digest header assignment'        => 'x-hub-signature-256=sha256=' . $sha256,
		'at sign spaced'                  => 'name @ host',
		'email'                           => 'user@example.com',
		'scp remote'                      => 'git@github.com:o/r.git',
		'userinfo without scheme'         => 'admin:hunter2@host',
		'userinfo name value'             => 'name:value@host',
		'userinfo with scheme'            => 'https://user:hunter2@example.test/x',
		'name colon value'                => 'ratio 3:1',
		'name colon value credential'     => 'admin:hunter2',
		'percent escaped at'              => 'user%40host',
		'percent escaped dots'            => '%2e%2e',
		'percent escape spaced'           => 'a %41 b',

		// URLs and schemes, including obfuscated forms.
		'url'                             => 'https://example.test/archive.zip',
		'url spaced scheme'               => 'https ://x',
		'url spaced both sides'           => 'https : //x',
		'scheme word spaced'              => 'see https : example',
		'scheme word javascript'          => 'javascript : void',
		'scheme word mailto'              => 'mailto :someone',
		'mailto'                          => 'mailto:a@b.c',
		'fullwidth scheme'                => "https\u{FF1A}//evil.com",
		'file url'                        => 'file:///etc/passwd',
		'ftp url'                         => 'ftp://host/file',

		// Paths anywhere in the text.
		'absolute path'                   => '/etc/passwd',
		'absolute path in text'           => 'copy failed for /srv/demo',
		'path after colon'                => 'at:/etc/passwd',
		'path after error colon'          => 'error:/var/www/x',
		'home directory path'             => '/home/user/site',
		'home tilde path'                 => '~/secrets',
		'tilde alone'                     => 'tilde ~ here',
		'dot dot traversal'               => '../../etc/passwd',
		'relative wordpress path'         => 'var/www/html/wp-config.php',
		'relative upload path'            => 'uploads/x/y.php',
		'two words slash'                 => 'and/or',
		'protocol slash'                  => 'TCP/IP',
		'windows drive backslash'         => 'C:\\Users\\x',
		'windows drive slash'             => 'C:/Users/x',
		'backslash only'                  => 'dir\\file',
		'unc path'                        => '\\\\server\\share\\x',
		'wordpress content word'          => 'in wp-content here',
		'wordpress config word'           => 'see wp-config now',
		'htaccess word'                   => 'edit .htaccess please',
		'cookie name'                     => 'wordpress_sec_abc',
		'session cookie name'             => 'PHPSESSID abc',

		// Credential words followed by any value.
		'api key equals'                  => 'api key = abc',
		'private key words'               => 'my private key',
		'private key header'              => '-----BEGIN RSA',
		'ssh key'                         => 'ssh-rsa AAAA',
		'bearer value'                    => 'Bearer abc123',
		'basic base64'                    => 'Basic dXNlcjpwYXNz',

		// Token prefixes at any length.
		'github personal'                 => 'ghp_abc',
		'github personal dashed'          => 'ghp-abc',
		'github oauth'                    => 'gho_abc',
		'github user'                     => 'ghu_abc',
		'github server'                   => 'ghs_abc',
		'github refresh'                  => 'ghr_abc',
		'github fine grained'             => 'github_pat_abc',
		'github personal long'            => 'ghp_' . str_repeat( 'a', 36 ),
		'gitlab short'                    => 'glpat-abc',
		'gitlab twenty'                   => 'glpat-' . str_repeat( 'a', 20 ),
		'slack bot'                       => 'xoxb-1234-5678-abcdefghij',
		'slack app'                       => 'xapp-1',
		'npm'                             => 'npm_abc',
		'stripe live'                     => 'sk_live_abc',
		'stripe test'                     => 'sk_test_abc',
		'stripe publishable'              => 'pk_live_abc',
		'openai style'                    => 'sk-abc',
		'aws access key'                  => 'AKIAIOSFODNN7EXAMPLE',
		'aws temporary key'               => 'ASIAIOSFODNN7EXAMPLE',
		'aws short'                       => 'AKIA1',
		'jwt short segments'              => 'eyJa.eyJb.sig',
		'jwt prefix only'                 => 'eyJ',
		'jwt header'                      => 'eyJhbGciOiJIUzI1NiJ9',
		'bitbucket ATBB prefix'           => 'ATBBabc',
		'bitbucket repository token'      => 'ATCTT3xabc',
		'bitbucket api token'             => 'ATATT3xabc',
		'google api key'                  => 'AIzaSyabc',
		'google oauth'                    => 'ya29.abc',
		'shopify'                         => 'shpat_abc',
		'digitalocean'                    => 'dop_v1_abc',

		// Digests, ids and long tokens.
		'digest prefix sha256'            => 'sha256 mismatch',
		'digest prefix sha-512'           => 'sha-512 failed',
		'digest prefix sha1'              => 'sha1',
		'digest prefix md5'               => 'checksum md5 failed',
		'sha1 hex'                        => $sha1,
		'sha1 hex dashed'                 => implode( '-', str_split( $sha1, 8 ) ),
		'sha256 hex'                      => $sha256,
		'sha512 hex'                      => $sha512,
		'md5 hex'                         => $md5,
		'hex partial twenty'              => 'da39a3ee5e6b4b0d3255',
		'hex groups spaced'               => implode( ' ', $groups ),
		'hex letters spaced'              => 'deadbeef cafebabe deadbeef cafebabe',
		'hex groups dot'                  => implode( '.', $groups ),
		'hex groups comma'                => implode( ',', $groups ),
		'hex groups slash'                => implode( '/', $groups ),
		'hex groups question'             => implode( '?', $groups ),
		'hex groups colon'                => implode( ':', $groups ),
		'hex groups underscore'           => implode( '_', $groups ),
		'hex groups dash'                 => implode( '-', $groups ),
		'hex groups zero width'           => implode( "\u{200B}", $groups ),
		'hex groups no break space'       => implode( "\u{A0}", $groups ),
		'hex groups fullwidth'            => implode( "\u{FF0C}", $groups ),
		'hex quartet words'               => 'aaaaaaaa-bbbbbbbb-cccccccc-dddddddd',
		'uuid'                            => '123e4567-e89b-12d3-a456-426614174000',
		'uuid uppercase'                  => '123E4567-E89B-12D3-A456-426614174000',
		'uuid compact'                    => '123e4567e89b12d3a456426614174000',
		'base64 32 bytes'                 => 'ypeBEsobvcr6wjGzmiPcTaeG7/gUfE5yuYB3ha/uSLs',
		'base64url 48 bytes'              => 'VKWbnyKwuAiA2EJ-VIt8I6vYc0huHwNdzpzWl-hRdQM8qojm1XvDXvrgta_TFF8x',
		'base64 padded'                   => 'H0D8ktokFpR1CXnubPWC8tXX0o4YM13gWrxU0FYOD1MChgxlK/CNVgJSql50IQVG82n7u86MEs/HlXsmUv6adQ==',
		'mixed run broken by dots'        => 'ypeBEsobvc.r6wjGzmiPc.TaeG7gUfE5.yuYB3hauSLs',
		'mixed run broken by underscores' => str_repeat( 'aB1_', 11 ),
		'embedded aws key'                => 'xAKIAIOSFODNN7EXAMPLE',
		'embedded npm'                    => 'xnpm_abc',
		'embedded payment'                => 'xsk_live_abc',
		'embedded jwt'                    => 'xeyJa.eyJb.sig',
		'embedded bitbucket'              => 'xATBBabc',
		'embedded google'                 => 'xAIzaSyabc',
		'embedded google oauth'           => 'xya29.abc',
		'bare aws prefix'                 => 'AKIA',
		'bare aws temporary prefix'       => 'ASIA',
		'base64 split by spaces'          => implode( ' ', array_fill( 0, 8, 'YWJjZGVm' ) ),
		'base64url split by spaces'       => implode( ' ', array_fill( 0, 8, 'YWJj_GVm' ) ),
		'dense chunks spaced'             => 'a1b2c3d4 e5f6g7h8 i9j0k1l2 m3n4o5p6',
		'long lowercase word'             => 'abcdefghijklmnopqrstuvwxyz',

		// Control, format, invisible and non-ASCII characters.
		'line feed'                       => "line\nbreak",
		'carriage return'                 => "line\rbreak",
		'crlf'                            => "line\r\nbreak",
		'null byte'                       => "a\0b",
		'tab'                             => "a\tb",
		'delete'                          => "a\x7Fb",
		'escape'                          => "a\x1B[0m",
		'trailing line feed'              => "benign text\n",
		'accented letter'                 => "caf\u{E9}",
		'zero width space'                => "a\u{200B}b",
		'right to left override'          => "a\u{202E}b",
		'next line'                       => "a\u{85}b",
		'line separator'                  => "a\u{2028}b",
		'byte order mark'                 => "\u{FEFF}text",
		'soft hyphen'                     => "a\u{AD}b",
		'cyrillic homoglyph'              => "p\u{430}ckage",
		'fullwidth letters'               => "\u{FF21}\u{FF21}",
		'invalid utf8'                    => "a\xFF",
		'overlong utf8'                   => "a\xC0\xAF",

		// Size and position of the bound.
		'empty'                           => '',
		'spaces only'                     => '   ',
		'oversized input'                 => str_repeat( 'word ', 900 ),
		'digest past the bound'           => str_repeat( 'a ', 70 ) . $sha1,
		'digest straddling the bound'     => str_repeat( 'a ', 55 ) . $sha1,
		'path past the bound'             => str_repeat( 'a ', 70 ) . '/etc/passwd',
		'credential past the bound'       => str_repeat( 'a ', 70 ) . 'password hunter2',
		'token prefix past the bound'     => str_repeat( 'a ', 70 ) . 'ghp_abc',
		'long benign text then secret'    => str_repeat( 'plain words ', 15 ) . 'ghp_abc',
	);

	$strict_only = array(
		'password sentence'   => 'the password is hunter2',
		'pwd value'           => 'pwd hunter2',
		'passwd value'        => 'passwd hunter2',
		'password leetspeak'  => 'passw0rd is x',
		'passphrase value'    => 'passphrase abc',
		'password colon'      => 'password:',
		'secret value'        => 'secret value abc',
		'client secret'       => 'client_secret abc',
		'token value'         => 'token hunter2',
		'tokens plural'       => 'tokens abc',
		'embedded token word' => 'usertoken abc',
		'api key spaced'      => 'api key abc',
		'api key compact'     => 'apikey abc',
		'api key underscore'  => 'api_key abc',
		'authorization value' => 'Authorization abc',
		'signature value'     => 'signature abc',
		'nonce value'         => 'nonce abc',
		'cookie value'        => 'cookie abc',
		'set cookie value'    => 'set-cookie abc',
		'credential value'    => 'credentials abc',
		'passphrases plural'  => 'passphrases abc',
		'passcodes plural'    => 'passcodes abc',
		'bearer short'        => 'Bearer x',
		'basic word value'    => 'basic abc',
	);

	$benign = array(
		'fixed code'                 => 'package_asset_missing',
		'fixed code http'            => 'http_404',
		'fixed code digest'          => 'download_digest_mismatch',
		'fixed code long snake case' => 'plugin_update_fatal_error_rollback_successful',
		'fixed code archive'         => 'incompatible_archive',
		'fixed code draft'           => 'release_draft',
		'header name'                => 'Plugin Name',
		'header version'             => 'Version',
		'header requires php'        => 'Requires PHP',
		'header requires wp'         => 'Requires at least',
		'version number'             => 'wordpress 6.4.1',
		'sentence'                   => 'The archive could not be opened.',
		'status sentence'            => 'Unexpected response (HTTP 502) while reading.',
		'label colon space'          => 'Error: archive is empty',
		'tokenizer word'             => 'tokenizer_failed',
		'secretary word'             => 'The secretary was notified.',
		'credential word at the end' => 'Missing token',
		'short commit id'            => 'abc1234',
		'numeric counts'             => 'Header check failed (3 of 5)',
		'trailing question'          => 'Why did it fail?',
		'long plain text'            => rtrim( str_repeat( 'plain words only ', 12 ) ),
	);

	$display_only = array(
		'sentence token permissions'  => 'Check the token permissions and organisation approval.',
		'sentence password incorrect' => 'The password you entered is not correct.',
		'sentence api key setting'    => 'Enter a valid API key in the settings.',
		'sentence renew secret'       => 'Ask an administrator to renew the secret.',
		'sentence token expired'      => 'Token expired. Create a new one.',
		'sentence signature missing'  => 'The webhook signature is missing from the delivery.',
		'sentence basic information'  => 'Basic information is required before continuing.',
	);

	$identifiers = array(
		'storage transaction'         => 'ran_booster_storage_transaction_unavailable',
		'storage provider identity'   => 'ran_booster_storage_invalid_provider_identity',
		'fixed code'                  => 'package_asset_missing',
		'long fixed code'             => 'plugin_update_fatal_error_rollback_successful',
		'http code'                   => 'http_404',
		'single letter'               => 'a',
		'reason with credential word' => 'credential_rejected',
		'token reason'                => 'token_expired',
		'rate reason'                 => 'rate_limited_secondary',
		'ninety six characters'       => str_repeat( 'ghijk_', 16 ),
	);

	$not_identifiers = array(
		'empty'                   => '',
		'github prefix'           => 'ghp_abc',
		'npm prefix'              => 'npm_install_failed',
		'payment prefix'          => 'sk_live_abc',
		'digest prefix'           => 'sha256_mismatch',
		'md5 prefix'              => 'md5_failed',
		'hex word'                => 'abcdef0123456789abcdef',
		'commit id'               => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
		'long word part'          => 'ran_booster_' . str_repeat( 'a', 30 ),
		'uppercase'               => 'Ran_booster',
		'leading digit'           => '9starts_with_digit',
		'leading underscore'      => '_private',
		'dash'                    => 'has-dash',
		'space'                   => 'has space',
		'dot'                     => 'has.dot',
		'colon'                   => 'has:colon',
		'trailing newline'        => "plain_id\n",
		'ninety seven characters' => str_repeat( 'ghijk_', 16 ) . 'a',
		'non ascii'               => "caf\u{E9}_code",
	);

	return array(
		'hostile'         => $hostile,
		'strict_only'     => $strict_only,
		'display_only'    => $display_only,
		'benign'          => $benign,
		'identifiers'     => $identifiers,
		'not_identifiers' => $not_identifiers,
	);
} )();
