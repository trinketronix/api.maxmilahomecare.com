<?php

namespace Api\Constants;

class Message {
    // Authentication related
    public const CREDENTIALS_REQUIRED = 'Username and password are required.';
    public const INVALID_CREDENTIALS = 'Invalid credentials.';
    public const TOKEN_EXPIRED = 'Token expired, please login again';
    public const TOKEN_INVALID = 'Invalid token authorization';
    public const TOKEN_UNACCEPTABLE = 'Token not acceptable';

    // Account status
    public const ACCOUNT_NOT_ACTIVATED = 'Account is not activated.';
    public const ACCOUNT_NOT_FOUND = 'Account not found';

    // Authorization
    public const UNAUTHORIZED = 'Authorization is required';
    public const UNAUTHORIZED_ROLE = 'Unauthorized user role';
    public const UNAUTHORIZED_ACCESS = 'Not authorized to access this data';

    // User operations
    public const USER_CREATED = 'User created successfully.';
    public const USER_UPDATED = 'User updated successfully';
    public const USER_DELETED = 'User deleted successfully.';
    public const USER_ACTIVATED = 'User activated successfully';
    public const USER_ACTIVATION_FAILED = 'User activation failed';
    public const USER_NOT_FOUND = 'User not found';
    public const USER_ID_REQUIRED = 'User ID is required';

    // Password operations
    public const PASSWORD_CHANGED = 'Password changed successfully';
    public const PASSWORD_CHANGE_FAILED = 'Failed to change password';

    // Role operations
    public const ROLE_CHANGED = 'User role changed successfully';
    public const ROLE_CHANGE_FAILED = 'Failed to change user role';
    public const ROLE_INVALID = 'Invalid role';
    public const ID_REQUIRED = 'User ID is required';
    public const ID_NOT_FOUND = 'User ID not found';

    // Email validations
    public const EMAIL_REQUIRED = 'Email is required';
    public const EMAIL_INVALID = 'Invalid email format';
    public const EMAIL_REGISTERED = 'The email is already registered';
    public const EMAIL_NOT_FOUND = 'Email not found';
    public const EMAIL_SECONDARY_INVALID = 'Invalid secondary email format';

    // Email operations
    public const EMAIL_SENT = 'Email sent successfully';
    public const EMAIL_ACTIVATION_SENT = 'An email has been sent to activate your new account';
    public const EMAIL_SUBJECT_EMPTY = 'Email subject cannot be empty.';
    public const EMAIL_BODY_EMPTY = 'Email body cannot be empty.';

    // SSN handling
    public const SSN_INVALID = 'Invalid SSN format';
    public const SSN_UPDATE_UNAUTHORIZED = 'Unauthorized to update the SSN';
    public const SSN_PROCESSING_ERROR = 'Error processing SSN';

    // File uploads
    public const UPLOAD_FAILED = 'Failed to upload file';
    public const UPLOAD_PHOTO_FAILED = 'Failed to upload photo';
    public const UPLOAD_PHOTO_SUCCESS = 'Photo uploaded successfully';
    public const UPLOAD_NO_FILES = 'No files were uploaded';
    public const UPLOAD_INVALID_TYPE = 'Invalid file type. Only JPEG, PNG, GIF and WEBP are allowed';

    // Database operations
    public const DB_QUERY_FAILED = 'Database query failed';
    public const DB_NO_RECORDS = 'No records found';
    public const DB_SESSION_UPDATE_FAILED = 'Failed to update user session.';
    public const DB_ID_GENERATION_FAILED = 'Failed to generate Auth ID';

    // Request validation
    public const REQUEST_CONTENT_TYPE_REQUIRED = 'Content-Type header is required';
    public const REQUEST_CONTENT_TYPE_JSON = 'Content-Type must be application/json';
    public const REQUEST_CONTENT_TYPE_MULTIPART = 'Content-Type must be multipart/form-data';
    public const REQUEST_BODY_JSON_ARRAY = 'Request body must be a JSON array';
    public const REQUEST_JSON_INVALID = 'Invalid JSON in request body: ';

    // Patient related
    public const PATIENT_NOT_FOUND = 'Patient not found';

    // Visit related
    public const VISIT_TIME_INVALID = 'End time cannot be before start time';

    // Status
    public const STATUS_INVALID = 'Invalid status value';

    // Batch operations
    public const BATCH_NONE_CREATED = 'No accounts were created';
    public const BATCH_CREATED_SUFFIX = ' accounts created successfully';

    // System
    public const BASE_URL = 'https://api.maxmilahomecare.com';
    public const ALGORITHM = 'sha512';
}