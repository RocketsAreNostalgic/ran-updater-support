# RAN Updater Support

`ran/updater-support` provides shared contracts and utilities for RAN updater packages. Its first component is a pure PHP 8.2 archive-safety contract. This component performs no ZIP, filesystem, network, or custody work.

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
