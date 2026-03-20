<?php

declare(strict_types=1);

/**
 * Slim Framework 4 — basic REST API example.
 *
 * Demonstrates: route definition, JSON response, error handling middleware.
 *
 * Requirements:
 *   composer require slim/slim slim/psr7
 *
 * Run:
 *   php -S localhost:8080 examples/basic_api.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Global error middleware — set third arg to true for detailed errors in development
$app->addErrorMiddleware(true, true, true);

// Parse JSON / form request bodies automatically
$app->addBodyParsingMiddleware();

// --- Routes ---

$app->get('/hello/{name}', function (Request $request, Response $response, array $args): Response {
    $payload = json_encode(['message' => 'Hello, ' . $args['name'] . '!']);
    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/users', function (Request $request, Response $response): Response {
    $users = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];
    $response->getBody()->write(json_encode($users));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/users', function (Request $request, Response $response): Response {
    $data = $request->getParsedBody();
    $name = $data['name'] ?? 'Anonymous';

    $created = ['id' => 3, 'name' => $name];
    $response->getBody()->write(json_encode($created));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(201);
});

$app->run();
