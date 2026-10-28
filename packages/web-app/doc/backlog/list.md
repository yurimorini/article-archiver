Scope: web app
Voglio aggiungere due nuove API. Due rotte distinte.

Voglio poter listare gli articoli archiviati e voglio potere ottenere il dettaglio di un archivio, compreso il body.

Rotta listing: 
- deve essere paginata con un numero massimo di items 
- la richiesta è GET
- posso determinare la pagina da visualizzare ed il numero di elementi attraverso un cursore. Non so quale sia la metodologia migliore. Dobbiamo valutare le più usate e scegliere tra quelle. 
- posso fare sorting ASC DESC in base alla data di creazione della entry. Ad oggi sarebbe sufficente e la creazione è anche un buon default ma un domani potremmo voler ordinare su altri parametri.
- trattiamo il formato di ritorno come un formato standard di lista di elementi, così che in futuro possa facilmente aggiungere un nuovo endpoint di ritorno con la stessa forma e logica. Ad esempio in futuro potrei fare fuzzy finding o ricerca e usare la stessa struttura con la stessa logica e trattare questa come una ricerca standard senza filtri
- c'è un hard cap configurabile del massimo numero di elementi ritornabile dal parametro. 
- Il listing torna esclusivamente metadata e non torna il body del corpo
- In caso di lista vuota, torna array vuoto con un 200

Rotta "archived":
- dato l'url o l'id della entry (abbiamo un id nello schema?) torna il dettaglio archiviato 
- In caso di entry mancante 404
- in caso di valore invalido 400 (o lo status più corretto)


## List endpoint

### Specs

GET api/archives/list?sort=creation&dir=asc&page=1&size=10
{
    length: 10,
    items: [{
        ...
    }],
    cursor: {
        total: 98, 
        page: 1,
        size: 10,
        dir: asc,
        next: /list?&dir=asc&page=1&size=10
    }
}

query options {
    enum sort { // optional, default created
        created
    },
    enum dir { // optional default desc
        asc,
        desc
    },
    int size, // optional, default 20, max 50
    int page  // optional, default 1
}

cursor {
    int total  // total num of entries
    int page   // Current page
    int size   // page size
    dir dir    // direction

    // to evaluate
    next: // link having only declared options for the next page
    prev: // link having only declared options for the prev page
}

int length, current page length

### Design

Flow:
- parse query string 
  - skip unknown query string properties
  - errors on invalid expected values
    - no invalid enum values
    - size greater than max
    - not numeric page or size
    - no negative values
  - page out of bound: 404
  
- validate query or fail
- use archive service for search, or a new SearchArchivedService 
- search in the archive passing the cursor
- returns the list, the total num of items, and the cursor
- generate the reply message building the next and prev links
  - scope is HTTP service (controller or reply, to define)
  - why ? it is a standard for listing, add the current route name, and serialize the cursor

## Detail endpoint

GET api/archived/{id}

La cosa più ignorante e semplice:
torna un JSON con metadati e articolo escaped

Spec: 
- id è obbligatorio, senza 404, con valore non valido 4XX

Dubbi per mettere in discussione il requirement:
- Ritorna archived (non summary) serializzato. Discutere come ritornare il body, in linea? o solo il body senza metadata ed un link al body HTML o ancora un file cachato su disco ? Cosa fanno altri sistemi simili conosciuti ?
- Domanda, è qui che in caso di Redis metterei una cache ? solo il body in Redis o tutta la entry ? oppure non cacho e cacho una eventuale pagina html renderizzata server side con controllo su Accept ? come renderizzo ? mi piacerebbe fare confronto con soluzioni simili. 

