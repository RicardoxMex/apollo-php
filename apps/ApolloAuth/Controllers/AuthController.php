<?php

namespace Apps\ApolloAuth\Controllers;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apps\ApolloAuth\Facades\Auth;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Exceptions\AuthenticationException;
use Exception;

class AuthController
{
    /**
     * Login user
     */
    public function login(Request $request): Response
    {
        try {
            $credentials = $request->json();
            
            if (!$credentials || !isset($credentials['email'], $credentials['password'])) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'Email and password are required'
                ], 400);
            }

            $remember = $credentials['remember'] ?? false;
            
            $result = Auth::attempt($credentials, $remember);

            return Response::json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $result['user']->id,
                        'username' => $result['user']->username,
                        'email' => $result['user']->email,
                        'full_name' => $result['user']->full_name,
                        'roles' => array_map(function($role) { return $role->name; }, $result['user']->roles()),
                        'permissions' => $result['user']->getAllPermissions()
                    ],
                    'token' => $result['token'],
                    'expires_at' => $result['expires_at']
                ]
            ]);

        } catch (AuthenticationException $e) {
            return Response::json([
                'error' => 'Authentication Failed',
                'message' => $e->getMessage()
            ], 401);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during login'
            ], 500);
        }
    }

    /**
     * Register new user
     */
    public function register(Request $request): Response
    {
        try {
            $data = $request->json();
            
            // Validación básica
            $required = ['username', 'email', 'password'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return Response::json([
                        'error' => 'Validation Error',
                        'message' => "Field {$field} is required"
                    ], 400);
                }
            }

            // Verificar si el usuario ya existe
            $existingUser = User::where('email', $data['email'])
                ->orWhere('username', $data['username'])
                ->first();

            if ($existingUser) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'User with this email or username already exists'
                ], 400);
            }

            // Crear usuario
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'phone' => $data['phone'] ?? null
            ]);

            // Asignar rol por defecto
            $user->assignRole('user');

            return Response::json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'full_name' => $user->full_name
                    ]
                ]
            ], 201);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during registration'
            ], 500);
        }
    }

    /**
     * Get current user profile
     */
    public function profile(Request $request): Response
    {
        $user = $request->user();

        return Response::json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'status' => $user->status,
                    'email_verified_at' => $user->email_verified_at,
                    'last_login_at' => $user->last_login_at,
                    'avatar' => $user->avatar,
                    'roles' => array_map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'display_name' => $role->display_name
                        ];
                    }, $user->roles()),
                    'permissions' => $user->getAllPermissions(),
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ]
            ]
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): Response
    {
        try {
            Auth::logout();

            return Response::json([
                'success' => true,
                'message' => 'Logout successful'
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during logout'
            ], 500);
        }
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Request $request): Response
    {
        try {
            Auth::logoutFromAllDevices();

            return Response::json([
                'success' => true,
                'message' => 'Logged out from all devices'
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during logout'
            ], 500);
        }
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): Response
    {
        try {
            $result = Auth::refresh();

            if (!$result) {
                return Response::json([
                    'error' => 'Authentication Error',
                    'message' => 'Unable to refresh token'
                ], 401);
            }

            return Response::json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => $result
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during token refresh'
            ], 500);
        }
    }

    /**
     * Get user sessions
     */
    public function sessions(Request $request): Response
    {
        $user = $request->user();
        $sessions = $user->sessions()
            ->orderBy('last_used_at', 'desc')
            ->get();

        return Response::json([
            'success' => true,
            'data' => [
                'sessions' => $sessions->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'device_name' => $session->device_name,
                        'ip_address' => $session->ip_address,
                        'user_agent' => $session->user_agent,
                        'last_used_at' => $session->last_used_at,
                        'expires_at' => $session->expires_at,
                        'is_current' => $session->token_id === Auth::token(),
                        'is_active' => $session->isActive()
                    ];
                })
            ]
        ]);
    }
}