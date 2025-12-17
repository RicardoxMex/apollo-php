<?php
namespace Apps\Users\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Container\Container;
use Apps\Users\Services\UserService;

class UserController extends Controller
{

    private UserService $userService;

    public function __construct(Container $container, UserService $userService)
    {
        parent::__construct($container);
        $this->userService = $userService;
    }

    public function index()
    {
        try {
            $page = $this->request->query('page', 1);
            $perPage = $this->request->query('perPage', 10);

            $users = $this->userService->paginate((int) $perPage, (int) $page);

            return $this->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to retrieve users',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = $this->userService->getUserById((int) $id);

            if (!$user) {
                return $this->json([
                    'error' => 'User not found'
                ], 404);
            }

            return $this->json([
                'success' => true,
                'data' => $user
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to retrieve user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function store()
    {
        try {
            $data = $this->request ? json_decode($this->request->getContent(), true) : [];

            if (empty($data)) {
                return $this->json([
                    'error' => 'No data provided'
                ], 400);
            }

            $user = $this->userService->createUser($data);

            return $this->json([
                'success' => true,
                'data' => $user,
                'message' => 'User created successfully'
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => 'Validation error',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to create user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update($id)
    {
        try {
            $data = $this->request ? json_decode($this->request->getContent(), true) : [];

            if (empty($data)) {
                return $this->json([
                    'error' => 'No data provided'
                ], 400);
            }

            $user = $this->userService->updateUser((int) $id, $data);

            if (!$user) {
                return $this->json([
                    'error' => 'User not found'
                ], 404);
            }

            return $this->json([
                'success' => true,
                'data' => $user,
                'message' => 'User updated successfully'
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => 'Validation error',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to update user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $deleted = $this->userService->deleteUser((int) $id);

            if (!$deleted) {
                return $this->json([
                    'error' => 'User not found'
                ], 404);
            }

            return $this->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Failed to delete user',
                'message' => $e->getMessage()
            ], 500);
        }
    }

}