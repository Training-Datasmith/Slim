# Architecture: Slim Framework 4

## Purpose

Slim is a micro PHP framework for building APIs and web applications. It provides PSR-7 HTTP message handling, PSR-15 middleware dispatch, and a fast route collector/dispatcher, with zero opinion on the container or template engine.

## Directory Structure

```
Slim/
  App.php                     Top-level application; extends RouteCollectorProxy
  CallableResolver.php        Resolves string "Class:method" to callable
  MiddlewareDispatcher.php    PSR-15 middleware stack (tip-of-stack pattern)
  ResponseEmitter.php         Sends PSR-7 response to PHP output
  Logger.php                  Minimal PSR-3 logger for internal use
  Error/
    AbstractErrorRenderer.php
    Renderers/                HTML, JSON, plain-text, XML error renderers
  Exception/
    HttpException.php         Base HTTP exception with status code + message
    Http*.php                 Specific 4xx/5xx exception subclasses
  Factory/
    AppFactory.php            Static factory: detects PSR-17 implementation, builds App
    Psr17/                    Adapters for Guzzle, Nyholm, Laminas, slim-psr7, etc.
    ServerRequestCreatorFactory.php  Detects available server request creator
  Handlers/
    ErrorHandler.php          Catches exceptions, selects renderer, returns error response
    Strategies/               Invocation strategies (RequestResponse, RequestResponseArgs, etc.)
  Interfaces/                 PSR-15-compatible contracts for all extension points
  Middleware/
    BodyParsingMiddleware.php   Decodes JSON/XML/form request bodies into parsed body
    ContentLengthMiddleware.php Adds Content-Length header
    ErrorMiddleware.php         Wraps inner stack; catches exceptions -> ErrorHandler
    MethodOverrideMiddleware.php _METHOD override for HTML forms
    OutputBufferingMiddleware.php Captures output into response body
    RoutingMiddleware.php        Runs route resolution, attaches RouteContext to request
  Routing/
    FastRouteDispatcher.php     Delegates to nikic/fast-route
    Route.php                   Single route with middleware stack
    RouteCollector.php          Stores route definitions + groups
    RouteCollectorProxy.php     Fluent API for get/post/put/delete/group/map/any
    RouteContext.php            Attaches routing results to PSR-7 request attributes
    RouteGroup.php              Scoped middleware + prefix group
    RouteParser.php             URL generation from route names + parameters
    RouteResolver.php           Resolves request to matching Route
    RoutingResults.php          DTO: dispatch status + route/method arrays
```

## Key Design Decisions

- **PSR-7 / PSR-15 / PSR-11**: Slim is container-agnostic and HTTP-implementation-agnostic. `AppFactory` auto-detects any installed PSR-17 library.
- **Middleware stack**: Middleware wraps inward (outermost added last runs first). `App::handle()` starts the stack at the outermost tip.
- **Route-level middleware**: Routes and groups can have their own middleware stacks that run inside the routing middleware.
- **Error pipeline**: `ErrorMiddleware` catches all throwables and delegates to registered handlers by exception type, falling back to the default `ErrorHandler`.

## Extension Points

- Implement `MiddlewareInterface` and add via `$app->add()` or per-route/group.
- Register custom error handlers with `ErrorMiddleware::setErrorHandler(ExceptionClass::class, handler)`.
- Implement `CallableResolverInterface` to change how string handlers are resolved.
- Pass a PSR-11 container to `AppFactory::createFromContainer()` for dependency injection.

## Dependency Flow

```
$_SERVER -> ServerRequestCreatorFactory -> ServerRequestInterface
  -> App::handle(request)
    -> MiddlewareDispatcher (outermost first)
      -> ErrorMiddleware
        -> RoutingMiddleware -> FastRouteDispatcher
          -> RouteRunner -> Route middleware stack -> Handler callable
            -> ResponseInterface
        <- error caught -> ErrorHandler -> error response
    <- ResponseInterface
  -> ResponseEmitter::emit(response)
```
