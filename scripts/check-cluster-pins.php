<?php

declare(strict_types=1);

/**
 * Fail if any cluster package is not installed at the expected index SHA and root alias.
 */

$expected = [
    'phpstan/phpstan' => [
        'alias' => '2.2.99',
        'reference' => '63f848a4b1dbe2f2cb87acb3ff32596661e45417',
    ],
    'psr/container' => [
        'alias' => '2.0.2',
        'reference' => '57d8cac2ff3db652075e2d6e44e0ede70720e8e5',
    ],
    'psr/http-factory' => [
        'alias' => '1.1.0',
        'reference' => 'f9a0862677e7b1b9bd8dc2ed459f48c7e06ff277',
    ],
    'psr/http-message' => [
        'alias' => '2.0.0',
        'reference' => 'c941fca6736161fe07e79fa83d85217b39411cef',
    ],
    'psr/http-server-handler' => [
        'alias' => '1.0.2',
        'reference' => 'd23743bfa4fc9c1bc7d6060de7c4eb2420e8e78a',
    ],
    'psr/http-server-middleware' => [
        'alias' => '1.0.2',
        'reference' => '67f6905493ca6bbb639b14d57883baa511d37ec3',
    ],
    'psr/log' => [
        'alias' => '3.0.2',
        'reference' => 'bde874f9b44efec5901a6783185c050a3855b0f0',
    ],
    'psr/simple-cache' => [
        'alias' => '3.0.0',
        'reference' => 'd03ac6ca8193e8b800f667a63745f1db7806f412',
    ],
    'symfony/polyfill-php84' => [
        'alias' => '1.33.99',
        'reference' => '6fd525a55d5b323edce99f17e44c9442df524c97',
    ],
];

$installedPhp = __DIR__ . '/../vendor/composer/installed.php';
if (!is_readable($installedPhp)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions: array<string, array{reference?: string, aliases?: list<string>}>} $installed */
$installed = require $installedPhp;
$versions = $installed['versions'] ?? [];

$failed = false;
foreach ($expected as $name => $want) {
    if (!isset($versions[$name])) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed.\n", $name));
        $failed = true;
        continue;
    }

    $pkg = $versions[$name];
    $aliases = $pkg['aliases'] ?? [];
    if (!in_array($want['alias'], $aliases, true)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s alias mismatch (expected %s in aliases %s).\n",
            $name,
            $want['alias'],
            json_encode($aliases)
        ));
        $failed = true;
    }

    $reference = $pkg['reference'] ?? null;
    if (!is_string($reference) || $reference === '') {
        fwrite(STDERR, sprintf("check-cluster-pins: %s has no reference.\n", $name));
        $failed = true;
        continue;
    }

    if (!hash_equals($want['reference'], $reference)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s reference mismatch (expected %s, got %s).\n",
            $name,
            $want['reference'],
            $reference
        ));
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "check-cluster-pins: all cluster packages match expected SHAs and aliases.\n");
exit(0);
