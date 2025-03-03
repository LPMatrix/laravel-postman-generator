<?php

namespace LPMatrix\PostmanGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GeneratePostmanCollection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postman:generate 
                            {--output=postman-collection.json : The output file path}
                            {--pretty : Format the JSON output with indentation}
                            {--include-env : Include environment variables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Postman collection from the application routes';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Generating Postman collection...');

        $generator = app('postman-generator');
        $collection = $generator->generate();

        // Add environment if requested
        if ($this->option('include-env')) {
            $this->addEnvironmentVariables($collection);
        }

        // Determine output path
        $outputPath = $this->option('output');
        if (!Str::endsWith($outputPath, '.json')) {
            $outputPath .= '.json';
        }

        // Pretty print if requested
        $jsonOptions = JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        // Write to file
        File::put($outputPath, json_encode($collection, $jsonOptions));

        $this->info("Postman collection generated: {$outputPath}");

        return 0;
    }

    /**
     * Add environment variables to the collection.
     *
     * @param  array  &$collection
     * @return void
     */
    protected function addEnvironmentVariables(&$collection)
    {
        $envDefaults = config('postman-generator.environment_defaults', []);
        $envName = config('postman-generator.environment_name', config('app.name') . ' Environment');

        $environment = [
            'id' => $this->generateUUID(),
            'name' => $envName,
            'values' => []
        ];

        foreach ($envDefaults as $key => $value) {
            $environment['values'][] = [
                'key' => $key,
                'value' => $value,
                'enabled' => true
            ];
        }

        // Add environment to the collection output
        $collection['environment'] = $environment;
    }

    /**
     * Generate a UUID v4.
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