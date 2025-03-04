<?php

namespace LPMatrix\PostmanGenerator;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use LPMatrix\PostmanGenerator\Traits\PostmanGeneratorTrait;

class PostmanGenerator
{
    use PostmanGeneratorTrait;

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
        
        // Debug the number of routes
        $totalRoutes = count($routes);
        $this->app['log']->info("Total routes found: {$totalRoutes}");
        
        // Filter routes to only include those from the routes folder or API routes
        $filteredRoutes = $this->filterRoutesFromRoutesFolder($routes);
        
        // Debug the number of filtered routes
        $filteredCount = count($filteredRoutes);
        $this->app['log']->info("Filtered routes count: {$filteredCount}");
        
        // If no routes were found, include all routes as a fallback
        if ($filteredCount === 0) {
            $this->app['log']->warning("No routes matched filters. Including all routes as fallback.");
            $filteredRoutes = collect($routes);
        }
        
        // Create collection structure
        $collection = [
            'info' => [
                'name' => $this->collectionName,
                '_postman_id' => $this->generateUUID(),
                'description' => $this->description,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
            ],
            'item' => $this->processRoutes($filteredRoutes),
            'variable' => [
                [
                    'key' => 'baseUrl',
                    'value' => $this->config['default_base_url'] ?? 'http://localhost:8000',
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
     * @param  \Illuminate\Support\Collection  $routes
     * @return array
     */
    protected function processRoutes($routes)
    {
        $routeGroups = [];
        $excludePatterns = $this->config['exclude_patterns'] ?? [];
        $excludeMethods = $this->config['exclude_methods'] ?? ['HEAD', 'OPTIONS'];
        
        foreach ($routes as $route) {
            // Skip excluded routes
            $uri = $route->uri();
            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            // Get route information
            $methods = $route->methods();
            
            // Skip routes without HTTP methods
            if (empty($methods)) {
                continue;
            }

            foreach ($methods as $method) {
                // Skip HEAD, OPTIONS methods if configured
                if (in_array(strtoupper($method), $excludeMethods)) {
                    continue;
                }

                // Determine grouping for this route (main and sub)
                $group = $this->determineRouteFile($route);
                $mainGroup = $group['main'];
                $subGroup = $group['sub'];

                // $this->app['log']->info($mainGroup);
                
                // Skip routes from channels.php and console.php
                if (in_array($mainGroup, ['channels', 'console'])) {
                    continue;
                }
                
                $postmanRequest = $this->createPostmanRequest($route, $method);
                
                // Initialize main group if not exists
                if (!isset($routeGroups[$mainGroup])) {
                    $routeGroups[$mainGroup] = [];
                }
                
                // Initialize sub group if not exists
                if (!isset($routeGroups[$mainGroup][$subGroup])) {
                    $routeGroups[$mainGroup][$subGroup] = [];
                }
                
                // Add request to the appropriate subgroup
                $routeGroups[$mainGroup][$subGroup][] = $postmanRequest;
            }
        }

        // Fallback if no routes were processed
        if (empty($routeGroups)) {
            $this->app['log']->warning('No routes were processed for Postman collection.');
        }

        // Organize into Postman collection format
        return $this->organizeIntoCollection($routeGroups);
    }
    
    /**
     * Organize routes into Postman collection structure with nested folders.
     *
     * @param  array  $routeGroups
     * @return array
     */
    protected function organizeIntoCollection($routeGroups)
    {
        $items = [];
        
        // Sort main groups alphabetically
        ksort($routeGroups);
        
        foreach ($routeGroups as $mainGroup => $subGroups) {
            // Create main folder
            $mainFolderItems = [];
            
            // Sort subgroups alphabetically
            ksort($subGroups);
            
            foreach ($subGroups as $subGroup => $routes) {
                // Create subfolder
                $subFolder = [
                    'name' => $this->formatGroupName($subGroup, 'sub'),
                    'item' => $routes
                ];
                
                $mainFolderItems[] = $subFolder;
            }
            
            // Add main folder with subfolders
            $mainFolder = [
                'name' => $this->formatGroupName($mainGroup, 'main'),
                'item' => $mainFolderItems
            ];
            
            $items[] = $mainFolder;
        }
        
        return $items;
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