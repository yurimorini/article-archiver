# Clean distribution image

The root `Dockerfile` builds `log-read-php`. That image is PHP 8.5 CLI plus Xdebug, git, unzip, and Composer. `./bin/docker-build` builds it. `./bin/docker-run` uses it for package tooling. `./bin/docker-serve` runs `php -S` from that image for local use. The development image bind-mounts the repository at `/workspace` and does not copy the application.

This image stays the development toolbox. It keeps the name `log-read-php` and the root `Dockerfile`. `article-reader` has no image of its own. It is a library, and the web-app build copies it in.

The distributable image is a second build, owned by web-app, named `eleanor`.

Already recorded elsewhere, and left as they are:

- [Package layout](../../../article-reader/doc/features/workspace/design/PackageLayout.md) keeps one development image from the root `Dockerfile`.
- [Bootstrap](../features/0001-bootstrap/feature.md) leaves a per-package Dockerfile as a later decision. A second image was out of scope for that feature. This item is that decision.

## Build

The Dockerfile is `packages/web-app/Dockerfile`. The build context is the repository root, because `yumo/article-reader` is a path repository (`../article-reader`).

Two stages.

The dependency stage runs `composer install --no-dev` with an optimized, classmap-authoritative autoloader. It copies both packages' `composer.json` and `composer.lock`, and the `article-reader` source. It does not copy the host `vendor/` directory. That directory symlinks `yumo/article-reader` (`symlink: true` in the web-app `composer.json`) and contains the dev dependencies. The image install turns the symlink off, so the library is a real copy inside the image.

The final stage contains the application code, the production `vendor/`, and the runtime libraries. It omits Xdebug, PECL, git, Composer, and the PHP build packages. The build sets `APP_ENV=prod` and `APP_DEBUG=0`, and runs `cache:warmup --env=prod`. Secrets come from the environment when the container starts.

Extensions the application already needs: `curl`, `dom`, `mbstring`, `ctype`, `iconv`, and `pdo_sqlite`. The image also includes `fileinfo`, so import classification can use libmagic. OPcache is on, with `validate_timestamps=0` and the preload file `config/preload.php`.

The same image runs `bin/console` when the command is replaced. A second CLI image is not part of this item.

`.dockerignore` excludes `.git`, `vendor`, `var`, `tests`, `doc`, and every package other than `article-reader` and `web-app`.

The final base image follows [Runtime](runtime.md): a FrankenPHP image, or a PHP-FPM image when Runtime keeps PHP-FPM. This item does not choose the process that listens.

## SQLite

The development image ships SQLite 3.40.1 (Debian bookworm). The WAL-reset bug in [Sqlite.md](../features/0002-server/research/Sqlite.md) is fixed in 3.51.3, with backports in 3.44.6 and 3.50.7. The clean image carries a SQLite that has the fix. The build checks `sqlite3 --version` and compiles a fixed SQLite when the base image is older. The owner database is WAL ([adr/0003](../adr/0003-owner-database-is-wal.md)), so this matters for the runtime.

## Code and data

The image contains the code. A volume contains the data. The container runs as a non-root user. A named volume is created with that user's id. A bind mount of an existing host directory is not, so the entrypoint may start as root, set the volume owner from `PUID` and `PGID`, and then exec as the application user.

Where those volumes mount is [Runtime](runtime.md). The Symfony CLI local server is not this item: that server is a development tool, and the Symfony docs say it is not for production.
