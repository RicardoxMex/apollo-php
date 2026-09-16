<?php
namespace Apps\Users\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Container\Container;
use Apollo\Core\Validation\ValidationException;
use Apps\Users\Services\UserService;

class UserController extends Controller
{
    /**
     * Campos seguros expuestos por la API (nunca password ni columnas internas).
     */
    private const SAFE_FIELDS = [
        'id',
        'username',
        'email',
        'first_name',
        'last_name',
        'status',
        'email_verified_at',
        'created_at',
        'updated_at',
    ];

    private UserService $userService;

    public function __construct(Container $container, UserService $userService)
    {
        parent::__construct($container);
        $this->userService = $userService;
    }

    /**
     * Proyecta una fila de users a los campos seguros permitidos.
     */
    private function safeUser(array $user): array
    {
        $safe = [];

        foreach (self::SAFE_FIELDS as $field) {
            if (array_key_exists($field, $user)) {
                $safe[$field] = $user[$field];
            }
        }

        return $safe;
    }

    public function index()
    {
        try {
            $search = $this->request->query('search');
            
            // Si hay parámetro de búsqueda, usar search en lugar de paginación
            if (!empty($search)) {
                $users = $this->userService->searchUsers($search);
                $users = array_map(fn(array $user) => $this->safeUser($user), $users);

                return $this->json([
                    'success' => true,
                    'data' => $users,
                    'search_term' => $search,
                    'total' => count($users)
                ]);
            }
            

            $users = $this->userService->paginate();
            $users['data'] = array_map(fn(array $user) => $this->safeUser($user), $users['data'] ?? []);

            return $this->json([
                'success' => true,
                ...$users  // Spread operator para incluir data y meta directamente
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
                'data' => $this->safeUser($user)
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
            $data = $this->validate($this->request->json() ?? [], [
                'name'     => 'required|string|max:100',
                'email'    => 'required|email|max:120',
                'password' => 'nullable|string|min:6',
                'role'     => 'nullable|string|max:50',
                'status'   => 'nullable|in:active,inactive,suspended',
            ]);

            $user = $this->userService->createUser($data);

            return $this->json([
                'success' => true,
                'data' => $user,
                'message' => 'User created successfully'
            ], 201);
        } catch (ValidationException $e) {
            return $this->json([
                'error' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
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
            $data = $this->validate($this->request->json() ?? [], [
                'name'     => 'sometimes|string|max:100',
                'email'    => 'sometimes|email|max:120',
                'password' => 'sometimes|nullable|string|min:6',
                'role'     => 'sometimes|string|max:50',
                'status'   => 'sometimes|in:active,inactive,suspended',
            ]);

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
        } catch (ValidationException $e) {
            return $this->json([
                'error' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
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