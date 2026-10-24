# Redirect opt-in is per caller

The library default is zero redirect hops, so a caller that sets nothing keeps today's failure on a 3xx. Eleanor's archive API keeps that default. The importer will set a hop cap, and that change belongs to the import feature, not to the library feature. The article-reader command will gain a way to set the cap later. The shape of that command flag is not decided yet.

`packages/web-app/doc/features/0003-archive-import/specs/archive-import.md` still requires the same write as `POST /api/archives`. That spec is revised in the import feature, when the importer's cap is chosen.
