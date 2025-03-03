<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Collection Settings
    |--------------------------------------------------------------------------
    |
    | These settings control the overall structure and metadata of the
    | generated Postman collection.
    |
    */

    // The name of the generated Postman collection
    'collection_name' => env('POSTMAN_COLLECTION_NAME', config('app.name') . ' API'),

    // Description for the collection
    'description' => env('POSTMAN_COLLECTION_DESCRIPTION', 'API Documentation generated automatically from Laravel routes.'),

    // Base URL for all requests (can use Postman variables)
    'base_url' => '{{baseUrl}}',

    // Default base URL value - will be set as the initial value for the baseUrl variable
    'default_base_url' => env('APP_URL', 'http://localhost') . '/api',

    /*
    |--------------------------------------------------------------------------
    | Route Filtering
    |--------------------------------------------------------------------------
    |
    | Configure which routes should be included or excluded from the collection.
    |
    */

    // Only include API routes (those with uri starting with 'api/')
    'api_routes_only' => true,

    // Route URI patterns to exclude
    'exclude_patterns' => [
        '_debugbar/*',
        '_ignition/*',
        'sanctum/*',
        'horizon/*',
        'telescope/*',
    ],

    // HTTP methods to exclude
    'exclude_methods' => [
        'HEAD',
        'OPTIONS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Grouping
    |--------------------------------------------------------------------------
    |
    | Configure how routes are organized into folders within the collection.
    |
    */

    // Strategy for grouping routes into folders
    // Supported values: 'prefix', 'controller', 'middleware', 'custom'
    'grouping_strategy' => 'prefix',

    // Custom grouping function - only used when grouping_strategy is 'custom'
    // Should be a callable that receives a Route object and returns a string (group name)
    'custom_grouping' => null,

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Configure authentication settings for the collection.
    |
    */

    // Authentication type for the collection
    // Supported values: 'noauth', 'bearer', 'basic'
    'auth' => [
        'type' => 'bearer',
        'token' => 'your-default-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Headers & Formatting
    |--------------------------------------------------------------------------
    |
    | Configure default headers and formatting options for requests.
    |
    */

    // Default headers added to each request
    'default_headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Variables
    |--------------------------------------------------------------------------
    |
    | Configure environment variable settings when using --include-env option.
    |
    */

    // Environment name
    'environment_name' => env('POSTMAN_ENV_NAME', config('app.name') . ' Environment'),

    // Default environment variables
    'environment_defaults' => [
        'baseUrl' => env('APP_URL', 'http://localhost') . '/api',
        'token' => env('POSTMAN_DEFAULT_TOKEN', 'your-default-token'),
        'username' => env('POSTMAN_DEFAULT_USERNAME', 'username'),
        'password' => env('POSTMAN_DEFAULT_PASSWORD', 'password'),
    ],
];