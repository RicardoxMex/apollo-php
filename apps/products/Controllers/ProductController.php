<?php
// ProductController.php

namespace Apps\Products\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json([
            'message' => 'Index method',
            'data' => []
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return Response::json([
            'message' => 'Show method',
            'id' => $id
        ]);
    }

    public function store(Request $request): Response
    {
        return Response::json([
            'message' => 'Store method',
            'data' => $request->all()
        ], 201);
    }

    public function update(Request $request, int $id): Response
    {
        return Response::json([
            'message' => 'Update method',
            'id' => $id,
            'data' => $request->all()
        ]);
    }

    public function destroy(Request $request, int $id): Response
    {
        return Response::json([
            'message' => 'Destroy method',
            'id' => $id
        ]);
    }
}
