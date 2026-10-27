<?php

namespace Illuminate\Tests\Fledge\PHPStan;

use Illuminate\Routing\Concerns\HasConcurrentMiddleware;

/**
 * Analysis-only host for phpstan.fledge.neon.dist.
 *
 * PHPStan analyses a trait only through a class that uses it. The production
 * users of this Fledge-only trait are upstream-derived classes that stay out of
 * the Fledge config, so this host lets the trait itself be analysed.
 */
final class ConcurrentMiddlewareHost
{
    use HasConcurrentMiddleware;
}
