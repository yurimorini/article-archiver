<?php

declare(strict_types=1);

use Yumo\LogRead\ArticleExtractor\ArticleExtractor;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HttpFetcher\FetchPolicy;
use Yumo\LogRead\HttpFetcher\HttpFetcher;
use Yumo\LogRead\UrlGuard\UrlGuard;

require __DIR__ . '/../vendor/autoload.php';

//$url = 'https://www.google.com';
$url = 'https://www.fractalkitty.com/rabbit-hole-minimum-l-seams/';

$urlGuard = new UrlGuard();
$result = $urlGuard->guard($url);

echo $result->safe->requestUri;

$policy = new FetchPolicy();
$httpFetcher = new HttpFetcher($policy);
$page = $httpFetcher->fetch($result->safe);

$encodingNormalizer = new EncodingNormalizer();
$normalizedPage = $encodingNormalizer->normalize($page);

$articleExtractor = new ArticleExtractor(new ExtractPolicy());
$article = $articleExtractor->extract($normalizedPage->html);
if ($article->document === null) {
    echo "No article content\n";

    return;
}

$htmlSanitizer = new HtmlSanitizer(new PurifyPolicy());
$safeDocument = $htmlSanitizer->purify($article->document);

var_dump($safeDocument);
