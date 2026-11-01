# Positive integer string

`ArchivePageQuery::integer` and `ArchiveId::from` parse the same digit string. `ArchiveId` is on the archive-detail branch and copies the private method in `packages/web-app/src/Archive/ArchivePageQuery.php`.

The string is a base-10 integer of at least 1 that fits in a PHP int. A sign, a decimal point, a leading zero, surrounding space, or a trailing newline is rejected. A digit string above `PHP_INT_MAX` is rejected by length and `strcmp` before the cast, because a cast past the limit wraps.

One shared parser should own that check. Callers still throw `InvalidArchiveQuery`. A PHP built-in is that parser only when it rejects the same set. `filter_var` with `FILTER_VALIDATE_INT` does not: it accepts a sign, surrounding space, a leading zero, and a `0x` prefix.

The offset check in `ArchivePageQuery::page` stays there. It is a page rule, not the integer parse.
