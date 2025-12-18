<?php

namespace Apps\ApolloAuth\Services;

use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Models\UserSession;
use Apps\ApolloAuth\Services\JWTManager;
use Apps\ApolloAuth\Exceptions\AuthenticationException;
use Apps\ApolloAuth\Exceptions\InvalidCredentialsException;
use Apps\ApolloAuth\Exceptions\UserNotActiveException;
use Apollo\Core\Http\Request;
use PDO;
use Exception;

class AuthService
{
    private ?User $user = null;
    private ?string $token = null;

    /**
     * Attempt to authenticate user with credentials
     */
    public function attempt(array $credentials, bool $remember = false): array
    {
        $user = $this->validateCredentials($credentials);
        
        if (!$user) {
            throw new InvalidCredentialsException('Invalid credentials provided');
        }

        if (!$user->isActive()) {
            throw new UserNotActiveException('User account is not active');
        }

        // Update last login
        $user->updateLastLogin();

        // Generate token
        $tokenData = $this->generateToken($user, $remember);

        // Store session
        $this->storeSession($user, $tokenData);

        return [
            'user' => $user,
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at']
        ];
    }

    /**
     * Validate user credentials
     */
    private function validateCredentials(array $credentials): ?User
    {
        $identifier = $credentials['email'] ?? $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!$identifier || !$password) {
            return null;
        }

        // Find user by email or username
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (!$user || !$user->verifyPassword($password)) {
            return null;
        }

        return $user;
    }

    /**
     * Generate JWT token for user
     */
    private function generateToken(User $user, bool $remember = false): array
    {
        $tokenId = bin2hex(random_bytes(32));
        $expiresIn = $remember ? (30 * 24 * 3600) : config('auth.jwt.expiry', 3600); // 30 days or default
        $expiresAt = time() + $expiresIn;

        $roles = $user->roles();
        $roleNames = [];
        foreach ($roles as $role) {
            $roleNames[] = $role->name;
        }

        $payload = [
            'sub' => $user->id,
            'jti' => $tokenId,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $roleNames,
            'permissions' => $user->getAllPermissions()
        ];

        $token = JWTManager::generateToken($payload);

        return [
            'token' => $token,
            'token_id' => $tokenId,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn
        ];
    }

    /**
     * Store user session
     */
    private function storeSession(User $user, array $tokenData): UserSession
    {
        try {
            $request = app('request');
            $deviceName = $this->getDeviceName($request);
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
        } catch (Exception $e) {
            // Si no hay request disponible (ej: en tests), usar valores por defecto
            $deviceName = 'Unknown';
            $ipAddress = '127.0.0.1';
            $userAgent = 'Unknown';
        }
        
        return UserSession::create([
            'user_id' => $user->id,
            'token_id' => $tokenData['token_id'],
            'device_name' => $deviceName,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => date('Y-m-d H:i:s', $tokenData['expires_at']),
            'last_used_at' => now()
        ]);
    }

    /**
     * Get device name from request
     */
    private function getDeviceName(Request $request): string
    {
        $userAgent = $request->userAgent();
        
        // Simple device detection
        if (str_contains($userAgent, 'Mobile')) {
            return 'Mobile Device';
        } elseif (str_contains($userAgent, 'Tablet')) {
            return 'Tablet';
        } else {
            return 'Desktop';
        }
    }

    /**
     * Authenticate user from token
     */
    public function authenticateFromToken(string $token): ?User
    {
        try {
            error_log("🔍 Validating token: " . substr($token, 0, 20) . "...");
            
            $payload = JWTManager::validateToken($token);
            
            if (!$payload) {
                error_log("❌ JWT validation failed");
                return null;
            }

            error_log("✅ JWT payload: " . json_encode($payload));

            // Check if session exists and is active
            $currentTime = date('Y-m-d H:i:s');
            
            // Usar consulta SQL directa con prepared statement
            $connection = UserSession::getConnection();
            $stmt = $connection->prepare("SELECT * FROM user_sessions WHERE token_id = :token_id AND is_revoked = 0 AND expires_at > :current_time LIMIT 1");
            $stmt->execute([
                'token_id' => $payload['jti'],
                'current_time' => $currentTime
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                error_log("❌ Session not found or expired for token_id: " . $payload['jti']);
                error_log("🔍 Current time: " . $currentTime);
                
                // Debug: mostrar sesiones existentes
                $debugStmt = $connection->prepare("SELECT * FROM user_sessions WHERE token_id = :token_id");
                $debugStmt->execute(['token_id' => $payload['jti']]);
                $allSessions = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("🔍 All sessions for token_id: " . json_encode($allSessions));
                
                return null;
            }
            
            // Crear instancia del modelo manualmente
            $session = new UserSession();
            $session->attributes = $result;
            $session->exists = true;

            if (!$session) {
                error_log("❌ Session not found or expired for token_id: " . $payload['jti']);
                error_log("🔍 Current time: " . $currentTime);
                
                // Debug: mostrar sesiones existentes
                $allSessions = UserSession::where('token_id', $payload['jti'])->get();
                error_log("🔍 All sessions for token_id: " . json_encode($allSessions->toArray()));
                
                return null;
            }

            error_log("✅ Session found: " . json_encode($session->toArray()));

            // Get user using direct query to avoid model issues
            $userStmt = $connection->prepare("SELECT * FROM users WHERE id = :user_id AND deleted_at IS NULL LIMIT 1");
            $userStmt->execute(['user_id' => $payload['sub']]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userData) {
                error_log("❌ User not found: " . $payload['sub']);
                return null;
            }
            
            // Create user model instance
            $user = new User();
            $user->attributes = $userData;
            $user->original = $userData;
            $user->exists = true;
            
            if (!$user->isActive()) {
                error_log("❌ User inactive: " . $user->email . " (status: " . $user->status . ")");
                return null;
            }

            error_log("✅ User authenticated: " . $user->email);

            // Update session last used
            $session->updateLastUsed();

            $this->user = $user;
            $this->token = $token;

            return $user;

        } catch (Exception $e) {
            error_log("❌ Auth exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get current authenticated user
     */
    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Get current token
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * Check if user is authenticated
     */
    public function check(): bool
    {
        return $this->user !== null;
    }

    /**
     * Check if user is guest
     */
    public function guest(): bool
    {
        return $this->user === null;
    }

    /**
     * Logout user (revoke current session)
     */
    public function logout(): bool
    {
        if (!$this->token) {
            return false;
        }

        try {
            $payload = JWTManager::decodeToken($this->token);
            
            if ($payload && isset($payload['jti'])) {
                UserSession::where('token_id', $payload['jti'])
                    ->update(['is_revoked' => true]);
            }

            $this->user = null;
            $this->token = null;

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Logout from all devices
     */
    public function logoutFromAllDevices(): bool
    {
        if (!$this->user) {
            return false;
        }

        $this->user->revokeAllSessions();
        $this->user = null;
        $this->token = null;

        return true;
    }

    /**
     * Refresh token
     */
    public function refresh(): ?array
    {
        if (!$this->user || !$this->token) {
            return null;
        }

        try {
            // Revoke current session
            $this->logout();

            // Generate new token
            $tokenData = $this->generateToken($this->user);
            $this->storeSession($this->user, $tokenData);

            $this->token = $tokenData['token'];

            return [
                'token' => $tokenData['token'],
                'expires_at' => $tokenData['expires_at']
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Set authenticated user manually
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }
}