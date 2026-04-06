<?php

namespace Illuminate\Http\Client;

use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Client\Response as AmpResponse;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

use function Amp\async;
use function GuzzleHttp\Psr7\stream_for;

/**
 * Guzzle handler backed by amphp/http-client for non-blocking I/O.
 *
 * Replaces CurlHandler as the default transport. All Guzzle middleware
 * (including stubbing, recording, and user middleware) runs unchanged
 * on top of this handler.
 *
 * Each request is dispatched via Amp\async(), which starts it on the
 * Revolt event loop immediately. The returned Guzzle Promise resolves
 * when Future::await() completes. Multiple concurrent requests (e.g.,
 * from Http::pool()) all progress when any single await() drives the
 * event loop.
 */
class AmphpHandler
{
    protected HttpClient $client;

    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
    }

    /**
     * Send an HTTP request via amphp/http-client.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $ampRequest = $this->createAmpRequest($request, $options);

        $future = async(fn () => $this->client->request($ampRequest));

        $promise = new Promise(function () use (&$promise, $future, $request, $options) {
            $startTime = microtime(true);

            try {
                $ampResponse = $future->await();
                $response = $this->createPsr7Response($ampResponse);

                $this->invokeStats($request, $options, $response, $startTime);

                $promise->resolve($response);
            } catch (\Throwable $e) {
                $this->invokeStats($request, $options, null, $startTime, $e);

                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Convert a PSR-7 request to an amphp request with Guzzle options applied.
     */
    protected function createAmpRequest(RequestInterface $request, array $options): AmpRequest
    {
        $ampRequest = new AmpRequest(
            (string) $request->getUri(),
            $request->getMethod()
        );

        // Copy headers
        foreach ($request->getHeaders() as $name => $values) {
            $ampRequest->setHeader($name, $values);
        }

        // Copy body
        $body = (string) $request->getBody();

        if ($body !== '') {
            $contentType = $request->getHeaderLine('Content-Type') ?: null;
            $ampRequest->setBody(BufferedContent::fromString($body, $contentType));
        }

        // Map timeouts
        if (isset($options['timeout']) && $options['timeout'] > 0) {
            $ampRequest->setTransferTimeout((float) $options['timeout']);
            $ampRequest->setInactivityTimeout((float) $options['timeout']);
        }

        if (isset($options['connect_timeout']) && $options['connect_timeout'] > 0) {
            $ampRequest->setTcpConnectTimeout((float) $options['connect_timeout']);
            $ampRequest->setTlsHandshakeTimeout((float) $options['connect_timeout']);
        }

        // Protocol version
        $version = $request->getProtocolVersion();

        if ($version) {
            $ampRequest->setProtocolVersions([$version]);
        }

        // Body size limit (for large responses)
        if (isset($options['max_body_size'])) {
            $ampRequest->setBodySizeLimit((int) $options['max_body_size']);
        }

        return $ampRequest;
    }

    /**
     * Convert an amphp response to a PSR-7 response.
     */
    protected function createPsr7Response(AmpResponse $ampResponse): Psr7Response
    {
        $body = $ampResponse->getBody()->buffer();

        return new Psr7Response(
            $ampResponse->getStatus(),
            $ampResponse->getHeaders(),
            $body,
            $ampResponse->getProtocolVersion(),
            $ampResponse->getReason(),
        );
    }

    /**
     * Invoke the on_stats callback if present.
     */
    protected function invokeStats(
        RequestInterface $request,
        array $options,
        ?Psr7Response $response,
        float $startTime,
        ?\Throwable $error = null,
    ): void {
        if (isset($options['on_stats'])) {
            $transferTime = microtime(true) - $startTime;

            $stats = new TransferStats(
                $request,
                $response,
                $transferTime,
                $error,
                [
                    'total_time' => $transferTime,
                    'http_code' => $response?->getStatusCode() ?? 0,
                    'handler' => 'amphp',
                ],
            );

            ($options['on_stats'])($stats);
        }
    }
}
