<?php

declare(strict_types=1);

/**
 * Register application middleware
 * This file centralizes all middleware registration for the application
 */

use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Micro\MiddlewareInterface;

if (isset($app)) {

// Create the middleware instances
    $eventsManager = $app->getEventsManager();

    /**
     * Headers validation middleware
     * Ensures proper Content-Type and other headers
     */
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {
        // Skip options requests (for CORS)
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            return true;
        }

        // Skip content-type validation for GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return true;
        }

        $contentType = $app->request->getHeader('Content-Type');
        if (empty($contentType)) {
            $app->response->setStatusCode(415, 'Unsupported Media Type');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 415,
                'message' => 'Content-Type header is required'
            ]);
            $app->response->send();
            return false;
        }

        // Get base content type without parameters
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        // Check if it's an upload request
        $isUploadRequest = false;
        $routePattern = $app->router->getMatchedRoute()->getPattern();
        if (strpos($routePattern, '/upload') !== false) {
            $isUploadRequest = true;
        }

        // Validate appropriate content type based on request type
        if ($isUploadRequest && $baseContentType !== 'multipart/form-data') {
            $app->response->setStatusCode(415, 'Unsupported Media Type');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 415,
                'message' => 'Content-Type must be multipart/form-data for uploads'
            ]);
            $app->response->send();
            return false;
        } else if (!$isUploadRequest && $baseContentType !== 'application/json') {
            $app->response->setStatusCode(415, 'Unsupported Media Type');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 415,
                'message' => 'Content-Type must be application/json'
            ]);
            $app->response->send();
            return false;
        }

        return true;
    });

    /**
     * CORS middleware
     * Handles Cross-Origin Resource Sharing headers
     */
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {
        $origin = $app->request->getHeader('Origin');

        // Allow from specific origins or use * for development
        $allowedOrigins = [
            'https://maxmilahomecare.com',
            'https://app.maxmilahomecare.com',
            'https://api.maxmilahomecare.com',
            'https://api-test.maxmilahomecare.com',
            'https://www.maxmilahomecare.com',
            // Add more allowed origins as needed
        ];

        // For development, you might want to allow all origins
        if (APP_ENV === 'development') {
            $allowedOrigins[] = '*';
        }

        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            $app->response->setHeader('Access-Control-Allow-Origin', $origin ?: '*');
            $app->response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $app->response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization');
            $app->response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            $app->response->setStatusCode(200, 'OK');
            $app->response->send();
            return false;
        }

        return true;
    });

    /**
     * Authentication middleware
     * Verifies JWT tokens where required
     */
    $eventsManager->attach('micro:beforeExecuteRoute', function (Event $event, Micro $app) {
        // Get the current route
        $router = $app->router;
        $matchedRoute = $router->getMatchedRoute();

        if (!$matchedRoute) {
            return true;
        }

        // List of public routes that don't require authentication
        $publicRoutes = [
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/forgot-password',
            // Add other public routes
        ];

        // Check if this is a public route
        if (in_array($matchedRoute->getPattern(), $publicRoutes)) {
            return true;
        }

        // Skip auth check for OPTIONS requests (CORS preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return true;
        }

        // Check for Authorization header
        $token = $app->request->getHeader('Authorization');
        if (empty($token)) {
            $app->response->setStatusCode(401, 'Unauthorized');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 401,
                'message' => 'Authorization header is required'
            ]);
            $app->response->send();
            return false;
        }

        // Validate token
        try {
            // Create a token service or use a method from BaseController
            // For now, assuming you have a token service registered in DI
            $tokenService = $app->getDI()->get('tokenService');
            $decoded = $tokenService->decodeToken($token);

            if (!$decoded) {
                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Invalid token format'
                ]);
                $app->response->send();
                return false;
            }

            // Check for token expiration
            if ($tokenService->isExpired($decoded)) {
                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Token expired, please login again'
                ]);
                $app->response->send();
                return false;
            }

            // Verify token in database
            $auth = \Api\Models\Auth::findFirstById($decoded['id']);
            if (!$auth || $token !== $auth->token) {
                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Invalid token authorization'
                ]);
                $app->response->send();
                return false;
            }

            // Check role permissions (if required)
            // Different routes might require different role levels
            $routeRoleRequirements = [
                '/api/admin/' => 0, // Admin routes require admin role
                '/api/manager/' => 1, // Manager routes require manager role or higher
                // Default for other routes is role 2 (caregiver)
            ];

            $requiredRole = 2; // Default role requirement
            foreach ($routeRoleRequirements as $routePrefix => $roleLevel) {
                if (strpos($matchedRoute->getPattern(), $routePrefix) === 0) {
                    $requiredRole = $roleLevel;
                    break;
                }
            }

            // Check if user has sufficient role level
            if ($decoded['role'] > $requiredRole) {
                $app->response->setStatusCode(403, 'Forbidden');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 403,
                    'message' => 'Insufficient permissions for this operation'
                ]);
                $app->response->send();
                return false;
            }

            // Store user data in DI for controllers
            $app->getDI()->set('auth_user', $decoded);

            return true;
        } catch (\Exception $e) {
            $app->response->setStatusCode(401, 'Unauthorized');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 401,
                'message' => 'Authentication error: ' . $e->getMessage()
            ]);
            $app->response->send();
            return false;
        }
    });

    $eventsManager->attach('micro:beforeExecuteRoute', function(Event $event, Micro $app) {
        // Skip for GET and DELETE requests
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'DELETE', 'OPTIONS'])) {
            return true;
        }

        // Get content type
        $contentType = $app->request->getHeader('Content-Type');
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        // Parse body based on content type
        if ($baseContentType === 'application/json') {
            try {
                $rawBody = $app->request->getRawBody() ?: '';
                if (!empty($rawBody)) {
                    $decodedBody = json_decode(
                        $rawBody,
                        true,
                        512,
                        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                    );

                    // Store parsed body in DI for controller access
                    $app->getDI()->set('request_body', is_array($decodedBody) ? $decodedBody : []);
                } else {
                    $app->getDI()->set('request_body', []);
                }
            } catch (\JsonException $e) {
                $app->response->setStatusCode(400, 'Bad Request');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 400,
                    'message' => 'Invalid JSON in request body: ' . $e->getMessage()
                ]);
                $app->response->send();
                return false;
            }
        } else if ($baseContentType === 'multipart/form-data') {
            // For form data, we can use the standard $_POST and $_FILES
            $app->getDI()->set('request_body', $_POST);
        } else if ($baseContentType === 'application/x-www-form-urlencoded') {
            $rawBody = $app->request->getRawBody() ?: '';
            if (!empty($rawBody)) {
                parse_str($rawBody, $parsedData);
                $app->getDI()->set('request_body', is_array($parsedData) ? $parsedData : []);
            } else {
                $app->getDI()->set('request_body', []);
            }
        }

        return true;
    });

    /**
     * Response formatting middleware
     * Ensures consistent API responses
     */
    $eventsManager->attach('micro:afterHandleRoute', function (Event $event, Micro $app) {
        // If response is already sent, don't do anything
        if ($app->response->isSent()) {
            return;
        }

        // Get the content from the response
        $content = $app->getReturnedValue();

        // If it's already a Response object, don't modify it
        if ($content instanceof Response) {
            return;
        }

        // Prepare the response
        $response = new Response();

        // Add security headers to all responses
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setContentType('application/json', 'UTF-8');

        // Format the response based on content
        if (is_array($content) && isset($content['status'])) {
            // Response is already formatted
            $response->setJsonContent($content);

            // Set appropriate status code
            $statusCode = $content['code'] ?? 200;
            $statusText = $content['status'] === 'error' ? 'Error' : 'OK';
            $response->setStatusCode($statusCode, $statusText);
        }
        else if (is_array($content) && isset($content['status']) && $content['status'] === 'error') {
            // Error response
            $statusCode = $content['code'] ?? 400;
            $response->setStatusCode($statusCode, 'Error');
            $response->setJsonContent([
                'status' => 'error',
                'code' => $statusCode,
                'message' => $content['message'] ?? 'Unknown error'
            ]);
        }
        else {
            // Success response with data
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([
                'status' => 'success',
                'code' => 200,
                'data' => $content
            ]);
        }

        // Replace the application response with our formatted one
        $app->response = $response;

        // Send the response
        $response->send();
    });

    // Set the events manager to the application
    $app->setEventsManager($eventsManager);
}