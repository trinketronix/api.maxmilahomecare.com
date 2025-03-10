<?php

declare(strict_types=1);

namespace Api\Controllers;

use Exception;
use JsonException;
use Api\Models\Auth;
use Api\Services\TokenService;
use Phalcon\Mvc\Controller;
use Phalcon\Http\Response;

class BaseController extends Controller
{
    /**
     * Get the parsed request body from the DI container
     */
    protected function getRequestBody(): array
    {
        return $this->getDI()->get('request_body') ?? [];
    }

    /**
     * Get the authenticated user data from the DI container
     */
    protected function getAuthUser(): ?array
    {
        return $this->getDI()->get('auth_user') ?? null;
    }

    /**
     * Get the current user ID from the authenticated token
     */
    protected function getCurrentUserId(): int
    {
        $authUser = $this->getAuthUser();
        return $authUser['id'] ?? -1;
    }

    /**
     * Get the current user role from the authenticated token
     */
    protected function getCurrentUserRole(): int
    {
        $authUser = $this->getAuthUser();
        return $authUser['role'] ?? -1;
    }

    /**
     * Check if the current user has admin role
     */
    protected function isAdmin(): bool
    {
        return $this->getCurrentUserRole() === 0; // Admin role = 0
    }

    /**
     * Check if the current user has manager role or higher
     */
    protected function isManagerOrHigher(): bool
    {
        return $this->getCurrentUserRole() <= 1; // Manager role = 1 or Admin role = 0
    }

    /**
     * Create a standardized success response
     */
    protected function respondWithSuccess(array|string $data, int $statusCode = 200): array
    {
        return [
            'status' => 'success',
            'code' => $statusCode,
            'data' => $data
        ];
    }

    /**
     * Create a standardized error response
     */
    protected function respondWithError(array|string $message, int $statusCode = 400): array
    {
        return [
            'status' => 'error',
            'code' => $statusCode,
            'message' => $message
        ];
    }

    /**
     * Get an Auth model for the current authenticated user
     */
    protected function getCurrentAuthUser(): ?Auth
    {
        $userId = $this->getCurrentUserId();
        if ($userId < 0) {
            return null;
        }

        return $this->getAuthUserById($userId);
    }

    /**
     * Get an Auth model by user ID with optional caching
     */
    protected function getAuthUserById(int $userId): ?Auth
    {
        // Check if cache service is available
        if ($this->getDI()->has('cache')) {
            return $this->getAuthUserFromCacheOrDb($userId);
        }

        // Fallback to direct database query
        return Auth::findFirstById($userId);
    }

    /**
     * Get Auth model from cache or database
     */
    private function getAuthUserFromCacheOrDb(int $userId): ?Auth
    {
        $cacheKey = "user_$userId";
        $cachedUser = $this->cache->get($cacheKey);

        if ($cachedUser) {
            return unserialize($cachedUser);
        }

        $user = Auth::findFirstById($userId);

        if ($user) {
            $this->cache->save($cacheKey, serialize($user), 3600); // Cache for 1 hour
        }

        return $user;
    }

    /**
     * Begin a database transaction
     */
    protected function beginTransaction(): void
    {
        $this->db->begin();
    }

    /**
     * Commit a database transaction
     */
    protected function commitTransaction(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback a database transaction
     */
    protected function rollbackTransaction(): void
    {
        if ($this->db->isUnderTransaction()) {
            $this->db->rollback();
        }
    }

    /**
     * Execute an operation within a transaction
     *
     * @param callable $operation The function to execute within the transaction
     * @return mixed The result of the operation
     */
    protected function withTransaction(callable $operation)
    {
        try {
            $this->beginTransaction();
            $result = $operation();
            $this->commitTransaction();
            return $result;
        } catch (Exception $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }
}