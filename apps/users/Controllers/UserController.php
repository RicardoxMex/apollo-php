<?php
namespace Apps\Users\Controllers;

use Apollo\Core\Http\Controller;

class UserController extends Controller {
    
    public function index() {
        $users = [
            ['id' => 1, 'name' => 'API User 1', 'email' => 'api1@example.com'],
            ['id' => 2, 'name' => 'API User 2', 'email' => 'api2@example.com'],
        ];
        
        return $this->json([
            'success' => true,
            'data' => $users
        ]);
    }
    
    public function show($id) {
        $users = [
            1 => ['id' => 1, 'name' => 'API User 1', 'email' => 'api1@example.com'],
            2 => ['id' => 2, 'name' => 'API User 2', 'email' => 'api2@example.com'],
        ];
        
        if (!isset($users[$id])) {
            return $this->json([
                'error' => 'User not found'
            ], 404);
        }
        
        return $this->json([
            'success' => true,
            'data' => $users[$id]
        ]);
    }

}