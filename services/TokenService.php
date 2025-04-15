<?php

namespace Api\Services;

use Api\Models\Auth;

class TokenService {
    private const int EXPIRATION_DAYS = 432000000; // 5 days in milliseconds

    public function getCurrentMilliseconds(): int {
        return (int) (microtime(true) * 1000);
    }

    public function generateTokenExpirationTime(): int {
        return ($this->getCurrentMilliseconds() + self::EXPIRATION_DAYS);
    }

    public function getExpiration(?array $decodedToken): int {
        if (!$decodedToken || !isset($decodedToken['expiration'])) {
            return true;
        }
        return $decodedToken['expiration'];
    }

    public function createToken(Auth $auth, int $expiration): string {
        $payload = [
            'id' => $auth->id,
            'username' => $auth->username,
            'role' => $auth->role,
            'expiration' => $expiration
        ];

        return base64_encode(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    public function decodeToken(string $token): ?array {
        $decoded = base64_decode($token, true);
        if ($decoded === false) return null; // Invalid Base64

        try {
            return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null; // Invalid JSON
        }
    }

    public function isExpired(?array $decodedToken): bool {
        if (!$decodedToken || !isset($decodedToken['expiration'])) {
            return true;
        }
        return $decodedToken['expiration'] < $this->getCurrentMilliseconds();
    }
}