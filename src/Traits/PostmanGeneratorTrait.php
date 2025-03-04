<?php

namespace LPMatrix\PostmanGenerator\Traits;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

trait PostmanGeneratorTrait
{
    /**
     * Filter routes to only include those defined in the routes folder.
     *
     * @param \Illuminate\Routing\RouteCollection $routes
     * @return \Illuminate\Support\Collection
     */
    protected function filterRoutesFromRoutesFolder($routes)
    {
        // Include all routes registered from files in the routes folder
        $filteredRoutes = collect($routes)->filter(function ($route) {
            $action = $route->getAction();
            
            // Get methods to check if this is a real route
            $methods = $route->methods();
            if (empty($methods)) {
                return false;
            }
            
            // Skip internal Laravel routes
            $uri = $route->uri();
            if (Str::startsWith($uri, '_')) {
                return false;
            }
            
            // If there's a file path, check if it's in the routes folder
            if (isset($action['file'])) {
                return $this->isRouteFileInRoutesFolder($action['file']);
            }
            
            // Include routes with controllers that might not have file info
            // This is a fallback for routes defined programmatically
            if (isset($action['controller']) && !Str::startsWith($action['controller'], 'Laravel\\')) {
                // Skip Laravel's internal controllers
                return true;
            }
            
            return false;
        });
        
        return $filteredRoutes;
    }

    /**
     * Check if a route file is in the routes folder.
     *
     * @param string $filePath
     * @return bool
     */
    protected function isRouteFileInRoutesFolder($filePath)
    {
        // Check if the file path contains the routes folder
        return strpos($filePath, base_path('routes/')) !== false;
    }

    /**
     * Determine the group for a route based on source file and controller.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array
     */
    protected function determineRouteFile($route)
    {
        $action = $route->getAction();
        $uri = $route->uri();
        
        // Initialize grouping information
        $mainGroup = 'general';
        $subGroup = 'other';
        
        // Step 1: Determine the main group based on source file if available
        if (isset($action['file'])) {
            $filePath = $action['file'];
            $fileName = pathinfo($filePath, PATHINFO_FILENAME);
            $mainGroup = strtolower($fileName);
        } elseif (Str::startsWith($uri, 'api/')) {
            // Fallback for API routes without file info
            $mainGroup = 'api';
        } else {
            // Fallback to a generic group based on URI prefix
            $segments = explode('/', $uri);
            $mainGroup = !empty($segments[0]) ? strtolower($segments[0]) : 'web';
        }
        
        // Step 2: Determine the subgroup based on controller if available
        if (isset($action['controller']) && $action['controller'] !== 'Closure') {
            // Extract the controller part from the action
            $controllerParts = explode('@', $action['controller']);
            $controller = $controllerParts[0];
            
            // Get the controller basename without namespace
            $controllerName = class_basename($controller);
            
            // Remove 'Controller' suffix for cleaner naming
            $controllerName = str_replace('Controller', '', $controllerName);
            
            // Set subgroup to the controller name
            $subGroup = strtolower($controllerName);
        } else {
            // For routes without controllers (closures), group by URI segments
            $segments = explode('/', $uri);
            if (count($segments) > 1) {
                $subGroup = strtolower($segments[1] ?? 'general');
            } else {
                $subGroup = 'general';
            }
        }
        
        // Return both main group and subgroup
        return [
            'main' => $mainGroup,
            'sub' => $subGroup
        ];
    }

    /**
     * Format group name for better readability.
     *
     * @param  string  $groupName
     * @param  string  $groupType
     * @return string
     */
    protected function formatGroupName($groupName, $groupType = 'main')
    {
        // Special case mapping for main groups
        $mainGroups = [
            'api' => 'API',
            'web' => 'Web',
            'channels' => 'Channels',
            'console' => 'Console',
            'admin' => 'Admin',
            'dashboard' => 'Dashboard',
            'auth' => 'Authentication'
        ];
        
        // Special case mapping for sub groups
        $subGroups = [
            'auth' => 'Authentication',
            'user' => 'User Management',
            'admin' => 'Administration',
            'general' => 'General',
            'other' => 'Other'
        ];
        
        if ($groupType === 'main' && isset($mainGroups[$groupName])) {
            return $mainGroups[$groupName];
        } elseif ($groupType === 'sub' && isset($subGroups[$groupName])) {
            return $subGroups[$groupName];
        }
        
        // Convert from kebab-case or snake_case to Title Case
        $groupName = str_replace(['-', '_'], ' ', $groupName);
        return ucwords($groupName);
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
        
        // Format the URI properly - avoid double slashes
        if (!Str::startsWith($uri, '/')) {
            $formattedUri = $uri;
        } else {
            $formattedUri = ltrim($uri, '/');
        }
        
        // Build URL with path variables
        $url = [
            'raw' => $this->baseUrl . '/' . $formattedUri,
            'host' => ['{{baseUrl}}'],
            'path' => $this->getUrlSegments($formattedUri),
            'variable' => $this->extractUrlVariables($formattedUri)
        ];
        
        // Get description from route or docblock if available
        $description = $this->getRouteDescription($route);
        
        // Build request body based on method
        $body = $this->buildRequestBody($route, $method);
        
        // Process parameters
        $params = $this->getRouteParameters($route);
        
        // Add middleware information to description if available
        $middleware = $route->middleware();
        if (!empty($middleware)) {
            $middlewareText = "Middleware: " . implode(', ', $middleware);
            $description = trim($description . "\n\n" . $middlewareText);
        }
        
        // Add route action to description
        if ($action) {
            $actionText = "Action: " . $action;
            $description = trim($description . "\n\n" . $actionText);
        }
        
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
     * Generate a request name from route information.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $method
     * @return string
     */
    protected function generateRequestName($route, $method)
    {
        $uri = $route->uri();
        $action = $route->getAction();
        
        // First priority: Use route name if available
        $routeName = $route->getName();
        if ($routeName) {
            return ucfirst(strtolower($method)) . ' - ' . $routeName;
        }
        
        // Second priority: For controller routes, use the method name
        if (isset($action['controller']) && $action['controller'] !== 'Closure') {
            $parts = explode('@', $action['controller']);
            if (isset($parts[1])) {
                // Get controller name without namespace
                $controllerName = class_basename($parts[0]);
                $controllerName = str_replace('Controller', '', $controllerName);
                
                return ucfirst(strtolower($method)) . ' - ' . $controllerName . '.' . $parts[1] . ' - ' . $uri;
            }
        }
        
        // Fallback: Use URI for the name
        return ucfirst(strtolower($method)) . ' - ' . $uri;
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
}