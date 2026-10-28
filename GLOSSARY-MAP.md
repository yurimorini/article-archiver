# Glossary Map

## Contexts

- [article-reader](./packages/article-reader/doc/GLOSSARY.md): the library that fetches a page and purifies its body.
- [web-app](./packages/web-app/doc/GLOSSARY.md): Eleanor, the application that keeps an owner's archive.

## Relationships

- **article-reader → web-app**: the library produces an Article from a page and reports the entry URL and the final URL. Eleanor stores an Archive of that page for one Owner and decides whether that archive records the final URL. A URL pointer names the page and is neither the Article nor the Archive.
