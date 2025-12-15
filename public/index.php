<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new ApolloPHP\Core\Application(dirname(__DIR__));

// Add a simple test route
$app->get('/', function($request) {
    return new ApolloPHP\Http\Response('Hello, ApolloPHP Framework!');
});

$app->run();