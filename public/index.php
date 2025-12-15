<?php

require __DIR__ . '/../vendor/autoload.php';

use ApolloPHP\Http\Response;
use ApolloPHP\Exceptions\HttpException;

$app = new ApolloPHP\Core\Application(dirname(__DIR__));

 $app->module('ApolloAuth');

$app->run();