<?php

namespace Illuminate\Http\Client;

use CurlSharePersistentHandle;

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
        sort($options);
        $key = implode('_', $options);

        return self::$handles[$key] ??= curl_share_init_persistent($options);
    }

    /**
     * Check if persistent cURL is available.
     */
    public static function isAvailable(): bool
    {
        return function_exists('curl_share_init_persistent');
    }
}
