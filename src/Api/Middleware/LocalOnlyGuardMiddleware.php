<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Guard for the keyless, localhost-only API.
 *
 * With no API key, anything that can reach 127.0.0.1 can drive the agent.
 * Two browser paths reach it without the user's intent:
 *
 * - DNS rebinding: a page on evil.example re-points its name at 127.0.0.1,
 *   so its requests arrive with `Host: evil.example`. Only loopback Host
 *   values (127.0.0.1, localhost, [::1]) are accepted.
 * - Cross-site "simple" requests: a page can POST multipart/form-data to
 *   127.0.0.1 without a CORS preflight. Browsers always send `Origin` on
 *   such requests, so any Origin the operator did not list is refused.
 *
 * Non-browser clients (curl, the CLI, SDKs) send no Origin and pass.
 */
final class LocalOnlyGuardMiddleware
{
    private const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    /**
     * @param list<string> $allowedOrigins Origins the operator allowed via --cors-origin; '*' allows any.
     */
    public function __construct(
        private readonly array $allowedOrigins = [],
    ) {}

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        if (!in_array(self::hostWithoutPort($request->getHeaderLine('Host')), self::LOOPBACK_HOSTS, true)) {
            return Router::errorResponse(
                ApiErrorCode::FORBIDDEN,
                'Host not allowed. Without an API key the API only answers requests addressed to 127.0.0.1, localhost or [::1].',
            );
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== ''
            && !in_array('*', $this->allowedOrigins, true)
            && !in_array($origin, $this->allowedOrigins, true)
        ) {
            return Router::errorResponse(
                ApiErrorCode::FORBIDDEN,
                'Cross-origin request refused. Set an API key, or allow this origin with --cors-origin.',
            );
        }

        return $next($request);
    }

    private static function hostWithoutPort(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }

        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }
}
