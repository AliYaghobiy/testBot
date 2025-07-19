<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use Illuminate\Console\Command;

class TestSingleProduct extends Command
{
    protected $signature = 'products:test-single {productId : ID of the product to test}';
    protected $description = 'Test categorization for a single product';

    public function handle()
    {
        $productId = $this->argument('productId');
        $bot = new ProductCategorizationBot();

        $this->info("Testing categorization for product ID: {$productId}");
        
        $result = $bot->testSingleProduct($productId);
        
        if ($result['success']) {
            $this->info("✅ Category found: {$result['category']}");
            $this->info("Score: {$result['score']}");
            $this->info("Search text: {$result['search_text']}");
        } else {
            $this->error("❌ {$result['message']}");
            $this->info("Search text: {$result['search_text']}");
        }
    }
}
