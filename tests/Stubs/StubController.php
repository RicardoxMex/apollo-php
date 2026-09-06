<?php

namespace Tests\Stubs;

use Apollo\Core\Http\Response;

class StubController
{
    public function handle(): Response
    {
        return Response::json(['from' => 'stub']);
    }
}