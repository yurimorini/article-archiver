# Runtime

One container speaks HTTP and is the unit that gets distributed. [Clean distribution image](clean-distribution-image.md) is that image. It omits the development toolchain and does not choose the process that listens. This item does.

FrankenPHP is the process. Caddy and PHP are one binary, so the image needs no init, and the container answers HTTP on one port. The same image serves HTTP by default and runs `bin/console` when the command is replaced. A long archive is bounded by FrankenPHP's worker timeout. That timeout is this item's `request_terminate_timeout`.

The previous direction of this note was PHP-FPM behind a Docker reverse proxy. Two forms keep that direction.

- One image, two processes. PHP-FPM and Caddy (or nginx) run together. s6-overlay is PID 1: it starts both, restarts a dead child, and forwards the stop signal Docker sends. FrankenPHP does not use s6. Supervisord is not the init. PHP-FPM then owns `request_terminate_timeout` and `pm.max_requests` directly.
- Two containers, one Compose stack. PHP-FPM in one, the reverse proxy in the other. That is the deploy shape for an orchestrator that scales them apart. It is not the way the application is published.

TLS ends on the same machine, outside the application image. Caddy or nginx listens on 443 and forwards to the app on localhost. The app container publishes its HTTP port on the loopback interface. A cache such as Varnish is not that proxy: it does not terminate TLS, and Eleanor's responses belong to one owner, sit behind a bearer, and come from writes. A second machine, or a load balancer in front of several instances, waits until more than one instance exists. The server is not open to the public internet.

The database stays on a disk of that same machine. The SQLite file, its `-wal`, and its `-shm` stay on one filesystem. The lock directory stays on a filesystem that provides flock.

Mounts, while prod still uses the project paths (`eleanor.data_dir` is `%kernel.project_dir%/var/data`, `eleanor.lock_dir` is `%kernel.project_dir%/var/lock`; only the test environment reads `ELEANOR_DATA_DIR` and `ELEANOR_LOCK_DIR`):

- `var/data` holds the owner SQLite file, `-wal`, and `-shm`.
- `var/lock` holds the flock files.
- `var/log` holds the prod rotating logs. Prod Monolog writes those files, not stderr.

`var/cache` stays the cache warmed into the image. A later change points the prod parameters at those environment variables and mounts one `/data` volume instead of the three paths.

Slices 1–8 of [0002-server](../features/0002-server/feature.md) use a temporary server. That feature closed on 2026-10-23 and left this item here. It was slice 9, then row 9, and it never started.

A configurable lock wait limit stays in [Limits and configuration](limits-and-configuration.md).

The Symfony CLI local server is not this item. That server is a development tool, and the Symfony docs say it is not for production.
