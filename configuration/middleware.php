<?php
declare(strict_types=1);
use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;
use Api\Constants\Message;
use Api\Models\Auth;

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

    // Authentication middleware
    $eventsManager->attach('micro:beforeExecuteRoute', function (Event $event, Micro $app) {
        $matchedRoute = $app->router->getMatchedRoute();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

        // Never log headers or bodies here: they carry session tokens, passwords and SSNs.
        if (APP_ENV === 'dev') {
            error_log(sprintf(
                '[request] %s %s -> %s',
                $method,
                $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
                $matchedRoute ? $matchedRoute->getPattern() : 'NO MATCH'
            ));
        }

        if (!$matchedRoute || $method === 'OPTIONS') {
            return true;
        }

        // Routes reachable without a session token. Everything else requires Authorization.
        // Patterns are matched against the route definition, not the concrete URL.
        $publicRoutes = [
            '/',
            '/auth/login',
            '/auth/register',
            '/activation/{edoc}',
        ];

        if (in_array($matchedRoute->getPattern(), $publicRoutes, true)) {
            return true;
        }

        $deny = static function (string $message) use ($app): never {
            $app->response->setStatusCode(401, 'Unauthorized');
            $app->response->setJsonContent([
                'status' => 'error',
                'code' => 401,
                'message' => $message
            ]);
            $app->response->send();
            exit();
        };

        $token = $app->request->getHeader('Authorization');
        if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $token = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (empty($token)) {
            $token = $app->request->getHeader('X-Auth-Token');
        }
        if (empty($token)) {
            $deny(Message::UNAUTHORIZED);
        }

        try {
            $tokenService = $app->getDI()->get('tokenService');
            $decoded = $tokenService->decodeToken($token);

            if (!is_array($decoded) || !isset($decoded['id']) || !is_numeric($decoded['id'])) {
                $deny('Invalid token format');
            }

            if ($tokenService->isExpired($decoded)) {
                $deny(Message::TOKEN_EXPIRED);
            }

            $auth = Auth::findFirstById((int)$decoded['id']);
            if (!$auth || $auth->token === null || !hash_equals($auth->token, $token)) {
                $deny(Message::TOKEN_INVALID);
            }

            // Disabled, archived, deleted or not-yet-verified accounts lose access immediately,
            // even if they still hold an unexpired token.
            if (!$auth->isActive()) {
                $deny(Message::ACCOUNT_NOT_ACTIVATED);
            }

            // The database is authoritative for identity and role; never trust the token payload.
            $decoded['id'] = (int)$auth->id;
            $decoded['role'] = (int)$auth->role;
            $decoded['username'] = $auth->username;

            $app->getDI()->setShared('decodedToken', $decoded);
            return true;

        } catch (\Throwable $e) {
            error_log('Auth middleware exception: ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
            $deny('Authentication error');
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
            return;
        }

        $content = $app->getReturnedValue();

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
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
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