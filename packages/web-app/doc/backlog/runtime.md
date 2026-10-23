# Runtime

PHP-FPM behind a Docker reverse proxy serves Eleanor. The server is not open to the public internet.

Slices 1–8 of [0002-server](../features/0002-server/feature.md) use a temporary server. That feature closed on 2026-10-23 and left this item here. It was slice 9, then row 9, and it never started.

This item chooses the process manager and the reverse proxy. [Clean distribution image](clean-distribution-image.md) is the image that omits the development toolchain. It does not choose either. `request_terminate_timeout` belongs here. A configurable lock wait limit stays in [Limits and configuration](limits-and-configuration.md).

The Symfony CLI local server is not this item. That server is a development tool, and the Symfony docs say it is not for production.
