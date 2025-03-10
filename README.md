# Maxmila Homecare API

A modern REST API for healthcare service management built with PHP 8.4 and the Phalcon 5 framework.

## Overview

Maxmila Homecare API is a comprehensive backend system for managing healthcare services, specifically designed for home care providers. The system handles user authentication, role-based access control, patient information management, address tracking, and visit scheduling and monitoring.

## Features

- **User Management**: Complete user lifecycle with role-based access control (Admin, Manager, Caregiver)
- **Patient Management**: Patient registration, updates, and status tracking
- **Address Management**: Flexible address system for both users and patients with geocoding support
- **Visit Tracking**: Schedule, monitor, and report on caregiver visits to patients
- **Secure Authentication**: Token-based authentication with automatic expiration
- **API Documentation**: Comprehensive API documentation with examples
- **HHAexchange Integration**: Synchronization with HHAexchange service provider system

## Technical Stack

- **PHP 8.4**: Leveraging the latest PHP features including strict typing
- **Phalcon 5**: High-performance PHP framework with minimal overhead
- **MySQL/MariaDB**: Relational database for data storage
- **RESTful Architecture**: Modern API design with consistent response formats
- **JWT-like Authentication**: Secure token-based authentication system
- **Middleware Pattern**: Request/response processing through configurable middleware

## Installation

### Requirements

- PHP 8.4+
- Phalcon 5 extension
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server

## API Endpoints

### Authentication

- `POST /api/auth/register` - Create a new user account
- `POST /api/auth/login` - Authenticate and receive token
- `PUT /api/auth/token/renew` - Renew authentication token
- `PUT /api/auth/activate` - Activate a user account
- `PUT /api/auth/role` - Change user role
- `PUT /api/auth/password` - Change user password

### Users

- `PUT /api/users/{userId}` - Update user information
- `POST /api/users/photo` - Upload user profile photo
- `PUT /api/users/photo` - Update user profile photo

### Patients

- `POST /api/patients` - Create a new patient
- `PUT /api/patients/{id}` - Update a patient
- `DELETE /api/patients/{id}` - Delete a patient (soft delete)
- `PUT /api/patients/{id}/archive` - Archive a patient
- `PUT /api/patients/{id}/restore` - Restore a patient

### Addresses

- `POST /api/addresses` - Create a new address
- `GET /api/addresses/person/{personId}/{personType}` - Get addresses for a person
- `GET /api/addresses/{id}` - Get a specific address
- `PUT /api/addresses/{id}` - Update an address
- `DELETE /api/addresses/{id}` - Delete an address
- `POST /api/addresses/nearby` - Find addresses within a radius

### Visits

- `POST /api/visits` - Create a new visit
- `PUT /api/visits/{id}` - Update a visit
- `DELETE /api/visits/{id}` - Delete a visit (soft delete)
- `PUT /api/visits/{id}/progress` - Update visit progress
- `PUT /api/visits/{id}/check-in` - Check in to a visit
- `PUT /api/visits/{id}/check-out` - Check out from a visit
- `PUT /api/visits/{id}/cancel` - Cancel a visit

## Environment Configuration

The application supports multiple environments through configuration files:

- `config/env/development.php` - Development environment settings
- `config/env/production.php` - Production environment settings

Set the `APP_ENV` variable in your web server configuration or .htaccess file to specify which environment to use.

## Middleware

The application uses a middleware system for request/response processing:

- **CORS Handling**: Configure cross-origin resource sharing
- **Authentication**: Validate tokens and set user context
- **Content Type Validation**: Ensure proper request formats
- **Response Formatting**: Standardize API responses

## Models

Core data models include:

- **Auth**: User authentication and access control
- **User**: User profile information
- **Patient**: Patient details and status
- **Address**: Location information with geocoding
- **Visit**: Scheduling and tracking of care visits

## Security Features

- **Token-based Authentication**: Secure JWT-like tokens with expiration
- **Role-based Access Control**: Granular permissions based on user roles
- **Password Hashing**: Secure password storage with salting
- **Input Validation**: Comprehensive validation on all endpoints
- **SSN Encryption**: Secure handling of sensitive information
- **XSS Protection**: Security headers to prevent cross-site scripting

## Mobile App Integration

The API is designed to be consumed by native mobile applications:

- Android client app
- iOS client app
- HarmonyOS client app

## Development

### Environment Setup

1. Configure PHP development environment
2. Set up local database
3. Configure environment variables

### Deployment

The application is deployed to shared hosting via FTP.

## License

Proprietary - All rights reserved Maxmila Homecare LLC & Trinketronix LLC
