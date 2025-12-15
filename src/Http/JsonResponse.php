<?php

namespace ApolloPHP\Http;

class JsonResponse extends Response
{
    public function __construct(
        $data = null,
        int $status = 200,
        array $headers = []
    ) {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        parent::__construct($body, $status, $headers);
    }
}