<?php

namespace Illuminate\Foundation\Console\Bench\Scenarios;

use Illuminate\Foundation\Console\Bench\Scenario;
use League\Uri\Uri as LeagueUri;
use Uri\Rfc3986\Uri as NativeUri;

class UriScenario implements Scenario
{
    private array $samples = [
        'https://example.com/path?query=1&filter=active',
        'https://user:pass@host.example.com:8443/segment/segment?a=1&b=2#frag',
        'https://api.example.com/v1/users/123/orders?status=pending&limit=50',
        'http://localhost:3000/dashboard',
        'https://example.com/',
    ];

    public function name(): string
    {
        return 'uri';
    }

    public function label(): string
    {
        return 'URI parsing (PHP 8.5 native vs league/uri)';
    }

    public function preflight(): ?string
    {
        if (! class_exists(NativeUri::class)) {
            return 'PHP 8.5 native URI extension is missing. This should never happen on Fledge (PHP 8.5 is a hard requirement). Check your PHP build with `php -m | grep -i uri`.';
        }

        return null;
    }

    public function setup(): void
    {
        //
    }

    public function variants(): array
    {
        $samples = $this->samples;

        $variants = [
            'native' => function () use ($samples) {
                foreach ($samples as $url) {
                    new NativeUri($url);
                }
            },
        ];

        if (class_exists(LeagueUri::class)) {
            $variants['league'] = function () use ($samples) {
                foreach ($samples as $url) {
                    LeagueUri::new($url);
                }
            };
        }

        return $variants;
    }

    public function teardown(): void
    {
        //
    }

    public static function compareInstallHint(): string
    {
        return <<<'TEXT'
        To compare against league/uri, install it as a temporary dev dependency:

            composer require --dev league/uri:^7.0

        Run the bench, then remove it again:

            composer remove --dev league/uri

        Without league/uri installed, the URI scenario reports native timings only.
        TEXT;
    }
}
