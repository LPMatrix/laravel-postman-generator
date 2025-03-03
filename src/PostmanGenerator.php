<?php

namespace LPMatrix\PostmanGenerator;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionMethod;

class PostmanGenerator
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * Config values.
     *
     * @var array
     */
    protected $config;

    /**
     * Collection name.
     *
     * @var string
     */
    protected $collectionName;

    /**
     * Base URL for the API.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Collection description.
     *
     * @var string
     */
    protected $description;

    /**
     * Create a new Postman Generator instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->loadConfig();
    }

    /**
     * Load configuration values.
     *
     * @return void
     */
    protected function loadConfig()
    {
        $this->config = $this->app['config']->get('postman-generator', []);
        $this->collectionName = $this->config['collection_name'] ?? $this->app['config']->get('app.name') . ' API';
        $this->baseUrl = $this->config['base_url'] ?? '{{baseUrl}}';
        $this->description = $this->config['description'] ?? 'API Documentation for ' . $this->collectionName;
    }

    /**
     * Generate Postman collection from routes.
     *
     * @return array
     */
    public function generate()
    {
        // Get all routes
        $routes = Route::getRoutes();
        
        // Create collection structure
        $collection = [
            'info' => [
                'name' => $this->collectionName,
                '_postman_id' => $this->generateUUID(),
                'description' => $this->description,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
            ],
            'item' => $this->processRoutes($routes),
            'variable' => [
                [
                    'key' => 'baseUrl',
                    'value' => $this->config['default_base_url'] ?? 'http://localhost:8000/api',
                    'type' => 'string'
                ]
            ],
            'auth' => $this->processAuth(),
        ];

        return $collection;
    }

    /**
     * Process authentication settings.
     *
     * @return array
     */
    protected function processAuth()
    {
        $authConfig = $this->config['auth'] ?? ['type' => 'noauth'];

        switch ($authConfig['type']) {
            case 'bearer':
                return [
                    'type' => 'bearer',
                    'bearer' => [
                        [
                            'key' => 'token',
                            'value' => '{{token}}',
                            'type' => 'string'
                        ]
                    ]
                ];
            case 'basic':
                return [
                    'type' => 'basic',
                    'basic' => [
                        [
                            'key' => 'username',
                            'value' => '{{username}}',
                            'type' => 'string'
                        ],
                        [
                            'key' => 'password',
                            'value' => '{{password}}',
                            'type' => 'string'
                        ]
                    ]
                ];
            default:
                return [
                    'type' => 'noauth'
                ];
        }
    }

    /**
     * Process routes and organize them into folders.
     *
     * @param  \Illuminate\Routing\RouteCollectionInterface  $routes
     * @return array
     */
    protected function processRoutes(\Illuminate\Routing\RouteCollectionInterface $routes)
    {
        $routeGroups = [];
        $excludePatterns = $this->config['exclude_patterns'] ?? [];
        $groupingStrategy = $this->config['grouping_strategy'] ?? 'prefix';

        foreach ($routes as $route) {
            // Skip excluded routes
            $uri = $route->uri();
            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            // Get route information
            $methods = $route->methods();
            
            // Skip routes without HTTP methods (like closures)
            if (empty($methods)) {
                continue;
            }

            // Handle only API routes if configured
            if ($this->config['api_routes_only'] ?? false) {
                if (!Str::startsWith($uri, 'api/') && $uri !== 'api') {
                    continue;
                }
            }

            foreach ($methods as $method) {
                // Skip HEAD, OPTIONS methods if configured
                if (in_array($method, $this->config['exclude_methods'] ?? [])) {
                    continue;
                }

                // Determine group based on strategy
                $group = $this->determineGroup($route, $groupingStrategy);
                
                $postmanRequest = $this->createPostmanRequest($route, $method);
                
                if (!array_key_exists($group, $routeGroups)) {
                    $routeGroups[$group] = [];
                }
                
                $routeGroups[$group][] = $postmanRequest;
            }
        }

        // Organize into Postman collection format
        return $this->organizeIntoCollection($routeGroups);
    }

    /**
     * Determine the group for a route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $strategy
     * @return string
     */
    protected function determineGroup($route, $strategy)
    {
        $uri = $route->uri();
        
        switch ($strategy) {
            case 'controller':
                $action = $route->getActionName();
                if ($action && !$route->getActionName() === 'Closure') {
                    list($controller) = explode('@', $action);
                    $controllerName = class_basename($controller);
                    return str_replace('Controller', '', $controllerName);
                }
                // Fallback to prefix if no controller
                return $this->extractPrefixGroup($uri);
                
            case 'middleware':
                $middleware = $route->middleware();
                if (!empty($middleware)) {
                    return implode('-', $middleware);
                }
                // Fallback to prefix if no middleware
                return $this->extractPrefixGroup($uri);
                
            case 'custom':
                if (isset($this->config['custom_grouping']) && is_callable($this->config['custom_grouping'])) {
                    return call_user_func($this->config['custom_grouping'], $route);
                }
                // Fallback to prefix if custom function not set
                return $this->extractPrefixGroup($uri);
                
            case 'prefix':
            default:
                return $this->extractPrefixGroup($uri);
        }
    }

    /**
     * Extract group from URI prefix.
     *
     * @param  string  $uri
     * @return string
     */
    protected function extractPrefixGroup($uri)
    {
        // Get the first segment of the URI as group
        $segments = explode('/', $uri);
        $group = $segments[0];
        
        // For api routes, use the second segment if available
        if ($group === 'api' && isset($segments[1])) {
            $group = 'api-' . $segments[1];
        }
        
        return $group ?: 'general';
    }

    /**
     * Create Postman request object from a route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $method
     * @return array
     */
    protected function createPostmanRequest($route, $method)
    {
        $uri = $route->uri();
        $name = $this->generateRequestName($route, $method);
        $action = $route->getActionName();
        
        // Build URL with path variables
        $url = [
            'raw' => $this->baseUrl . '/' . $uri,
            'host' => ['{{baseUrl}}'],
            'path' => $this->getUrlSegments($uri),
            'variable' => $this->extractUrlVariables($uri)
        ];
        
        // Get description from route or docblock if available
        $description = $this->getRouteDescription($route);
        
        // Build request body based on method
        $body = $this->buildRequestBody($route, $method);
        
        // Process parameters
        $params = $this->getRouteParameters($route);
        
        $request = [
            'name' => $name,
            'request' => [
                'method' => strtoupper($method),
                'header' => $this->getDefaultHeaders(),
                'url' => $url,
                'description' => $description
            ],
            'response' => []
        ];
        
        // Add body if needed
        if ($body && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $request['request']['body'] = $body;
        }
        
        // Add query params if any
        if (!empty($params)) {
            $request['request']['url']['query'] = $params;
        }
        
        return $request;
    }

    /**
     * Get route description from docblock if available.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string
     */
    protected function getRouteDescription($route)
    {
        $action = $route->getActionName();
        
        // Only process controller actions, not closures
        if (strpos($action, '@') === false) {
            return '';
        }
        
        list($controller, $method) = explode('@', $action);
        
        // Check if controller exists
        if (!class_exists($controller)) {
            return '';
        }
        
        try {
            $reflection = new ReflectionMethod($controller, $method);
            $docComment = $reflection->getDocComment();
            
            if ($docComment) {
                // Extract the first line of the docblock as description
                $docComment = preg_replace('/^\s*\/\*\*\s*|\s*\*\/\s*$/', '', $docComment);
                $lines = preg_split('/\n/', $docComment);
                $description = '';
                
                foreach ($lines as $line) {
                    $line = preg_replace('/^\s*\*\s*/', '', $line);
                    
                    // Skip @param, @return, etc.
                    if (preg_match('/^@\w+/', $line)) {
                        continue;
                    }
                    
                    if ($line) {
                        $description .= $line . "\n";
                    }
                }
                
                return trim($description);
            }
        } catch (\ReflectionException $e) {
            return '';
        }
        
        return '';
    }

    /**
     * Generate a request name from route information.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $method
     * @return string
     */
    protected function generateRequestName($route, $method)
    {
        $uri = $route->uri();
        $action = $route->getActionName();
        
        // Use route name if available
        $routeName = $route->getName();
        if ($routeName) {
            return ucfirst($method) . ' - ' . $routeName;
        }
        
        // Extract method name from controller action
        if (strpos($action, '@') !== false) {
            list($controller, $actionMethod) = explode('@', $action);
            return ucfirst($method) . ' - ' . class_basename($controller) . ' - ' . $actionMethod;
        }
        
        // Use URI if no better name available
        return ucfirst($method) . ' - ' . $uri;
    }

    /**
     * Get URL segments for Postman.
     *
     * @param  string  $uri
     * @return array
     */
    protected function getUrlSegments($uri)
    {
        // Remove URI parameters (e.g., {id})
        $segments = explode('/', $uri);
        
        // Clean segments, removing empty values
        return array_filter($segments, function ($segment) {
            return $segment !== '';
        });
    }

    /**
     * Extract URL variables from URI.
     *
     * @param  string  $uri
     * @return array
     */
    protected function extractUrlVariables($uri)
    {
        $variables = [];
        $segments = explode('/', $uri);
        
        foreach ($segments as $segment) {
            if (preg_match('/^\{(.+?)\}$/', $segment, $matches)) {
                $name = $matches[1];
                // Handle optional parameters
                $name = str_replace('?', '', $name);
                
                $variables[] = [
                    'key' => $name,
                    'value' => '',
                    'description' => 'Path parameter: ' . $name
                ];
            }
        }
        
        return $variables;
    }

    /**
     * Get default headers for requests.
     *
     * @return array
     */
    protected function getDefaultHeaders()
    {
        $headers = $this->config['default_headers'] ?? [];
        $result = [];
        
        foreach ($headers as $key => $value) {
            $result[] = [
                'key' => $key,
                'value' => $value,
                'type' => 'text'
            ];
        }
        
        return $result;
    }

    /**
     * Build request body based on route and method.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $method
     * @return array|null
     */
    protected function buildRequestBody($route, $method)
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            return null;
        }
        
        $validationRules = $this->getValidationRules($route);
        
        if (empty($validationRules)) {
            // Default example request body
            return [
                'mode' => 'raw',
                'raw' => '{}',
                'options' => [
                    'raw' => [
                        'language' => 'json'
                    ]
                ]
            ];
        }
        
        $exampleBody = $this->generateExampleRequestBody($validationRules);
        
        return [
            'mode' => 'raw',
            'raw' => json_encode($exampleBody, JSON_PRETTY_PRINT),
            'options' => [
                'raw' => [
                    'language' => 'json'
                ]
            ]
        ];
    }

    /**
     * Get validation rules from the route's controller method.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array
     */
    protected function getValidationRules($route)
    {
        $action = $route->getActionName();
        
        // Only process controller actions, not closures
        if (strpos($action, '@') === false) {
            return [];
        }
        
        list($controller, $method) = explode('@', $action);
        
        // Check if controller exists
        if (!class_exists($controller)) {
            return [];
        }
        
        try {
            $reflectionMethod = new ReflectionMethod($controller, $method);
            $parameters = $reflectionMethod->getParameters();
            
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                
                if ($type && !$type->isBuiltin()) {
                    $requestClassName = $type->getName();
                    
                    // Look for FormRequest instances
                    if (is_subclass_of($requestClassName, 'Illuminate\Foundation\Http\FormRequest')) {
                        $requestInstance = new $requestClassName();
                        
                        // Get rules method
                        $reflectionRequest = new ReflectionClass($requestClassName);
                        if ($reflectionRequest->hasMethod('rules')) {
                            $rulesMethod = $reflectionRequest->getMethod('rules');
                            $rulesMethod->setAccessible(true);
                            return $rulesMethod->invoke($requestInstance);
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            return [];
        }
        
        return [];
    }

    /**
     * Generate example request body based on validation rules.
     *
     * @param  array  $rules
     * @return array
     */
    protected function generateExampleRequestBody($rules)
    {
        $body = [];
        
        foreach ($rules as $field => $ruleset) {
            // Handle dot notation for nested attributes
            if (strpos($field, '.') !== false) {
                $parts = explode('.', $field);
                $current = &$body;
                
                foreach ($parts as $i => $part) {
                    if ($i < count($parts) - 1) {
                        if (!isset($current[$part]) || !is_array($current[$part])) {
                            // Handle arrays in dot notation (e.g., items.*.name)
                            if ($part === '*') {
                                $current = [[]];
                                continue;
                            }
                            $current[$part] = [];
                        }
                        $current = &$current[$part];
                    } else {
                        $current[$part] = $this->getExampleValueForRule($ruleset);
                    }
                }
            } else {
                $body[$field] = $this->getExampleValueForRule($ruleset);
            }
        }
        
        return $body;
    }

    /**
     * Get example value based on validation rule.
     *
     * @param  mixed  $rule
     * @return mixed
     */
    protected function getExampleValueForRule($rule)
    {
        // Convert rule to array if it's a string
        if (is_string($rule)) {
            $rule = explode('|', $rule);
        }
        
        // Convert rules to lowercase strings for comparison
        $rulesLower = array_map('strtolower', (array) $rule);
        
        // For the array type, return empty array
        if (in_array('array', $rulesLower)) {
            return [];
        }
        
        // For boolean type
        if (in_array('boolean', $rulesLower)) {
            return true;
        }
        
        // For numeric types
        if (in_array('numeric', $rulesLower) || in_array('integer', $rulesLower)) {
            return 1;
        }
        
        // For date types
        if (in_array('date', $rulesLower)) {
            return date('Y-m-d');
        }
        
        // For datetime types
        if (in_array('datetime', $rulesLower)) {
            return date('Y-m-d H:i:s');
        }
        
        // For email types
        if (in_array('email', $rulesLower)) {
            return 'user@example.com';
        }
        
        // For URL types
        if (in_array('url', $rulesLower)) {
            return 'https://example.com';
        }
        
        // Default to string
        return 'Example Value';
    }

    /**
     * Get route query parameters.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array
     */
    protected function getRouteParameters($route)
    {
        // Get parameters from validation rules if available
        $validationRules = $this->getValidationRules($route);
        $params = [];
        
        foreach ($validationRules as $field => $rule) {
            // Only include simple parameters, not dotted ones (which are for request body)
            if (strpos($field, '.') === false && !$this->isRequiredField($rule)) {
                $params[] = [
                    'key' => $field,
                    'value' => $this->getExampleValueForRule($rule),
                    'description' => 'Optional parameter',
                    'disabled' => true
                ];
            }
        }
        
        return $params;
    }

    /**
     * Check if a field is required based on its rules.
     *
     * @param  mixed  $rule
     * @return bool
     */
    protected function isRequiredField($rule)
    {
        // Convert rule to array if it's a string
        if (is_string($rule)) {
            $rule = explode('|', $rule);
        }
        
        return in_array('required', (array) $rule);
    }

    /**
     * Organize routes into Postman collection structure.
     *
     * @param  array  $routeGroups
     * @return array
     */
    protected function organizeIntoCollection($routeGroups)
    {
        $items = [];
        
        // Sort groups alphabetically
        ksort($routeGroups);
        
        foreach ($routeGroups as $group => $routes) {
            // Create folder for group
            $folder = [
                'name' => $this->formatGroupName($group),
                'item' => $routes
            ];
            
            $items[] = $folder;
        }
        
        return $items;
    }

    /**
     * Format group name for better readability.
     *
     * @param  string  $group
     * @return string
     */
    protected function formatGroupName($group)
    {
        // Convert from kebab-case or snake_case to Title Case
        $group = str_replace(['-', '_'], ' ', $group);
        return ucwords($group);
    }

    /**
     * Check if a route should be excluded.
     *
     * @param  string  $uri
     * @param  array  $patterns
     * @return bool
     */
    protected function shouldExcludeRoute($uri, $patterns)
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $uri)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate a UUID v4 for Postman.
     *
     * @return string
     */
    protected function generateUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
