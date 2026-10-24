<?php

namespace Illuminate\Http\Client;

use CurlSharePersistentHandle;
use InvalidArgumentException;

/**
 * Manages persistent cURL share handles for connection pooling.
 *
 * PHP 8.5 feature: Handles are shared across PHP requests for improved
 * performance through DNS caching and connection reuse.
 */
class PersistentCurlShareManager
{
    /**
     * Cache of persistent handles keyed by options hash.
     */
    protected static array $handles = [];

    /**
     * Get or create a persistent share handle for the given options.
     *
     * @param  array  $options  CURL_LOCK_DATA_* constants to share
     * @return CurlSharePersistentHandle
     */
    public static function getHandle(array $options = [CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT]): CurlSharePersistentHandle
    {
        static::assertSafeOptions($options);

        sort($options);
        $key = implode('_', $options);

        return self::$handles[$key] ??= curl_share_init_persistent($options);
    }

    /**
     * Reject share options that would leak state between unrelated requests.
     *
     * A persistent share handle outlives the request and is shared by every
     * client in the process. Sharing CURL_LOCK_DATA_COOKIE would hand one
     * tenant's cookies to another tenant's requests, so it is never allowed.
     *
     * @param  array  $options  CURL_LOCK_DATA_* constants to share
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeOptions(array $options): void
    {
        if (in_array(CURL_LOCK_DATA_COOKIE, $options, true)) {
            throw new InvalidArgumentException(
                'CURL_LOCK_DATA_COOKIE cannot be shared through persistent cURL connections: cookies would leak between unrelated requests.'
            );
        }
    }

    /**
     * Check if persistent cURL is available.
     */
    public static function isAvailable(): bool
    {
        return function_exists('curl_share_init_persistent');
    }
}
