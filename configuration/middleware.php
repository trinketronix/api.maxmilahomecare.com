<?php
declare(strict_types=1);
use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

if (isset($app)) {
    $eventsManager = $app->getEventsManager();

    // CORS middleware
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            $response = new Response();
            $response->setHeader('Access-Control-Allow-Origin', '*');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
            $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
            $response->setHeader('Access-Control-Max-Age', '86400');
            $response->setContentType('application/json', 'UTF-8');
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([]);
            $response->send();
            exit();
        }
        $response = $app->response;
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Max-Age', '86400');
        return true;
    });

    // Content-Type validation middleware - FIXED VERSION
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            return true;
        }
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

        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        // Check if this is an upload request by examining the actual URI
        $requestUri = $_SERVER['REQUEST_URI'] ?? $app->request->getURI();
        $isUploadRequest = false;

        // Define upload route patterns
        $uploadPatterns = [
            '#^/user/upload/photo$#',
            '#^/user/update/photo$#',
            '#^/user/\d+/upload/photo$#',
            '#^/user/\d+/update/photo$#',
            '#^/patient/upload/photo$#',
            '#^/patient/update/photo$#',
            '#^/patient/\d+/upload/photo$#',
            '#^/patient/\d+/update/photo$#'
        ];

        foreach ($uploadPatterns as $pattern) {
            if (preg_match($pattern, $requestUri)) {
                $isUploadRequest = true;
                break;
            }
        }

        if ($isUploadRequest && $baseContentType !== 'multipart/form-data') {
            $app->response->setStatusCode(415, 'Unsupported Media Type');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 415,
                'message' => 'Content-Type must be multipart/form-data for uploads'
            ]);
            $app->response->send();
            exit();
        }
        else if (!$isUploadRequest && $baseContentType !== 'application/json') {
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

    // Authentication middleware - unchanged
    $eventsManager->attach('micro:beforeExecuteRoute', function (Event $event, Micro $app) {
        $router = $app->router;
        $matchedRoute = $router->getMatchedRoute();

// Enhanced logging for debugging
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
        $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
            "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        error_log("========== NEW REQUEST ==========");
        error_log("Method: " . $method);
        error_log("URI: " . $uri);
        error_log("Full URL: " . $fullUrl);
        error_log("Pattern: " . ($matchedRoute ? $matchedRoute->getPattern() : 'NO MATCH'));
        error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        error_log("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'));

        // Log all headers
        error_log("Headers: " . json_encode($app->request->getHeaders()));

        // Check for Authorization in different ways
        error_log("Auth Header (getHeader): " . ($app->request->getHeader('Authorization') ?: 'EMPTY'));
        error_log("Auth Header (SERVER): " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        error_log("Auth Header (getallheaders): " . (function_exists('getallheaders') ?
                (getallheaders()['Authorization'] ?? 'NOT SET') : 'FUNCTION NOT AVAILABLE'));

        // Log request body for POST/PUT
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $app->request->getHeader('Content-Type');
            error_log("Content-Type: " . $contentType);

            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $app->request->getRawBody();
                error_log("Request Body: " . (strlen($rawBody) > 500 ?
                        substr($rawBody, 0, 500) . '... (truncated)' : $rawBody));
            }
        }

        if (!$matchedRoute) {
            error_log("ERROR: No matching route found!");
            return true;
        }

        $publicRoutes = [
            '/',
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
            '/tools',
            '/tool/{id}',
        ];

        error_log("Is Public Route: " . (in_array($matchedRoute->getPattern(), $publicRoutes) ? 'YES' : 'NO'));

        if (in_array($matchedRoute->getPattern(), $publicRoutes)) {
            error_log("Skipping auth - public route");
            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            error_log("Skipping auth - OPTIONS request");
            return true;
        }

        $token = $app->request->getHeader('Authorization');
        if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $token = $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Add the custom header support here too
        if (empty($token)) {
            $token = $app->request->getHeader('X-Auth-Token');
            if ($token) {
                error_log("Using X-Auth-Token instead of Authorization");
            }
        }

        if (empty($token)) {
            error_log("AUTH FAILED: No token found in any header");
            error_log("========== END REQUEST (UNAUTHORIZED) ==========");

            $app->response->setStatusCode(401, 'Unauthorized');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 401,
                'message' => 'Authorization header is required'
            ]);
            $app->response->send();
            exit();
        }

        error_log("Token found: " . substr($token, 0, 20) . "...");

        try {
            $tokenService = $app->getDI()->get('tokenService');
            $decoded = $tokenService->decodeToken($token);
            if (!$decoded) {
                error_log("AUTH FAILED: Invalid token format");
                error_log("========== END REQUEST (INVALID TOKEN) ==========");

                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Invalid token format'
                ]);
                $app->response->send();
                exit();
            }

            error_log("Token decoded - User ID: " . $decoded['id'] . ", Role: " . $decoded['role']);

            if ($tokenService->isExpired($decoded)) {
                error_log("AUTH FAILED: Token expired");
                error_log("========== END REQUEST (TOKEN EXPIRED) ==========");

                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Token expired, please login again'
                ]);
                $app->response->send();
                exit();
            }

            $auth = \Api\Models\Auth::findFirstById($decoded['id']);
            if (!$auth || $token !== $auth->token) {
                error_log("AUTH FAILED: Token mismatch or user not found");
                error_log("========== END REQUEST (INVALID AUTH) ==========");

                $app->response->setStatusCode(401, 'Unauthorized');
                $app->response->setJsonContent([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Invalid token authorization'
                ]);
                $app->response->send();
                exit();
            }

            error_log("AUTH SUCCESS: User authenticated");
            $app->getDI()->setShared('decodedToken', $decoded);
            return true;

        } catch (Exception $e) {
            error_log("AUTH EXCEPTION: " . $e->getMessage());
            error_log("========== END REQUEST (EXCEPTION) ==========");

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

    // Request body parsing middleware - unchanged
    $eventsManager->attach('micro:beforeExecuteRoute', function(Event $event, Micro $app) {
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'DELETE', 'OPTIONS'])) {
            return true;
        }

        $contentType = $app->request->getHeader('Content-Type');
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

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
        }
        else if ($baseContentType === 'multipart/form-data') {
            $app->getDI()->setShared('request_body', $_POST ?: []);
        }
        else if ($baseContentType === 'application/x-www-form-urlencoded') {
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

    // Response formatting middleware - unchanged
    $eventsManager->attach('micro:afterHandleRoute', function (Event $event, Micro $app) {
        if ($app->response->isSent()) {
            error_log("Response already sent");
            return;
        }

        $content = $app->getReturnedValue();

        // Log response info
        error_log("Response content type: " . (is_array($content) ? 'array' : gettype($content)));
        if (is_array($content) && isset($content['status'])) {
            error_log("Response status: " . $content['status'] . ", code: " . ($content['code'] ?? 'not set'));
        }
        error_log("========== END REQUEST ==========\n");

        if ($content instanceof Response) {
            return;
        }

        $matchedRoute = $app->router->getMatchedRoute();
        if ($matchedRoute && strpos($matchedRoute->getPattern(), '/activation/') === 0) {
            $response = new Response();
            $response->setStatusCode(500, 'Internal Server Error');
            $response->setContentType('text/html', 'UTF-8');
            $response->setContent('<html><body><h1>Error</h1><p>Something went wrong with the activation process.</p></body></html>');
            $app->response = $response;
            $response->send();
            return;
        }

        $response = new Response();
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        $response->setContentType('application/json', 'UTF-8');
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');

        if (is_array($content) && isset($content['status'])) {
            $response->setJsonContent($content);
            $statusCode = $content['code'] ?? 200;
            $statusText = $content['status'] === 'error' ? 'Error' : 'OK';
            $response->setStatusCode($statusCode, $statusText);
        }
        else if (is_array($content) && isset($content['status']) && $content['status'] === 'error') {
            $statusCode = $content['code'] ?? 400;
            $response->setStatusCode($statusCode, 'Error');
            $response->setJsonContent([
                'status' => 'error',
                'code' => $statusCode,
                'message' => $content['message'] ?? 'Unknown error'
            ]);
        }
        else if ($content === null) {
            $response->setStatusCode(404, 'Not Found');
            $response->setJsonContent([
                'status' => 'error',
                'code' => 404,
                'message' => 'Endpoint not found',
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
        }
        else {
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([
                'status' => 'success',
                'code' => 200,
                'data' => $content
            ]);
        }

        $app->response = $response;
        $response->send();
    });

    $app->setEventsManager($eventsManager);
}