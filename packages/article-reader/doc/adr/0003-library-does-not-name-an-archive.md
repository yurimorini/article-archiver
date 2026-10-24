# The library reports both URLs and does not name an archive

The fetch result carries the entry URL and the final URL. Naming an archive, and storing the final URL beside it, belongs to the caller. Two entry URLs that land on one final URL stay two fetches. The library does not merge them.
