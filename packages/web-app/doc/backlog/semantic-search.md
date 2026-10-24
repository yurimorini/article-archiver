# Semantic search

Preventive research, recorded on 2026-10-24. Non-authoritative. No feature owns this note. It is not a requirement, a design, or a glossary change. A later feature adopts any part of it by writing that decision in its own `feature.md`.

The owner described four capabilities over years of readings in the owner SQLite database: search by a known term, fuzzy search, semantic search where the term is absent and the subject is present, and themes found without a hand-made label.

## What exists

One SQLite file per owner holds the archives. An archive stores the URL pointer, the title, an optional excerpt, an optional site name, and the purified HTML. Nothing in that file is a search index. The text to search would be plain text taken from the purified body. The HTML would not be queried directly.

The archive file stays the copy. A search index would be derived from that copy and rebuildable. This note does not choose whether that index shares the archive file or lives in a second file per owner.

Bookmark stays a separate concept. A theme, a search hit, and a bookmark are not the same thing. This note does not define glossary terms.

## Four mechanisms

| Need | Mechanism | Example |
| --- | --- | --- |
| Known term | Lexical search | `sqlite` finds archives that contain that word |
| Typo or a near form | Fuzzy match | `sqllite` finds `sqlite` |
| Word absent, subject present | Semantic search | a query about long-term memory finds a passage on embeddings |
| Themes on their own | Offline analysis of the corpus | a group forms without an owner-written label |

Lexical search matches words. Fuzzy match measures edit distance between strings. Semantic search measures closeness of meaning. Theme finding does not answer a query. It reads the corpus and proposes groups.

Semantic search does not replace search by term. A proper name, a URL, a sigil, or an exact quotation is a lexical hit. A vector often misses those. Running both for one query is the behavior this note records as the one that holds a personal archive. That combination is not a decision.

The stack below is a direction that fits a local-first archive. The index lives in storage the owner controls. A local process computes vectors. A hosted embedding API is not the only copy. None of the product names below is a chosen dependency.

## Term

SQLite FTS5 can index the plain text of each archive, title and body, and rank hits with BM25. It covers a word, a phrase, and a prefix (`archiv*`).

The stock FTS5 tokenizer does not treat `lettura` and `leggere` as one family. Two open options:

- Stem or lemmatize while indexing, so inflected forms share a token.
- Leave the forms intact in the lexical index and leave kinship of meaning to the semantic layer.

The first helps search by term. The second keeps apart words the owner may still want distinct. For a mixed Italian and English archive, one start is the Unicode tokenizer, with a stemmer added only after real queries ask for it.

Lexicological analysis sits on the same tokens and is not a third search mode. A batch can count frequencies, words characteristic of one year, and collocations. That is the vocabulary of the readings. It is stored in tables. It does not need a model.

## Fuzzy

Fuzzy match is edit distance: one or two wrong letters, or a fragment of a word. FTS5 has a trigram tokenizer that brings substrings and small errors closer. Another option is to correct a short query against the index vocabulary first (the `spellfix1` extension, or a corrector in the application) and then run the lexical search on the corrected term.

Fuzzy match stays on a short, single-term query. Correcting a whole sentence hurts semantic search, because it rewrites words the model would have understood.

## Semantic

Each passage becomes a vector, a list of numbers that stands for the sense of that passage. The query becomes a vector from the same model. The nearest passages are the hits. A query can find a paragraph that shares none of its words.

Points recorded here, in order. None is closed.

1. Index passages, not the whole archive. A long archive mixes subjects. A passage of a few hundred words, with the archive title repeated in the passage text, is the unit this note records. A hit points at the archive and the passage.
2. Use a local multilingual model. The readings will mix Italian and English. Examples named in the conversation: `bge-m3` (dense vectors and lexical signals in one model), or a smaller model such as `multilingual-e5-small`. The model version is part of the index. A different model means the vectors are computed again.
3. Skip an external vector database. Years of readings, split into passages, stay in tens or a few hundreds of megabytes. `sqlite-vec` is enough inside the SQLite world. A second file per owner would let the archive schema stay the copy, and the index could be deleted and rebuilt.
4. Run a hybrid query. FTS5 and vector nearness run together, then the two rankings merge. The usual merge is reciprocal rank fusion. An exact term rises through FTS. A subject without the words rises through the vector.

Eleanor is PHP. Computing a vector fits outside the web process: a small local service (Python, or Ollama on `127.0.0.1`) that answers "for this text, the vector". The application calls it while indexing, and once per query. That service is a replaceable adapter. The vectors stay in SQLite.

## Themes

Themes are computed cold, on an index that already exists. Three levels, from the simpler. None is chosen.

- Keyphrases per archive, with no neural net (YAKE or a similar statistical extractor). Then count which keys recur. This also yields the vocabulary.
- A classical topic model (LDA or NMF) on a bag of words. The groups are interpretable. They are weak on short texts and on paraphrases.
- Clusters on the same vectors used for search (the BERTopic shape: nearness, then a label taken from the words typical of the group). That groups passages that do not share terms. A local language model can name a group that already exists. The group exists without that step.

Store the result as derived data: archive, theme, weight, model version. Recompute it. A theme is not a bookmark and not a folder the owner wrote.

The cut by year is the part that makes the analysis the owner's. The same theme counted over time says what was read in one year and what in another. That series is the archive dates plus membership in the cluster. It does not need another model.

## Order

A possible sequence, not a plan:

1. Plain text from the purified HTML, with a command that rebuilds the index from scratch.
2. FTS5 and search by term. Check that it finds what the owner already knows is stored.
3. Trigrams or vocabulary correction, only for a short query.
4. Passages, local vectors, `sqlite-vec`, merged with FTS5.
5. Themes on the vectors already computed, plus the count by year.
6. Later, if needed: archives near this one, and a natural-language question that answers by citing the passages it found. That reader sits on the index. It is the last layer.

Before tuning models and thresholds, keep a short list of real queries: archives that must appear, archives that must not. Without that list, semantic search looks like it works and its misses stay invisible.

## Limits

A semantic neighbor is the model's opinion, not a true classification. Two passages end up near each other when they only share a register (a tutorial, a polemic, a review). Hybrid search and short passages reduce that noise. An automatic theme is a hypothesis to inspect, with the words that support it visible beside the label.
