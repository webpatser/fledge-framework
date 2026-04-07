<?php

namespace Illuminate\Contracts\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ConcurrentMiddleware
{
    /**
     * Handle the request before it is passed to the next middleware.
     *
     * Return the (optionally modified) request to continue, or a response to short-circuit.
     */
    public function before(Request $request): Request|Response;

    /**
     * Handle the response after the next middleware has executed.
     */
    public function after(Request $request, Response $response): Response;
}
