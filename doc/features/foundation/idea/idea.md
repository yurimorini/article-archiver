ok ora analizziamo l'orchestratore, ti dirò quello che voglio fare. Voglio pro e contro di un approccio con argomentazione e fonti.

Interfaccia fetchArticle(string $url): 
- input stringa
- output SafeDocument 

costruttore: da analizzare, nella mia esperienza con symfony avrei passato UrlGuard, EncodingNormalizer, HttpFetcher, HtmlNormalizer come oggetti in partenza. Non avrei mockato e testato l'implementazione tramite mocks ma fatto integration tests.

chiama UrlGuard->guard(raw)
prende GuardResult
log del risultato

estrae SafeFetchTarget dalla GuardResult
HttpFetcher->fetch(target)
  se errore di timeout fa fino a 3 retry incrementali
prende FetchedPage
log del risultato

passa a EncodingNormalizer->normalize(page)
verifica EncodingOutcome 
   se degraded fa un warning
   se ok logga
ritorna Utf8Html

Passa a ArticleExtractor->extract(html)
verifica il risultato
  logga
  se no ok salta sanitizzatore
  se ok passa a sanitizzare

dal risultato estrae ReadableDocument
lo passa a sanitizer->purify(document)
logga 
ritorna SafeDocument

Architettura: separerei la logica in funzioni per mantenere l'albero della funzione principale comprensibile il più possibile. La gestione del log può essere fatta locale nella funzione origine, eccezione può essere rethrowed. 

Dubbio: il contratto con l'esterno implica tutte le eccezioni ? Si io farei così, sono semantiche, però in verità non serve a nulla per il risultato che vogliamo ottenere, solo per estrarre dati di debug o decidere un eventuale status nel caso utilizzi questo Orchestrator in un server http.

Setup: sono abituato a impostare così la mia applicazione

$service1 = new Service1() 
$service2 = new Service2() 
$service3 = new Service3($service1, $service2)
e via così fino a che
$orchestrator = new Orchestrator( services...)
 
$orchestrator->do(url);

Questo però implica una conoscenza della libreria che a questo livello ha poco senso per un esterno. Mi chiedo se valga la pena fare una factory che faccia il wiring con le opzioni di base tratte da un oggetto costruito con un builder che vuole come input le opzioni di base per le policy, o come fluent interface o come un oggetto con options (a schema fisso). 

in questa maniera avrei:

$orchestrator = Factory->build({ ... });
$orchestrator->run();

ma con la possibilità di creare un Orchestrator manualmente 

$orchestrator = Factory->policy1()->policy2()->build({ ... });
$orchestrator->run();

Ultimo punto da valutare, il logging, usiamo un logger standard anchesso come opzione inseribile come dipendenza dell'orchestratore e quindi del mio sistema. In caso di assenza dobbiamo avere un logger minimale Psr-x