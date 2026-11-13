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

## Failure reasons

`ArchiveSafety::path_failure()`, `ArchiveSafety::entry_type_reason()` and
`InvalidRepositoryPathException::reason()` say which rule rejected an input. They
are additive: `normalize_path()`, `entry_type_failure()`, `collision_failure()`
and `RepositoryRelativePath::normalize()` accept and reject exactly what they did
before, `entry_type_failure()` and `collision_failure()` still return only their
documented codes, and the exception message is unchanged.

Guarantees, stated precisely:

- Every reason is one value of a closed public list (`ArchiveSafety::PATH_FAILURES`,
  `ArchiveSafety::ENTRY_TYPE_REASONS`, `InvalidRepositoryPathException::REASONS`).
  Each is a fixed lowercase snake_case identifier written in this package. A
  reason never contains the input, a path, a URL, a credential, a digest or any
  other text from the archive or repository.
- The exception message is the fixed string `The repository-relative path is
  invalid.` and never contains the path. The exception is an
  `InvalidArgumentException`, so existing catches keep working.
- A reason names the first failing check. Where several rules fail, which one is
  reported follows the order below, and later additions to a list are additive.
  Consumers should treat an identifier they do not know as a generic rejection.
- No path or other archive text is returned by any failure method. Callers that
  add the rejected path to their own diagnostics must treat it as untrusted
  publisher text and escape and bound it themselves.

Archive path reasons (`path_failure()`, checked in this order): `path_empty`,
`path_too_long` (over 4,096 bytes), `path_absolute` (leading slash),
`path_control_character`, `path_unsafe_character` (non-printable or non-ASCII
byte, or one of `\ : < > " | ? *`), `path_empty_component` (a `//`, or an empty
name before or after a slash), `path_dot_segment` (`.` or `..`),
`component_too_long` (over 255 bytes), `component_trailing_dot_or_space`,
`component_reserved_name` (Windows device name, with or without extension).

Entry type reasons (`entry_type_reason()`, with the legacy code
`entry_type_failure()` returns for each): `entry_metadata_unavailable` and
`entry_type_mismatch` (contradictory file or directory metadata) map to
`entry_metadata_invalid`; `entry_type_special` (symlink, device or other
non-regular UNIX type), `entry_origin_unsupported` (ZIP origin other than DOS or
UNIX) and `entry_volume_label` map to `entry_type_unsupported`.

Repository path reasons (`InvalidRepositoryPathException::reason()`):
`path_control_character`, `path_empty`, `path_absolute`, `path_backslash`,
`path_drive_letter`, `path_empty_component`, `path_dot_segment`,
`path_encoded_traversal` (a percent-decoded segment is `.`, `..` or contains a
slash or backslash), `path_encoded_drive_letter`,
`path_encoded_control_character` and `path_decode_limit` (more than eight
decode passes). `collision_failure()` already returns the distinct codes
`path_duplicate` and `file_parent_collision`; it does not report which path
collided.

## Failure text

`FailureText` is the one screener that decides whether untrusted failure text
(a provider message, a publisher-supplied detail, a remediation sentence) may be
shown to an operator or written to a log. Core and both updaters use it, so a
string cannot pass one package and fail another. It performs no I/O, has no
state and no WordPress dependency.

```php
use RAN\UpdaterSupport\V1\FailureText;

$detail   = FailureText::strict( $text, 64 );           // untrusted free-text detail, or null: drop it
$sentence = FailureText::display( $text, 512 );         // curated sentence Core shows, or null
$id       = FailureText::identifier( $code );           // closed snake_case id, or null
$commit   = FailureText::commit_id( $resolved_ref );    // exactly 40 or 64 lowercase hex, or null
$exact    = FailureText::strict( $text, 255 ) === $text; // validating sinks: reject, do not truncate
```

### Modes

One rule set, three explicit ways to apply it. Every mode drops non-ASCII text, and
every shape rule below applies to every mode; the modes differ only in whether prose
about credentials is treated as a credential. Pick the mode by where the text comes from.

| Method | Input | Applies | Bound | Used for |
| --- | --- | --- | --- | --- |
| `strict( $text, $max )` | untrusted free-text detail from a foreign package | every shape rule and the prose credential-word rules | caller supplies, small (updaters use 64 to 120) | updater rejection and failure detail (release updater, branch updater); any logger value that is not an identifier or commit id |
| `display( $text, $max )` | curated sentences Core shows | shape rules only; no prose-word rule; full sentences with spaces and punctuation | caller supplies, longer (Core uses 255 to 512) | Core provider diagnostics, webhook remediation, activity text |
| `identifier( $text )` | a closed id | exactly `[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*`, at most 96 bytes, and not hostile by any shape rule; never truncated | 96 | diagnostic ids and reason codes, snake_case, dotted or dashed (`ran_booster_storage_transaction_unavailable`, `token_expired`, `gh.credential.valid`, `webhook.operation_succeeded`, `github-fine-grained-pat-v1`); logger identifier values |
| `commit_id( $text )` | a resolved ref | exactly 40 or 64 lowercase hex digits | fixed | commit ids kept by Core's logger |

`display( 'Check the token permissions and organisation approval.' )` passes and
`strict()` drops it; both drop `ghp_abc`, `https://x`, a digest, a path or markup. The
identifier allowance does not weaken the digest and long-token rules: `ghp_abc`,
`npm_x`, `sha256_x`, a 16 or more digit hex word and a 25 or more character word are
not identifiers.

The policy, stated precisely:

- **Screen first, then bound.** The whole text is screened before any
  bounding. A secret that starts after the bound, or straddles it, drops the
  text. The accepted result is then cut to `$max_length` bytes.
- **Drop, never redact.** A text that matches any rule is dropped whole. Nothing
  is masked, rewritten, normalized or partly kept. The only edits to accepted
  text are trimming surrounding spaces and cutting to the bound, and the cut is
  screened again: a cut that forms a hostile shape (`a passenger rides` cut to
  `a pass` in `strict()`; an application-password shape in either mode) drops the
  text instead of returning the cut.
- **Closed result.** `strict()` and `display()` return a `string` or `null`. A caller-supplied
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

A text is dropped when any of these match (the two credential-word rows apply to STRICT only). Rule names are the identifiers used
by `tests/failure-text-mutation.php`.

| Group | Dropped | Rule names |
| --- | --- | --- |
| Characters | anything outside printable ASCII | `printable_ascii_only` |
| Markup | `<` `>` `&` (tags and entity forms), `{` `}` `[` `]`, `` ` ``, `\|`, and a `;` directly followed by text (`a;b`; a `;` before a space or at the end is prose punctuation) | `angle_bracket`, `ampersand`, `bracket_or_brace`, `backtick`, `pipe`, `semicolon` |
| Assignments | any `=` (query strings, `name=value`, `sha256=`, DSNs) | `equals` |
| Userinfo | any `@` (userinfo, email, `git@host:`), any `%XX` escape (`%40`, `%2e`) | `at_sign`, `percent_escape` |
| URLs | a colon or question mark attached to the next character (`https://`, `mailto:a`, `a:b`, `?token`); a scheme word then a colon even with spaces (`https :`) | `colon_attached`, `question_attached`, `url_scheme_word` |
| Paths | any `/`, `\` or `~`; `wp-content`, `wp-admin`, `wp-includes`, `.htaccess`, `.htpasswd`, `public_html`; WordPress and PHP session cookie names; private-key headers | `slash`, `backslash`, `tilde`, `wordpress_path_word`, `session_cookie_name`, `private_key_material` |
| Credential words (STRICT only) | `password` (and `passw0rd`), `passwd`, `pwd`, `passphrase`, `passcode`, `pass`, `pw`, `passwort`, `jwt`, `auth`, `key is`, `pin is`, `access key`, `secret`, `token`, `api key`, `apikey`, `authorization` or `authorisation`, `signature`, `nonce`, `cookie`, `credential` (and plurals) with any other word or text before or after it, inside a longer word or across an underscore (`usertoken x`, `client_secret x`) but not before a letter (`tokenizer`); the word on its own (`token`, `api keys`) passes. `Basic` or `Bearer` followed by any text | `credential_*`, `credential_other`, `bearer_or_basic` |
| Bearer or Basic token (every mode) | `Basic` or `Bearer` followed by token-like text (a digit, capital letter, `+`, `/` or `=` in it): `Bearer abc123`, `Basic dXNlcjpwYXNz`; `Basic information` is not one | `bearer_basic_token` |
| WordPress Application Password (every mode) | six space-separated groups of four letters or digits, as WordPress displays one: `abcd efgh ijkl mnop qrst uvwx` (six consecutive four-letter words of ordinary prose match too) | `wordpress_app_password` |
| Token prefixes at any length, anywhere in the text (`xAKIA...` and bare `AKIA` included) | `gh[pousr]_` and `ghp-`, `github_pat_`, `glpat-` and kin, `xox[abprs]-`, `xapp-`, `npm_`, `sk_live_`, `sk_test_`, `pk_live_`, `sk-`, `AKIA`, `ASIA`, `AROA`, `AIDA`, `ABIA`, `ACCA`, `AGPA`, `ANPA`, `ANVA`, `APKA`, `eyJ`, Bitbucket `ATBB`, `ATCTT3` and `ATATT3`, `AIza`, `ya29.`, `shpat_`, `dop_v1_` | `prefix_*` |
| Digests | `sha1`, `sha256`, `sha-512`, `md5`, `blake2` prefixes; 32 or more hex digits once separators, spaces and punctuation are removed (this also drops UUIDs); 16 or more hex digits in one word | `digest_prefix`, `hex_run`, `hex_word` |
| Long tokens | a 32 or more character word, punctuation removed, holding a digit or capital letter (base64, base64url, mixed tokens); any run of 25 or more letters and digits; space-separated chunks that each mix letters and digits (or lower then upper case): 20 or more characters across two or more chunks, or 24 or more in one | `long_mixed_run`, `long_word`, `dense_chunks` |

A lowercase snake_case code such as `plugin_update_fatal_error_rollback_successful`
is not a long token: its separators split it into short parts.

### Trade-off: false positives over false negatives

A leaked credential or path is the failure this screener exists to prevent; a
dropped benign sentence only costs a less specific message, because callers keep
the closed reason code. Every rule is therefore deliberately broad. Benign text
that is now dropped, so callers must not rely on it:

- any text containing `/`, `\`, `~`, `=`, `@`, `|`, `&`, `<`, `>`, brackets or
  braces, or a `;` directly followed by text: `TCP/IP`, `and/or`, `R&D`, `a=b`,
  `[error]`, `a;b`. Admin copy must use ASCII and avoid `/`: curly quotes, arrows
  and ellipses are dropped, and so are phrases such as `owner/repository`;
- a colon or question mark followed directly by text: `12:30`, `ratio 3:1`,
  `Error:x`, `what?now`, timestamps such as `2026-11-12T10:00:00Z`;
- in STRICT only, a credential word with any other word before or after it:
  `Webhook signature missing`, `Token expired`, `Missing token`, `Invalid password.`,
  `The signature does not match`, `hunter2 is the password`, `authorisation hunter2`,
  `token_expired`, `signature_invalid`, `Basic information`, `A Basic plan`. DISPLAY
  keeps such curated sentences and `identifier()` keeps such ids. The word on its own
  passes (`token`, `Passwords`, `authorisation`). A cut that leaves a credential word
  beside other text (`a passenger rides` cut to `a pass`) is screened again and dropped;
- single dictionary words that end in a credential word, because `pass`, `auth`,
  `secret` and `token` match inside longer words: `bypass`, `compass`, `overpass`,
  `underpass`, `trespass`, `encompass`, `surpass` and about 30 more are dropped by
  `strict()` even alone, as are `OAuth` and `1password`; `passenger` and `tokenizer`
  still pass;
- non-ASCII text, any run of 25 or more letters and digits, and any text holding
  `AKIA`, `ASIA`, `eyJ`, `sk-` or `npm_` (`ASIAN`, long CamelCase names, `task-force`,
  `disk-full`), because token prefixes drop at any position;
- runs of space-separated CamelCase or letter-and-digit words that total 20 or more
  characters across two or more words (`WordPress WooCommerce JetPack`), and six
  consecutive four-letter words (`this that with from they have`);
- a number of 16 or more digits, and a few words ending `ghs-` or `ghr-` such as
  `doughs-`.

Not dropped, on purpose: prose punctuation `;` before a space, a bare file name such as
`wp-config.php` (real paths are caught by `/`, `\` and `:`), short commit ids (7 to 15 hex digits), words that merely
contain a credential word (`tokenizer`, `secretary`), plain prose, fixed lowercase
snake_case codes and ordinary version numbers. SQL fragments are not a rule: the
punctuation SQL needs to carry data (`=`, and `;` before text) is already dropped, and a keyword
rule would block ordinary prose (`update the plugin from the list`) without
closing a credential or path leak.

Closed reason identifiers go through `identifier()` or are validated against their
closed list; curated Core sentences go through `display()`. Only text whose
content the package does not control needs `strict()`.

### Language stance

The screener is English and ASCII by design. Core's own translated copy (`__()`)
never passes through it. Provider-authored fixed text that goes through `display()` must
be English ASCII, and non-ASCII text is dropped whole, so a translated string fails
closed rather than being half-screened. Failures are made translatable by the closed
reason vocabulary, mapped to Core-owned translatable copy, not by screening translated
text. The credential-word rules are an English best-effort extra (both `authorization`
and `authorisation`, and their plurals; no other languages) and are not the guarantee:
the shape rules and closed call-site values are. Free text from third parties is best
effort.

### Scope and limits

- **`display()` is only for fixed Core strings and provider-authored fixed remediation.**
  Never pass it text that echoes remote or foreign content: an HTTP body, an exception
  or `WP_Error` message, a header value, a publisher string. That text needs `strict()`,
  or no text at all and a closed reason code. `display()` omits the prose
  credential-word rule precisely because the caller vouches that the sentence is curated.
- **Engine failures fail closed.** Every regular expression goes through one helper.
  If the engine fails (a backtrack or JIT stack limit, `preg_last_error()` set), the
  public method returns `null`; a failure is never read as "no match". A test runs
  the corpus under starved limits and requires every hostile string to be dropped.
- **Input.** The public methods accept `?string` and return `null` for `null`. Anything
  else is a type error under `strict_types`.
- **Not caught.** Bare hostnames, IP addresses and file names that contain none of
  `/ : @` (`db.internal`, `192.168.0.1`, `wp-config.php`) are not credentials or paths and
  pass; the shape rules catch paths and URLs by their separators, not by name. Measured residual (2,000 random tokens per cell, `strict()`): a random token of
  mixed case with digits is caught from 16 characters (94 to 98 percent) and by 24
  characters always, but under 16 characters passes every time; a random token of
  lowercase letters and digits with no capital passes up to 23 characters, and one of
  lowercase letters only passes at any length below the 25 character run limit and, when
  split by separators into short parts, at any length. Nothing can tell those from words
  or ids without a distinctive prefix. A re-encoding the rules do not name
  is not detected.
- **`identifier()` is only for closed, Core-owned ids, never foreign text.** Its shape
  admits any all-lowercase letters-only string up to the 96-byte cap, because that cannot
  be told from a word; the shape rules still drop token prefixes, digests, hex, mixed
  tokens and long runs.
- **Current Core strings that `display()` drops today** (from a scan of the Core,
  GitHub provider, Bitbucket and updater sources, 1,569 translatable literals and 689
  multi-word sentences): copy with curly apostrophes, arrows or ellipses (about 45),
  phrases with `/` such as `owner/repository form` and `workspace/repository`
  (5), `..\/` in an unsafe-path hint, HTML in a few strings (3 with `<`, 3 with
  brackets), and guidance that quotes a token prefix (`ghp_`, `github_pat_`). Rewrite
  these in ASCII without `/` and without prefixes, or keep them out of the sink
  (HTML and SQL belong in templates and queries, not in failure text).

### Commit ids

`strict()` and `display()` always drop a 40 or 64 digit hex string, because that is also the
shape of a digest. Core's logger keeps resolved commit ids, so
`FailureText::commit_id()` is a separate, narrow opt-in: it returns the text only
when it is exactly 40 or 64 lowercase hex digits, with no surrounding text or
whitespace, and otherwise `null`. It never relaxes `strict()` or `display()`. A 32 digit
correlation id is a Core-owned exact identifier and stays a local check.

### Corpus contract

`tests/fixtures/failure-text-corpus.php` is the cross-package contract. It returns
six maps of unique label to string:

- `hostile`: dropped (`null`) by `strict()` and `display()` at every bound from 1
  to 4,096;
- `strict_only`: dropped by `strict()` (a credential word beside other text) but
  returned unchanged by `display()`;
- `display_only`: curated sentences returned by `display()` and dropped by `strict()`;
- `benign`: returned unchanged by both modes at bound 4,096. At a smaller bound the
  result is the exact bounded prefix when that prefix itself screens clean, and
  `null` when the cut forms a hostile shape;
- `identifiers`: returned unchanged by `identifier()`;
- `not_identifiers`: dropped by `identifier()`.

Consumers load the file in their own tests and assert both lists against their
adopted screener (from the installed package, or by loading the file from a
checkout of this repository at the pinned version). It is a development
fixture, not runtime code: it is not loaded in production and is not part of any
consumer's shipped runtime. A string found passing a screener is added to
`hostile` (or `strict_only` if it is only prose); a string that must keep passing
is added to `benign`, `display_only` or `identifiers`; a new rule gets
an entry only it rejects. `composer test:failure-text` runs the corpus and the
deterministic property tests. `composer test:failure-text-mutation` removes each
rule in turn from a private copy and fails if the corpus does not notice.

### Adopting the screener

Version impact: additive, a `feat` with no change to an existing class, so no
consumer changes until it adopts. Release Please versions it like any other `feat`.

Adoption order: release this package, then Core and the branch updater (Composer
dependency bump), then the release updater (vendored copy).

| Consumer | How |
| --- | --- |
| Core (`ran-booster`) | Composer dependency already present: bump it to the release, replace the screeners listed below (provider diagnostics, webhook remediation and activity text with `display()`; logger values with `identifier()`, `commit_id()` and otherwise `strict()`), and run the corpus in Core's tests. Before Core adopts `display()` in place of `InvalidCredentialInput::assert_safe_text` and the other constructors, these Core and provider strings must be reworded in ASCII, or their sink changed, because the constructor would otherwise throw on the provider's own messages: the three `CredentialPolicy` messages that quote `ghp_` and `github_pat_`; the `DeploymentOutcomeMessage` unsafe-path hint that contains `../`; the `owner/repository` and `workspace/repository` strings; and the 18 Core strings with curly apostrophes, em dashes or arrows. Core's real ids (`gh.credential.valid`, `bb.repository.not_found`, `webhook.operation_succeeded`, `ran_booster_storage_transaction_unavailable`, `github-fine-grained-pat-v1`) pass `identifier()`; two current ids that name a hash algorithm (`github-hmac-sha256-v1`, `artifact_sha256`) are dropped by the digest rule, so compare those against their closed list instead. |
| Branch updater | Composer dependency bump; `AdmittedFailureReason::screen_detail()` becomes a call to `FailureText::strict( $detail, 64 )`; run the corpus. |
| Release updater | Vendored copy: bump the exact pin in `composer.json`; extend `scripts/sync-updater-support.php` to generate `src/Dependency/FailureText.php` (the class has exactly one namespace occurrence, as the script requires); add the `RAN\WPReleaseUpdater\V1\Dependency\FailureText` entry, with that path, to the class map in `runtime.php`; recompute `runtime-copy.json` `package_revision`; run `composer sync:updater-support` then `check:updater-support`; call `FailureText::strict()` from `ReleaseRejection::detail()`. |

### Migration notes for existing screeners

"Loses" is text the old screener accepted that is now dropped. "Gains" is what the
old screener passed and the shared one drops, or the reverse for benign text.

| Screener | Loses | Gains |
| --- | --- | --- |
| Core `ProviderDiagnosticResult` private screen and `is_safe_text()` (move to `display()`) | text with `=`, `@`, `;`, `\|`, `&`, `%XX`, `a:b`, non-ASCII; curated sentences that mention a token or password are kept | rejection of non-ASCII and invisible characters (its `[\x00-\x1F\x7F]` without `/u` passed U+202E, U+200B and U+0085); query-string and `name=value` credentials; token prefixes at any length including `ATBB`, `ATCTT3`, `ghp-`; digest forms; hex and base64 runs through separators; relative and embedded paths; obfuscated schemes; snake_case codes of 40 or more characters now pass |
| Core `InvalidCredentialInput` (`display()`) | the same text, plus anything its narrower set allowed (bare `https://x`, hex, `sha256=`, `AKIA`) | all of the above in one policy rather than a drifted copy |
| Core webhook remediation (`RepositoryWebhookOperationResult`, `RepositoryWebhookFitnessResult`; `display()`) | any printable text up to 512 that holds a screened shape (they checked controls and length only) | every shape; the controller and coordinator that call `is_safe_text()` on the in-progress #834 branch move to `display()` with the same bounds |
| Core `BoosterLogger` string context (`identifier()`, `commit_id()`, else `strict()`) | its pre-screen normalization of control characters to spaces (the shared screener drops instead); its 40 or more character run rule that dropped snake_case diagnostic ids such as `ran_booster_storage_transaction_unavailable` (they now pass `identifier()`); exact 32 hex correlation ids stay a Core-local check | whole-text screening of free text before it is kept |
| Release updater `ReleaseRejection::detail()` (`strict()`) | its replacement of non-printable and non-ASCII characters with `?` before screening (the shared screener drops them whole) | rejection of URLs without `@`, assignments the old pattern missed, entities, brackets, most token prefixes, `signature`, `nonce`, `cookie`; one definition |
| Branch updater `AdmittedFailureReason::screen_detail()` (`strict( $detail, 64 )`) | its `[A-Za-z0-9 ._-]` allowlist as a charset (the shared screener also allows `, ( ) ' " ! * + $`), and its input cap of 1,024 (now 4,096, truncated to the caller's bound) | `tokenizer`, `secretary`, `session`, `key`, short commit ids and prose with a colon and a space now pass; rejection of `pwd`, `nonce`, `Basic`, `npm_`, `sk_live_`, `AKIA` and the other prefixes it missed |

A caller that wants the branch updater's narrower charset applies it after the
shared screener. There is no charset mode: the three modes differ only in the prose
rule and the identifier shape, and a further mode would be another policy to keep
in sync.


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
