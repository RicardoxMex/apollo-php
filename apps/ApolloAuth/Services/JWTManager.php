<?php

namespace Apps\ApolloAuth\Services;

use Exception;

class JWTManager
{
    private static $secret;
    private static $algorithm;
    private static $issuer;
    private static $audience;
    private static $expiry;

    /**
     * Initialize JWT configuration
     */
    private static function initConfig()
    {
        if (self::$secret === null) {
            self::$secret = config('auth.jwt.secret_key');
            self::$algorithm = config('auth.jwt.algorithm', 'HS256');
            self::$issuer = config('auth.jwt.issuer', 'apollo-api.local');
            self::$audience = config('auth.jwt.audience', 'apollo-client');
            self::$expiry = config('auth.jwt.expiry', 3600);
        }
    }

    /**
     * Generate JWT token
     */
    public static function generateToken($payload)
    {
        self::initConfig();

        if (empty(self::$secret)) {
            throw new Exception('JWT secret key not configured');
        }

        $header = [
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ];

        $payload = array_merge($payload, [
            'iss' => self::$issuer,
            'aud' => self::$audience,
            'iat' => time(),
            'exp' => time() + self::$expiry
        ]);

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Validate JWT token
     */
    public static function validateToken($token)
    {
        self::initConfig();

        if (empty(self::$secret)) {
            throw new Exception('JWT secret key not configured');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret, true);
        $expectedSignature = self::base64UrlEncode($signature);

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return false;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        return $payload;
    }

    /**
     * Decode JWT token without validation (for debugging)
     */
    public static function decodeToken($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        return $payload;
    }

    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data)
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Get JWT configuration
     */
    public static function getConfig()
    {
        self::initConfig();
        return [
            'algorithm' => self::$algorithm,
            'issuer' => self::$issuer,
            'audience' => self::$audience,
            'expiry' => self::$expiry,
            'secret_configured' => !empty(self::$secret)
        ];
    }
}