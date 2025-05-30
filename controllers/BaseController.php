<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Constants\Message;
use Api\Email\Sender;
use Api\Email\SMTP;
use Api\Models\Auth;
use Exception;
use Phalcon\Mvc\Controller;

class BaseController extends Controller {
    /**
     * Get the parsed request body from the DI container
     */
    protected function getRequestBody(): array {
        // Use getShared() instead of get() for values that are not service definitions
        // Or use getRaw() which returns the stored value without checking for service definitions
        try {
            // Try to retrieve as raw value
            if ($this->getDI()->has('request_body')) {
                return $this->getDI()->getRaw('request_body') ?? [];
            }
        } catch (Exception $e) {
            // If any error, return empty array
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
        }
        return [];
    }

    /**
     * Get the authenticated user data from the DI container
     */
    protected function getAuthUser(): ?array {
        try {
            // Try to retrieve as raw value
            if ($this->getDI()->has('decodedToken')) {
                return $this->getDI()->getRaw('decodedToken') ?? [];
            }
        } catch (Exception $e) {
            // If any error, return empty array
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
        }
        return [];
    }

    /**
     * Get the current user ID from the authenticated token
     */
    protected function getCurrentUserId(): int {
        $authUser = $this->getAuthUser();
        return $authUser['id'] ?? -1;
    }

    /**
     * Get the current user role from the authenticated token
     */
    protected function getCurrentUserRole(): int {
        $authUser = $this->getAuthUser();
        return $authUser['role'] ?? -1;
    }

    /**
     * Check if the current user has admin role
     */
    protected function isAdmin(): bool {
        return $this->getCurrentUserRole() === 0; // Admin role = 0
    }

    /**
     * Check if the current user has manager role or higher
     */
    protected function isManagerOrHigher(): bool {
        return $this->getCurrentUserRole() <= 1; // Manager role = 1 or Admin role = 0
    }

    /**
     * Create a standardized success response
     */
    protected function respondWithSuccess(array|string $data, int $statusCode = 200, array|string $message = Message::NO_MSG): array {
        $response = [
            'status' => 'success',
            'code' => $statusCode,
            'data' => $data,
        ];

        // Only add the 'message' key if $message is not the string 'na'
        if ($message !== 'na') $response['message'] = $message;

        return $response;
    }

    /**
     * Create a standardized error response
     */
    protected function respondWithError(array|string $message, int $statusCode = 400): array {
        return [
            'status' => 'error',
            'code' => $statusCode,
            'message' => $message
        ];
    }

    /**
     * Get an Auth model for the current authenticated user
     */
    protected function getCurrentAuthUser(): ?Auth {
        $userId = $this->getCurrentUserId();
        if ($userId < 0) {
            return null;
        }

        return $this->getAuthUserById($userId);
    }

    /**
     * Get an Auth model by user ID with optional caching
     */
    protected function getAuthUserById(int $userId): ?Auth {
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
    private function getAuthUserFromCacheOrDb(int $userId): ?Auth {
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
    protected function beginTransaction(): void {
        $this->db->begin();
    }

    /**
     * Commit a database transaction
     */
    protected function commitTransaction(): void {
        $this->db->commit();
    }

    /**
     * Rollback a database transaction
     */
    protected function rollbackTransaction(): void {
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
    protected function withTransaction(callable $operation) {
        try {
            // Make sure $this->db is available
            if (!isset($this->db) || !$this->db) {
                // Try to get the db service from the DI container
                $this->db = $this->getDI()->get('db');

                // If still not available, throw a meaningful exception
                if (!$this->db) {
                    throw new Exception("Database service not available");
                }
            }

            $this->beginTransaction();
            $result = $operation();
            $this->commitTransaction();
            return $result;
        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            if ($this->db && $this->db->isUnderTransaction()) {
                $this->rollbackTransaction();
            }
            throw $e;
        }
    }

    protected function processEmail(string $to, string $subject, string $body, bool $isHtml = false): ?array {
        try {
            $mail = new Sender(true);
            $mail->isSMTP();
            $mail->Host =  getenv('EMAIL_HOSTPATH') ?: '127.0.0.1';
            $mail->Port = getenv('EMAIL_SERVPORT') ?: 25;
            $mail->SMTPAuth = getenv('EMAIL_SMTPAUTH') ?: false;
            $mail->Username = getenv('EMAIL_USERNAME') ?: '';
            $mail->Password = getenv('EMAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = Sender::ENCRYPTION_SMTPS;
            $mail->SMTPDebug = SMTP::DEBUG_OFF;

            $mail->setFrom(getenv('EMAIL_REP_ADDR') ?: 'failsafe@maxmilahomecare.com', getenv('EMAIL_REP_NAME') ?: 'Maxmila Homecare Failsafe System');
            $mail->addAddress($to);

            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            if($isHtml) $mail->AltBody = strip_tags($body);

            return $mail->send();
        } catch (Exception $e) {
            error_log("BaseController->processEmail(): Exception: " . $e->getMessage(). " Sender:" . $mail->ErrorInfo);
            return null;
        }
    }
}