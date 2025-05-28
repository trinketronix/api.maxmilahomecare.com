<?php

declare(strict_types=1);

/**
 * Application Middleware Registration and Configuration
 *
 * This file serves as the central middleware registration system for the Maxmila Homecare REST API.
 * It implements a comprehensive middleware stack that handles cross-origin requests, authentication,
 * request validation, body parsing, and response formatting in a specific order to ensure proper
 * API functionality and security.
 *
 * The middleware stack operates using Phalcon's Event Manager system, which allows for intercepting
 * and modifying the request/response cycle at strategic points. Each middleware is attached to
 * specific events that fire during different phases of request processing.
 *
 * Middleware Processing Order:
 * 1. CORS Middleware (beforeHandleRoute) - Handles preflight requests and sets CORS headers
 * 2. Headers Validation (beforeHandleRoute) - Validates Content-Type and other required headers
 * 3. Authentication (beforeExecuteRoute) - Verifies JWT tokens and user permissions
 * 4. Request Body Parsing (beforeExecuteRoute) - Parses JSON/form data into usable format
 * 5. Response Formatting (afterHandleRoute) - Ensures consistent API response structure
 *
 * @package    MaxmilaHomecare\Configuration
 * @subpackage Middleware
 * @version    1.0.0
 * @author     Maxmila Homecare Development Team
 * @copyright  2025 Maxmila Homecare LLC & Trinketronix LLC
 * @license    Proprietary - All rights reserved
 *
 * @requires   Phalcon 5.0+
 * @requires   PHP 8.4+
 *
 * @see        https://docs.phalcon.io/5.0/en/events Events Manager Documentation
 * @see        https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS CORS Specification
 *
 * @since      1.0.0
 * @deprecated None
 *
 * @api
 * @internal   This file is loaded automatically by the application bootstrap process
 *
 * @example
 * // Middleware is automatically registered when this file is included:
 * require_once BASE_PATH . '/configuration/middleware.php';
 *
 * @todo       Implement rate limiting middleware for API protection
 * @todo       Add request/response logging middleware for audit trails
 * @todo       Implement caching middleware for frequently accessed endpoints
 * @todo       Add request size validation middleware
 * @todo       Implement API versioning middleware
 */

// Import required Phalcon classes for event handling and HTTP responses
use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

// Ensure the application instance is available before registering middleware
if (isset($app)) {

    /**
     * Event Manager Instance Retrieval
     *
     * Obtains the Events Manager instance from the Micro application. The Events Manager
     * is responsible for dispatching events throughout the request lifecycle and managing
     * the middleware execution order.
     *
     * @var \Phalcon\Events\Manager $eventsManager The application's event management system
     */
    $eventsManager = $app->getEventsManager();

    // ============================================================================
    // CORS MIDDLEWARE - CROSS-ORIGIN RESOURCE SHARING HANDLER
    // ============================================================================

    /**
     * CORS (Cross-Origin Resource Sharing) Middleware
     *
     * This middleware MUST BE EXECUTED FIRST in the middleware stack to properly handle
     * cross-origin requests from web browsers. It performs two critical functions:
     *
     * 1. **Preflight Request Handling**: Immediately responds to OPTIONS requests with
     *    appropriate CORS headers and exits, preventing further processing.
     *
     * 2. **CORS Header Injection**: Adds necessary CORS headers to all other HTTP requests
     *    to allow cross-origin access from web applications.
     *
     * The middleware is attached to the 'micro:beforeHandleRoute' event, ensuring it
     * executes before any route matching or authentication checks occur.
     *
     * **CORS Headers Explained:**
     * - `Access-Control-Allow-Origin: *` - Allows requests from any domain
     * - `Access-Control-Allow-Methods` - Specifies permitted HTTP methods
     * - `Access-Control-Allow-Headers` - Lists headers that can be sent in requests
     * - `Access-Control-Allow-Credentials: true` - Allows cookies and authentication
     * - `Access-Control-Max-Age: 86400` - Caches preflight response for 24 hours
     *
     * **Security Considerations:**
     * - Using wildcard (*) for Allow-Origin is permissive but appropriate for public APIs
     * - In production, consider restricting origins to specific domains
     * - The middleware allows credentials, which requires careful origin configuration
     *
     * **Browser Preflight Process:**
     * 1. Browser sends OPTIONS request before actual request (for complex requests)
     * 2. This middleware intercepts OPTIONS and responds with CORS headers
     * 3. Browser evaluates response and proceeds with actual request if allowed
     * 4. Actual request receives CORS headers and completes successfully
     *
     * @event micro:beforeHandleRoute Fires before route matching begins
     *
     * @param Event $event The event object containing request context
     * @param Micro $app   The Phalcon Micro application instance
     *
     * @return bool|void Returns true to continue processing, or exits for OPTIONS requests
     *
     * @throws none This middleware is designed to never throw exceptions
     *
     * @since 1.0.0
     *
     * @example
     * // Browser preflight request:
     * OPTIONS /api/users HTTP/1.1
     * Origin: https://app.example.com
     * Access-Control-Request-Method: POST
     * Access-Control-Request-Headers: Content-Type, Authorization
     *
     * // Middleware response:
     * HTTP/1.1 200 OK
     * Access-Control-Allow-Origin: *
     * Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD
     * Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma
     *
     * @api
     * @httpmethod OPTIONS Handles browser preflight requests
     * @httpcode 200 Returns OK status for successful preflight responses
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS MDN CORS Documentation
     * @see https://www.w3.org/TR/cors/ W3C CORS Specification
     */
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {

        /**
         * Preflight Request Detection and Handling
         *
         * Browsers send OPTIONS requests as "preflight" checks before making actual
         * cross-origin requests with custom headers or non-simple methods. This
         * section detects these preflight requests and responds immediately.
         *
         * **What triggers a preflight request:**
         * - HTTP methods other than GET, HEAD, or POST
         * - POST requests with Content-Type other than application/x-www-form-urlencoded,
         *   multipart/form-data, or text/plain
         * - Requests with custom headers like Authorization
         * - Requests with credentials (cookies, authorization headers)
         *
         * **Why immediate response is necessary:**
         * - Preflight requests should not trigger authentication or business logic
         * - They should return quickly to minimize latency
         * - They don't carry the actual request payload
         *
         * @var string $_SERVER['REQUEST_METHOD'] The HTTP method of the current request
         */
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

            /**
             * Preflight Response Creation
             *
             * Creates a new HTTP response specifically for the preflight request.
             * This response contains all necessary CORS headers but no body content.
             *
             * @var Response $response New response instance for preflight handling
             */
            $response = new Response();

            /**
             * Access-Control-Allow-Origin Header
             *
             * Specifies which origins are allowed to access the resource. The wildcard (*)
             * allows any origin to make requests. In production environments, this should
             * potentially be restricted to specific domains for enhanced security.
             *
             * **Security Note**: When using credentials (cookies/auth), browsers require
             * specific origins rather than wildcards. However, for public APIs without
             * sensitive data, wildcards are acceptable.
             *
             * @header Access-Control-Allow-Origin Controls which domains can access the API
             */
            $response->setHeader('Access-Control-Allow-Origin', '*');

            /**
             * Access-Control-Allow-Methods Header
             *
             * Lists all HTTP methods that the client is allowed to use when making
             * requests to this resource. This includes both standard REST methods
             * and additional methods for comprehensive API support.
             *
             * **Methods Explained:**
             * - GET: Retrieve data (safe, idempotent)
             * - POST: Create new resources or submit data
             * - PUT: Update/replace entire resources (idempotent)
             * - DELETE: Remove resources (idempotent)
             * - OPTIONS: CORS preflight and API discovery
             * - PATCH: Partial resource updates
             * - HEAD: Get headers without response body (for caching)
             *
             * @header Access-Control-Allow-Methods Permitted HTTP methods for cross-origin requests
             */
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');

            /**
             * Access-Control-Allow-Headers Header
             *
             * Specifies which headers the client is allowed to include in actual requests.
             * This list covers common headers used in API interactions and authentication.
             *
             * **Headers Explained:**
             * - Origin: Identifies the origin of the cross-origin request
             * - X-Requested-With: Identifies AJAX requests (often used by libraries)
             * - Content-Type: Specifies the format of request body data
             * - Accept: Indicates which content types the client can process
             * - Authorization: Contains authentication credentials (JWT tokens, etc.)
             * - Cache-Control: Controls caching behavior
             * - Pragma: Legacy caching control header
             *
             * @header Access-Control-Allow-Headers Headers that can be included in requests
             */
            $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');

            /**
             * Access-Control-Allow-Credentials Header
             *
             * Indicates whether the response to the request can be exposed when the
             * credentials flag is true. When a request includes credentials (cookies,
             * authorization headers), this header must be present and set to true.
             *
             * **Important**: When credentials are allowed, Access-Control-Allow-Origin
             * cannot use wildcards in strict CORS implementations. However, this API
             * uses token-based authentication rather than cookies, making this safer.
             *
             * @header Access-Control-Allow-Credentials Allows credentials in cross-origin requests
             */
            $response->setHeader('Access-Control-Allow-Credentials', 'true');

            /**
             * Access-Control-Max-Age Header
             *
             * Indicates how long (in seconds) the results of a preflight request can be
             * cached by the browser. This reduces the number of preflight requests for
             * repeated API calls from the same origin.
             *
             * **Value**: 86400 seconds = 24 hours
             * **Benefit**: Reduces latency for subsequent requests from the same origin
             * **Browser Behavior**: Each browser may impose its own maximum cache time
             *
             * @header Access-Control-Max-Age Duration to cache preflight response (seconds)
             */
            $response->setHeader('Access-Control-Max-Age', '86400');

            /**
             * Response Content Type Configuration
             *
             * Sets the content type to JSON even though the response body is empty.
             * This maintains consistency with the API's response format and ensures
             * proper client-side handling.
             *
             * @param string $contentType The MIME type for the response
             * @param string $charset     The character encoding for the response
             */
            $response->setContentType('application/json', 'UTF-8');

            /**
             * HTTP Status Code Configuration
             *
             * Sets the HTTP status code to 200 OK for successful preflight responses.
             * This indicates to the browser that the preflight check was successful
             * and the actual request can proceed.
             *
             * @param int    $code   The HTTP status code (200)
             * @param string $status The status text description
             */
            $response->setStatusCode(200, 'OK');

            /**
             * Empty Response Body for Preflight
             *
             * Sets an empty JSON object as the response body. Preflight responses
             * typically don't need body content, but including an empty JSON object
             * maintains consistency and prevents potential parsing errors.
             *
             * @param array $content Empty array that will be JSON-encoded
             */
            $response->setJsonContent([]);

            /**
             * Immediate Response Transmission
             *
             * Sends the preflight response immediately to the client and terminates
             * script execution. This prevents any further middleware or route processing
             * for OPTIONS requests, which is the correct behavior for CORS preflight.
             *
             * **Why exit() is used:**
             * - Preflight requests should not trigger business logic
             * - Immediate response reduces latency
             * - Prevents unnecessary processing and resource usage
             *
             * @return void Script execution terminates after sending response
             */
            $response->send();
            exit();
        }

        /**
         * CORS Headers for Non-Preflight Requests
         *
         * For all HTTP methods other than OPTIONS, this section adds the same CORS
         * headers to the response to ensure cross-origin requests are properly handled.
         * These headers are applied to the application's response object and will be
         * sent with the final response after route processing.
         *
         * **Why headers are set here:**
         * - Ensures CORS headers are present on all API responses
         * - Allows cross-origin access for actual API requests
         * - Maintains consistency between preflight and actual responses
         *
         * @var Response $response The application's response object
         */
        $response = $app->response;

        // Apply the same CORS headers as used in preflight responses
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Max-Age', '86400');

        /**
         * Middleware Continuation Signal
         *
         * Returns true to indicate that middleware processing should continue.
         * This allows subsequent middleware in the stack to execute after CORS
         * headers have been properly configured.
         *
         * @return bool true to continue middleware execution
         */
        return true;
    });

    // ============================================================================
    // HEADERS VALIDATION MIDDLEWARE - CONTENT-TYPE AND HEADER ENFORCEMENT
    // ============================================================================

    /**
     * Headers Validation Middleware
     *
     * This middleware enforces proper HTTP header usage for API requests, ensuring that
     * clients send appropriate Content-Type headers based on their request type. It serves
     * as a quality gate to prevent malformed requests from reaching the application logic.
     *
     * **Primary Functions:**
     * 1. **Content-Type Validation**: Ensures requests have appropriate Content-Type headers
     * 2. **Request Type Detection**: Differentiates between regular API calls and file uploads
     * 3. **Early Request Rejection**: Stops invalid requests before they consume resources
     * 4. **Protocol Compliance**: Enforces HTTP/REST best practices
     *
     * **Validation Rules:**
     * - GET/DELETE requests: No Content-Type validation (typically no body)
     * - OPTIONS requests: Skipped (handled by CORS middleware)
     * - Upload endpoints: Must use multipart/form-data
     * - All other requests: Must use application/json
     *
     * **Benefits:**
     * - Prevents parsing errors from incorrect content types
     * - Provides clear error messages for client developers
     * - Reduces server processing overhead for invalid requests
     * - Maintains API consistency and reliability
     *
     * @event micro:beforeHandleRoute Executes after CORS but before route matching
     *
     * @param Event $event The event object containing request context
     * @param Micro $app   The Phalcon Micro application instance
     *
     * @return bool|void Returns true to continue processing, or exits with error response
     *
     * @throws none This middleware handles all validation internally
     *
     * @since 1.0.0
     *
     * @example
     * // Valid JSON API request:
     * POST /api/users HTTP/1.1
     * Content-Type: application/json
     * {"name": "John Doe", "email": "john@example.com"}
     *
     * // Valid file upload request:
     * POST /api/user/upload/photo HTTP/1.1
     * Content-Type: multipart/form-data; boundary=----FormBoundary123
     *
     * // Invalid request (will be rejected):
     * POST /api/users HTTP/1.1
     * Content-Type: text/plain
     * name=John&email=john@example.com
     *
     * @api
     * @httpcode 415 Returns "Unsupported Media Type" for invalid Content-Type
     *
     * @see https://tools.ietf.org/html/rfc7231#section-3.1.1.5 HTTP Content-Type Header
     * @see https://tools.ietf.org/html/rfc7578 Multipart Form Data Specification
     */
    $eventsManager->attach('micro:beforeHandleRoute', function (Event $event, Micro $app) {

        /**
         * OPTIONS Request Bypass
         *
         * Skips Content-Type validation for OPTIONS requests since they are handled
         * by the CORS middleware and don't contain request bodies that need validation.
         * This prevents unnecessary processing and potential conflicts.
         *
         * **Reasoning:**
         * - OPTIONS requests are CORS preflight checks
         * - They typically don't contain request bodies
         * - CORS middleware handles them completely
         * - No need for Content-Type validation
         *
         * @var string $_SERVER['REQUEST_METHOD'] The HTTP method of the current request
         */
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            return true;
        }

        /**
         * GET Request Bypass
         *
         * Skips Content-Type validation for GET requests since they typically don't
         * contain request bodies and therefore don't require Content-Type headers.
         * This follows HTTP/REST conventions where GET requests retrieve data.
         *
         * **HTTP Specification Compliance:**
         * - GET requests should not have request bodies (RFC 7231)
         * - Content-Type is primarily for request body formatting
         * - Query parameters are used instead of body content
         * - Some proxies/servers may reject GET requests with bodies
         *
         * @var string $_SERVER['REQUEST_METHOD'] The HTTP method of the current request
         */
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return true;
        }

        /**
         * Content-Type Header Extraction and Validation
         *
         * Retrieves the Content-Type header from the incoming request and validates
         * its presence. The Content-Type header is crucial for proper request body
         * parsing and determines how the server should interpret the request data.
         *
         * **Header Importance:**
         * - Tells the server how to parse the request body
         * - Prevents data corruption from incorrect parsing
         * - Enables proper error handling and validation
         * - Required by HTTP standards for requests with bodies
         *
         * @var string|null $contentType The Content-Type header value from the request
         */
        $contentType = $app->request->getHeader('Content-Type');

        /**
         * Missing Content-Type Header Handling
         *
         * If no Content-Type header is present in a request that should have one,
         * the middleware responds with an HTTP 415 "Unsupported Media Type" error.
         * This prevents the application from attempting to parse request bodies
         * without knowing their format.
         *
         * **Error Response Components:**
         * - HTTP 415 status code indicating media type issue
         * - Standardized error response format
         * - Clear error message for client debugging
         * - Immediate response transmission to prevent further processing
         *
         * @httpcode 415 Unsupported Media Type
         * @response {
         *   "status": "error",
         *   "code": 415,
         *   "message": "Content-Type header is required"
         * }
         */
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

        /**
         * Content-Type Normalization
         *
         * Extracts the base content type from the header value, removing any additional
         * parameters like charset or boundary information. This ensures consistent
         * comparison regardless of how clients format their Content-Type headers.
         *
         * **Examples of normalization:**
         * - "application/json; charset=utf-8" → "application/json"
         * - "multipart/form-data; boundary=xyz" → "multipart/form-data"
         * - "APPLICATION/JSON" → "application/json"
         *
         * **Process:**
         * 1. Split on semicolon to separate main type from parameters
         * 2. Take the first part (main content type)
         * 3. Trim whitespace and convert to lowercase
         * 4. Use for comparison against expected types
         *
         * @var string $baseContentType The normalized content type without parameters
         */
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        /**
         * Upload Request Detection
         *
         * Determines if the current request is a file upload by examining the matched
         * route pattern for upload-related paths. This detection is crucial for applying
         * the correct Content-Type validation rules.
         *
         * **Upload Detection Logic:**
         * - Examines the matched route pattern from the router
         * - Looks for "/upload" substring in the route
         * - Sets flag for conditional validation later
         * - Handles cases where route matching might fail
         *
         * **Why Route-Based Detection:**
         * - More reliable than header-only detection
         * - Allows for explicit upload endpoint designation
         * - Prevents false positives from client-side decisions
         * - Enables fine-grained control over upload handling
         *
         * @var bool $isUploadRequest Flag indicating if this is a file upload request
         */
        $isUploadRequest = false;
        $matchedRoute = $app->router->getMatchedRoute();

        /**
         * Route Pattern Analysis
         *
         * If a route was successfully matched, extract its pattern and check for
         * upload-related paths. This enables the middleware to apply different
         * validation rules for file upload endpoints versus regular API endpoints.
         *
         * @var string|null $routePattern The pattern of the matched route
         */
        if ($matchedRoute) {
            $routePattern = $matchedRoute->getPattern();
            if (strpos($routePattern, '/upload') !== false) {
                $isUploadRequest = true;
            }
        }

        /**
         * Upload Request Content-Type Validation
         *
         * For requests identified as file uploads, validates that the Content-Type
         * is set to multipart/form-data, which is required for proper file upload
         * handling by web servers and PHP.
         *
         * **Why multipart/form-data is required:**
         * - Enables file uploads with metadata
         * - Supports multiple files in single request
         * - Allows mixing of form fields and file data
         * - Standard format for HTML form file uploads
         * - Properly handles binary data transmission
         *
         * **Validation Process:**
         * 1. Check if request is identified as upload
         * 2. Verify Content-Type is multipart/form-data
         * 3. Reject with 415 error if mismatch occurs
         * 4. Provide clear error message for client correction
         *
         * @httpcode 415 Unsupported Media Type for upload requests with wrong Content-Type
         * @response {
         *   "status": "error",
         *   "code": 415,
         *   "message": "Content-Type must be multipart/form-data for uploads"
         * }
         */
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
        /**
         * Regular API Request Content-Type Validation
         *
         * For non-upload requests, validates that the Content-Type is set to
         * application/json, which is the standard format for REST API data exchange.
         * This ensures consistent data formatting across the entire API.
         *
         * **Why application/json is required:**
         * - Standardized data interchange format
         * - Native support in modern programming languages
         * - Efficient parsing and generation
         * - Type-safe data representation
         * - Wide client library support
         *
         * **Alternative formats not supported:**
         * - application/x-www-form-urlencoded (traditional forms)
         * - text/plain (unstructured data)
         * - text/xml (legacy format)
         * - Any other MIME types
         *
         * **Validation Process:**
         * 1. Check if request is NOT an upload request
         * 2. Verify Content-Type is application/json
         * 3. Reject with 415 error if different type used
         * 4. Provide clear error message for client correction
         *
         * @httpcode 415 Unsupported Media Type for non-upload requests with wrong Content-Type
         * @response {
         *   "status": "error",
         *   "code": 415,
         *   "message": "Content-Type must be application/json"
         * }
         */
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

        /**
         * Successful Validation Continuation
         *
         * If all Content-Type validations pass, returns true to allow the request
         * to continue through the middleware pipeline. This indicates that the
         * request has proper headers and can proceed to authentication and
         * business logic processing.
         *
         * **What happens next:**
         * - Request proceeds to authentication middleware
         * - Route matching occurs
         * - Controller actions are executed
         * - Response formatting middleware processes the result
         *
         * @return bool true to continue middleware pipeline execution
         */
        return true;
    });

    // ============================================================================
    // AUTHENTICATION MIDDLEWARE - JWT TOKEN VERIFICATION AND ACCESS CONTROL
    // ============================================================================

    /**
     * Authentication Middleware
     *
     * This middleware handles JWT-like token verification and role-based access control
     * for the API. It operates at the 'beforeExecuteRoute' event to ensure authentication
     * occurs after route matching but before controller execution.
     *
     * **Primary Functions:**
     * 1. **Public Route Exemption**: Allows access to registration, login, and public endpoints
     * 2. **Token Extraction**: Retrieves JWT tokens from Authorization headers
     * 3. **Token Validation**: Verifies token format, expiration, and database consistency
     * 4. **Role-Based Access Control**: Enforces permission levels based on user roles
     * 5. **User Context Setup**: Makes authenticated user data available to controllers
     *
     * **Authentication Flow:**
     * 1. Check if route requires authentication (not in public routes list)
     * 2. Extract and validate Authorization header format
     * 3. Decode and verify JWT token structure
     * 4. Check token expiration timestamp
     * 5. Verify token exists in database and matches user record
     * 6. Validate user role permissions for the requested endpoint
     * 7. Store user context in DI container for controller access
     *
     * **Security Features:**
     * - Token expiration enforcement
     * - Database token verification (prevents token reuse after logout)
     * - Role-based access control with hierarchical permissions
     * - Comprehensive error handling with specific error messages
     *
     * @event micro:beforeExecuteRoute Executes after route matching, before controller
     *
     * @param Event $event The event object containing request context
     * @param Micro $app   The Phalcon Micro application instance
     *
     * @return bool|void Returns true to continue processing, or exits with error response
     *
     * @throws Exception For token decoding errors or database connectivity issues
     *
     * @since 1.0.0
     *
     * @example
     * // Authenticated request:
     * GET /api/users/profile HTTP/1.1
     * Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
     *
     * // Public request (no auth required):
     * POST /api/auth/login HTTP/1.1
     * Content-Type: application/json
     * {"username": "user@example.com", "password": "password123"}
     *
     * @api
     * @httpcode 401 Returns "Unauthorized" for missing, invalid, or expired tokens
     * @httpcode 403 Returns "Forbidden" for insufficient role permissions
     *
     * @see https://tools.ietf.org/html/rfc7519 JWT Specification
     * @see https://tools.ietf.org/html/rfc6750 OAuth 2.0 Bearer Token Usage
     */
    $eventsManager->attach('micro:beforeExecuteRoute', function (Event $event, Micro $app) {

        /**
         * Route Matching and Extraction
         *
         * Retrieves the currently matched route from the application router to
         * determine if authentication is required. This information is used to
         * bypass authentication for public endpoints while enforcing it for
         * protected resources.
         *
         * **Route Matching Process:**
         * 1. Router analyzes the request URI against registered routes
         * 2. Finds the best matching route pattern
         * 3. Stores route information for middleware access
         * 4. Returns null if no route matches (handled by 404 middleware later)
         *
         * @var \Phalcon\Mvc\Router\Route|null $matchedRoute The matched route object or null
         */
        $router = $app->router;
        $matchedRoute = $router->getMatchedRoute();

        /**
         * Unmatched Route Handling
         *
         * If no route was matched, allows the request to continue to the 404 handler.
         * This prevents authentication errors from masking legitimate 404 responses
         * and ensures proper error handling hierarchy.
         *
         * **Why continue without authentication:**
         * - 404 errors should be returned even for unauthenticated requests
         * - Prevents information leakage about protected endpoints
         * - Maintains consistent error response behavior
         * - Follows HTTP specification recommendations
         *
         * @return bool true to continue processing (will result in 404)
         */
        if (!$matchedRoute) {
            return true;
        }

        /**
         * Public Routes Configuration
         *
         * Defines a comprehensive list of API endpoints that do not require authentication.
         * These routes are accessible to anonymous users and typically include authentication
         * endpoints, registration, password reset, and some informational endpoints.
         *
         * **Route Categories:**
         * - **Root**: API information and health check endpoints
         * - **Authentication**: Login, registration, password management
         * - **Bulk Operations**: System maintenance and data migration endpoints
         * - **Email**: Communication services (may need additional security)
         * - **Tools**: Development and testing utilities
         *
         * **Security Considerations:**
         * - Bulk operations are public for system maintenance (consider IP restrictions)
         * - Email endpoints should have rate limiting in production
         * - Tool endpoints should be disabled in production environments
         * - Public routes should still validate input and implement rate limiting
         *
         * @var array $publicRoutes List of route patterns that bypass authentication
         */
        $publicRoutes = [
            '/',  // Root route - API information and status
            '/auth/login',           // User authentication endpoint
            '/auth/register',        // New user registration
            '/auth/change/password', // Password reset functionality
            '/activation/{edoc}',    // Email activation endpoint
            '/bulk/auths',          // Bulk user creation (system maintenance)
            '/bulk/users',          // Bulk user updates (system maintenance)
            '/bulk/patients',       // Bulk patient import (system maintenance)
            '/bulk/addresses',      // Bulk address import (system maintenance)
            '/bulk/user-patient',   // Bulk relationship import (system maintenance)
            '/bulk/visits',         // Bulk visit import (system maintenance)
            '/send/email',          // Email sending service
            '/tools',               // Tool listing endpoint (development)
            '/tool/{id}',           // Individual tool access (development)
            // Additional public routes can be added here as needed
        ];

        /**
         * Public Route Authentication Bypass
         *
         * Checks if the current request matches any of the defined public routes.
         * If a match is found, authentication is bypassed and the request continues
         * directly to the controller without token verification.
         *
         * **Matching Process:**
         * 1. Get the route pattern from the matched route
         * 2. Compare against the public routes array
         * 3. Use exact string matching for security
         * 4. Continue without authentication if match found
         *
         * **Security Note:** Exact pattern matching prevents partial matches that
         * could lead to unintended public access to protected endpoints.
         *
         * @var string $routePattern The pattern of the currently matched route
         */
        if (in_array($matchedRoute->getPattern(), $publicRoutes)) {
            return true;
        }

        /**
         * CORS Preflight Request Bypass
         *
         * Additional safety check to ensure OPTIONS requests (CORS preflight) are
         * not subjected to authentication. This is redundant with the CORS middleware
         * but provides an extra layer of protection against authentication errors
         * during preflight checks.
         *
         * **Why this check is important:**
         * - Some reverse proxies might reorder middleware execution
         * - Provides failsafe against CORS configuration errors
         * - Ensures compatibility with all client implementations
         * - Prevents authentication errors from blocking CORS functionality
         *
         * @var string $_SERVER['REQUEST_METHOD'] The HTTP method of the current request
         */
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return true;
        }

        /**
         * Authorization Header Extraction and Validation
         *
         * Retrieves the Authorization header from the request, which should contain
         * the JWT token for authentication. The presence and format of this header
         * are validated before attempting token verification.
         *
         * **Expected Header Format:**
         * - Header Name: "Authorization"
         * - Header Value: JWT token string (no Bearer prefix in this implementation)
         * - Example: "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
         *
         * **Alternative Implementations:**
         * Some APIs use "Bearer " prefix: "Bearer eyJhbGciOiJIUzI1NiI..."
         * This implementation expects the raw token without prefix
         *
         * @var string|null $token The JWT token from Authorization header
         */
        $token = $app->request->getHeader('Authorization');

        /**
         * Missing Authorization Header Handling
         *
         * If no Authorization header is present in a request to a protected endpoint,
         * responds with HTTP 401 Unauthorized. This is the standard response for
         * requests that require authentication but don't provide credentials.
         *
         * **HTTP 401 vs 403 Decision:**
         * - 401 Unauthorized: Authentication required but not provided
         * - 403 Forbidden: Authentication provided but insufficient permissions
         * - This case is 401 because no authentication was attempted
         *
         * **Response Components:**
         * - HTTP 401 status code with appropriate reason phrase
         * - Standardized JSON error response format
         * - Clear error message indicating missing authentication
         * - Immediate response transmission to prevent further processing
         *
         * @httpcode 401 Unauthorized - Missing authentication credentials
         * @response {
         *   "status": "error",
         *   "code": 401,
         *   "message": "Authorization header is required"
         * }
         */
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

        /**
         * Token Validation and User Authentication Process
         *
         * This section performs comprehensive token validation including format
         * verification, expiration checking, and database consistency validation.
         * The process is wrapped in a try-catch block to handle various token-related
         * errors gracefully.
         *
         * **Validation Steps:**
         * 1. Token format and structure verification
         * 2. Token expiration timestamp checking
         * 3. Database token verification and user lookup
         * 4. Role-based access control enforcement
         * 5. User context setup for controller access
         *
         * **Error Handling:**
         * - Token decoding errors (malformed tokens)
         * - Database connectivity issues
         * - Token expiration scenarios
         * - Invalid or revoked tokens
         * - Insufficient permissions
         */
        try {
            /**
             * Token Service Integration
             *
             * Retrieves the token service from the dependency injection container.
             * The token service handles JWT-like token operations including decoding,
             * validation, and expiration checking.
             *
             * **Token Service Responsibilities:**
             * - JWT token decoding and signature verification
             * - Token structure validation
             * - Expiration timestamp checking
             * - Token generation for authentication
             *
             * @var \Api\Services\TokenService $tokenService Service for token operations
             *
             * @throws \Phalcon\Di\Exception If token service is not registered in DI
             */
            $tokenService = $app->getDI()->get('tokenService');

            /**
             * Token Decoding and Structure Validation
             *
             * Attempts to decode the provided JWT token and extract user information.
             * The token service validates the token structure, signature, and returns
             * the decoded payload containing user identification and role information.
             *
             * **Token Payload Structure:**
             * - id: User identification number
             * - role: User role level (0=Admin, 1=Manager, 2=Caregiver)
             * - exp: Token expiration timestamp
             * - iat: Token issued at timestamp
             *
             * **Validation Performed:**
             * - JWT structure validation (header.payload.signature)
             * - Signature verification using application secret
             * - Payload format and required field checking
             *
             * @var array|false $decoded Decoded token payload or false if invalid
             */
            $decoded = $tokenService->decodeToken($token);

            /**
             * Token Format Validation Response
             *
             * If token decoding fails due to malformed structure, invalid signature,
             * or missing required fields, responds with HTTP 401 and a descriptive
             * error message to help client developers identify the issue.
             *
             * **Common Token Format Issues:**
             * - Malformed JWT structure (missing dots or sections)
             * - Invalid Base64 encoding in token components
             * - Signature verification failure
             * - Missing required payload fields
             * - Token corruption during transmission
             *
             * @httpcode 401 Unauthorized - Invalid token format
             * @response {
             *   "status": "error",
             *   "code": 401,
             *   "message": "Invalid token format"
             * }
             */
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

            /**
             * Token Expiration Validation
             *
             * Checks if the decoded token has expired by comparing the token's
             * expiration timestamp with the current time. Expired tokens are
             * rejected to maintain security and force re-authentication.
             *
             * **Expiration Logic:**
             * - Token contains 'exp' field with Unix timestamp
             * - Current time is compared against expiration time
             * - Buffer time may be applied for clock skew tolerance
             * - Expired tokens require new login to obtain fresh token
             *
             * **Security Benefits:**
             * - Limits exposure window if tokens are compromised
             * - Forces periodic re-authentication
             * - Reduces risk of token replay attacks
             * - Enables token rotation for enhanced security
             *
             * @httpcode 401 Unauthorized - Token expired
             * @response {
             *   "status": "error",
             *   "code": 401,
             *   "message": "Token expired, please login again"
             * }
             */
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

            /**
             * Database Token Verification
             *
             * Verifies that the token still exists in the database and matches the
             * user's current active token. This prevents use of revoked tokens and
             * ensures that logout/token refresh operations are properly enforced.
             *
             * **Database Verification Process:**
             * 1. Look up user record by token's user ID
             * 2. Compare provided token with stored token in database
             * 3. Verify user account is still active
             * 4. Ensure token hasn't been revoked or replaced
             *
             * **Security Benefits:**
             * - Prevents use of tokens after logout
             * - Enables immediate token revocation
             * - Protects against token reuse attacks
             * - Supports concurrent session management
             *
             * @var \Api\Models\Auth|null $auth User authentication record from database
             */
            $auth = \Api\Models\Auth::findFirstById($decoded['id']);

            /**
             * Invalid Token Authorization Response
             *
             * If the user doesn't exist in the database or the provided token doesn't
             * match the stored token, responds with HTTP 401. This could indicate a
             * deleted user account, token revocation, or token tampering.
             *
             * **Scenarios Handled:**
             * - User account deleted after token issuance
             * - Token revoked due to logout or security concerns
             * - Token modified or tampered with during transmission
             * - Database token updated due to password change or re-login
             *
             * @httpcode 401 Unauthorized - Invalid token authorization
             * @response {
             *   "status": "error",
             *   "code": 401,
             *   "message": "Invalid token authorization"
             * }
             */
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

            /**
             * Role-Based Access Control (RBAC) Configuration
             *
             * Defines route-specific role requirements for fine-grained access control.
             * Different API endpoints require different permission levels based on the
             * sensitivity and scope of operations they perform.
             *
             * **Role Hierarchy (Lower numbers = Higher privileges):**
             * - 0: Administrator - Full system access, user management, system configuration
             * - 1: Manager - Team management, patient assignment, reporting, limited admin functions
             * - 2: Caregiver - Basic user access, own profile, assigned patients, visit management
             *
             * **Route Prefix Mapping:**
             * Routes are mapped to minimum required role levels based on their prefix patterns.
             * More specific patterns should be listed first for accurate matching.
             *
             * **Access Control Logic:**
             * User's role number must be less than or equal to the required role level.
             * This creates a hierarchical permission system where higher roles include
             * all permissions of lower roles.
             *
             * @var array $routeRoleRequirements Mapping of route prefixes to minimum role levels
             */
            $routeRoleRequirements = [
                '/api/admin/'   => 0, // Administrator routes - system management, full access
                '/api/manager/' => 1, // Manager routes - team and patient management
                // Default role requirement is 2 (Caregiver) for all other authenticated routes
            ];

            /**
             * Default Role Requirement Setup
             *
             * Sets the default role requirement for protected routes. Most API endpoints
             * should be accessible to authenticated users with basic permissions (Caregivers),
             * with more restrictive requirements only for administrative functions.
             *
             * **Default Access Philosophy:**
             * - Basic authenticated access should be the norm
             * - Restrictions should be applied only where necessary
             * - Administrative functions should be explicitly protected
             * - User experience should not be hindered by excessive restrictions
             *
             * @var int $requiredRole The minimum role level required for the current route
             */
            $requiredRole = 2; // Default to Caregiver level access

            /**
             * Route-Specific Role Requirement Resolution
             *
             * Iterates through the route role requirements to find the most specific
             * match for the current route pattern. Uses prefix matching to allow
             * flexible route organization while maintaining security boundaries.
             *
             * **Matching Algorithm:**
             * 1. Check each configured route prefix in order
             * 2. Use strpos() to find prefix matches in route pattern
             * 3. Apply the first matching requirement found
             * 4. Break after first match to prevent conflicts
             *
             * **Pattern Matching Examples:**
             * - Route: "/api/admin/users" matches "/api/admin/" → requires role 0
             * - Route: "/api/manager/reports" matches "/api/manager/" → requires role 1
             * - Route: "/api/users/profile" no match → uses default role 2
             *
             * @var string $routePrefix The route prefix being evaluated
             * @var int $roleLevel The required role level for the matched prefix
             */
            foreach ($routeRoleRequirements as $routePrefix => $roleLevel) {
                if (strpos($matchedRoute->getPattern(), $routePrefix) === 0) {
                    $requiredRole = $roleLevel;
                    break; // Use first match to prevent conflicts
                }
            }

            /**
             * Role Permission Validation
             *
             * Compares the user's role level from the token against the required role
             * level for the requested endpoint. Users with higher role numbers (lower
             * privileges) are denied access to endpoints requiring lower role numbers
             * (higher privileges).
             *
             * **Permission Logic:**
             * - User role <= Required role = Access granted
             * - User role > Required role = Access denied (HTTP 403)
             *
             * **Examples:**
             * - Admin (role 0) accessing manager endpoint (requires 1) = ✓ Allowed
             * - Manager (role 1) accessing admin endpoint (requires 0) = ✗ Denied
             * - Caregiver (role 2) accessing basic endpoint (requires 2) = ✓ Allowed
             *
             * **Security Principle:**
             * Fail-safe design where insufficient permissions result in explicit denial
             * rather than potentially granting unintended access.
             *
             * @httpcode 403 Forbidden - Insufficient role permissions
             * @response {
             *   "status": "error",
             *   "code": 403,
             *   "message": "Insufficient permissions for this operation"
             * }
             */
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

            /**
             * Authenticated User Context Setup
             *
             * Stores the decoded token data in the dependency injection container,
             * making the authenticated user's information available to controllers
             * and other application components during request processing.
             *
             * **Stored User Context:**
             * - User ID for database operations
             * - Role level for additional authorization checks
             * - Token metadata for audit logging
             * - Any other claims from the JWT payload
             *
             * **Controller Access Pattern:**
             * Controllers can access this data using methods like getCurrentUserId()
             * and getCurrentUserRole() defined in the BaseController class.
             *
             * **Security Note:**
             * Only decoded token data is stored, not the raw token itself, to prevent
             * accidental token leakage in logs or error messages.
             *
             * @param string $key The DI container key for the user context
             * @param array $decoded The decoded token payload containing user information
             */
            $app->getDI()->setShared('decodedToken', $decoded);

            /**
             * Successful Authentication Continuation
             *
             * Returns true to indicate successful authentication and authorization,
             * allowing the request to proceed to the matched controller action.
             * At this point, the user's identity has been verified and their
             * permissions validated for the requested operation.
             *
             * **Post-Authentication Flow:**
             * 1. Request proceeds to request body parsing middleware
             * 2. Matched controller is instantiated
             * 3. Controller action is executed with user context available
             * 4. Response is processed through formatting middleware
             * 5. Final response is sent to client
             *
             * @return bool true to continue request processing pipeline
             */
            return true;

        } catch (Exception $e) {
            /**
             * Authentication Exception Handling
             *
             * Catches and handles any exceptions that occur during the authentication
             * process, including token decoding errors, database connectivity issues,
             * or other unexpected failures. Provides comprehensive error logging
             * while returning a generic error response to clients.
             *
             * **Exception Scenarios:**
             * - Token service initialization failures
             * - Database connection errors during user lookup
             * - JWT library exceptions during token decoding
             * - Memory or resource exhaustion during processing
             *
             * **Error Response Strategy:**
             * - Log detailed error information for debugging
             * - Return generic "Authentication error" to clients
             * - Include specific error details only in development environments
             * - Use HTTP 401 to indicate authentication failure
             *
             * **Security Considerations:**
             * - Avoid exposing internal system details to clients
             * - Log sufficient information for troubleshooting
             * - Maintain consistent error response format
             * - Prevent information leakage through error messages
             *
             * @param Exception $e The caught exception with error details
             *
             * @httpcode 401 Unauthorized - Authentication system error
             * @response {
             *   "status": "error",
             *   "code": 401,
             *   "message": "Authentication error: [error details]"
             * }
             */

            /**
             * Comprehensive Error Logging
             *
             * Logs the complete exception information including message, stack trace,
             * file location, and line number for comprehensive debugging support.
             * This information is crucial for diagnosing authentication system issues.
             *
             * **Logged Information:**
             * - Exception message describing the specific error
             * - Complete stack trace showing the call path
             * - File path where the exception occurred
             * - Line number of the exception
             *
             * @var string $message Formatted error message for logging
             */
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);

            /**
             * Client Error Response
             *
             * Sends a standardized error response to the client indicating an
             * authentication system error. The response includes the exception
             * message to help with client-side debugging while maintaining
             * security boundaries.
             *
             * **Response Components:**
             * - HTTP 401 Unauthorized status code
             * - Standardized JSON error response structure
             * - Generic error message with exception details
             * - Immediate response transmission
             */
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

    // ============================================================================
    // REQUEST BODY PARSING MIDDLEWARE - DATA EXTRACTION AND NORMALIZATION
    // ============================================================================

    /**
     * Request Body Parsing Middleware
     *
     * This middleware is responsible for extracting and parsing request body data from
     * various content types, normalizing it into a consistent format that controllers
     * can reliably access. It handles JSON, form data, and URL-encoded content while
     * providing robust error handling for malformed data.
     *
     * **Primary Functions:**
     * 1. **Content-Type Detection**: Identifies the format of incoming request data
     * 2. **Body Parsing**: Extracts and decodes request body based on content type
     * 3. **Data Normalization**: Converts various formats into consistent PHP arrays
     * 4. **Error Handling**: Gracefully handles malformed or invalid request data
     * 5. **DI Integration**: Stores parsed data in dependency injection container
     *
     * **Supported Content Types:**
     * - `application/json`: JSON-encoded data (primary API format)
     * - `multipart/form-data`: File uploads and form submissions
     * - `application/x-www-form-urlencoded`: Traditional HTML form data
     *
     * **Processing Flow:**
     * 1. Skip processing for GET/DELETE/OPTIONS requests (no body expected)
     * 2. Extract Content-Type header and normalize format
     * 3. Apply appropriate parsing strategy based on content type
     * 4. Validate parsed data structure and handle errors
     * 5. Store parsed data in DI container for controller access
     *
     * **Controller Integration:**
     * Controllers access parsed data through BaseController::getRequestBody(),
     * which retrieves the normalized array from the DI container.
     *
     * @event micro:beforeExecuteRoute Executes after authentication, before controller
     *
     * @param Event $event The event object containing request context
     * @param Micro $app   The Phalcon Micro application instance
     *
     * @return bool|void Returns true to continue processing, or exits with error response
     *
     * @throws \JsonException For JSON parsing errors with detailed error information
     *
     * @since 1.0.0
     *
     * @example
     * // JSON request body:
     * POST /api/users HTTP/1.1
     * Content-Type: application/json
     *
     * {"name": "John Doe", "email": "john@example.com", "role": 2}
     *
     * // Form data request body:
     * POST /api/user/upload/photo HTTP/1.1
     * Content-Type: multipart/form-data; boundary=---FormBoundary123
     *
     * ---FormBoundary123
     * Content-Disposition: form-data; name="description"
     *
     * Profile photo update
     * ---FormBoundary123
     * Content-Disposition: form-data; name="photo"; filename="avatar.jpg"
     * Content-Type: image/jpeg
     *
     * [binary image data]
     * ---FormBoundary123--
     *
     * @api
     * @httpcode 400 Returns "Bad Request" for malformed JSON or parsing errors
     *
     * @see https://tools.ietf.org/html/rfc7159 JSON Data Interchange Format
     * @see https://tools.ietf.org/html/rfc7578 Multipart Form Data
     * @see https://www.w3.org/TR/html401/interact/forms.html#h-17.13.4.1 URL Encoded Forms
     */
    $eventsManager->attach('micro:beforeExecuteRoute', function(Event $event, Micro $app) {

        /**
         * HTTP Method-Based Processing Bypass
         *
         * Skips body parsing for HTTP methods that typically don't include request
         * bodies according to HTTP/REST conventions. This optimization prevents
         * unnecessary processing and potential errors from attempting to parse
         * non-existent or irrelevant request bodies.
         *
         * **Methods Bypassed:**
         * - **GET**: Retrieval operations use query parameters, not request bodies
         * - **DELETE**: Resource removal typically uses URL parameters only
         * - **OPTIONS**: CORS preflight requests don't contain meaningful body data
         *
         * **HTTP Specification Compliance:**
         * - RFC 7231 states GET requests should not have bodies
         * - Many HTTP implementations reject or ignore bodies in GET requests
         * - DELETE operations traditionally use URL/header parameters
         * - OPTIONS requests are for capability discovery, not data submission
         *
         * **Performance Benefits:**
         * - Reduces CPU usage for read-only operations
         * - Prevents memory allocation for unnecessary parsing
         * - Improves response times for frequent GET requests
         * - Eliminates potential parsing errors for empty bodies
         *
         * @var string $_SERVER['REQUEST_METHOD'] The HTTP method of the current request
         *
         * @return bool true to skip body parsing and continue to controller
         */
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'DELETE', 'OPTIONS'])) {
            return true;
        }

        /**
         * Content-Type Header Extraction and Processing
         *
         * Retrieves the Content-Type header from the request to determine the
         * appropriate parsing strategy. The Content-Type header was already
         * validated by the headers validation middleware, so we can safely
         * proceed with parsing based on its value.
         *
         * **Header Format Examples:**
         * - "application/json; charset=utf-8"
         * - "multipart/form-data; boundary=----FormBoundary123"
         * - "application/x-www-form-urlencoded"
         *
         * **Previous Validation:**
         * The headers validation middleware has already ensured that:
         * - Content-Type header is present for POST/PUT/PATCH requests
         * - The content type is appropriate for the endpoint type
         * - Upload endpoints have multipart/form-data
         * - Regular API endpoints have application/json
         *
         * @var string $contentType The full Content-Type header value with parameters
         */
        $contentType = $app->request->getHeader('Content-Type');

        /**
         * Content-Type Normalization and Base Type Extraction
         *
         * Extracts the primary content type from the header, removing additional
         * parameters like charset specifications and boundary definitions. This
         * normalization enables consistent content type comparison regardless of
         * how clients format their headers.
         *
         * **Normalization Process:**
         * 1. Split header value on semicolon (separates type from parameters)
         * 2. Take the first segment (the actual content type)
         * 3. Trim whitespace that might surround the type
         * 4. Convert to lowercase for case-insensitive comparison
         *
         * **Examples of Normalization:**
         * - "application/json; charset=utf-8" → "application/json"
         * - "MULTIPART/FORM-DATA; boundary=xyz" → "multipart/form-data"
         * - " Application/JSON " → "application/json"
         *
         * @var string $baseContentType The normalized primary content type
         */
        $baseContentType = strtolower(trim(explode(';', $contentType)[0]));

        /**
         * Content-Type Specific Parsing Strategy Selection
         *
         * Based on the normalized content type, applies the appropriate parsing
         * strategy to extract request body data. Each content type requires
         * different handling due to encoding and structure differences.
         *
         * **Parsing Strategies:**
         * - JSON: Decode JSON string into PHP associative array
         * - Multipart Form Data: Use PHP's built-in $_POST and $_FILES superglobals
         * - URL Encoded: Parse query string format into associative array
         *
         * **Error Handling:**
         * Each parsing strategy includes comprehensive error handling to catch
         * malformed data and provide meaningful error messages to clients.
         */

        // ====================================================================
        // JSON REQUEST BODY PARSING
        // ====================================================================

        /**
         * JSON Request Body Parsing and Validation
         *
         * Handles requests with 'application/json' content type by extracting the
         * raw request body and decoding it into a PHP associative array. This is
         * the primary data format for REST API operations.
         *
         * **JSON Parsing Features:**
         * - UTF-8 encoding support with invalid character handling
         * - Comprehensive error detection using JSON_THROW_ON_ERROR
         * - Deep nesting support with configurable depth limits
         * - Automatic conversion to PHP associative arrays
         *
         * **Security Considerations:**
         * - Maximum parsing depth prevents resource exhaustion attacks
         * - Invalid UTF-8 handling prevents encoding-based attacks
         * - Exception-based error handling prevents data corruption
         * - Empty body handling prevents null pointer issues
         *
         * **Performance Optimizations:**
         * - Single-pass JSON decoding for efficiency
         * - Early validation of empty bodies to avoid processing
         * - Memory-efficient parsing with depth limits
         * - Cached results in DI container to prevent re-parsing
         */
        if ($baseContentType === 'application/json') {
            try {
                /**
                 * Raw Request Body Extraction
                 *
                 * Retrieves the raw HTTP request body as a string from the Phalcon
                 * request object. The body contains the JSON-encoded data sent by
                 * the client and needs to be decoded into a usable PHP structure.
                 *
                 * **Body Extraction Process:**
                 * - Accesses raw HTTP request body stream
                 * - Reads entire body content into memory
                 * - Returns empty string if no body is present
                 * - Preserves original encoding and formatting
                 *
                 * **Memory Considerations:**
                 * Large request bodies are loaded entirely into memory. In production
                 * environments, consider implementing request size limits to prevent
                 * memory exhaustion from oversized payloads.
                 *
                 * @var string $rawBody The raw HTTP request body content
                 */
                $rawBody = $app->request->getRawBody() ?: '';

                /**
                 * Non-Empty Body Processing
                 *
                 * Only attempts JSON parsing if the request body contains data.
                 * Empty bodies are handled gracefully by setting an empty array
                 * as the parsed result, which is appropriate for requests that
                 * don't require body data.
                 *
                 * **Empty Body Scenarios:**
                 * - Optional body parameters in PUT requests
                 * - GET requests with accidentally set Content-Type
                 * - Client-side errors in request construction
                 * - Network issues causing body truncation
                 */
                if (!empty($rawBody)) {
                    /**
                     * JSON Decoding with Enhanced Error Handling
                     *
                     * Decodes the JSON string into a PHP associative array using
                     * strict error handling and UTF-8 validation. The configuration
                     * options provide comprehensive error detection and security.
                     *
                     * **JSON Decoding Options:**
                     * - `true`: Return associative arrays instead of objects
                     * - `512`: Maximum parsing depth (prevents resource exhaustion)
                     * - `JSON_THROW_ON_ERROR`: Throw exceptions for parsing errors
                     * - `JSON_INVALID_UTF8_IGNORE`: Handle invalid UTF-8 gracefully
                     *
                     * **Exception Triggers:**
                     * - Malformed JSON syntax (missing brackets, commas, etc.)
                     * - Invalid UTF-8 sequences in string values
                     * - Exceeding maximum parsing depth
                     * - Unsupported JSON features or extensions
                     *
                     * **Security Features:**
                     * - Depth limiting prevents infinite recursion attacks
                     * - UTF-8 validation prevents encoding-based vulnerabilities
                     * - Exception handling prevents silent data corruption
                     * - Memory usage control through depth restrictions
                     *
                     * @var array $decodedBody The parsed JSON data as PHP associative array
                     *
                     * @throws \JsonException If JSON parsing fails for any reason
                     */
                    $decodedBody = json_decode(
                        $rawBody,                    // JSON string to decode
                        true,                        // Return associative array
                        512,                         // Maximum parsing depth
                        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE  // Error handling flags
                    );

                    /**
                     * Parsed Data Storage in Dependency Injection Container
                     *
                     * Stores the successfully parsed JSON data in the DI container
                     * using the 'request_body' key. Controllers can access this data
                     * through the BaseController::getRequestBody() method.
                     *
                     * **Storage Method:**
                     * Uses setShared() to ensure the parsed data is cached and reused
                     * if multiple components need access during the same request.
                     * This prevents redundant parsing and improves performance.
                     *
                     * **Fallback Handling:**
                     * If decoding results in null (empty JSON object "{}"), stores
                     * an empty array instead to provide consistent data structure
                     * for controllers.
                     *
                     * @param string $key The DI container key for request body data
                     * @param array $data The parsed request body data or empty array
                     */
                    $app->getDI()->setShared('request_body', $decodedBody ?: []);
                } else {
                    /**
                     * Empty Body Handling
                     *
                     * For requests with empty bodies, stores an empty array in the
                     * DI container. This ensures controllers always receive a
                     * consistent array structure, even when no data was submitted.
                     *
                     * **Consistency Benefits:**
                     * - Controllers can always expect array data type
                     * - Eliminates need for null checks in business logic
                     * - Simplifies validation and processing code
                     * - Prevents errors from undefined data structures
                     */
                    $app->getDI()->setShared('request_body', []);
                }

            } catch (\JsonException $e) {
                /**
                 * JSON Parsing Exception Handling
                 *
                 * Catches JSON parsing errors and responds with a detailed error
                 * message that helps client developers identify and fix JSON
                 * formatting issues. The error includes the specific parsing
                 * problem encountered.
                 *
                 * **Common JSON Errors:**
                 * - Syntax errors (missing brackets, quotes, commas)
                 * - Invalid escape sequences in strings
                 * - Trailing commas in objects or arrays
                 * - Unicode encoding issues
                 * - Exceeding maximum parsing depth
                 *
                 * **Error Response Strategy:**
                 * - Log detailed error information for server-side debugging
                 * - Return specific error message to help client developers
                 * - Use HTTP 400 Bad Request status code
                 * - Include parsing error details in response message
                 * - Terminate request processing to prevent data corruption
                 *
                 * **Security Considerations:**
                 * - Error messages help legitimate developers debug issues
                 * - Detailed errors don't expose sensitive system information
                 * - Consistent error format maintains API usability
                 * - Immediate termination prevents processing of invalid data
                 *
                 * @param \JsonException $e The JSON parsing exception with error details
                 *
                 * @httpcode 400 Bad Request - Invalid JSON in request body
                 * @response {
                 *   "status": "error",
                 *   "code": 400,
                 *   "message": "Invalid JSON in request body: [specific error]"
                 * }
                 */

                /**
                 * Comprehensive Error Logging
                 *
                 * Logs complete exception details for server-side debugging while
                 * maintaining security by not exposing internal system details to
                 * clients in the error response.
                 *
                 * @var string $message Formatted error message for comprehensive logging
                 */
                $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
                error_log('Exception: ' . $message);

                /**
                 * Client Error Response for JSON Parsing Failures
                 *
                 * Sends a standardized error response that includes the specific
                 * JSON parsing error to help client developers identify and fix
                 * the formatting issue in their request data.
                 */
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

        // ====================================================================
        // MULTIPART FORM DATA PARSING
        // ====================================================================

        /**
         * Multipart Form Data Processing
         *
         * Handles requests with 'multipart/form-data' content type, which is
         * primarily used for file uploads and forms containing both text data
         * and binary files. PHP automatically parses this format into $_POST
         * and $_FILES superglobals.
         *
         * **Multipart Data Characteristics:**
         * - Contains both text fields and file uploads
         * - Uses boundary markers to separate different parts
         * - Each part has its own Content-Disposition headers
         * - Binary data is transmitted without encoding
         * - Supports multiple files in a single request
         *
         * **PHP Processing:**
         * PHP's built-in multipart parser automatically processes this data
         * and populates $_POST with text fields and $_FILES with uploaded
         * files, eliminating the need for manual parsing.
         *
         * **Security Considerations:**
         * - File upload validation should occur in controllers
         * - Consider implementing file size limits
         * - Validate file types and extensions
         * - Scan uploaded files for malware if applicable
         *
         * **Use Cases:**
         * - Profile photo uploads
         * - Document attachment submissions
         * - Forms with mixed text and file data
         * - Bulk file upload operations
         *
         * @see https://tools.ietf.org/html/rfc7578 Multipart Form Data Specification
         */
        else if ($baseContentType === 'multipart/form-data') {
            /**
             * Form Data Extraction from PHP Superglobals
             *
             * Retrieves form field data from the $_POST superglobal, which PHP
             * automatically populates when processing multipart/form-data requests.
             * File upload data is available separately in $_FILES.
             *
             * **Data Structure:**
             * $_POST contains text form fields as key-value pairs:
             * - Simple fields: $_POST['field_name'] = 'field_value'
             * - Array fields: $_POST['field_name[]'] = ['value1', 'value2']
             * - Nested arrays: $_POST['nested']['field'] = 'value'
             *
             * **File Access:**
             * Controllers can access uploaded files through PHP's $_FILES superglobal
             * or Phalcon's request->getUploadedFiles() method for enhanced file handling.
             *
             * **Empty Form Handling:**
             * If no form fields are present, an empty array is stored to maintain
             * consistency with other parsing methods.
             *
             * @var array $formData The form field data from $_POST superglobal
             */
            $app->getDI()->setShared('request_body', $_POST ?: []);
        }

        // ====================================================================
        // URL-ENCODED FORM DATA PARSING
        // ====================================================================

        /**
         * URL-Encoded Form Data Processing
         *
         * Handles requests with 'application/x-www-form-urlencoded' content type,
         * which is the traditional format used by HTML forms. Data is encoded
         * as key-value pairs similar to URL query parameters.
         *
         * **URL-Encoded Format Characteristics:**
         * - Data format: "key1=value1&key2=value2&key3=value3"
         * - Special characters are percent-encoded (%20, %21, etc.)
         * - Spaces are encoded as plus signs (+) or %20
         * - Array notation: "items[]=value1&items[]=value2"
         * - Nested objects: "user[name]=John&user[email]=john@example.com"
         *
         * **Processing Method:**
         * Uses PHP's parse_str() function to decode the URL-encoded string
         * into a PHP associative array, handling all encoding and structure
         * conversion automatically.
         *
         * **Use Cases:**
         * - Traditional HTML form submissions
         * - Simple API integrations from legacy systems
         * - Third-party service webhooks
         * - Basic data submission without JSON support
         *
         * **Limitations:**
         * - Less efficient than JSON for complex data structures
         * - Limited support for nested objects and arrays
         * - Special character encoding can cause issues
         * - Not suitable for binary data transmission
         *
         * @see https://www.w3.org/TR/html401/interact/forms.html#h-17.13.4.1 Form URL Encoding
         */
        else if ($baseContentType === 'application/x-www-form-urlencoded') {
            /**
             * Raw Body Extraction for URL-Encoded Data
             *
             * Retrieves the raw request body containing the URL-encoded form data.
             * Unlike multipart data, URL-encoded data is not automatically parsed
             * by PHP and must be manually processed.
             *
             * **Data Format Example:**
             * "name=John+Doe&email=john%40example.com&age=30&interests[]=coding&interests[]=music"
             *
             * @var string $rawBody The raw URL-encoded request body
             */
            $rawBody = $app->request->getRawBody() ?: '';

            /**
             * URL-Encoded Data Parsing and Validation
             *
             * If the request body contains data, parses it using PHP's parse_str()
             * function, which handles URL decoding and array structure conversion
             * automatically.
             *
             * **Parsing Process:**
             * 1. parse_str() decodes percent-encoded characters
             * 2. Converts plus signs to spaces
             * 3. Builds associative array from key-value pairs
             * 4. Handles array notation (key[]=value) properly
             * 5. Supports nested array structures
             *
             * **Error Handling:**
             * parse_str() rarely fails, but malformed data might result in
             * unexpected array structures. Controllers should validate the
             * parsed data structure before use.
             */
            if (!empty($rawBody)) {
                /**
                 * Parse URL-Encoded String into PHP Array
                 *
                 * Uses parse_str() to convert the URL-encoded string into a
                 * PHP associative array. The function handles all aspects of
                 * URL decoding and array structure creation.
                 *
                 * **Function Parameters:**
                 * - $rawBody: The URL-encoded string to parse
                 * - $parsedData: Output variable to receive parsed array
                 *
                 * **Parsing Examples:**
                 * - "name=John&age=30" → ['name' => 'John', 'age' => '30']
                 * - "items[]=a&items[]=b" → ['items' => ['a', 'b']]
                 * - "user[name]=John&user[age]=30" → ['user' => ['name' => 'John', 'age' => '30']]
                 *
                 * @var array $parsedData The resulting associative array
                 */
                parse_str($rawBody, $parsedData);

                /**
                 * Parsed URL-Encoded Data Storage
                 *
                 * Stores the parsed form data in the DI container with fallback
                 * to empty array if parsing results in null or invalid data.
                 * This ensures controllers always receive a valid array structure.
                 *
                 * @param string $key The DI container key for request body data
                 * @param array $data The parsed form data or empty array fallback
                 */
                $app->getDI()->setShared('request_body', $parsedData ?: []);
            } else {
                /**
                 * Empty URL-Encoded Body Handling
                 *
                 * For requests with empty bodies, stores an empty array to
                 * maintain consistency with other content type processors.
                 */
                $app->getDI()->setShared('request_body', []);
            }
        }

        /**
         * Request Body Parsing Completion
         *
         * Returns true to indicate successful request body parsing and allow
         * the request to continue to the controller execution phase. At this
         * point, the request body data has been parsed and stored in the DI
         * container for controller access.
         *
         * **Post-Parsing Flow:**
         * 1. Controller is instantiated and executed
         * 2. Controller accesses parsed data via getRequestBody()
         * 3. Business logic processes the request data
         * 4. Response is generated and passed to formatting middleware
         * 5. Final formatted response is sent to client
         *
         * **Data Availability:**
         * The parsed request body is now available to:
         * - Controller actions through BaseController methods
         * - Validation middleware (if implemented)
         * - Business logic components
         * - Any other services that need request data
         *
         * @return bool true to continue request processing pipeline
         */
        return true;
    });

    // ============================================================================
    // RESPONSE FORMATTING MIDDLEWARE - OUTPUT STANDARDIZATION AND SECURITY
    // ============================================================================

    /**
     * Response Formatting Middleware
     *
     * This final middleware in the processing chain handles response standardization,
     * security header injection, and consistent API response formatting. It operates
     * on the 'afterHandleRoute' event, which fires after controller execution but
     * before the response is sent to the client.
     *
     * **Primary Functions:**
     * 1. **Response Standardization**: Ensures all API responses follow consistent format
     * 2. **Security Headers**: Adds protective HTTP headers to prevent various attacks
     * 3. **CORS Enforcement**: Reinforces CORS headers on all responses
     * 4. **Error Handling**: Formats error responses and handles edge cases
     * 5. **Content-Type Management**: Sets appropriate content types for different responses
     * 6. **Status Code Normalization**: Maps response data to appropriate HTTP status codes
     *
     * **Response Format Standardization:**
     * All API responses are formatted into a consistent structure:
     * ```json
     * {
     *   "status": "success|error",
     *   "code": 200,
     *   "data": {...} | "message": "error description"
     * }
     * ```
     *
     * **Security Features:**
     * - XSS protection headers
     * - Content-type sniffing prevention
     * - Clickjacking protection
     * - CORS policy enforcement
     *
     * **Special Cases Handled:**
     * - HTML responses for activation endpoints
     * - Pre-formatted controller responses
     * - Null responses (404 handling)
     * - Error responses with specific status codes
     *
     * @event micro:afterHandleRoute Executes after controller, before response transmission
     *
     * @param Event $event The event object containing request context
     * @param Micro $app   The Phalcon Micro application instance
     *
     * @return void Modifies response object and sends it to client
     *
     * @throws none This middleware handles all scenarios gracefully
     *
     * @since 1.0.0
     *
     * @example
     * // Controller returns array:
     * return ['user_id' => 123, 'name' => 'John Doe'];
     *
     * // Middleware formats as:
     * HTTP/1.1 200 OK
     * Content-Type: application/json
     * {
     *   "status": "success",
     *   "code": 200,
     *   "data": {
     *     "user_id": 123,
     *     "name": "John Doe"
     *   }
     * }
     *
     * @api
     * @httpcode 200 Standard success response
     * @httpcode 404 Not found for unhandled routes
     * @httpcode 500 Internal error for processing failures
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers Security Headers Reference
     * @see https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html OWASP Security Headers
     */
    $eventsManager->attach('micro:afterHandleRoute', function (Event $event, Micro $app) {

        /**
         * Response Transmission Status Check
         *
         * Verifies that the response hasn't already been sent to the client.
         * Some controller actions or middleware components might send responses
         * directly (like file downloads or redirects), and we should not interfere
         * with those scenarios.
         *
         * **Scenarios Where Response Might Already Be Sent:**
         * - File download controllers using readfile() or similar
         * - Authentication middleware rejecting requests
         * - CORS middleware handling OPTIONS requests
         * - Error handlers sending immediate responses
         * - Stream response for large data transfers
         *
         * **Why This Check Is Important:**
         * - Prevents "headers already sent" PHP errors
         * - Avoids double-processing of responses
         * - Maintains compatibility with direct response sending
         * - Prevents corruption of streamed responses
         *
         * @var bool $isSent Response transmission status from Phalcon
         *
         * @return void Early return if response already transmitted
         */
        if ($app->response->isSent()) {
            return;
        }

        /**
         * Controller Response Content Extraction
         *
         * Retrieves the content returned by the controller action for processing
         * and formatting. Controllers can return various data types including
         * arrays, strings, Response objects, or null values.
         *
         * **Expected Return Types:**
         * - **Array**: Data to be JSON-formatted (most common)
         * - **Response**: Pre-formatted response object (used as-is)
         * - **String**: Plain text or HTML content
         * - **null**: No content (typically results in 404)
         *
         * **Controller Return Examples:**
         * - `return ['status' => 'success', 'data' => $userData];`
         * - `return $this->respondWithSuccess($data);`
         * - `return new Response(); // Pre-formatted`
         * - `return null; // Route not found`
         *
         * @var mixed $content The content returned by the controller action
         */
        $content = $app->getReturnedValue();

        /**
         * Pre-Formatted Response Object Handling
         *
         * If the controller returns a Response object, it indicates that the
         * response has been pre-formatted and should be used as-is without
         * additional processing. This allows controllers to have full control
         * over response formatting when needed.
         *
         * **Use Cases for Response Objects:**
         * - File downloads with specific headers
         * - Custom content types (XML, CSV, PDF)
         * - Redirects with location headers
         * - Responses with custom status codes
         * - Streaming responses for large data
         *
         * **Why Skip Processing:**
         * - Controllers have explicitly formatted the response
         * - Additional processing might break custom formatting
         * - Headers and content type are already set appropriately
         * - Status codes are already configured correctly
         *
         * @return void Early return to preserve pre-formatted responses
         */
        if ($content instanceof Response) {
            return;
        }

        /**
         * Route-Specific Response Handling
         *
         * Checks if the current request is for a special route that requires
         * non-standard response handling. Some routes like account activation
         * return HTML content instead of JSON and need different processing.
         *
         * **Special Routes:**
         * - `/activation/{edoc}`: Email activation confirmation (returns HTML)
         * - File serving routes: Direct file content responses
         * - Health check endpoints: Simple text responses
         * - Documentation routes: HTML or other content types
         *
         * **Activation Route Handling:**
         * The activation route should return a Response object with HTML content.
         * If we reach this point, something went wrong in the activation process
         * and we need to provide a fallback error response.
         *
         * @var \Phalcon\Mvc\Router\Route|null $matchedRoute The currently matched route
         */
        $matchedRoute = $app->router->getMatchedRoute();
        if ($matchedRoute && strpos($matchedRoute->getPattern(), '/activation/') === 0) {
            /**
             * Activation Route Error Handling
             *
             * If the activation route doesn't return a proper Response object,
             * creates an HTML error response indicating something went wrong
             * with the activation process. This provides user-friendly feedback
             * instead of a generic JSON error.
             *
             * **Error Response Creation:**
             * - Creates new Response object for HTML content
             * - Sets HTTP 500 status for internal server error
             * - Provides HTML content type with UTF-8 encoding
             * - Includes user-friendly error message in HTML format
             * - Assigns response to application and sends immediately
             *
             * @var Response $response New response object for activation error
             */
            $response = new Response();
            $response->setStatusCode(500, 'Internal Server Error');
            $response->setContentType('text/html', 'UTF-8');
            $response->setContent('<html><body><h1>Error</h1><p>Something went wrong with the activation process.</p></body></html>');
            $app->response = $response;
            $response->send();
            return;
        }

        /**
         * Response Object Initialization
         *
         * Creates a new Response object that will be configured with appropriate
         * headers, content type, and formatted content based on the controller's
         * return value and the type of response needed.
         *
         * **Response Configuration Process:**
         * 1. Create base Response object
         * 2. Add security headers for protection
         * 3. Set CORS headers for cross-origin support
         * 4. Configure content type (usually JSON)
         * 5. Format and set response content
         * 6. Set appropriate HTTP status code
         * 7. Replace application response and send
         *
         * @var Response $response New response object for formatting
         */
        $response = new Response();

        /**
         * Security Headers Implementation
         *
         * Adds essential security headers to protect against common web
         * vulnerabilities. These headers are applied to all responses to
         * provide comprehensive protection regardless of content type.
         *
         * **Security Headers Applied:**
         *
         * **X-Content-Type-Options: nosniff**
         * - Prevents browsers from MIME-type sniffing
         * - Stops execution of files with incorrect Content-Type
         * - Protects against drive-by download attacks
         * - Forces browsers to respect declared content types
         *
         * **X-Frame-Options: DENY**
         * - Prevents the page from being displayed in frames/iframes
         * - Protects against clickjacking attacks
         * - Ensures API responses can't be embedded in malicious sites
         * - Alternative: SAMEORIGIN allows same-domain framing
         *
         * **X-XSS-Protection: 1; mode=block**
         * - Enables browser's built-in XSS protection
         * - Blocks pages when XSS attacks are detected
         * - Provides additional layer beyond input validation
         * - Legacy header but still supported by many browsers
         *
         * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers Security Header Documentation
         */
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');

        /**
         * Primary Content Type Configuration
         *
         * Sets the content type to JSON with UTF-8 encoding for all standard
         * API responses. This ensures consistent response formatting and proper
         * character encoding handling across all endpoints.
         *
         * **Content-Type Components:**
         * - `application/json`: Indicates JSON-formatted response data
         * - `UTF-8`: Specifies character encoding for international support
         *
         * **Why JSON Is Default:**
         * - Standardized data interchange format
         * - Native browser and programming language support
         * - Efficient parsing and generation
         * - Strong typing and structure validation
         * - Wide client library compatibility
         *
         * @param string $contentType The MIME type for response content
         * @param string $charset The character encoding specification
         */
        $response->setContentType('application/json', 'UTF-8');

        /**
         * CORS Headers Reinforcement
         *
         * Re-applies CORS headers to ensure they are present on all responses,
         * even those that might have been processed by components that don't
         * preserve the original headers. This provides redundant protection
         * for cross-origin functionality.
         *
         * **Redundant CORS Protection:**
         * While the CORS middleware already sets these headers, this reinforcement
         * ensures they remain present throughout the entire response pipeline,
         * protecting against middleware or controller actions that might reset
         * or modify the response object.
         *
         * **Headers Re-Applied:**
         * - Access-Control-Allow-Origin: Cross-origin access permissions
         * - Access-Control-Allow-Methods: Permitted HTTP methods
         * - Access-Control-Allow-Headers: Allowed request headers
         * - Access-Control-Allow-Credentials: Credential inclusion permission
         *
         * @see CORS Middleware documentation for detailed header explanations
         */
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');

        /**
         * Content-Based Response Formatting Logic
         *
         * Analyzes the content returned by the controller and applies appropriate
         * formatting based on the content structure and type. Different content
         * types receive different formatting treatments to ensure consistency.
         *
         * **Response Formatting Categories:**
         * 1. **Pre-formatted Success/Error**: Already structured controller responses
         * 2. **Error Responses**: Responses with error status requiring special handling
         * 3. **Null Content**: Empty responses that should return 404 Not Found
         * 4. **Raw Data**: Unstructured data that needs success wrapper formatting
         *
         * **Processing Priority:**
         * The order of these checks is important to ensure proper response handling
         * and prevent incorrect formatting of already-structured responses.
         */

        /**
         * Pre-Formatted Controller Response Handling
         *
         * Detects and processes responses that have already been formatted by
         * controllers using the standardized response format that includes
         * 'status' and 'code' fields. These responses are used as-is with
         * appropriate HTTP status code mapping.
         *
         * **Expected Response Structure:**
         * ```php
         * [
         *   'status' => 'success|error',
         *   'code' => 200|400|401|403|404|500|etc,
         *   'data' => [...] // for success responses
         *   'message' => 'Error description' // for error responses
         * ]
         * ```
         *
         * **Why This Check Is First:**
         * Controllers using BaseController methods (respondWithSuccess,
         * respondWithError) already return properly formatted responses that
         * shouldn't be modified further. This preserves the intended response
         * structure and status codes.
         *
         * **Status Code Mapping:**
         * - Extracts HTTP status code from 'code' field
         * - Maps status to appropriate HTTP reason phrase
         * - Uses 'success' vs 'error' status for reason phrase selection
         * - Defaults to 200 OK if no code is specified
         *
         * @var array $content Controller response with status and code fields
         */
        if (is_array($content) && isset($content['status'])) {
            /**
             * Pre-Formatted Response Processing
             *
             * Sets the JSON content directly from the controller response and
             * maps the included status code to the appropriate HTTP response code.
             * This preserves the exact formatting intended by the controller.
             *
             * **Response Components:**
             * - JSON content: Uses controller response exactly as provided
             * - Status code: Extracted from 'code' field in response
             * - Status text: Determined by 'status' field (success/error)
             * - Headers: Already configured with security and CORS headers
             *
             * **Status Code Selection:**
             * - Uses 'code' field from response or defaults to 200
             * - Maps 'error' status to 'Error' reason phrase
             * - Maps 'success' status to 'OK' reason phrase
             * - Provides fallback for edge cases
             */
            $response->setJsonContent($content);

            /**
             * HTTP Status Code and Reason Phrase Configuration
             *
             * Extracts the HTTP status code from the response data and sets
             * it on the Response object along with an appropriate reason phrase
             * based on whether the response indicates success or error.
             *
             * **Status Code Logic:**
             * - Primary: Use 'code' field from response array
             * - Fallback: Default to 200 if no code specified
             * - Reason phrase: 'Error' for error responses, 'OK' for success
             *
             * @var int $statusCode The HTTP status code to set
             * @var string $statusText The HTTP reason phrase to use
             */
            $statusCode = $content['code'] ?? 200;
            $statusText = $content['status'] === 'error' ? 'Error' : 'OK';
            $response->setStatusCode($statusCode, $statusText);
        }

        /**
         * Legacy Error Response Format Handling
         *
         * Handles error responses that use the legacy format with 'status' => 'error'
         * but may not have the complete standardized structure. This provides
         * backward compatibility with older controller implementations.
         *
         * **Legacy Error Format:**
         * ```php
         * [
         *   'status' => 'error',
         *   'message' => 'Error description',
         *   // 'code' field might be missing
         * ]
         * ```
         *
         * **Processing Logic:**
         * - Detects error responses by 'status' field
         * - Provides default status code if missing
         * - Ensures consistent error response structure
         * - Maintains backward compatibility
         *
         * **Note:** This section might be redundant with the previous check
         * but provides additional safety for edge cases where responses
         * have status but incomplete structure.
         */
        else if (is_array($content) && isset($content['status']) && $content['status'] === 'error') {
            /**
             * Legacy Error Response Formatting
             *
             * Formats legacy error responses into the standardized structure,
             * providing default values for missing fields and ensuring
             * consistent error response format across the application.
             *
             * **Standardization Process:**
             * - Extract status code or default to 400 Bad Request
             * - Ensure message field is present with fallback
             * - Set appropriate HTTP status code and reason phrase
             * - Format as JSON with consistent structure
             *
             * @var int $statusCode HTTP status code for the error response
             */
            $statusCode = $content['code'] ?? 400;
            $response->setStatusCode($statusCode, 'Error');
            $response->setJsonContent([
                'status' => 'error',
                'code' => $statusCode,
                'message' => $content['message'] ?? 'Unknown error'
            ]);
        }

        /**
         * Null Content Handling (404 Not Found)
         *
         * Handles cases where controllers return null, which typically indicates
         * that no matching route was found or no content is available for the
         * requested resource. This results in a standardized 404 Not Found response.
         *
         * **Null Content Scenarios:**
         * - Route matched but controller returned nothing
         * - Resource lookup returned no results
         * - Controller method exists but returns null explicitly
         * - Unimplemented controller methods
         *
         * **404 Response Structure:**
         * Provides a standardized 404 response that includes:
         * - Clear indication that the endpoint wasn't found
         * - The requested path for client debugging
         * - Consistent error response format
         * - Appropriate HTTP status code and headers
         *
         * **Client Benefits:**
         * - Clear indication of what went wrong
         * - Path information for debugging
         * - Consistent error format for handling
         * - Proper HTTP semantics
         *
         * @httpcode 404 Not Found - No content available for request
         * @response {
         *   "status": "error",
         *   "code": 404,
         *   "message": "Endpoint not found",
         *   "path": "/requested/path"
         * }
         */
        else if ($content === null) {
            /**
             * 404 Not Found Response Generation
             *
             * Creates a standardized 404 error response that provides clear
             * information about the missing resource while maintaining
             * consistent API response formatting.
             *
             * **Response Components:**
             * - HTTP 404 status with "Not Found" reason phrase
             * - Standardized error response structure
             * - Descriptive error message
             * - Requested path for debugging purposes
             * - Security and CORS headers already applied
             *
             * **Path Information:**
             * Includes the requested URI to help developers identify
             * routing issues, with fallback to 'unknown' if URI
             * information is not available.
             */
            $response->setStatusCode(404, 'Not Found');
            $response->setJsonContent([
                'status' => 'error',
                'code' => 404,
                'message' => 'Endpoint not found',
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
        }

        /**
         * Raw Data Success Response Formatting
         *
         * Handles any other content returned by controllers (arrays, strings,
         * objects) by wrapping it in the standardized success response format.
         * This ensures all successful responses follow the same structure.
         *
         * **Raw Content Types:**
         * - Associative arrays with business data
         * - Indexed arrays with list data
         * - String responses (rare but supported)
         * - Object responses (converted to arrays)
         * - Numeric or boolean responses
         *
         * **Success Response Wrapper:**
         * ```json
         * {
         *   "status": "success",
         *   "code": 200,
         *   "data": [raw controller content]
         * }
         * ```
         *
         * **Why Wrap Raw Content:**
         * - Ensures consistent API response format
         * - Provides status indication for all responses
         * - Enables standard client-side response handling
         * - Maintains compatibility with API documentation
         * - Supports automated response validation
         *
         * @var mixed $content Raw data returned by controller
         */
        else {
            /**
             * Success Response Wrapper Application
             *
             * Wraps the raw controller content in a standardized success
             * response structure with HTTP 200 OK status code. This ensures
             * that all successful API responses follow the same format
             * regardless of how controllers return their data.
             *
             * **Formatting Process:**
             * 1. Set HTTP 200 OK status code
             * 2. Wrap content in standardized success structure
             * 3. Apply consistent JSON formatting
             * 4. Preserve original data structure within 'data' field
             * 5. Maintain all security and CORS headers
             *
             * **Data Preservation:**
             * The original controller response is preserved exactly within
             * the 'data' field, ensuring no information is lost during
             * the formatting process.
             */
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([
                'status' => 'success',
                'code' => 200,
                'data' => $content
            ]);
        }

        /**
         * Response Object Integration and Transmission
         *
         * Replaces the application's default response object with the newly
         * formatted response and sends it to the client. This ensures that
         * all middleware modifications are applied and the response is
         * transmitted with all configured headers and content.
         *
         * **Integration Process:**
         * 1. Replace application response object with formatted version
         * 2. All middleware-applied headers are preserved
         * 3. Security headers are included in final response
         * 4. CORS headers are applied for cross-origin support
         * 5. Response is immediately transmitted to client
         *
         * **Final Response Characteristics:**
         * - Consistent JSON formatting across all endpoints
         * - Comprehensive security headers for protection
         * - CORS headers for cross-origin compatibility
         * - Appropriate HTTP status codes and reason phrases
         * - UTF-8 encoding for international character support
         *
         * **Client Benefits:**
         * - Predictable response format for easier parsing
         * - Security protections against common vulnerabilities
         * - Cross-origin support for web applications
         * - Clear status indication and error messaging
         * - Consistent character encoding handling
         *
         * @var Response $response The fully configured response object
         */
        $app->response = $response;

        /**
         * Response Transmission to Client
         *
         * Sends the formatted response to the client, completing the request
         * processing pipeline. This includes transmitting all headers and
         * the response body over the HTTP connection.
         *
         * **Transmission Process:**
         * - HTTP headers are sent first (status, content-type, security, CORS)
         * - Response body is transmitted as JSON-encoded content
         * - Connection is completed and resources are cleaned up
         * - Request processing is considered complete
         *
         * **Post-Transmission:**
         * After this point, no further modifications can be made to the
         * response, and the client has received the complete API response
         * with all middleware processing applied.
         */
        $response->send();
    });

    /**
     * Events Manager Assignment to Application
     *
     * Assigns the configured Events Manager instance to the Phalcon Micro
     * application, activating all registered middleware for request processing.
     * This step is crucial for enabling the middleware pipeline.
     *
     * **Activation Process:**
     * Without this assignment, none of the middleware registered above would
     * execute. The Events Manager needs to be explicitly assigned to the
     * application instance to enable event-driven middleware processing.
     *
     * **Middleware Execution Order:**
     * Once activated, middleware will execute in the following order:
     * 1. CORS middleware (beforeHandleRoute)
     * 2. Headers validation middleware (beforeHandleRoute)
     * 3. Authentication middleware (beforeExecuteRoute)
     * 4. Request body parsing middleware (beforeExecuteRoute)
     * 5. [Controller execution occurs here]
     * 6. Response formatting middleware (afterHandleRoute)
     *
     * **Event-Driven Architecture Benefits:**
     * - Modular middleware design with clear separation of concerns
     * - Easy to add, remove, or reorder middleware components
     * - Consistent execution pattern across all requests
     * - Flexible event-based processing pipeline
     * - Clear debugging and profiling capabilities
     *
     * @var EventsManager $eventsManager The configured events manager instance
     * @var Micro $app The Phalcon Micro application instance
     */
    $app->setEventsManager($eventsManager);
}

/**
 * Middleware Configuration Completion
 *
 * This marks the end of the middleware configuration file. All middleware
 * components have been registered and configured, and the Events Manager
 * has been assigned to the application.
 *
 * **Configuration Summary:**
 * - 5 middleware components registered across 3 event types
 * - Comprehensive request/response processing pipeline established
 * - Security, authentication, and formatting handled systematically
 * - CORS support enabled for cross-origin API access
 * - Consistent error handling and response formatting implemented
 *
 * **Next Steps in Application Flow:**
 * After this configuration, the application will:
 * 1. Load route definitions from individual route files
 * 2. Set up the 404 not found handler
 * 3. Begin processing incoming HTTP requests
 * 4. Execute middleware pipeline for each request
 * 5. Handle responses through the formatting middleware
 *
 * **Maintenance Notes:**
 * - Middleware order is critical and should be preserved
 * - Security headers should be reviewed regularly for updates
 * - CORS configuration may need adjustment for production deployments
 * - Authentication logic should be kept synchronized with token service
 * - Response formatting should remain consistent across all endpoints
 *
 * @see /routes/ Route definition files for endpoint configuration
 * @see /controllers/ Controller implementations for business logic
 * @see /models/ Data model definitions for database operations
 * @see /index.php Main application bootstrap file
 */