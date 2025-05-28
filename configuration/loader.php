<?php

declare(strict_types=1);

/**
 * Phalcon Autoloader Configuration - Maxmila Homecare REST API
 *
 * This file configures the Phalcon autoloader for the Maxmila Homecare application,
 * implementing PSR-4 autoloading standards to enable automatic class loading without
 * requiring manual include/require statements throughout the application.
 *
 * The autoloader maps PHP namespaces to filesystem directories, allowing the
 * framework to automatically locate and load class files when they are first
 * referenced in the code. This approach provides:
 *
 * - Improved performance through lazy loading
 * - Better code organization and maintainability
 * - Reduced memory footprint
 * - Elimination of manual file inclusion management
 * - Support for modern PHP namespace conventions
 *
 * Key Features:
 * - PSR-4 compliant namespace-to-directory mapping
 * - Organized separation of concerns by component type
 * - Scalable architecture supporting future expansion
 * - Integration with Phalcon's high-performance autoloader
 *
 * @package    MaxmilaHomecare
 * @subpackage Configuration
 * @category   Autoloading
 * @version    1.0.0
 * @author     Maxmila Homecare Development Team
 * @copyright  2025 Maxmila Homecare LLC & Trinketronix LLC
 * @license    Proprietary - All rights reserved
 * @link       https://api.maxmilahomecare.com
 *
 * @requires   PHP 8.4+
 * @requires   Phalcon 5.0+
 *
 * @see        https://www.php-fig.org/psr/psr-4/ PSR-4 Autoloading Standard
 * @see        https://docs.phalcon.io/5.0/en/autoload Phalcon Autoloader Documentation
 * @see        https://getcomposer.org/doc/04-schema.md#psr-4 Composer PSR-4 Implementation
 *
 * @since      1.0.0 Initial implementation with core namespace mappings
 * @todo       Add support for vendor-specific namespaces
 * @todo       Implement namespace validation and debugging features
 * @todo       Add performance monitoring for autoload operations
 */

// ============================================================================
// PHALCON AUTOLOADER IMPORTS
// ============================================================================

use Phalcon\Autoload\Loader;

// ============================================================================
// FILESYSTEM PATH CONFIGURATION
// ============================================================================

/**
 * Application Root Path Resolution
 *
 * Determines the absolute filesystem path to the application root directory
 * by moving one level up from the current configuration directory. This path
 * serves as the base for all namespace-to-directory mappings.
 *
 * Path Structure:
 * - /path/to/app/                 <- $rootPath (application root)
 * - /path/to/app/configuration/   <- __DIR__ (current directory)
 * - /path/to/app/utils/           <- $utilsPath (utilities directory)
 *
 * @var string $rootPath Absolute path to the application root directory
 *
 * @example /var/www/maxmila-api (typical production path)
 * @example /home/user/projects/maxmila-api (typical development path)
 */
$rootPath = dirname(__DIR__);

/**
 * Utilities Directory Path Resolution
 *
 * Constructs the absolute path to the utilities directory which contains
 * specialized utility classes organized by functional area. These utilities
 * provide common functionality used across the application.
 *
 * Utility Categories:
 * - encoding: Data encoding/decoding utilities (Base64, encryption)
 * - email: Email composition and sending utilities
 * - http: HTTP client and request handling utilities
 *
 * @var string $utilsPath Absolute path to the utilities directory
 *
 * @see $rootPath For the base application path
 */
$utilsPath = $rootPath . '/utils';

// ============================================================================
// PHALCON AUTOLOADER INITIALIZATION
// ============================================================================

/**
 * Phalcon Autoloader Instance Creation
 *
 * Creates a new instance of the Phalcon Autoloader, which provides
 * high-performance class loading capabilities optimized for the Phalcon
 * framework. The autoloader supports multiple loading strategies:
 *
 * - Namespace-based loading (PSR-4 compliant)
 * - Directory-based loading
 * - File-based loading
 * - Class map loading for performance optimization
 *
 * Performance Characteristics:
 * - Lazy loading: Classes loaded only when first accessed
 * - Caching: Frequently accessed classes cached in memory
 * - Optimized path resolution with minimal filesystem operations
 * - Integration with PHP's native autoloading mechanism
 *
 * @var Loader $loader The Phalcon autoloader instance
 *
 * @see https://docs.phalcon.io/5.0/en/autoload#autoloader-performance
 */
$loader = new Loader();

// ============================================================================
// NAMESPACE-TO-DIRECTORY MAPPING CONFIGURATION
// ============================================================================

/**
 * Application Namespace Registration
 *
 * Registers all application namespaces with their corresponding filesystem
 * directories using PSR-4 autoloading standards. This configuration enables
 * automatic class loading based on namespace conventions.
 *
 * Namespace Organization Strategy:
 * 1. **Functional Separation**: Each namespace represents a distinct functional area
 * 2. **Hierarchical Structure**: Nested namespaces for sub-components
 * 3. **Scalable Architecture**: Easy addition of new namespaces as application grows
 * 4. **Clear Boundaries**: Well-defined separation of concerns
 *
 * Mapping Convention:
 * - Namespace: Api\ComponentName
 * - Directory: /path/to/app/componentname/
 * - Class File: ComponentName.php
 * - Full Path: /path/to/app/componentname/ClassName.php
 *
 * @method Loader setNamespaces(array $namespaces) Sets the namespace mappings
 * @method Loader register() Registers the autoloader with PHP's spl_autoload
 *
 * @throws \Phalcon\Autoload\Exception If namespace registration fails
 * @throws \InvalidArgumentException If directory paths are invalid
 *
 * @example
 * // When this code is executed:
 * use Api\Models\User;
 * $user = new User();
 *
 * // The autoloader will:
 * // 1. Parse namespace: Api\Models
 * // 2. Map to directory: /path/to/app/models/
 * // 3. Look for file: /path/to/app/models/User.php
 * // 4. Include the file if found
 */
$loader->setNamespaces([
    // ========================================================================
    // UTILITY NAMESPACES
    // ========================================================================

    /**
     * Data Encoding and Security Utilities
     *
     * Contains classes for data encoding, decoding, encryption, and security
     * operations. These utilities provide secure handling of sensitive data
     * such as passwords, SSN numbers, and authentication tokens.
     *
     * Typical Classes:
     * - Base64: Enhanced Base64 encoding with salt and pepper
     * - Encryption: Symmetric and asymmetric encryption utilities
     * - Hash: Secure hashing functions and password utilities
     * - Sanitizer: Input sanitization and validation
     *
     * @namespace Api\Encoding
     * @directory /utils/encoding/
     *
     * @example Api\Encoding\Base64::encodingSaltedPeppered($data)
     * @example Api\Encoding\Hash::securePassword($password)
     */
    'Api\Encoding'   => $utilsPath . '/encoding/',

    /**
     * Email Communication Utilities
     *
     * Provides comprehensive email functionality including message composition,
     * SMTP configuration, template processing, and delivery management.
     * Supports various email providers and authentication methods.
     *
     * Typical Classes:
     * - Sender: Main email sending functionality with SMTP support
     * - SMTP: SMTP server configuration and connection management
     * - Template: Email template processing and variable substitution
     * - Attachment: File attachment handling and validation
     *
     * @namespace Api\Email
     * @directory /utils/email/
     *
     * @example Api\Email\Sender::sendActivationEmail($email, $token)
     * @example Api\Email\SMTP::configure($host, $port, $credentials)
     */
    'Api\Email'      => $utilsPath . '/email/',

    /**
     * HTTP Client and Request Utilities
     *
     * Contains utilities for making HTTP requests, handling responses,
     * and managing external API communications. Includes support for
     * various authentication methods and response formats.
     *
     * Typical Classes:
     * - Client: HTTP client for external API calls
     * - Request: Request building and configuration
     * - Response: Response parsing and validation
     * - Auth: HTTP authentication helpers (Bearer, Basic, etc.)
     *
     * @namespace Api\Http
     * @directory /utils/http/
     *
     * @example Api\Http\Client::get($url, $headers)
     * @example Api\Http\Request::post($endpoint, $data)
     */
    'Api\Http'       => $rootPath . '/utils/http/',

    // ========================================================================
    // CORE APPLICATION NAMESPACES
    // ========================================================================

    /**
     * Application Constants and Enumerations
     *
     * Defines application-wide constants, enumerations, and configuration
     * values used throughout the system. Provides centralized management
     * of magic numbers, status codes, and system defaults.
     *
     * Typical Classes:
     * - Role: User role constants (Admin, Manager, Caregiver)
     * - Status: Record status constants (Active, Inactive, Deleted)
     * - Message: Standardized error and success messages
     * - Api: API configuration and metadata constants
     * - Progress: Visit progress status constants
     *
     * @namespace Api\Constants
     * @directory /constants/
     *
     * @example Api\Constants\Role::ADMINISTRATOR
     * @example Api\Constants\Status::ACTIVE
     * @example Api\Constants\Message::USER_NOT_FOUND
     */
    'Api\Constants'  => $rootPath . '/constants/',

    /**
     * Data Models and Entity Definitions
     *
     * Contains all Phalcon model classes representing database entities
     * and their relationships. Models handle data validation, relationships,
     * and business logic related to data persistence.
     *
     * Model Categories:
     * - User Management: Auth, User, UserAuthView
     * - Patient Management: Patient, Address
     * - Visit Management: Visit, UserPatient
     * - System: Tool (for testing and utilities)
     *
     * Model Features:
     * - Active Record pattern implementation
     * - Automatic validation and sanitization
     * - Relationship management (belongsTo, hasMany, etc.)
     * - Event-driven behaviors (timestamps, soft deletes)
     * - Query builder integration
     *
     * @namespace Api\Models
     * @directory /models/
     *
     * @example Api\Models\User::findFirst($id)
     * @example Api\Models\Patient::findActive()
     * @example Api\Models\Visit::findByUser($userId)
     */
    'Api\Models'     => $rootPath . '/models/',

    /**
     * Request Controllers and Route Handlers
     *
     * Contains all controller classes that handle HTTP requests and
     * coordinate between models, services, and views. Controllers
     * implement the application's business logic and API endpoints.
     *
     * Controller Categories:
     * - Authentication: AuthController (login, register, tokens)
     * - User Management: UserController, AccountController
     * - Patient Management: PatientController, AddressController
     * - Visit Management: VisitController, UserPatientController
     * - System: ToolController, EmailController, BulkController
     *
     * Controller Features:
     * - RESTful API implementation
     * - Input validation and sanitization
     * - Authentication and authorization
     * - Error handling and logging
     * - Response formatting and status codes
     *
     * @namespace Api\Controllers
     * @directory /controllers/
     *
     * @example Api\Controllers\AuthController::login()
     * @example Api\Controllers\PatientController::create()
     * @example Api\Controllers\VisitController::checkIn($id)
     */
    'Api\Controllers'=> $rootPath . '/controllers/',

    // ========================================================================
    // INFRASTRUCTURE AND SUPPORT NAMESPACES
    // ========================================================================

    /**
     * Middleware Components
     *
     * Contains middleware classes that process requests and responses
     * before and after controller execution. Middleware provides
     * cross-cutting concerns like authentication, CORS, and logging.
     *
     * Middleware Types:
     * - Authentication: Token validation and user context
     * - Authorization: Role-based access control
     * - CORS: Cross-origin resource sharing headers
     * - Validation: Request format and content validation
     * - Logging: Request/response logging and monitoring
     *
     * @namespace Api\Middleware
     * @directory /middleware/
     *
     * @example Api\Middleware\AuthMiddleware::validateToken()
     * @example Api\Middleware\CorsMiddleware::handlePreflight()
     */
    'Api\Middleware' => $rootPath . '/middleware/',

    /**
     * Business Services and Logic
     *
     * Contains service classes that encapsulate complex business logic
     * and provide reusable functionality across controllers. Services
     * help maintain clean separation of concerns and code reusability.
     *
     * Service Categories:
     * - Authentication: TokenService for JWT-like token management
     * - Notification: Email and SMS notification services
     * - Integration: External API integration services
     * - Validation: Complex validation rules and business logic
     * - Reporting: Data analysis and report generation
     *
     * @namespace Api\Services
     * @directory /services/
     *
     * @example Api\Services\TokenService::createToken($user)
     * @example Api\Services\NotificationService::sendWelcome($user)
     */
    'Api\Services'   => $rootPath . '/services/',

    /**
     * Data Repository Pattern Implementation
     *
     * Contains repository classes that abstract data access logic
     * and provide a clean interface between controllers and models.
     * Repositories implement the Repository pattern for better
     * testability and maintainability.
     *
     * Repository Benefits:
     * - Abstraction of data access logic
     * - Improved testability with mock repositories
     * - Centralized query optimization
     * - Clean separation of concerns
     * - Support for multiple data sources
     *
     * @namespace Api\Repositories
     * @directory /repositories/
     *
     * @example Api\Repositories\UserRepository::findActiveUsers()
     * @example Api\Repositories\VisitRepository::findUpcoming($date)
     *
     * @todo Implement repository pattern for all models
     * @todo Add caching layer to repositories
     */
    'Api\Repositories' => $rootPath . '/repositories/',

])->register();

// ============================================================================
// AUTOLOADER REGISTRATION AND VALIDATION
// ============================================================================

/**
 * Autoloader Registration with PHP
 *
 * Registers the configured Phalcon autoloader with PHP's native autoloading
 * mechanism (spl_autoload). This integration ensures that when PHP encounters
 * an undefined class, it will automatically attempt to load it using the
 * registered namespace mappings.
 *
 * Registration Process:
 * 1. Validates all namespace-to-directory mappings
 * 2. Registers with PHP's spl_autoload_register()
 * 3. Sets up internal caching for performance
 * 4. Enables lazy loading throughout the application
 *
 * Performance Optimizations:
 * - First-access caching of successful loads
 * - Optimized path resolution algorithms
 * - Minimal filesystem operations
 * - Integration with PHP's native mechanisms
 *
 * Error Handling:
 * - Graceful degradation if directories don't exist
 * - Detailed error messages for debugging
 * - Fallback to standard PHP autoloading
 *
 * @throws \Phalcon\Autoload\Exception If registration fails
 * @throws \RuntimeException If required directories are not accessible
 *
 * @return Loader Returns the configured loader instance for potential chaining
 */

// ============================================================================
// LOADER RETURN FOR DEPENDENCY INJECTION
// ============================================================================

/**
 * Autoloader Instance Export
 *
 * Returns the configured loader instance to the calling context, typically
 * the main application bootstrap (index.php). This allows the application
 * to access the loader for additional configuration, debugging, or
 * integration with other systems.
 *
 * Use Cases:
 * - Additional namespace registration at runtime
 * - Performance monitoring and debugging
 * - Integration with development tools
 * - Dynamic module loading capabilities
 *
 * @return Loader The fully configured and registered autoloader instance
 *
 * @example
 * // In index.php:
 * $loader = require_once 'configuration/loader.php';
 * // $loader now contains the configured autoloader
 *
 * @see index.php For how the returned loader is utilized
 */
return $loader;