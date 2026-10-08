# An archive is looked up for one owner

Status: proposed. This is a research direction, not a final decision. A later session can replace it. A spec adopts it when implementation starts.

The port answers whether this owner already has this URL. Another owner's copy is not a hit. A first post by this owner fetches and saves for this owner. The first adapter writes that owner's SQLite file and does not read anyone else's. A later adapter may keep one article body and an ownership link per owner. On save it may attach this owner to a body that already exists, and the service does not know that. The service does not ask whether any owner has the URL.

Treating one URL as one archive for the whole machine would let Ada's post return Yuri's copy without a fetch. That collapses two lists into one. The concrete export is a copy of one owner's file, so the lists stay separate. The shared body is a property a later adapter may have. It is not the port's answer.
