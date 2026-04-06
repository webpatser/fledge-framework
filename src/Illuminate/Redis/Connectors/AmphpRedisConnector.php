<?php

namespace Illuminate\Redis\Connectors;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisSubscriber;
use Amp\Redis\Connection\ReconnectingRedisLink;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\AmphpRedisConnection;
use Illuminate\Support\Arr;
use InvalidArgumentException;

use function Amp\Redis\createRedisConnector;

class AmphpRedisConnector implements Connector
{
    /**
     * Create a new connection to a Redis server.
     */
    public function connect(array $config, array $options): AmphpRedisConnection
    {
        $formattedOptions = Arr::pull($config, 'options', []);

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $merged = array_merge($config, $options, $formattedOptions);

        $prefix = $merged['prefix'] ?? '';
        $uri = $this->buildUri($merged);

        $amphpConnector = createRedisConnector($uri);

        $connectorCallback = fn () => new RedisClient(new ReconnectingRedisLink($amphpConnector));

        $client = $connectorCallback();
        $subscriber = new RedisSubscriber($amphpConnector);

        return new AmphpRedisConnection($client, $subscriber, $connectorCallback, $merged, $prefix);
    }

    /**
     * Create a new clustered connection.
     *
     * @throws \InvalidArgumentException
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): never
    {
        throw new InvalidArgumentException(
            'The amphp Redis driver does not support Redis Cluster. Use phpredis or predis for cluster connections.'
        );
    }

    /**
     * Build a Redis URI from the given configuration array.
     */
    protected function buildUri(array $config): string
    {
        $scheme = $config['scheme'] ?? 'tcp';
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 6379);

        // Handle unix sockets
        if (($scheme === 'unix' || isset($config['path'])) && ! isset($config['host'])) {
            $path = $config['path'] ?? $host;

            return "unix://{$path}";
        }

        // Build the base URI
        $uri = "tcp://{$host}:{$port}";

        // Add query params for auth and database
        $query = [];

        if (! empty($config['password'])) {
            $query['password'] = $config['password'];
        }

        if (isset($config['database']) && (int) $config['database'] !== 0) {
            $query['database'] = (int) $config['database'];
        }

        if (isset($config['timeout'])) {
            $query['timeout'] = (float) $config['timeout'];
        }

        if (! empty($query)) {
            $uri .= '?'.http_build_query($query);
        }

        return $uri;
    }
}
