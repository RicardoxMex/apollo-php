<?php

namespace Apps\ApolloAuth\Controllers;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Validation\ValidationException;
use Apollo\Core\Validation\Validator;
use Apps\ApolloAuth\Facades\Auth;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Exceptions\AuthenticationException;
use Apps\ApolloAuth\Services\PasswordResetService;
use Apps\ApolloAuth\Services\VerificationService;
use Exception;

class AuthController
{
    /**
     * Login user
     */
    public function login(Request $request): Response
    {
        try {
            $credentials = $request->json() ?? [];

            Validator::make($credentials, [
                'email'    => 'required|email',
                'password' => 'required|string',
                'remember' => 'nullable|boolean',
            ])->validateOrFail();

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

        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
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
            $data = $request->json() ?? [];

            Validator::make($data, [
                'username'   => 'required|string|min:3|max:50',
                'email'      => 'required|email|max:120',
                'password'   => 'required|string|min:6',
                'first_name' => 'nullable|string|max:60',
                'last_name'  => 'nullable|string|max:60',
                'phone'      => 'nullable|string|max:30',
            ])->validateOrFail();

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

            // Email de verificación (best-effort: el mailer nunca rompe la petición).
            try {
                app(VerificationService::class)->issue($user, $request);
            } catch (\Throwable $e) {
                error_log('Verificación inicial fallida: ' . $e->getMessage());
            }

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

        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during registration'
            ], 500);
        }
    }

    /**
     * Verify email with the token from the link (POST /auth/verify-email).
     */
    public function verifyEmail(Request $request): Response
    {
        try {
            $data = $request->json() ?? [];

            Validator::make($data, [
                'token' => 'required|string',
            ])->validateOrFail();

            $user = app(VerificationService::class)->verify((string) $data['token'], $request);

            return Response::json([
                'success' => true,
                'message' => 'Email verificado correctamente',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'email_verified' => $user->hasVerifiedEmail(),
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        } catch (\RuntimeException $e) {
            return Response::json([
                'error' => $e->getMessage(),
            ], (int) $e->getCode() ?: 400);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred during email verification'
            ], 500);
        }
    }

    /**
     * Resend the verification email (POST /auth/resend-verification, auth).
     */
    public function resendVerification(Request $request): Response
    {
        try {
            $user = $request->user();
            app(VerificationService::class)->resend($user, $request);

            return Response::json([
                'success' => true,
                'message' => 'Email de verificación reenviado. Revisa tu bandeja de entrada.',
            ]);
        } catch (\RuntimeException $e) {
            return Response::json([
                'error' => $e->getMessage(),
            ], (int) $e->getCode() ?: 409);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while resending the verification email'
            ], 500);
        }
    }

    /**
     * Request a password reset email (POST /auth/forgot-password).
     * Always 200: does not reveal whether the email exists (anti enumeration).
     */
    public function forgotPassword(Request $request): Response
    {
        try {
            $data = $request->json() ?? [];

            Validator::make($data, [
                'email' => 'required|email',
            ])->validateOrFail();

            app(PasswordResetService::class)->forgot((string) $data['email'], $request);

            return Response::json([
                'success' => true,
                'message' => 'Si el email existe, recibirás un enlace para restablecer tu contraseña.',
            ]);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while requesting the password reset'
            ], 500);
        }
    }

    /**
     * Apply the password reset (POST /auth/reset-password).
     */
    public function resetPassword(Request $request): Response
    {
        try {
            $data = $request->json() ?? [];

            Validator::make($data, [
                'email'    => 'required|email',
                'token'    => 'required|string',
                'password' => 'required|string|min:6',
            ])->validateOrFail();

            $user = app(PasswordResetService::class)->reset(
                (string) $data['email'],
                (string) $data['token'],
                (string) $data['password'],
                $request,
            );

            return Response::json([
                'success' => true,
                'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        } catch (\RuntimeException $e) {
            return Response::json([
                'error' => $e->getMessage(),
            ], (int) $e->getCode() ?: 400);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while resetting the password'
            ], 500);
        }
    }

    /**
     * Update the current user's profile (PUT /auth/profile).
     * The email is not editable (identity); only profile data changes.
     */
    public function updateProfile(Request $request): Response
    {
        try {
            $data = $request->json() ?? [];

            Validator::make($data, [
                'first_name' => 'nullable|string|max:60',
                'last_name'  => 'nullable|string|max:60',
                'phone'      => 'nullable|string|max:30',
                'avatar'     => 'nullable|string|max:500',
            ])->validateOrFail();

            $user = $request->user();

            $fields = [];
            foreach (['first_name', 'last_name', 'phone', 'avatar'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    $fields[$field] = $value === null || $value === '' ? null : (string) $value;
                }
            }

            if ($fields !== []) {
                $user->update($fields);
            }

            return Response::json([
                'success' => true,
                'message' => 'Perfil actualizado',
                'data' => [
                    'user' => $this->userPayload($user),
                ],
            ]);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => 'Validation Error',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while updating the profile'
            ], 500);
        }
    }

    /**
     * Current user profile (reused by profile() and updateProfile()).
     */
    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
            'email_verified' => $user->hasVerifiedEmail(),
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
        ];
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
                'user' => $this->userPayload($user),
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