<?php

namespace ApolloAuth\Controllers;

use ApolloPHP\Http\Request;
use ApolloPHP\Http\JsonResponse;

class AuthController
{
    public function __construct()
    {
        // Constructor simplificado para debug
    }

    public function login(Request $request): JsonResponse
    {
        dd($request->all());
        return new JsonResponse([
            'message' => 'login',
            'debug' => 'AuthController login method reached successfully'
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'User registered successfully',
        ], 201);
    }

    public function logout(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Successfully logged out',
        ]);
    }

    public function me(): JsonResponse
    {
        return new JsonResponse([
            'data' => ''
        ]);
    }

    public function refresh(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Password changed successfully',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Password changed successfully',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Password changed successfully',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Password reset email sent',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Password reset successfully',
        ]);
    }
}