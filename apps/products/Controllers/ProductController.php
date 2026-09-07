<?php
// ProductController.php

namespace Apps\Products\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Validation\ValidationException;
use Apollo\Core\Validation\Validator;

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
        $data = $request->all();

        try {
            Validator::make($data, [
                'name'  => 'required|string|max:120',
                'price' => 'required|numeric|min:0',
            ])->validateOrFail();
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        }

        return Response::json([
            'message' => 'Store method',
            'data' => $data
        ], 201);
    }

    public function update(Request $request, int $id): Response
    {
        $data = $request->all();

        try {
            Validator::make($data, [
                'name'  => 'sometimes|string|max:120',
                'price' => 'sometimes|numeric|min:0',
            ])->validateOrFail();
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        }

        return Response::json([
            'message' => 'Update method',
            'id' => $id,
            'data' => $data
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
