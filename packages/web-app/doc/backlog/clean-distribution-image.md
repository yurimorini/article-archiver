# Clean distribution image

The root `Dockerfile` builds `log-read-php`. That image is PHP 8.5 CLI plus Xdebug, git, unzip, and Composer. `./bin/docker-build` builds it. `./bin/docker-run` uses it for package tooling and for a temporary development server.

This image is a development image. It is not a production image and it is not a distributable runtime.

A later activity designs the build that publishes a clean image. That image omits the development toolchain. The development image stays the root `Dockerfile` and keeps the name `log-read-php`.

Already recorded elsewhere, and left as they are:

- [Package layout](../../../article-reader/doc/features/workspace/design/PackageLayout.md) keeps one development image from the root `Dockerfile`.
- [Bootstrap](../features/0001-bootstrap/feature.md) leaves a per-package Dockerfile as a later decision. A second image was out of scope for that feature.

The development image ships SQLite 3.40.1 (Debian bookworm). The WAL-reset bug in [Sqlite.md](../features/0002-server/research/Sqlite.md) is fixed in 3.51.3, with backports in 3.44.6 and 3.50.7. The clean image should carry a SQLite that has the fix. The owner database is WAL ([adr/0003](../adr/0003-owner-database-is-wal.md)), so this matters for the runtime.

This item is the distributable image. It does not choose the process manager or the reverse proxy. [Runtime](runtime.md) owns PHP-FPM behind a Docker reverse proxy. The Symfony CLI local server is not this item: that server is a development tool, and the Symfony docs say it is not for production.
