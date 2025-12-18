<?php

namespace Apps\ApolloAuth\Controllers;

use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Models\Role;
use Exception;

class AdminController
{
    /**
     * List all users
     */
    public function users(Request $request): Response
    {
        try {
            $page = (int) $request->query('page', 1);
            $limit = (int) $request->query('limit', 20);
            $search = $request->query('search');

            $query = User::with('roles');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            $users = $query->skip(($page - 1) * $limit)
                          ->take($limit)
                          ->orderBy('created_at', 'desc')
                          ->get();

            return Response::json([
                'success' => true,
                'data' => [
                    'users' => $users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'username' => $user->username,
                            'email' => $user->email,
                            'full_name' => $user->full_name,
                            'status' => $user->status,
                            'roles' => $user->roles->pluck('name'),
                            'last_login_at' => $user->last_login_at,
                            'created_at' => $user->created_at
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => $total,
                        'last_page' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while fetching users'
            ], 500);
        }
    }

    /**
     * Get user details
     */
    public function showUser(Request $request): Response
    {
        try {
            $userId = $request->attributes['id'] ?? null;
            
            if (!$userId) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'User ID is required'
                ], 400);
            }

            $user = User::with('roles', 'sessions')->find($userId);

            if (!$user) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => 'User not found'
                ], 404);
            }

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
                        'metadata' => $user->metadata,
                        'roles' => $user->roles->map(function ($role) {
                            return [
                                'id' => $role->id,
                                'name' => $role->name,
                                'display_name' => $role->display_name,
                                'assigned_at' => $role->pivot->assigned_at
                            ];
                        }),
                        'permissions' => $user->getAllPermissions(),
                        'active_sessions' => $user->activeSessions()->count(),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while fetching user'
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request): Response
    {
        try {
            $userId = $request->attributes['id'] ?? null;
            $data = $request->json();

            if (!$userId) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'User ID is required'
                ], 400);
            }

            $user = User::find($userId);

            if (!$user) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => 'User not found'
                ], 404);
            }

            // Campos actualizables
            $updateData = [];
            $allowedFields = ['username', 'email', 'first_name', 'last_name', 'phone', 'status'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'No valid fields to update'
                ], 400);
            }

            $user->update($updateData);

            return Response::json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'full_name' => $user->full_name,
                        'status' => $user->status
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while updating user'
            ], 500);
        }
    }

    /**
     * Assign role to user
     */
    public function assignRole(Request $request): Response
    {
        try {
            $userId = $request->attributes['id'] ?? null;
            $data = $request->json();

            if (!$userId || !isset($data['role'])) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'User ID and role are required'
                ], 400);
            }

            $user = User::find($userId);
            if (!$user) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => 'User not found'
                ], 404);
            }

            $currentUser = $request->user();
            $success = $user->assignRole($data['role'], $currentUser->id);

            if (!$success) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'Role not found or already assigned'
                ], 400);
            }

            return Response::json([
                'success' => true,
                'message' => 'Role assigned successfully'
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while assigning role'
            ], 500);
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(Request $request): Response
    {
        try {
            $userId = $request->attributes['id'] ?? null;
            $roleName = $request->attributes['role'] ?? null;

            if (!$userId || !$roleName) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'User ID and role are required'
                ], 400);
            }

            $user = User::find($userId);
            if (!$user) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => 'User not found'
                ], 404);
            }

            $success = $user->removeRole($roleName);

            if (!$success) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => 'Role not found'
                ], 400);
            }

            return Response::json([
                'success' => true,
                'message' => 'Role removed successfully'
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while removing role'
            ], 500);
        }
    }

    /**
     * List all roles
     */
    public function roles(Request $request): Response
    {
        try {
            $roles = Role::orderBy('name')->get();

            return Response::json([
                'success' => true,
                'data' => [
                    'roles' => $roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'display_name' => $role->display_name,
                            'description' => $role->description,
                            'permissions' => $role->permissions,
                            'is_system' => $role->is_system,
                            'users_count' => $role->users()->count()
                        ];
                    })
                ]
            ]);

        } catch (Exception $e) {
            return Response::json([
                'error' => 'Server Error',
                'message' => 'An error occurred while fetching roles'
            ], 500);
        }
    }
}