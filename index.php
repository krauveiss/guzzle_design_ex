<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

echo "=== ТЕСТ 1: Пользовательское middleware и реальный HTTP-запрос ===\n";

$stack = HandlerStack::create();

$stack->push(
    static function (callable $handler): callable {
        return static function (RequestInterface $request, array $options) use ($handler) {
            echo "[Middleware] Запрос перехвачен, добавляем заголовок\n";
            $request = $request->withHeader('X-Architecture-Demo', 'Guzzle-HandlerStack-Student');

            return $handler($request, $options)->then(
                static function (ResponseInterface $response): ResponseInterface {
                    echo "[Middleware] Ответ получен, статус: {$response->getStatusCode()}\n";

                    return $response;
                }
            );
        };
    },
    'my_custom_debugger'
);

$client = new Client([
    'handler' => $stack,
    'timeout' => 5.0,
]);

try {
    $response = $client->get('https://httpbin.org/headers');
    echo $response->getBody() . "\n\n";
} catch (GuzzleException $exception) {
    echo "[Ошибка HTTP-запроса] {$exception->getMessage()}\n\n";
}

echo "=== ТЕСТ 2: MockHandler возвращает ответы из очереди ===\n";

$mock = new MockHandler([
    new Response(404, [], 'Not Found'),
    new Response(200, ['X-Foo' => 'Bar'], 'Hello, World!'),
]);

$mockStack = HandlerStack::create($mock);
$mockClient = new Client(['handler' => $mockStack]);

$notFoundResponse = $mockClient->get('https://api.example.test/test', [
    'http_errors' => false,
]);
echo "Первый ответ: {$notFoundResponse->getStatusCode()} {$notFoundResponse->getBody()}\n";

$okResponse = $mockClient->get('https://api.example.test/test');
echo "Второй ответ: {$okResponse->getStatusCode()} {$okResponse->getBody()}\n\n";

echo "=== ТЕСТ 3: RetryMiddleware повторяет запрос после 503 ===\n";

$retryMock = new MockHandler([
    new Response(503, [], 'Service Unavailable'),
    new Response(200, [], 'Recovered'),
]);

$retryStack = HandlerStack::create($retryMock);
$retryStack->push(
    Middleware::retry(
        static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response,
            mixed $reason
        ): bool {
            return $retries < 1
                && $response !== null
                && $response->getStatusCode() === 503;
        },
        static fn (int $retries, ?ResponseInterface $response, RequestInterface $request): int => 0
    ),
    'retry'
);

$retryClient = new Client(['handler' => $retryStack]);
$retryResponse = $retryClient->get('https://api.example.test/retry');

echo "Итоговый ответ после повтора: {$retryResponse->getStatusCode()} {$retryResponse->getBody()}\n";
