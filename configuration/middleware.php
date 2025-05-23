<?php

declare(strict_types=1);

/**
 * Register application middleware
 * This file centralizes all middleware registration for the application
 */

use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

if (isset($app)) {

    // Create the middleware instances
    $eventsManager = $app->getEventsManager();

    /**
     * CORS middleware - MUST BE FIRST
     * Handles Cross-Origin Resource Sharing headers
     */
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {
        // Handle preflight OPTIONS requests FIRST
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            $response = new Response();

            // Allow all origins
            $response->setHeader('Access-Control-Allow-Origin', '*');

            // Allow all common methods
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');

            // Allow all common headers
            $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');

            // Allow credentials if needed
            $response->setHeader('Access-Control-Allow-Credentials', 'true');

            // Cache preflight request for 24 hours
            $response->setHeader('Access-Control-Max-Age', '86400');

            // Set content type
            $response->setContentType('application/json', 'UTF-8');

            // Set status code to 200 OK
            $response->setStatusCode(200, 'OK');

            // Empty response body for OPTIONS
            $response->setJsonContent([]);

            // Send the response immediately
            $response->send();
            exit();
        }

        // For all other requests, set CORS headers
        $response = $app->response;

        // Allow all origins
        $response->setHeader('Access-Control-Allow-Origin', '*');

        // Allow all common methods
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');

        // Allow all common headers
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');

        // Allow credentials if needed
        $response->setHeader('Access-Control-Allow-Credentials', 'true');

        // Cache preflight request for 24 hours
        $response->setHeader('Access-Control-Max-Age', '86400');

        return true;
    });

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
            exit();
        }

        // Get base content type without parameters
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        // Check if it's an upload request
        $isUploadRequest = false;
        $matchedRoute = $app->router->getMatchedRoute();
        if ($matchedRoute) {
            $routePattern = $matchedRoute->getPattern();
            if (strpos($routePattern, '/upload') !== false) {
                $isUploadRequest = true;
            }
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
            exit();
        } else if (!$isUploadRequest && $baseContentType !== 'application/json') {
            $app->response->setStatusCode(415, 'Unsupported Media Type');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 415,
                'message' => 'Content-Type must be application/json'
            ]);
            $app->response->send();
            exit();
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
            '/',  // Root route
            '/auth/login',
            '/auth/register',
            '/auth/change/password',
            '/activation/{edoc}',
            '/bulk/auths',
            '/bulk/users',
            '/bulk/patients',
            '/bulk/addresses',
            '/bulk/user-patient',
            '/bulk/visits',
            '/send/email',
            '/tools',  // Make the getAll route public
            '/tool/{id}', // Make individual tool route public
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
            exit();
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
                exit();
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
                exit();
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
                exit();
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
                exit();
            }

            // Store user data in DI for controllers
            $app->getDI()->setShared('decodedToken', $decoded);

            return true;
        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            $app->response->setStatusCode(401, 'Unauthorized');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 401,
                'message' => 'Authentication error: ' . $e->getMessage()
            ]);
            $app->response->send();
            exit();
        }
    });

    /**
     * Request body parsing middleware
     */
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

                    // Store parsed body using setShared/setData instead of set
                    $app->getDI()->setShared('request_body', $decodedBody ?: []);
                } else {
                    $app->getDI()->setShared('request_body', []);
                }
            } catch (\JsonException $e) {
                $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
                $app->response->setStatusCode(400, 'Bad Request');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 400,
                    'message' => 'Invalid JSON in request body: ' . $e->getMessage()
                ]);
                $app->response->send();
                exit();
            }
        } else if ($baseContentType === 'multipart/form-data') {
            // For form data, we can use the standard $_POST and $_FILES
            $app->getDI()->setShared('request_body', $_POST ?: []);
        } else if ($baseContentType === 'application/x-www-form-urlencoded') {
            $rawBody = $app->request->getRawBody() ?: '';
            if (!empty($rawBody)) {
                parse_str($rawBody, $parsedData);
                $app->getDI()->setShared('request_body', $parsedData ?: []);
            } else {
                $app->getDI()->setShared('request_body', []);
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

        // Check if this is the activation route
        $matchedRoute = $app->router->getMatchedRoute();
        if ($matchedRoute && strpos($matchedRoute->getPattern(), '/activation/') === 0) {
            // For the activation route, we expect a Response object to be returned
            // If we reach here, something went wrong
            $response = new Response();
            $response->setStatusCode(500, 'Internal Server Error');
            $response->setContentType('text/html', 'UTF-8');
            $response->setContent('<html><body><h1>Error</h1><p>Something went wrong with the activation process.</p></body></html>');
            $app->response = $response;
            $response->send();
            return;
        }

        // Prepare the response
        $response = new Response();

        // Add security headers to all responses
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setContentType('application/json', 'UTF-8');

        // Ensure CORS headers are set on the response
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');

        // Format the response based on content
        if (is_array($content) && isset($content['status'])) {
            // Response is already formatted (including not-found responses)
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
        else if ($content === null) {
            // Handle case where no content is returned (404 case)
            $response->setStatusCode(404, 'Not Found');
            $response->setJsonContent([
                'status' => 'error',
                'code' => 404,
                'message' => 'Endpoint not found',
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown'
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