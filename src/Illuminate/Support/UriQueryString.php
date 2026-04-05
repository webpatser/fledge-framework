<?php

namespace Illuminate\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\InteractsWithData;
use Stringable;

class UriQueryString implements Arrayable, Stringable
{
    use InteractsWithData;

    /**
     * Create a new URI query string instance.
     */
    public function __construct(protected Uri $uri)
    {
        //
    }

    /**
     * Retrieve all data from the instance.
     *
     * @param  mixed  $keys
     * @return array
     */
    public function all($keys = null)
    {
        $query = $this->toArray();

        if (! $keys) {
            return $query;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($query, $key));
        }

        return $results;
    }

    /**
     * Retrieve data from the instance.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    protected function data($key = null, $default = null)
    {
        return $this->get($key, $default);
    }

    /**
     * Get a query string parameter.
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->toArray(), $key, $default);
    }

    /**
     * Get the URL decoded version of the query string.
     */
    public function decode(): string
    {
        return rawurldecode((string) $this);
    }

    /**
     * Get the string representation of the query string.
     */
    public function value(): string
    {
        return (string) $this;
    }

    /**
     * Convert the query string into an array.
     *
     * Uses a custom parser that preserves dots in keys (unlike parse_str which
     * converts dots to underscores for historical PHP reasons).
     */
    public function toArray()
    {
        return static::extractQueryString($this->value());
    }

    /**
     * Extract query string parameters while preserving dots in keys.
     *
     * PHP's parse_str() converts dots to underscores in keys due to historical
     * reasons. This method provides League\Uri\QueryString::extract() compatible
     * behavior using PHP 8.5's native capabilities.
     */
    protected static function extractQueryString(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $pairs = explode('&', $query);
        $result = [];

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = rawurldecode($parts[0]);
            $value = isset($parts[1]) ? rawurldecode($parts[1]) : '';

            // Handle array notation in keys like key[0] or key[sub]
            if (str_contains($key, '[')) {
                preg_match('/^([^\[]+)(.*)$/', $key, $matches);
                $baseKey = $matches[1];
                $arrayPath = $matches[2];

                preg_match_all('/\[([^\]]*)\]/', $arrayPath, $indexMatches);
                $indices = $indexMatches[1];

                $current = &$result;

                if (! isset($current[$baseKey])) {
                    $current[$baseKey] = [];
                }
                $current = &$current[$baseKey];

                foreach ($indices as $i => $index) {
                    $isLast = ($i === count($indices) - 1);

                    if ($index === '') {
                        if ($isLast) {
                            $current[] = $value;
                        } else {
                            $current[] = [];
                            end($current);
                            $current = &$current[key($current)];
                        }
                    } else {
                        if (is_numeric($index)) {
                            $index = (int) $index;
                        }

                        if ($isLast) {
                            $current[$index] = $value;
                        } else {
                            if (! isset($current[$index])) {
                                $current[$index] = [];
                            }
                            $current = &$current[$index];
                        }
                    }
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the string representation of the query string.
     */
    public function __toString(): string
    {
        return (string) $this->uri->getUri()->getQuery();
    }
}
