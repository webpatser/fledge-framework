<?php

namespace Illuminate\Support;

use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;
use JsonSerializable;
use SensitiveParameter;
use Stringable;
use Uri\Rfc3986\Uri as NativeUri;

class Uri implements Htmlable, JsonSerializable, Responsable, Stringable
{
    use Conditionable, Dumpable, Macroable, Tappable;

    /**
     * The URI instance.
     */
    protected NativeUri $uri;

    /**
     * The URL generator resolver.
     */
    protected static ?Closure $urlGeneratorResolver = null;

    /**
     * Create a new parsed URI instance.
     */
    public function __construct(NativeUri|Stringable|string $uri = '')
    {
        $this->uri = $uri instanceof NativeUri ? $uri : new NativeUri(static::normalizeForRfc3986((string) $uri));
    }

    /**
     * Normalize a URI string for RFC 3986 compliance.
     *
     * RFC 3986 is stricter than what many applications expect. This method
     * handles common cases that would otherwise be rejected:
     * - Internationalized domain names (IDN) → converted to punycode
     * - Unicode characters in path → percent-encoded
     * - Unencoded brackets in query string → percent-encoded
     */
    protected static function normalizeForRfc3986(string $uri): string
    {
        if ($uri === '') {
            return $uri;
        }

        $uri = static::encodeIdnHost($uri);
        $uri = static::encodeUnicodePath($uri);
        $uri = static::encodeQueryBrackets($uri);

        return $uri;
    }

    /**
     * Convert internationalized domain names (IDN) to ASCII punycode.
     *
     * Domains like "bébé.be" become "xn--bb-bjab.be" for RFC 3986 compliance.
     */
    protected static function encodeIdnHost(string $uri): string
    {
        if (! preg_match('~^([a-zA-Z][a-zA-Z0-9+.-]*://)([^/:?#]+)(.*)$~u', $uri, $matches)) {
            return $uri;
        }

        [, $schemeAndSlashes, $authority, $rest] = $matches;

        // Split off any userinfo so IDN mapping only ever runs on the real host
        // label. Folding userinfo through UTS-46 can relocate the host boundary
        // (e.g. a fullwidth "@" collapsing into a real "@") and corrupts legit
        // unicode credentials.
        $userInfoBoundary = strrpos($authority, '@');

        if ($userInfoBoundary !== false) {
            $userInfo = substr($authority, 0, $userInfoBoundary + 1);
            $host = substr($authority, $userInfoBoundary + 1);
        } else {
            $userInfo = '';
            $host = $authority;
        }

        if (! preg_match('/[^\x00-\x7F]/', $host)) {
            return $uri;
        }

        if (function_exists('idn_to_ascii')) {
            $asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($asciiHost !== false) {
                return $schemeAndSlashes.$userInfo.$asciiHost.$rest;
            }
        }

        return $uri;
    }

    /**
     * Percent-encode Unicode characters in the URI path.
     *
     * Characters like "汉字" become "%E6%B1%89%E5%AD%97" for RFC 3986 compliance.
     */
    protected static function encodeUnicodePath(string $uri): string
    {
        if (! preg_match('~^((?:[a-zA-Z][a-zA-Z0-9+.-]*://)?[^/?#]*)(/[^?#]*)?(.*)$~u', $uri, $matches)) {
            return $uri;
        }

        [, $beforePath, $path, $queryAndFragment] = $matches;

        if (empty($path)) {
            return $uri;
        }

        if (! preg_match('/[^\x00-\x7F]/', $path)) {
            return $uri;
        }

        $encodedPath = preg_replace_callback(
            '/[^\x00-\x7F]+/u',
            fn ($m) => rawurlencode($m[0]),
            $path
        );

        return $beforePath.$encodedPath.$queryAndFragment;
    }

    /**
     * Encode unencoded brackets in the query string portion of a URI.
     *
     * RFC 3986 requires brackets to be percent-encoded. PHP's native Uri\Rfc3986\Uri
     * is strictly compliant and will reject URIs with unencoded brackets.
     */
    protected static function encodeQueryBrackets(string $uri): string
    {
        $queryStart = strpos($uri, '?');

        if ($queryStart === false) {
            return $uri;
        }

        $fragmentStart = strpos($uri, '#', $queryStart);
        $beforeQuery = substr($uri, 0, $queryStart + 1);

        if ($fragmentStart !== false) {
            $query = substr($uri, $queryStart + 1, $fragmentStart - $queryStart - 1);
            $fragment = substr($uri, $fragmentStart);
        } else {
            $query = substr($uri, $queryStart + 1);
            $fragment = '';
        }

        $query = preg_replace_callback(
            '/(?<!%5[BD])(\[|\])/',
            fn ($m) => $m[1] === '[' ? '%5B' : '%5D',
            $query
        );

        return $beforeQuery.$query.$fragment;
    }

    /**
     * Create a new URI instance.
     */
    public static function of(NativeUri|Stringable|string $uri = ''): static
    {
        return new static($uri);
    }

    /**
     * Get a URI instance of an absolute URL for the given path.
     */
    public static function to(string $path): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->to($path));
    }

    /**
     * Get a URI instance for a named route.
     *
     * @param  \BackedEnum|string  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return static
     *
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException|\InvalidArgumentException
     */
    public static function route($name, $parameters = [], $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->route($name, $parameters, $absolute));
    }

    /**
     * Create a signed route URI instance for a named route.
     *
     * @param  \BackedEnum|string  $name
     * @param  mixed  $parameters
     * @param  \DateTimeInterface|\DateInterval|int|null  $expiration
     * @param  bool  $absolute
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public static function signedRoute($name, $parameters = [], $expiration = null, $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->signedRoute($name, $parameters, $expiration, $absolute));
    }

    /**
     * Create a temporary signed route URI instance for a named route.
     *
     * @param  \BackedEnum|string  $name
     * @param  \DateTimeInterface|\DateInterval|int  $expiration
     * @param  array  $parameters
     * @param  bool  $absolute
     * @return static
     */
    public static function temporarySignedRoute($name, $expiration, $parameters = [], $absolute = true): static
    {
        return static::signedRoute($name, $parameters, $expiration, $absolute);
    }

    /**
     * Get a URI instance for a controller action.
     *
     * @param  string|array  $action
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public static function action($action, $parameters = [], $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->action($action, $parameters, $absolute));
    }

    /**
     * Get the URI's authority.
     */
    public function authority(): ?string
    {
        $host = $this->uri->getHost();

        if ($host === null) {
            return null;
        }

        $authority = '';
        $userInfo = $this->uri->getUserInfo();

        if ($userInfo !== null) {
            $authority = $userInfo.'@';
        }

        $authority .= $host;
        $port = $this->uri->getPort();

        if ($port !== null) {
            $authority .= ':'.$port;
        }

        return $authority;
    }

    /**
     * Get the URI's scheme.
     */
    public function scheme(): ?string
    {
        return $this->uri->getScheme();
    }

    /**
     * Get the user from the URI.
     */
    public function user(bool $withPassword = false): ?string
    {
        return $withPassword
            ? $this->uri->getUserInfo()
            : $this->uri->getUsername();
    }

    /**
     * Get the password from the URI.
     */
    public function password(): ?string
    {
        return $this->uri->getPassword();
    }

    /**
     * Get the URI's host.
     */
    public function host(): ?string
    {
        return $this->uri->getHost();
    }

    /**
     * Get the URI's port.
     */
    public function port(): ?int
    {
        return $this->uri->getPort();
    }

    /**
     * Get the URI's path.
     *
     * Empty or missing paths are returned as a single "/".
     *
     * @return non-empty-string
     */
    public function path(): string
    {
        $path = trim((string) $this->uri->getPath(), '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Get the URI's path segments.
     *
     * Empty or missing paths are returned as an empty collection.
     */
    public function pathSegments(): Collection
    {
        $path = $this->path();

        return $path === '/' ? new Collection : new Collection(explode('/', $path));
    }

    /**
     * Get the URI's query string.
     */
    public function query(): UriQueryString
    {
        return new UriQueryString($this);
    }

    /**
     * Get the URI's fragment.
     */
    public function fragment(): ?string
    {
        return $this->uri->getFragment();
    }

    /**
     * Specify the scheme of the URI.
     */
    #[\NoDiscard]
    public function withScheme(Stringable|string $scheme): static
    {
        return new static($this->uri->withScheme($scheme));
    }

    /**
     * Specify the user and password for the URI.
     */
    #[\NoDiscard]
    public function withUser(Stringable|string|null $user, #[SensitiveParameter] Stringable|string|null $password = null): static
    {
        if ($user === null) {
            return new static($this->uri->withUserInfo(null));
        }

        $userInfo = (string) $user;

        if ($password !== null) {
            $userInfo .= ':'.(string) $password;
        }

        return new static($this->uri->withUserInfo($userInfo));
    }

    /**
     * Specify the host of the URI.
     */
    #[\NoDiscard]
    public function withHost(Stringable|string $host): static
    {
        return new static($this->uri->withHost($host));
    }

    /**
     * Specify the port of the URI.
     */
    #[\NoDiscard]
    public function withPort(?int $port): static
    {
        return new static($this->uri->withPort($port));
    }

    /**
     * Specify the path of the URI.
     */
    #[\NoDiscard]
    public function withPath(Stringable|string $path): static
    {
        return new static($this->uri->withPath(Str::start((string) $path, '/')));
    }

    /**
     * Merge new query parameters into the URI.
     */
    #[\NoDiscard]
    public function withQuery(array $query, bool $merge = true): static
    {
        foreach ($query as $key => $value) {
            if ($value instanceof UrlRoutable) {
                $query[$key] = $value->getRouteKey();
            }
        }

        if ($merge) {
            $mergedQuery = $this->query()->all();

            foreach ($query as $key => $value) {
                data_set($mergedQuery, $key, $value);
            }

            $newQuery = $mergedQuery;
        } else {
            $newQuery = [];

            foreach ($query as $key => $value) {
                data_set($newQuery, $key, $value);
            }
        }

        return new static($this->uri->withQuery(Arr::query($newQuery) ?: null));
    }

    /**
     * Merge new query parameters into the URI if they are not already in the query string.
     */
    #[\NoDiscard]
    public function withQueryIfMissing(array $query): static
    {
        $currentQuery = $this->query();

        foreach ($query as $key => $value) {
            if (! $currentQuery->missing($key)) {
                Arr::forget($query, $key);
            }
        }

        return $this->withQuery($query);
    }

    /**
     * Push a value onto the end of a query string parameter that is a list.
     */
    #[\NoDiscard]
    public function pushOntoQuery(string $key, mixed $value): static
    {
        $currentValue = data_get($this->query()->all(), $key);

        $values = Arr::wrap($value);

        return $this->withQuery([$key => match (true) {
            is_array($currentValue) && array_is_list($currentValue) => array_values(array_unique([...$currentValue, ...$values])),
            is_array($currentValue) => [...$currentValue, ...$values],
            ! is_null($currentValue) => [$currentValue, ...$values],
            default => $values,
        }]);
    }

    /**
     * Remove the given query parameters from the URI.
     */
    #[\NoDiscard]
    public function withoutQuery(array|string $keys): static
    {
        return $this->replaceQuery(Arr::except($this->query()->all(), $keys));
    }

    /**
     * Specify new query parameters for the URI.
     */
    #[\NoDiscard]
    public function replaceQuery(array $query): static
    {
        return $this->withQuery($query, merge: false);
    }

    /**
     * Specify the fragment of the URI.
     */
    #[\NoDiscard]
    public function withFragment(string $fragment): static
    {
        return new static($this->uri->withFragment($fragment));
    }

    /**
     * Remove the fragment from the URI.
     */
    public function withoutFragment(): static
    {
        return new static($this->uri->withFragment(null));
    }

    /**
     * Create a redirect HTTP response for the given URI.
     */
    public function redirect(int $status = 302, array $headers = []): RedirectResponse
    {
        return new RedirectResponse($this->value(), $status, $headers);
    }

    /**
     * Get the URI as a Stringable instance.
     *
     * @return \Illuminate\Support\Stringable
     */
    public function toStringable()
    {
        return Str::of($this->value());
    }

    /**
     * Create an HTTP response that represents the URI object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        return new RedirectResponse($this->value());
    }

    /**
     * Get the URI as a string of HTML.
     *
     * @return string
     */
    public function toHtml()
    {
        return $this->value();
    }

    /**
     * Get the decoded string representation of the URI.
     */
    public function decode(): string
    {
        if (empty($this->query()->toArray())) {
            return $this->value();
        }

        return Str::replace($this->query()->value(), $this->query()->decode(), $this->value());
    }

    /**
     * Get the string representation of the URI.
     */
    public function value(): string
    {
        return $this->toString();
    }

    /**
     * Get the string representation of the URI.
     */
    public function toString(): string
    {
        return $this->uri->toString();
    }

    /**
     * Determine if the URI is currently an empty string.
     */
    public function isEmpty(): bool
    {
        return trim($this->value()) === '';
    }

    /**
     * Determine if the URI is not an empty string.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Dump the string representation of the URI.
     *
     * @param  mixed  ...$args
     * @return $this
     */
    public function dump(...$args)
    {
        dump($this->value(), ...$args);

        return $this;
    }

    /**
     * Set the URL generator resolver.
     */
    public static function setUrlGeneratorResolver(Closure $urlGeneratorResolver): void
    {
        static::$urlGeneratorResolver = $urlGeneratorResolver;
    }

    /**
     * Get the underlying URI instance.
     */
    public function getUri(): NativeUri
    {
        return $this->uri;
    }

    /**
     * Convert the object into a value that is JSON serializable.
     *
     * @return string
     */
    public function jsonSerialize(): string
    {
        return $this->value();
    }

    /**
     * Get the string representation of the URI.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
