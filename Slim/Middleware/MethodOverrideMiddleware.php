<?php

/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim\Middleware;

use function in_array;
use function is_array;
use function is_string;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

use Psr\Http\Server\RequestHandlerInterface;

use function strtoupper;

/** @api */
class MethodOverrideMiddleware implements MiddlewareInterface
{
    private const ALLOWED_OVERRIDE_METHODS = ['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            $method = null;

            $methodHeader = $request->getHeaderLine('X-Http-Method-Override');
            if ($methodHeader) {
                $method = strtoupper($methodHeader);
            } else {
                $body = $request->getParsedBody();
                if (is_array($body) && !empty($body['_METHOD']) && is_string($body['_METHOD'])) {
                    $method = strtoupper($body['_METHOD']);
                }
            }

            if ($method !== null && in_array($method, self::ALLOWED_OVERRIDE_METHODS, true)) {
                $request = $request->withMethod($method);
            }

            $body = $request->getBody();
            if ($body->eof() && $body->isSeekable()) {
                $body->rewind();
            }
        }

        return $handler->handle($request);
    }
}
