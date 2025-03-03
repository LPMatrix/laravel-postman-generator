<?php

namespace LPMatrix\PostmanGenerator\Tests\Feature;

use LPMatrix\PostmanGenerator\PostmanGeneratorServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class GenerateCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            PostmanGeneratorServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Define some test routes
        Route::prefix('api')->group(function () {
            Route::get('users', function () {
                return ['data' => []];
            })->name('users.index');
            
            Route::get('users/{id}', function ($id) {
                return ['data' => ['id' => $id]];
            })->name('users.show');
            
            Route::post('users', function () {
                return ['data' => 'created'];
            })->name('users.store');
        });
        
        // Make sure there's no existing collection file
        if (File::exists(base_path('postman-collection.json'))) {
            File::delete(base_path('postman-collection.json'));
        }
        
        if (File::exists(base_path('custom-output.json'))) {
            File::delete(base_path('custom-output.json'));
        }
    }

    protected function tearDown(): void
    {
        // Clean up generated files
        if (File::exists(base_path('postman-collection.json'))) {
            File::delete(base_path('postman-collection.json'));
        }
        
        if (File::exists(base_path('custom-output.json'))) {
            File::delete(base_path('custom-output.json'));
        }
        
        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_a_collection_file()
    {
        $this->artisan('postman:generate')
            ->expectsOutput('Generating Postman collection...')
            ->expectsOutput('Postman collection generated: postman-collection.json')
            ->assertExitCode(0);
        
        $this->assertTrue(File::exists(base_path('postman-collection.json')));
        
        // Verify the file contains valid JSON
        $content = File::get(base_path('postman-collection.json'));
        $collection = json_decode($content, true);
        
        $this->assertIsArray($collection);
        $this->assertArrayHasKey('info', $collection);
        $this->assertArrayHasKey('item', $collection);
    }

    /** @test */
    public function it_can_specify_custom_output_path()
    {
        $this->artisan('postman:generate', ['--output' => 'custom-output.json'])
            ->expectsOutput('Generating Postman collection...')
            ->expectsOutput('Postman collection generated: custom-output.json')
            ->assertExitCode(0);
        
        $this->assertTrue(File::exists(base_path('custom-output.json')));
    }

    /** @test */
    public function it_can_pretty_print_json_output()
    {
        $this->artisan('postman:generate', ['--pretty' => true])
            ->assertExitCode(0);
        
        $content = File::get(base_path('postman-collection.json'));
        
        // Check if the output is formatted with indentation (pretty-printed)
        $this->assertStringContainsString('    ', $content);
        $this->assertStringContainsString("\n", $content);
    }

    /** @test */
    public function it_can_include_environment_variables()
    {
        // Configure environment defaults
        config(['postman-generator.environment_defaults' => [
            'baseUrl' => 'http://example.com/api',
            'token' => 'test-token'
        ]]);
        
        $this->artisan('postman:generate', ['--include-env' => true])
            ->assertExitCode(0);
        
        $content = File::get(base_path('postman-collection.json'));
        $collection = json_decode($content, true);
        
        $this->assertArrayHasKey('environment', $collection);
        $this->assertEquals('test-token', $this->findEnvironmentVariable($collection, 'token'));
        $this->assertEquals('http://example.com/api', $this->findEnvironmentVariable($collection, 'baseUrl'));
    }

    /**
     * Find an environment variable by key
     *
     * @param array $collection
     * @param string $key
     * @return mixed|null
     */
    protected function findEnvironmentVariable($collection, $key)
    {
        foreach ($collection['environment']['values'] as $variable) {
            if ($variable['key'] === $key) {
                return $variable['value'];
            }
        }
        
        return null;
    }
}