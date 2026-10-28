# The library default of five redirect hops is the Eleanor default

Status: revised 2026-10-25. The title keeps the old file name.

The library default is five redirect hops, so a caller that sets nothing follows up to five hops. Eleanor's archive API and `archive:import` use that default, because both call the library fetch with no hop cap of their own. An explicit cap of zero still refuses the first hop. The article-reader command has no cap flag yet.

The earlier decision kept the library and Eleanor at zero and left the importer's cap to the import feature. `packages/web-app/doc/features/0003-archive-import/specs/archive-import.md` still requires the same write as `POST /api/archives`. That write now follows up to five hops.
