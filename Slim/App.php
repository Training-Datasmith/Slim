<?php

/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\MiddlewareDispatcherInterface;
use Slim\Interfaces\RouteCollectorInterface;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Middleware\BodyParsingMiddleware;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\RoutingMiddleware;
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteResolver;
use Slim\Routing\RouteRunner;

use function strtoupper;

/**
 * @api
 * @template TContainerInterface of (ContainerInterface|null)
 * @template-extends RouteCollectorProxy<TContainerInterface>
 */
class App extends RouteCollectorProxy implements RequestHandlerInterface
{
    /**
     * Current version
     *
     * @var string
     */
    public const VERSION = '4.15.1';

    protected RouteResolverInterface $routeResolver;

    protected MiddlewareDispatcherInterface $middlewareDispatcher;

    /**
     * @param TContainerInterface $container
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        ?ContainerInterface $container = null,
        ?CallableResolverInterface $callableResolver = null,
        ?RouteCollectorInterface $routeCollector = null,
        ?RouteResolverInterface $routeResolver = null,
        ?MiddlewareDispatcherInterface $middlewareDispatcher = null
    ) {
        parent::__construct(
            $responseFactory,
            $callableResolver ?? new CallableResolver($container),
            $container,
            $routeCollector
        );

        $this->routeResolver = $routeResolver ?? new RouteResolver($this->routeCollector);
        $routeRunner = new RouteRunner($this->routeResolver, $this->routeCollector->getRouteParser(), $this);

        if (!$middlewareDispatcher) {
            $middlewareDispatcher = new MiddlewareDispatcher($routeRunner, $this->callableResolver, $container);
        } else {
            $middlewareDispatcher->seedMiddlewareStack($routeRunner);
        }

        $this->middlewareDispatcher = $middlewareDispatcher;
    }

    /**
     * Returns the route resolver responsible for matching requests to routes.
     *
     * @return RouteResolverInterface The configured route resolver
     */
    public function getRouteResolver(): RouteResolverInterface
    {
        return $this->routeResolver;
    }

    /**
     * Returns the middleware dispatcher that manages the application middleware stack.
     *
     * @return MiddlewareDispatcherInterface The configured middleware dispatcher
     */
    public function getMiddlewareDispatcher(): MiddlewareDispatcherInterface
    {
        return $this->middlewareDispatcher;
    }

    /**
     * Adds middleware to the application's outer middleware stack.
     *
     * Middleware is executed in LIFO order: the last middleware added runs first.
     * Pass a PSR-15 MiddlewareInterface, a callable, or a string "Class:method" reference.
     *
     * @param MiddlewareInterface|string|callable $middleware Middleware to add
     *
     * @return App<TContainerInterface> Fluent interface for chaining
     */
    public function add($middleware): self
    {
        $this->middlewareDispatcher->add($middleware);
        return $this;
    }

    /**
     * Adds a PSR-15 middleware instance to the application's outer middleware stack.
     *
     * Prefer this over add() when you already have a MiddlewareInterface instance,
     * as it provides stronger type safety.
     *
     * @param MiddlewareInterface $middleware Typed middleware instance to add
     *
     * @return App<TContainerInterface> Fluent interface for chaining
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewareDispatcher->addMiddleware($middleware);
        return $this;
    }

    /**
     * Adds the Slim built-in routing middleware to the application middleware stack.
     *
     * Adds a RoutingMiddleware that runs route resolution and attaches the matching
     * route and its arguments to the request as attributes. This method can be used
     * to control middleware order; calling it is not required when using addErrorMiddleware()
     * as that implicitly registers routing.
     *
     * @return RoutingMiddleware The registered routing middleware instance
     */
    public function addRoutingMiddleware(): RoutingMiddleware
    {
        $routingMiddleware = new RoutingMiddleware(
            $this->getRouteResolver(),
            $this->getRouteCollector()->getRouteParser()
        );
        $this->add($routingMiddleware);
        return $routingMiddleware;
    }

    /**
     * Adds the Slim built-in error middleware to the application middleware stack.
     *
     * The error middleware catches all throwables from the inner middleware stack and
     * delegates to registered error handlers. It should typically be the outermost
     * middleware so it can catch errors from all inner middleware.
     *
     * @param bool                 $displayErrorDetails Whether to include exception details in error responses (disable in production)
     * @param bool                 $logErrors           Whether to log errors via the provided logger
     * @param bool                 $logErrorDetails     Whether to include exception details in log entries
     * @param LoggerInterface|null $logger              PSR-3 logger; defaults to Slim's built-in logger when null
     *
     * @return ErrorMiddleware The registered error middleware instance (allows registering custom handlers)
     */
    public function addErrorMiddleware(
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
        ?LoggerInterface $logger = null
    ): ErrorMiddleware {
        $errorMiddleware = new ErrorMiddleware(
            $this->getCallableResolver(),
            $this->getResponseFactory(),
            $displayErrorDetails,
            $logErrors,
            $logErrorDetails,
            $logger
        );
        $this->add($errorMiddleware);
        return $errorMiddleware;
    }

    /**
     * Adds the Slim body parsing middleware to the application middleware stack.
     *
     * Automatically decodes JSON, XML, and URL-encoded request bodies into
     * parsed body arrays accessible via $request->getParsedBody(). Additional
     * custom parsers can be provided for other content types.
     *
     * @param callable[] $bodyParsers Map of content-type string => callable($body): mixed
     *
     * @return BodyParsingMiddleware The registered body parsing middleware instance
     */
    public function addBodyParsingMiddleware(array $bodyParsers = []): BodyParsingMiddleware
    {
        $bodyParsingMiddleware = new BodyParsingMiddleware($bodyParsers);
        $this->add($bodyParsingMiddleware);
        return $bodyParsingMiddleware;
    }

    /**
     * Runs the application and emits the response to the HTTP client.
     *
     * Creates a server request from PHP globals if none is provided, passes it
     * through the full middleware stack, then emits the resulting response with
     * headers and body via PHP's output functions.
     *
     * @param ServerRequestInterface|null $request Pre-built server request, or null to create from $_SERVER/$_POST/etc.
     *
     * @return void
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        if (!$request) {
            $serverRequestCreator = ServerRequestCreatorFactory::create();
            $request = $serverRequestCreator->createServerRequestFromGlobals();
        }

        $response = $this->handle($request);
        $responseEmitter = new ResponseEmitter();
        $responseEmitter->emit($response);
    }

    /**
     * Handle a request
     *
     * This method traverses the application middleware stack and then returns the
     * resultant Response object.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->middlewareDispatcher->handle($request);

        /**
         * This is to be in compliance with RFC 2616, Section 9.
         * If the incoming request method is HEAD, we need to ensure that the response body
         * is empty as the request may fall back on a GET route handler due to FastRoute's
         * routing logic which could potentially append content to the response body
         * https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.4
         */
        $method = strtoupper($request->getMethod());
        if ($method === 'HEAD') {
            $emptyBody = $this->responseFactory->createResponse()->getBody();
            return $response->withBody($emptyBody);
        }

        return $response;
    }
}
