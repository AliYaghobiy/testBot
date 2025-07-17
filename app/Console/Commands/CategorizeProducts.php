<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use Illuminate\Console\Command;

class CategorizeProducts extends Command
{
    protected $signature = 'products:categorize {--setup : Setup Elasticsearch index}';
    protected $description = 'Categorize products automatically using Elasticsearch';

    public function handle()
    {
        $bot = new ProductCategorizationBot();

        if ($this->option('setup')) {
            $this->info('Setting up Elasticsearch index...');
            $bot->createIndex();
            $bot->indexCategories();
            $this->info('Setup completed!');
            return;
        }

        $this->info('Starting product categorization...');
        $results = $bot->processAllProducts();

        $this->info("Categorization completed!");
        $this->info("Processed: {$results['processed']}");
        $this->info("Categorized: {$results['categorized']}");
        $this->info("Errors: {$results['errors']}");
    }
}

