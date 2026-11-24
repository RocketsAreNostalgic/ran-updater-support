# RAN Updater Support

`ran/updater-support` provides shared archive, failure-text, and local file primitives for RAN updater packages. The library supports PHP 8.2 and later 8.x releases without WordPress. Archive policy evaluates the metadata supplied by the caller.

`ArchiveSafety::path_failure()` validates ASCII relative paths by default, with one optional trailing slash. It can also validate UTF-8 paths when explicitly enabled. It rejects control and format characters, line and paragraph separators, traversal, empty components, Windows-reserved names, unsafe punctuation, trailing spaces or dots, and paths or components over the documented byte limits.

`entry_type_reason()` accepts ZIP origin OS values DOS (`0`), UNIX (`3`), Windows NTFS (`10`), and macOS / OS X (`19`). UNIX and macOS accept unspecified, regular, and directory modes. DOS and NTFS accept unspecified file flags or a matching directory bit and reject volume labels; NTFS also rejects reparse-point and device flags. `collision_failure()` detects duplicate paths and files used as ancestors while allowing explicit or implicit directories.

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

$failure = ArchiveSafety::entry_type_reason(
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
`path_duplicate`, `file_parent_collision`, or `null`. Collision checks fold
ASCII case and compare other bytes exactly. `path_failure()` and `normalize_path()`
both default to ASCII for compatibility. An extractor that checks additional
case and normalization equivalences on its destination filesystem can pass
`allow_utf8: true` to either method. Normalization retains the spelling of
validated UTF-8 paths.

### Shared ZIP policy

Start with `ArchiveSafety::entry_count_failure($zip->numFiles)` to admit 1 to
10,000 entries. Set the remaining expanded budget with
`ArchiveSafety::maximum_expanded_bytes($maximum_artifact_bytes)`. It returns
four times the artifact limit and throws `InvalidArgumentException` for
nonpositive or overflowing limits.

For each entry, pass its `statIndex()` array, the directory flag from its
normalized path and the remaining budget to
`ArchiveSafety::entry_policy_failure($stat, $directory, $remaining)`. This
requires integer size and encryption metadata, accepts `ZipArchive::EM_NONE`,
rejects directory payloads and enforces a 100:1 ratio from 128 KiB upward.
After admission, subtract the declared size from the remaining budget. Continue
checking entry names, external attributes with `entry_type_reason()`,
collisions and the actual decompressed stream at extraction.

ZIP origin identifies the archive writer's attribute encoding, not the operating
system currently running PHP. DOS and NTFS upper bits are not interpreted as Unix
mode. Missing metadata, unknown origins and contradictory file/directory metadata
fail closed. Extending supported origin encodings requires evidence and fixtures.
See [ZIP APPNOTE sections 4.4.2 and 4.4.15](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT)
and [Windows filename rules](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file).

## Failure reasons

`ArchiveSafety::path_failure()`, `ArchiveSafety::entry_type_reason()` and
`InvalidRepositoryPathException::reason()` say which rule rejected an input. They
return fixed reason codes. Existing callers can continue using
`entry_type_failure()` with its two legacy codes.

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
`path_control_character` (control, format, line or paragraph separator; an
ASCII control byte is reported before malformed UTF-8),
`path_unsafe_character` (non-ASCII in default mode, malformed UTF-8, or one of `\ : < > " | ? *`),
`path_empty_component` (a `//`, or an empty
name before or after a slash), `path_dot_segment` (`.` or `..`),
`component_too_long` (over 255 bytes), `component_trailing_dot_or_space`,
`component_reserved_name` (Windows device name, with or without extension).

Entry type reasons (`entry_type_reason()`, with the legacy code
`entry_type_failure()` returns for each): `entry_metadata_unavailable` and
`entry_type_mismatch` (contradictory file or directory metadata) map to
`entry_metadata_invalid`; `entry_type_special` (UNIX/macOS symlink, device or
other non-regular mode, or NTFS reparse-point/device attribute),
`entry_origin_unsupported` (ZIP origin other than DOS, UNIX, NTFS or macOS / OS X)
and `entry_volume_label` map to `entry_type_unsupported`.

Repository path reasons (`InvalidRepositoryPathException::reason()`):
`path_too_long` (over 4,096 bytes), `path_control_character`, `path_empty`,
`path_absolute`, `path_backslash`, `path_drive_letter`, `path_empty_component`,
`path_dot_segment`, `component_too_long` (over 255 bytes),
`path_encoded_traversal` (a percent-decoded segment is `.`, `..` or contains a
slash or backslash), `path_encoded_drive_letter`,
`path_encoded_control_character` and `path_decode_limit` (more than eight
decode passes). `collision_failure()` already returns the distinct codes
`path_duplicate` and `file_parent_collision`; it does not report which path
collided.

## Package directories

`RAN\UpdaterSupport\V1\PackageDirectory` owns package folder names and the
optional repository subdirectory handling shared by Core and the branch updater.
It is pure PHP, with no filesystem or WordPress access.

| Method | Contract |
| --- | --- |
| `is_valid_name( string $value ): bool` | Exactly 1–191 ASCII bytes: a letter or digit, followed by letters, digits, dots, underscores or hyphens, with no trailing dot and no Windows reserved device name (`con`, `prn`, `aux`, `nul`, `com1`–`com9`, `lpt1`–`lpt9`, with or without a dot extension, ASCII case-insensitive), matching `ArchiveSafety::path_failure()`. Capitals are allowed. No trimming or case folding. |
| `installed_slug( string $package_type, string $identifier ): string` | For `plugin`, the top-level directory of the plugin file, or its filename without the extension for a single-file plugin. For `theme`, the stylesheet unchanged. Retains case; accepts consumer-validated WordPress identifiers, rather than validating identifier safety. Other package types throw `InvalidArgumentException`. |
| `normalize( mixed $value ): ?string` | Optional repository subdirectory: `null`, empty and space-only strings become `null`; other strings use `RepositoryRelativePath::normalize()`. Controls, including whitespace controls, and non-strings are rejected. |
| `slug( mixed $value ): string` | Final segment of a normalized, required repository subdirectory, retaining case. |
| `normalize_slug( mixed $value ): string` | Legacy provider-name normalization: one required repository path segment, with surrounding spaces trimmed and no trailing slash. |
| `installation_slug( mixed $provider_slug, mixed $subdirectory ): string` | The subdirectory's final segment when present, otherwise the normalized provider name. Retains case; this is source derivation, not an installed-folder lookup. |
| `deployment_slug( mixed $provider_slug, mixed $subdirectory ): string` | Lower-cases `installation_slug()` for a new install only. |
| `installed_folder_matches( string $installed_folder, string $source_name ): bool` | Identity: the installed folder equals the source-derived name exactly, or its lower-case new-install form. |
| `collision_key( string $name ): string` | ASCII `A`–`Z` folded to `a`–`z`; every other byte unchanged. Equal keys mean the names could be one folder. |
| `names_collide( string $first, string $second ): bool` | Lock, scope and collision checks: `collision_key()` equality. |

The 191-byte admission limit preserves every name accepted by the previous
100-byte, mixed-case release rule or 191-byte, lower-case branch rule. Admitted
names keep their case: `MyPlugin` and `myplugin` are different names. Compare
them with the methods in [Comparing package folder names](#comparing-package-folder-names).
This rule does not claim filesystem portability or replace archive-safety checks.

The five legacy normalization/derivation methods deliberately retain their
broader path semantics, including punctuation, non-ASCII segments and names
longer than 191 bytes. They do **not** perform folder-name admission. Validate
the resulting destination with `is_valid_name()` at the consumer's existing
admission boundary. Invalid legacy inputs throw `InvalidArgumentException` with
the fixed message `The package subdirectory must be a normalized relative path.`;
the rejected value never enters the exception. Repository path failures are
mapped to that message, as in the two former consumer copies.

```php
use RAN\UpdaterSupport\V1\PackageDirectory;

$source = PackageDirectory::normalize( ' packages/MyPlugin/ ' ); // packages/MyPlugin
$new    = PackageDirectory::deployment_slug( '', $source );     // myplugin
$live   = PackageDirectory::installed_slug( 'plugin', 'MyPlugin/Main.php' ); // MyPlugin
$single = PackageDirectory::installed_slug( 'plugin', 'Single.php' );        // Single
$theme  = PackageDirectory::installed_slug( 'theme', 'MixedCaseTheme' );     // MixedCaseTheme
$valid  = PackageDirectory::is_valid_name( $live );             // true
```

Consumer adoption requires a published support release. Core and the branch
updater can replace their `PackageSubdirectory` copies with this class and retain
the five existing method names. Branch catches of `InvalidArgumentException`
continue to work; Core's specific `InvalidPackageSubdirectory` catches must
catch the shared exception or translate at their own outward boundary. Replace
deployment folder predicates with `is_valid_name()` while retaining each
consumer's outward failure codes. Installed identity derivation does not consult
repository subdirectories, change lookup-key case folding, or authorize a folder
rename. Preserve exact installed-identifier mismatch refusals. The release
updater uses the folder predicate for its archive root; header-file syntax and
extension checks remain its responsibility. Release subdirectories gain no
support from this helper.

### Comparing package folder names

An installed package keeps the case of the folder it is in. A new installation
uses the lower-case form of its source-derived name. Whether two names are "the
same" depends on what the comparison protects, so there are three methods and
no other folder-name comparison should be written by hand.

| The comparison protects | Use | Rule |
| --- | --- | --- |
| Identity of one package: is this installed folder the one this source installs as? | `installed_folder_matches( $installed_folder, $source_name )` | Exact, or the source name's lower-case new-install form. The installed folder is never folded. |
| A lock, a scope or a collision: could these two names be one folder? | `names_collide( $first, $second )` | ASCII case-insensitive. |
| A map key or a SQL value for the collision rule | `collision_key( $name )` | The folded name; equal keys collide. |
| Identity of an admitted name against another spelling that must be the same name, such as a configured archive root or a WordPress-reported identifier | `===` or `hash_equals()` | Exact. |

```php
use RAN\UpdaterSupport\V1\PackageDirectory;

PackageDirectory::installed_folder_matches( 'myplugin', 'MyPlugin' ); // true: the new-install form
PackageDirectory::installed_folder_matches( 'MyPlugin', 'myplugin' ); // false: installed case is kept
PackageDirectory::names_collide( 'MyPlugin', 'myplugin' );            // true
PackageDirectory::names_collide( 'my-plugin', 'my_plugin' );          // false
PackageDirectory::collision_key( 'My.Plugin_V2' );                    // my.plugin_v2
```

A lock or scope must be at least as wide as identity: whenever
`installed_folder_matches()` is true, `names_collide()` is true. Folding covers
ASCII letters only and does not depend on the PHP locale; any other byte,
including every non-ASCII byte, compares exactly. None of the three methods
validates its input or throws: admit names with `is_valid_name()` first, as the
consumer already does.

#### SQL

The library builds no SQL. To apply the collision rule in a query, bind
`collision_key()` as the value and wrap the column once as `LOWER(column)`; for a
prefix, bind `collision_key( $folder ) . '/'` escaped with the driver's own LIKE
escaping. A column that already stores `collision_key()` values can be compared
directly.

```php
$key   = PackageDirectory::collision_key( $folder );
$query = $wpdb->prepare(
	'SELECT * FROM %i WHERE LOWER(package) = %s OR LOWER(package) LIKE %s',
	$table,
	$key,
	$wpdb->esc_like( $key . '/' ) . '%'
);
```

Precondition: `LOWER(column)` equals `collision_key()` only when the column's
collation maps ASCII `I` to `i`. Turkish and Azeri collations map it to dotless
`ı`, so the query can miss a colliding row, and a missed row lets a lock or
scope check pass. A consumer that cannot rule those collations out, or for
which a missed row is unsafe, selects by its other columns and decides each row
in PHP with `names_collide()`. A bare `column = %s` takes its case rule from the
collation and is not this rule; write exact identity as `BINARY column = BINARY %s`.

#### Exceptions

These comparisons cannot call a method directly. Each is documented where it is
made.

- Full WordPress identifiers (`folder/main.php`) used as registry or reservation
  keys keep their local separator and leading-slash handling, then fold the whole
  identifier with `collision_key()`.
- Full filesystem paths (realpath equality, destination existence, root
  containment) are decided by the filesystem and the operating system, not by a
  folder-name rule.
- Archive entry paths use `ArchiveSafety::collision_failure()`.

`composer test:package-directory` compares all five legacy methods case by case
against frozen Core and branch implementations at the commits recorded in the
test fixtures. It also covers folder admission and installed/new-install names,
every comparison case above, and checks `names_collide()` against `strcasecmp()`
over a name corpus with case variants.

## Updater helpers

- **`WordPressHeaders::parse($contents, $labels)`** extracts header occurrences
  from the first 8 KiB of plugin PHP or theme CSS. Pass a map such as
  `['Name' => 'Plugin Name', 'Version' => 'Version']`. Values are lists in file
  order. Missing fields have no entry, while an empty field yields `['']`.
  The parser handles an initial UTF-8 BOM, CR and CRLF, and WordPress-style
  comment prefixes and terminators, including a compact `<?php/**` opening.
  Callers decide which fields are required, how to handle duplicates, and what
  values to admit.
- **`WordPressHeaders::read($path, $labels)`** verifies that the pathname
  identifies a local regular file and reads up to 8 KiB from one local
  read-only handle. An unavailable, non-regular, or unreadable file returns
  `null`. A readable file with no matching labels returns an empty array. The
  caller resolves the installed file and checks containment and package
  identity.
- **`TemporaryArchive::with_archive($allocate, $acquire, $consume, $limit, $sha256, $size)`**
  scopes a local archive to one synchronous operation. The allocator supplies a
  fresh empty file in a trusted directory, such as a path from `wp_tempnam()`
  in a WordPress adapter. The acquisition callback writes in place and enforces
  the byte limit during transfer. The helper checks the result size, computes
  SHA-256 in one bounded pass, and verifies any supplied expected digest and
  size. The consume callback receives the absolute path, digest and size for inspection
  and handoff. Cleanup runs on success or failure and reports an error if the
  owned path cannot be cleared. Where the host reports no inode number, a file
  at the path is removed only if its metadata and SHA-256 still match the
  verified archive, or the untouched empty allocation; any other file is kept
  and reported. Keep archive use inside the callback.
- **`RuntimeVersion::php($value)`** and **`RuntimeVersion::wordpress($value)`**
  return a three-part runtime version with a normalized prerelease if present,
  or `null` for an unrecognized input. For example, `8.2.0-ubuntu1` becomes
  `8.2.0`, Debian's `8.5.0~alpha4-1` becomes `8.5.0-alpha.4`, and
  `6.8-RC1` becomes `6.8.0-rc.1`. A tilde immediately after a PHP core version
  requires a recognized prerelease stage. A tilde inside the package revision
  is stripped with the revision, as in `8.2.31-1~deb12u1` becoming `8.2.31`.
  WordPress prerelease revisions and their `-src` suffix are dropped.
  A standalone `-src` remains. Callers still validate requirement headers and
  choose a comparison policy. With `version_compare()`, compare three-part
  cores on both sides to preserve prerelease ordering against a two-part
  minimum such as `6.8`.
- **`PluginFileIdentifier::is_valid($value)`** checks relative identifiers such
  as `main.php` and `package/src/main.PHP`. Each slash-separated component
  starts with an ASCII letter or digit and can also contain dots, underscores
  and hyphens. Filename case is preserved. Callers enforce any package depth,
  containment and filename equality requirements.
- **`RegularFileStat::read($path)`** reads a fresh native `lstat()` snapshot of
  a local regular-file entry. The snapshot contains `dev`, `ino`, raw `mode`,
  `nlink`, `uid`, `gid`, `size`, `mtime` and `ctime`. A missing entry,
  directory, final-component symlink or stream-wrapper URI yields `null`.
  Callers govern ancestor containment, permissions, link counts, digest checks,
  repeat observations and cleanup.

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

Pick the mode by where the text comes from. The modes share secret-shape
screening and have separate text admission rules.

| Method | Input | Applies | Bound | Used for |
| --- | --- | --- | --- | --- |
| `strict( $text, $max )` | untrusted free-text detail from a foreign package | every shape rule and the prose credential-word rules | caller supplies, small (updaters use 64 to 120) | updater rejection and failure detail (release updater, branch updater); any logger value that is not an identifier or commit id |
| `display( $text, $max )` | curated sentences Core shows | printable UTF-8 with shape screening and space-delimited slash separators | caller supplies, longer (Core uses 255 to 512) | Core provider diagnostics, webhook remediation, activity text |
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
- **Byte bounds.** `strict()` and `identifier()` output printable ASCII.
  `display()` trims an incomplete UTF-8 code point at the byte boundary and
  returns `null` if no text fits.
- **Not a proof.** The screen is by shape. Sinks must still prefer fixed or
  provider-public text, and consumers must escape the result on output. Encodings
  the rules do not name (a secret re-spelled in a new alphabet, or an unlisted
  vendor token that looks like an ordinary word) are not detected.

### Unicode

`display()` accepts translated prose and typographic punctuation. Malformed
UTF-8, Unicode Other characters (including controls and format characters) and
line and paragraph separators cause rejection. Shape matching treats Unicode
space separators as spaces and preserves them in returned text. A forward
slash must have space on both sides and separate nonempty text.

### Rules

A text is dropped when any of these match (the two credential-word rows apply to STRICT only). Rule names are the identifiers used
by `tests/failure-text-mutation.php`.

| Group | Dropped | Rule names |
| --- | --- | --- |
| Characters | anything outside printable ASCII in STRICT and IDENTIFIER, or outside printable UTF-8 in DISPLAY | `printable_ascii_only`, `printable_utf8_only` |
| Markup | `<` `>` `&` (tags and entity forms), `{` `}` `[` `]`, `` ` ``, `\|`, and a `;` directly followed by text (`a;b`; a `;` before a space or at the end is prose punctuation) | `angle_bracket`, `ampersand`, `bracket_or_brace`, `backtick`, `pipe`, `semicolon` |
| Assignments | any `=` (query strings, `name=value`, `sha256=`, DSNs) | `equals` |
| Userinfo | any `@` (userinfo, email, `git@host:`), any `%XX` escape (`%40`, `%2e`) | `at_sign`, `percent_escape` |
| URLs | a colon or question mark attached to the next character (`https://`, `mailto:a`, `a:b`, `?token`), except inside a clock time (below); a scheme word then a colon even with spaces (`https :`) | `colon_attached`, `question_attached`, `url_scheme_word` |
| Paths | `/` except as a DISPLAY separator, any `\` or `~`; `wp-content`, `wp-admin`, `wp-includes`, `.htaccess`, `.htpasswd`, `public_html`; WordPress and PHP session cookie names; private-key headers | `slash`, `display_slash_policy`, `backslash`, `tilde`, `wordpress_path_word`, `session_cookie_name`, `private_key_material` |
| Credential words (STRICT only) | `password` (and `passw0rd`), `passwd`, `pwd`, `passphrase`, `passcode`, `pass`, `pw`, `passwort`, `jwt`, `auth`, `key is`, `pin is`, `access key`, `secret`, `token`, `api key`, `apikey`, `authorization` or `authorisation`, `signature`, `nonce`, `cookie`, `credential` (and plurals) with any other word or text before or after it, inside a longer word or across an underscore (`usertoken x`, `client_secret x`) but not before a letter (`tokenizer`); the word on its own (`token`, `api keys`) passes. `Basic` or `Bearer` followed by any text | `credential_*`, `credential_other`, `bearer_or_basic` |
| Clock times (exempt from the attached-colon rule) | `HH:MM` and `HH:MM:SS` (hour 0 to 23, minutes and seconds 00 to 59), alone or with `Z` or a UTC offset such as `+00:00`, `+0000` or `-05:30`, and the time after the `T` of an ISO date: `received 12:00`, `at 09:05:59`, `2026-08-13 12:00:00`, `2026-08-13T12:00:00Z`. The time must not follow a letter, digit, colon, dot, plus or hyphen and must not be followed by a letter, digit, colon or slash, so `12:30pm`, `12:00:00:00:00`, `2026-08-13 12:00:00:abc`, `x:12:34`, `password:12:34`, `1:2:3:4`, `aa:bb:cc:dd` and MAC or IPv6 shapes still drop | `colon_attached` (with `CLOCK_TIME`) |
| Credential header name (every mode) | a known credential header name directly followed by a colon, any case, spaces allowed before it: `Authorization`/`Authorisation`, `Proxy-Authorization`, `WWW-Authenticate`, `api-key`, `Cookie`, `Set-Cookie`, `X-WP-Nonce`, and any `x-` header whose name holds `key`, `token`, `secret`, `auth`, `password` or `signature` (`X-API-Key`, `X-Auth-Token`, `X-Access-Token`, `X-CSRF-Token`, `X-XSRF-Token`, `X-Foo-Secret`): `Authorization: Bearer abcdefgh`, `X-API-Key: abcdefgh`. `Note: check the settings.` and `Check the token permissions and organisation approval.` are not headers | `credential_header_name`, `credential_header_generic` |
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

- attached slashes such as `TCP/IP`, `and/or` and `owner/repository`, and text
  containing `\`, `~`, `=`, `@`, `|`, `&`, `<`, `>`, brackets or braces, or a
  `;` directly followed by text, as in `R&D`, `a=b`, `[error]` and `a;b`;
- a sentence that puts a credential header name directly before a colon (`Cookie: theme is dark`, `Set a cookie: it expires soon`, `Key` alone before a colon is fine);
- a colon or question mark followed directly by text, other than a clock time: `12:30pm`, `ratio 3:1`, `12:00: done`,
  `Error:x`, `what?now`, a time with a fractional-seconds colon or a trailing colon;
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
- non-ASCII text in STRICT or IDENTIFIER, any run of 25 or more ASCII letters and digits, and any text holding
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

Use `display()` for curated translations, including fixed provider messages.
Keep the closed reason vocabulary so callers can supply their own translated
fallbacks. The credential-word rules in `strict()` recognize English terms
and selected variants.

### Scope and limits

- **`display()` is only for fixed Core strings and provider-authored fixed remediation.**
  Never pass it text that echoes remote or foreign content: an HTTP body, an exception
  or `WP_Error` message, a header value, a publisher string. That text needs `strict()`,
  or no text at all and a closed reason code. `display()` omits the prose
  credential-word rule precisely because the caller vouches that the sentence is curated.
- **Engine failures fail closed.** A screening error such as a backtrack or
  JIT stack limit drops the whole text. The corpus also runs under starved limits.
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
- **Curated text still screened by shape.** Attached slashes, markup and quoted
  token prefixes can cause rejection even in a translated message.

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

Adoption order: release this package, then Core and the branch updater (Composer
dependency bump), then the release updater (vendored copy).

| Consumer | How |
| --- | --- |
| Core (`ran-booster`) | Composer dependency already present: bump it to the release, replace the screeners listed below (provider diagnostics, webhook remediation and activity text with `display()`; logger values with `identifier()`, `commit_id()` and otherwise `strict()`), and run the corpus in Core's tests. Use curated translations for diagnostics and keep fixed fallbacks for text containing a rejected shape, such as the attached slashes in `owner/repository` and quoted token prefixes. Core's real ids (`gh.credential.valid`, `bb.repository.not_found`, `webhook.operation_succeeded`, `ran_booster_storage_transaction_unavailable`, `github-fine-grained-pat-v1`) pass `identifier()`; two current ids that name a hash algorithm (`github-hmac-sha256-v1`, `artifact_sha256`) are dropped by the digest rule, so compare those against their closed list instead. |
| Branch updater | Composer dependency bump; `AdmittedFailureReason::screen_detail()` becomes a call to `FailureText::strict( $detail, 64 )`; run the corpus. |
| Release updater | Vendored copy: bump the exact pin in `composer.json`; extend `scripts/sync-updater-support.php` to generate `src/Dependency/FailureText.php` (the class has exactly one namespace occurrence, as the script requires); add the `RAN\WPReleaseUpdater\V1\Dependency\FailureText` entry, with that path, to the class map in `runtime.php`; recompute `runtime-copy.json` `package_revision`; run `composer sync:updater-support` then `check:updater-support`; call `FailureText::strict()` from `ReleaseRejection::detail()`. |

### Migration notes for existing screeners

"Loses" is text the old screener accepted that is now dropped. "Gains" is what the
old screener passed and the shared one drops, or the reverse for benign text.

| Screener | Loses | Gains |
| --- | --- | --- |
| Core `ProviderDiagnosticResult` private screen and `is_safe_text()` (move to `display()`) | text with `=`, `@`, `;`, `\|`, `&`, `%XX` or `a:b`; curated sentences that mention a token or password are kept | rejection of control and format characters (its `[\x00-\x1F\x7F]` without `/u` passed U+202E, U+200B and U+0085); query-string and `name=value` credentials; token prefixes at any length including `ATBB`, `ATCTT3`, `ghp-`; digest forms; hex and base64 runs through separators; relative and embedded paths; obfuscated schemes; snake_case codes of 40 or more characters now pass |
| Core `InvalidCredentialInput` (`display()`) | the same text, plus anything its narrower set allowed (bare `https://x`, hex, `sha256=`, `AKIA`) | all of the above in one policy rather than a drifted copy |
| Core webhook remediation (`RepositoryWebhookOperationResult`, `RepositoryWebhookFitnessResult`; `display()`) | any printable text up to 512 that holds a screened shape (they checked controls and length only) | every shape; the controller and coordinator that call `is_safe_text()` on the in-progress #834 branch move to `display()` with the same bounds |
| Core `BoosterLogger` string context (`identifier()`, `commit_id()`, else `strict()`) | its pre-screen normalization of control characters to spaces (the shared screener drops instead); its 40 or more character run rule that dropped snake_case diagnostic ids such as `ran_booster_storage_transaction_unavailable` (they now pass `identifier()`); exact 32 hex correlation ids stay a Core-local check | whole-text screening of free text before it is kept |
| Release updater `ReleaseRejection::detail()` (`strict()`) | its replacement of non-printable and non-ASCII characters with `?` before screening (the shared screener drops them whole) | rejection of URLs without `@`, assignments the old pattern missed, entities, brackets, most token prefixes, `signature`, `nonce`, `cookie`; one definition |
| Branch updater `AdmittedFailureReason::screen_detail()` (`strict( $detail, 64 )`) | its `[A-Za-z0-9 ._-]` allowlist as a charset (the shared screener also allows `, ( ) ' " ! * + $`), and its input cap of 1,024 (now 4,096, truncated to the caller's bound) | `tokenizer`, `secretary`, `session`, `key`, short commit ids and prose with a colon and a space now pass; rejection of `pwd`, `nonce`, `Basic`, `npm_`, `sk_live_`, `AKIA` and the other prefixes it missed |

A caller that wants the branch updater's narrower charset applies it after the
shared screener.

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
