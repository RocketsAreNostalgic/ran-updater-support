# RAN Updater Support

`ran/updater-support` provides shared contracts and utilities for RAN updater packages. Its first component is a pure PHP 8.2 archive-safety contract. This component performs no ZIP, filesystem, network, or custody work. The shared failure-text screener, `FailureText`, is its second component.

`ArchiveSafety::normalize_path()` accepts printable ASCII relative paths with one optional trailing slash. It rejects traversal, empty components, Windows-reserved names, unsafe punctuation, trailing spaces or dots, and paths or components over the documented byte limits.

`entry_type_failure()` accepts only ZIP origin OS values DOS (`0`) and UNIX (`3`). Unknown origins are rejected because the package does not guess external-attribute semantics. UNIX accepts unspecified, regular, and directory modes; DOS accepts unspecified file flags or a matching directory bit, and rejects volume labels. `collision_failure()` detects case-insensitive duplicate paths and files used as ancestors while allowing explicit or implicit directories.

## Contract and usage

The raw archive name is limited to 4,096 bytes, including any trailing directory
slash. Each component is limited to 255 bytes. There is no independent depth
limit. These are archive admission budgets; the installer must still check its
actual destination and filesystem capabilities.

```php
use RAN\UpdaterSupport\V1\ArchiveSafety;

$entry = ArchiveSafety::normalize_path('example/assets/icon.svg');
if ($entry === null) {
    throw new InvalidArgumentException('Unsafe archive path.');
}

$failure = ArchiveSafety::entry_type_failure(
    origin_os: 3,
    attributes: 0100644 << 16,
    directory: false,
);

$collision = ArchiveSafety::collision_failure([
    ['path' => 'example', 'directory' => true],
    $entry,
]);
```

Only pass successfully normalized entry facts to `collision_failure()`. It returns
`path_duplicate`, `file_parent_collision`, or `null`. Metadata validation returns
`entry_metadata_invalid`, `entry_type_unsupported`, or `null`; unavailable
metadata differs from valid zero attributes. Consumers retain their own outward
error codes. Case folding is deterministic because admitted names are ASCII.

ZIP origin identifies the archive writer's attribute encoding, not the operating
system currently running PHP. DOS upper bits are not interpreted as Unix mode.
Missing metadata, unknown origins and contradictory file/directory metadata fail
closed. Extending supported origin encodings requires evidence and fixtures.
See [ZIP APPNOTE sections 4.4.2 and 4.4.15](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT)
and [Windows filename rules](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file).

## Failure text

`FailureText` is the one screener that decides whether untrusted failure text
(a provider message, a publisher-supplied detail, a remediation sentence) may be
shown to an operator or written to a log. Core and both updaters use it, so a
string cannot pass one package and fail another. It performs no I/O, has no
state and no WordPress dependency.

```php
use RAN\UpdaterSupport\V1\FailureText;

$detail = FailureText::screen( $text, 120 );            // string, or null: drop it
$exact  = FailureText::screen( $text, 255 ) === $text;  // validating sinks: reject, do not truncate
$commit = FailureText::commit_id( $resolved_ref );      // exactly 40 or 64 lowercase hex, or null
```

The policy, stated precisely:

- **Screen first, then bound.** The whole text is screened before any
  bounding. A secret that starts after the bound, or straddles it, drops the
  text. The accepted result is then cut to `$max_length` bytes.
- **Drop, never redact.** A text that matches any rule is dropped whole. Nothing
  is masked, rewritten, normalized or partly kept. The only edits to accepted
  text are trimming surrounding spaces and cutting to the bound.
- **Closed result.** `screen()` returns a `string` or `null`. A caller-supplied
  `$max_length` outside 1 to `FailureText::MAX_INPUT_LENGTH` (4,096) returns
  `null`, as does empty text and text longer than 4,096 bytes.
- **Printable ASCII only.** Output is bytes `0x20` to `0x7E`.
- **Not a proof.** The screen is by shape. Sinks must still prefer fixed or
  provider-public text, and consumers must escape the result on output. Encodings
  the rules do not name (a secret re-spelled in a new alphabet, or an unlisted
  vendor token that looks like an ordinary word) are not detected.

### Unicode

All non-ASCII input is dropped: any byte at or above `0x80`, so every control,
format, invisible, bidirectional, separator, combining, homoglyph or
fullwidth character, and invalid UTF-8, with no normalization attempted. The
alternative (accept some Unicode after NFKC or a property allowlist) was rejected
because normalization is where hostile separators and look-alikes hide
(`https：//`, zero-width separators inside digests), it needs an extension the
package does not assume, and the failure text these packages show is fixed
English or ASCII provider text. Legitimate non-ASCII text (`café`) is dropped;
that false positive is accepted. Controls, including tab, CR, LF, NUL and DEL,
are dropped, not collapsed to spaces.

### Rules

A text is dropped when any of these match. Rule names are the identifiers used
by `tests/failure-text-mutation.php`.

| Group | Dropped | Rule names |
| --- | --- | --- |
| Characters | anything outside printable ASCII | `printable_ascii_only` |
| Markup | `<` `>` `&` (tags and entity forms), `{` `}` `[` `]`, `` ` ``, `\|`, `;` | `angle_bracket`, `ampersand`, `bracket_or_brace`, `backtick`, `pipe`, `semicolon` |
| Assignments | any `=` (query strings, `name=value`, `sha256=`, DSNs) | `equals` |
| Userinfo | any `@` (userinfo, email, `git@host:`), any `%XX` escape (`%40`, `%2e`) | `at_sign`, `percent_escape` |
| URLs | a colon or question mark attached to the next character (`https://`, `mailto:a`, `a:b`, `?token`); a scheme word then a colon even with spaces (`https :`) | `colon_attached`, `question_attached`, `url_scheme_word` |
| Paths | any `/`, `\` or `~`; `wp-content`, `wp-config`, `wp-admin`, `wp-includes`, `.htaccess`, `.htpasswd`, `public_html`; WordPress and PHP session cookie names; private-key headers | `slash`, `backslash`, `tilde`, `wordpress_path_word`, `session_cookie_name`, `private_key_material` |
| Credential words | `password` (and `passw0rd`), `passwd`, `pwd`, `passphrase`, `passcode`, `secret`, `token`, `api key`, `apikey`, `authorization`, `signature`, `nonce`, `cookie`, `credential` (and plurals) followed by any value, inside a longer word or across an underscore (`usertoken x`, `client_secret x`) but not before a letter (`tokenizer`); `Basic` or `Bearer` followed by any text | `credential_*`, `bearer_or_basic` |
| Token prefixes at any length | `gh[pousr]_` and `ghp-`, `github_pat_`, `glpat-` and kin, `xox[abprs]-`, `xapp-`, `npm_`, `sk_live_`, `sk_test_`, `pk_live_`, `sk-`, `AKIA`, `ASIA`, `eyJ`, Bitbucket `ATBB`, `ATCTT3` and `ATATT3`, `AIza`, `ya29.`, `shpat_`, `dop_v1_` | `prefix_*` |
| Digests | `sha1`, `sha256`, `sha-512`, `md5`, `blake2` prefixes; 32 or more hex digits once separators, spaces and punctuation are removed (this also drops UUIDs); 16 or more hex digits in one word | `digest_prefix`, `hex_run`, `hex_word` |
| Long tokens | a 32 or more character word, punctuation removed, holding a digit or capital letter (base64, base64url, mixed tokens); any run of 25 or more letters and digits; spaced chunks that each mix letters and digits, 24 or more characters in all | `long_mixed_run`, `long_word`, `dense_chunks` |

A lowercase snake_case code such as `plugin_update_fatal_error_rollback_successful`
is not a long token: its separators split it into short parts.

### Trade-off: false positives over false negatives

A leaked credential or path is the failure this screener exists to prevent; a
dropped benign sentence only costs a less specific message, because callers keep
the closed reason code. Every rule is therefore deliberately broad. Benign text
that is now dropped, so callers must not rely on it:

- any text containing `/`, `\`, `~`, `=`, `@`, `;`, `|`, `&`, `<`, `>`, brackets or
  braces: `TCP/IP`, `and/or`, `R&D`, `a=b`, `[error]`;
- a colon or question mark followed directly by text: `12:30`, `ratio 3:1`,
  `Error:x`, `what?now`, timestamps such as `2026-11-12T10:00:00Z`;
- a credential word followed by anything: `Webhook signature missing`, `Token
  expired`, `Invalid password.`, `The signature does not match`, `token_expired`,
  `signature_invalid`, `Basic information`, `A Basic plan`. At the end of the text
  the word passes (`Missing token`, `invalid_signature`), because no value
  follows;
- non-ASCII text, and any run of 25 or more letters and digits (`ASIAN`, long
  CamelCase names, `sk-learn`);
- a number of 16 or more digits, and a few words ending `ghs-` or `ghr-` such as
  `doughs-`.

Not dropped, on purpose: short commit ids (7 to 15 hex digits), words that merely
contain a credential word (`tokenizer`, `secretary`), plain prose, fixed lowercase
snake_case codes and ordinary version numbers. SQL fragments are not a rule: the
punctuation SQL needs to carry data (`=`, `;`) is already dropped, and a keyword
rule would block ordinary prose (`update the plugin from the list`) without
closing a credential or path leak.

Closed reason identifiers and fixed Core-authored sentences should not be passed
through `screen()`; validate them against their closed list instead. The screener
is for text whose content the package does not control.

### Commit ids

`screen()` always drops a 40 or 64 digit hex string, because that is also the
shape of a digest. Core's logger keeps resolved commit ids, so
`FailureText::commit_id()` is a separate, narrow opt-in: it returns the text only
when it is exactly 40 or 64 lowercase hex digits, with no surrounding text or
whitespace, and otherwise `null`. It never relaxes `screen()`. A 32 digit
correlation id is a Core-owned exact identifier and stays a local check.

### Corpus contract

`tests/fixtures/failure-text-corpus.php` is the cross-package contract. It returns
`array{hostile: array<string, string>, benign: array<string, string>}`:

- every `hostile` value is dropped (`null`) by `screen()` at every bound from 1
  to 4,096;
- every `benign` value is returned unchanged by `screen( $value, 4096 )`, and as
  an exact bounded prefix at smaller bounds.

Consumers load the file in their own tests and assert both lists against their
adopted screener (from the installed package, or by loading the file from a
checkout of this repository at the pinned version). It is a development
fixture, not runtime code: it is not loaded in production and is not part of any
consumer's shipped runtime. A string found passing a screener is added to
`hostile`; a string that must keep passing is added to `benign`; a new rule gets
an entry only it rejects. `composer test:failure-text` runs the corpus and the
deterministic property tests. `composer test:failure-text-mutation` removes each
rule in turn from a private copy and fails if the corpus does not notice.

### Adopting the screener

Version impact: additive, a `feat` with no change to an existing class, so no
consumer changes until it adopts. Under this package's beta versioning each
Release Please release bumps the beta number from `1.0.0-beta.4`. The open
path-failure-reasons change is `1.0.0-beta.5` if it releases first; this change
is `1.0.0-beta.5` if it releases first and `1.0.0-beta.6` if it follows; if both
merge before the next release proposal, one release carries both.

Adoption order: release this package, then Core and the branch updater (Composer
dependency bump), then the release updater (vendored copy).

| Consumer | How |
| --- | --- |
| Core (`ran-booster`) | Composer dependency already present: bump it to the release, replace the screeners listed below with `FailureText::screen()` and `commit_id()`, and run the corpus in Core's tests. |
| Branch updater | Composer dependency bump; `AdmittedFailureReason::screen_detail()` becomes a call to `FailureText::screen( $detail, 64 )`; run the corpus. |
| Release updater | Vendored copy: bump the exact pin in `composer.json`; extend `scripts/sync-updater-support.php` to generate `src/Dependency/FailureText.php` (the class has exactly one namespace occurrence, as the script requires); add the `RAN\WPReleaseUpdater\V1\Dependency\FailureText` entry, with that path, to the class map in `runtime.php`; recompute `runtime-copy.json` `package_revision`; run `composer sync:updater-support` then `check:updater-support`; call it from `ReleaseRejection::detail()`. |

### Migration notes for existing screeners

"Loses" is text the old screener accepted that is now dropped. "Gains" is what the
old screener passed and the shared one drops, or the reverse for benign text.

| Screener | Loses | Gains |
| --- | --- | --- |
| Core `ProviderDiagnosticResult` private screen and `is_safe_text()` | text with `=`, `@`, `;`, `\|`, `&`, `%XX`, `a:b`, non-ASCII, or a `signature`, `nonce`, `cookie` or `credential` word followed by text | rejection of non-ASCII and invisible characters (its `[\x00-\x1F\x7F]` without `/u` passed U+202E, U+200B and U+0085); query-string and `name=value` credentials; token prefixes at any length including `ATBB`, `ATCTT3`, `ghp-`; digest forms; hex and base64 runs through separators; relative and embedded paths; obfuscated schemes; snake_case codes of 40 or more characters now pass |
| Core `InvalidCredentialInput` | the same text, plus anything its narrower set allowed (bare `https://x`, hex, `sha256=`, `AKIA`) | all of the above in one policy rather than a drifted copy |
| Core webhook remediation (`RepositoryWebhookOperationResult`, `RepositoryWebhookFitnessResult`) | any printable text up to 512 that holds a screened shape (they checked controls and length only) | every shape; the controller and coordinator that call `is_safe_text()` on the in-progress #834 branch move to `screen()` with the same bounds |
| Core `BoosterLogger` string context | its pre-screen normalization of control characters to spaces (the shared screener drops instead); exact 32 hex correlation ids and 40 or 64 hex commit ids go through the exact-identifier path and `commit_id()` | whole-text screening of free text before it is kept |
| Release updater `ReleaseRejection::detail()` | its replacement of non-printable and non-ASCII characters with `?` before screening (the shared screener drops them whole) | rejection of URLs without `@`, assignments the old pattern missed, entities, brackets, most token prefixes, `signature`, `nonce`, `cookie`; one definition |
| Branch updater `AdmittedFailureReason::screen_detail()` | its `[A-Za-z0-9 ._-]` allowlist as a charset (the shared screener also allows `, ( ) ' " ! * + $`), and its input cap of 1,024 (now 4,096, truncated to the caller's bound) | `tokenizer`, `secretary`, `session`, `key`, short commit ids and prose with a colon and a space now pass; rejection of `pwd`, `nonce`, `Basic`, `npm_`, `sk_live_`, `AKIA` and the other prefixes it missed |

A caller that wants the branch updater's narrower charset applies it after the
shared screener. No charset mode exists, because a second mode is a second policy
to keep in sync.

## Package boundary

The package has no WordPress or updater dependency. Additional utilities belong
here when actual consumers need the same behavior and a shared implementation
reduces maintained duplication.

The branch updater loads the Composer dependency directly. The release updater
bundles a generated namespace-scoped copy within its verified runtime and checks
that copy against the installed dependency. The canonical fixture corpus in
`tests/fixtures/archive-safety.php` is included for consumer compatibility checks.

Both whole-archive validators retain package identity, root selection, resource
limits and their source-specific checks. Archive safety does not extract archives
or own temporary files, permissions, cleanup, credentials or deployment state.

## Development and releases

Run `composer install`, then `composer check` with PHP 8.2 and Node 24.21.0.
Composer consumes the package through its Git version; `composer.json` deliberately
has no version field. The repository now uses the organisation-owned Profile A
release lifecycle: Release Please owns version selection, changelog, release PR,
tag, and GitHub Release creation after exact-main CI admission. Published
tags/releases remain the availability boundary for Composer consumers.

See [RELEASING.md](RELEASING.md) for the shared Profile A beta release process.

## Community

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Use
[SUPPORT.md](SUPPORT.md) for non-sensitive support and
[SECURITY.md](SECURITY.md) for confidential vulnerability reports. Participation
is governed by [RAN Code of Conduct](https://github.com/RocketsAreNostalgic/.github/blob/main/CODE_OF_CONDUCT.md).
