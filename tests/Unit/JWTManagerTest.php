<?php

namespace Tests\Unit;

use Apollo\Core\Auth\JWTManager;
use Tests\TestCase;

class JWTManagerTest extends TestCase
{
    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Firmar manualmente un token (mismo secreto que el entorno de test)
     */
    private function signManual(array $payload): string
    {
        $header = $this->base64Url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = $this->base64Url(json_encode($payload));
        $secret = $_ENV['JWT_SECRET_KEY'] ?? '';
        $signature = $this->base64Url(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        return $header . '.' . $body . '.' . $signature;
    }

    public function test_generate_and_validate_token_roundtrip(): void
    {
        $payload = [
            'sub' => 1,
            'username' => 'testuser',
            'roles' => ['user'],
        ];

        $token = JWTManager::generateToken($payload);
        $this->assertNotSame($payload['sub'], $token, 'El token no debe ser el payload plano');

        $decoded = JWTManager::validateToken($token);

        $this->assertIsArray($decoded);
        $this->assertSame($payload, array_intersect_key($decoded, $payload));
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertArrayHasKey('iat', $decoded);
    }

    public function test_validate_rejects_tampered_token(): void
    {
        $token = JWTManager::generateToken(['sub' => 1]);

        // Modificar el payload manteniendo la firma original -> debe fallar
        $parts = explode('.', $token);
        $tampered = $parts[0] . '.' . $this->base64Url(json_encode(['sub' => 2])) . '.' . $parts[2];

        $this->assertFalse(JWTManager::validateToken($tampered));
    }

    public function test_validate_rejects_modified_signature(): void
    {
        $token = JWTManager::generateToken(['sub' => 1]);

        $parts = explode('.', $token);
        $badSignature = $parts[0] . '.' . $parts[1] . '.' . str_repeat('A', strlen($parts[2]));

        $this->assertFalse(JWTManager::validateToken($badSignature));
    }

    public function test_validate_rejects_malformed_token(): void
    {
        $this->assertFalse(JWTManager::validateToken('no-es-un-jwt'));
        $this->assertFalse(JWTManager::validateToken(''));
    }

    public function test_decode_without_validation_returns_payload(): void
    {
        $token = JWTManager::generateToken(['sub' => 7]);

        $decoded = JWTManager::decodeToken($token);

        $this->assertIsArray($decoded);
        $this->assertSame(7, $decoded['sub']);
    }

    public function test_validate_rejects_expired_token(): void
    {
        // generateToken siempre emite exp futuro; firmamos uno expirado manualmente
        $expired = $this->signManual(['sub' => 1, 'exp' => time() - 60]);

        $this->assertFalse(JWTManager::validateToken($expired));

        // Control: el mismo token con exp futuro sí valida
        $valid = $this->signManual(['sub' => 1, 'exp' => time() + 3600]);
        $this->assertIsArray(JWTManager::validateToken($valid));
    }
}