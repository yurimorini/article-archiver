# Glossary Map

## Contexts

- [article-reader](./packages/article-reader/doc/GLOSSARY.md): the library that fetches a page and purifies its body. No glossary file until that package resolves a term.
- [web-app](./packages/web-app/doc/GLOSSARY.md): Eleanor, the application that keeps an owner's archive.

## Relationships

- **article-reader → web-app**: the library produces an Article from a page. Eleanor stores an Archive of that page for one Owner. A URL pointer names the page and is neither the Article nor the Archive.
