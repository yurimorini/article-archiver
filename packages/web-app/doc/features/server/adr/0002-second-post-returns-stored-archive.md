# A second POST of the same URL returns the archive already stored

Status: proposed. This is a research direction, not a final decision. A later session can replace it. A spec adopts it when implementation starts. This refines the success row of [0001](0001-archive-outcome-status.md): 201 is only the request that wrote the copy.

One owner has one archive of a page, named by its URL. When that archive is already stored, Eleanor does not fetch and does not write. The response is 200 with the same JSON body a 201 would have carried, read from the stored record. Refreshing a page that has changed is a later command, not this POST.

Running the pipeline again would spend a fetch to refresh the page. Answering 409 would treat a command that is already satisfied as a conflict the client must correct and retry. [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html#name-409-conflict) reserves 409 for that conflict. Two posts that both miss the row may both fetch. The URL is unique in the owner's file, so the first insert wins and the other reads the stored row and takes the 200 path. [Storage.md](../research/Storage.md) records that race.

An idempotency key is a different rule. It recognizes one HTTP attempt whose response the client missed. It is recorded in [Types.md](../research/Types.md) and is not part of this contract.
