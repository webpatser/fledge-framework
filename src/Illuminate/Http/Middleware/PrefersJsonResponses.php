<?php

namespace Illuminate\Http\Middleware;

use Closure;

class PrefersJsonResponses
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $accept = $request->headers->get('Accept');

        if ($this->acceptHeaderIsBroad($accept)) {
            if ($accept !== null) {
                $request->headers->set('X-Original-Accept', $accept);
            }

            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }

    /**
     * Determine if the given "Accept" header value is broad enough to be treated as JSON.
     *
     * The header is broad when it's missing or every media-type listed is wildcard ("*\/*" or "application/*").
     *
     * @param  string|null  $accept
     * @return bool
     */
    protected function acceptHeaderIsBroad($accept)
    {
        if ($accept === null || trim($accept) === '') {
            return true;
        }

        return array_all(explode(',', $accept), function (string $value): bool {
            $value = strtolower(trim($value));

            if ($value === '') {
                return true;
            }

            if (($pos = strpos($value, ';')) !== false) {
                $value = trim(substr($value, 0, $pos));
            }

            return in_array($value, ['*/*', 'application/*'], true);
        });
    }
}
