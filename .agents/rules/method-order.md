---
description: Order methods so a class reads as one operation from top to bottom
globs: packages/**/src/**/*.php
alwaysApply: false
---

# Method order

Order the methods of a class so a reader can follow one operation from top to bottom.

## Order

1. The constructor comes first.
2. The public methods come next, in the order a caller meets them. The entry of the type is first.
3. The private method that carries a public method sits directly under that public method.
4. The steps that method calls follow it, in the order of those calls in its body. A step's own callees come straight after that step, before the next step.
5. A method called from more than one method is placed once, after those steps, in the order of its first use. The methods that exist only to serve it follow it.

Do not leave a helper between two steps because that is where it was added.

`ArchiveService` reads `archive`, `archiveLocked`, `alreadyStored`, `fetch`, `fromSuccess`, `save`, `written`, then the shared `failed`, `log`, and `context`.

## Out of scope

PHPUnit test methods stay in case order. A test helper follows the tests that use it.
