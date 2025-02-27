# Laravel Postman Generator

A Laravel package that automatically generates a Postman collection from your Laravel routes, including support for route groups, authentication settings, and customization options.

## Features

- Automatic generation of Postman Collection v2.1 from Laravel routes
- Support for route grouping (by prefix, controller, or middleware)
- Intelligent handling of route parameters and request bodies
- Support for FormRequest validation rules to generate example requests
- Authentication configuration (Bearer token, Basic auth)
- Environment variables support
- Customizable output with pretty-printing option
- Works with Laravel 10.x and above

## Installation

You can install the package via composer:

```bash
composer require lpmatrix/postman-generator
```

The service provider will be automatically registered for Laravel 8+.

## Configuration

To publish the configuration file:

```bash
php artisan vendor:publish --provider="LPMatrix\PostmanGenerator\PostmanGeneratorServiceProvider" --tag="config"
```

This will create a `config/postman-generator.php` file where you can customize the behavior of the package.

## Usage

### Basic Usage

To generate a Postman collection:

```bash
php artisan postman:generate
```

This will create a `postman-collection.json` file in your project root that you can import into Postman.

### Command Options

```bash
# Specify a custom output path
php artisan postman:generate --output=custom-file.json

# Pretty-print the JSON output
php artisan postman:generate --pretty

# Include environment variables
php artisan postman:generate --include-env
```

## Configuration Options

### Collection Settings

```php
// The name of the generated Postman collection
'collection_name' => env('POSTMAN_COLLECTION_NAME', config('app.name') . ' API'),

// Description for the collection
'description' => env('POSTMAN_COLLECTION_DESCRIPTION', 'API Documentation'),

// Base URL for all requests (can use Postman variables)
'base_url' => '{{baseUrl}}',

// Default base URL value 
'default_base_url' => env('APP_URL', 'http://localhost') . '/api',
```

### Route Filtering

```php
// Only include API routes (those with uri starting with 'api/')
'api_routes_only' => true,

// Route URI patterns to exclude
'exclude_patterns' => [
    '_debugbar/*',
    'sanctum/*',
    // ...
],

// HTTP methods to exclude
'exclude_methods' => [
    'HEAD',
    'OPTIONS',
],
```

### Route Grouping

```php
// Strategy for grouping routes into folders
// Supported values: 'prefix', 'controller', 'middleware', 'custom'
'grouping_strategy' => 'prefix',

// Custom grouping function - only used when grouping_strategy is 'custom'
'custom_grouping' => function ($route) {
    // Your custom logic here
    return 'Your Group Name';
},
```

### Authentication

```php
// Authentication type for the collection
// Supported values: 'noauth', 'bearer', 'basic'
'auth' => [
    'type' => 'bearer',
    'token' => 'your-default-token',
],
```

## Advanced Usage Examples

### Custom Route Grouping

If you want to implement a custom grouping logic, you can define a closure in your `AppServiceProvider`:

```php
use Acme\PostmanGenerator\PostmanGenerator;

public function boot()
{
    config(['postman-generator.grouping_strategy' => 'custom']);
    config(['postman-generator.custom_grouping' => function ($route) {
        // Your custom grouping logic
        $uri = $route->uri();
        $method = $route->methods()[0];
        
        // Group by HTTP method
        return strtoupper($method);
    }]);
}
```

### Extracting Documentation from Route Annotations

This package automatically extracts documentation from PHPDoc comments in your controller methods. For best results, document your API endpoints like this:

```php
/**
 * Get a list of all products.
 * 
 * This endpoint returns a paginated list of products with basic information.
 * 
 * @return \Illuminate\Http\Response
 */
public function index()
{
    // Your code here
}
```

### Using with FormRequest Validation

The package automatically detects FormRequest validation rules to generate example request bodies:

```php
public function store(StoreProductRequest $request)
{
    // When StoreProductRequest contains validation rules, they will be used
    // to generate an example request body with appropriate data types
}
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.