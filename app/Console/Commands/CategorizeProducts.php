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
    $this->newLine();

    // تنظیم callback برای نمایش پیشرفت
    $results = $bot->processAllProducts(function($productId, $categoryResult, $processed, $categorized, $skipped = 0) {
        // بررسی اگر محصول از قبل دسته‌بندی دارد
        if ($categoryResult && isset($categoryResult['already_categorized'])) {
            $this->error("Product ID: {$productId} | ALREADY HAS CATEGORY - SKIPPED");
            $this->info("---------------------------------");
            return;
        }

        if ($categoryResult && isset($categoryResult['category']) && $categoryResult['category']) {
            $category = $categoryResult['category'];
            $score = number_format($categoryResult['score'], 1);

            // دریافت مسیر کامل دسته‌بندی
            $parentCategories = $category->getAllParentCategories();
            $fullPath = [];

            foreach ($parentCategories as $parent) {
                $fullPath[] = $parent['name'];
            }
            $fullPath[] = $category->name;
            $fullCategoryPath = implode(' / ', $fullPath);

            $this->info("---------------------------------");
            $this->info("Product ID: {$productId} | Category: {$category->name} | Score: {$score}");
            $this->info("Full Category: {$fullCategoryPath}");
            $this->info("---------------------------------");
        } else {
            $this->warn("Product ID: {$productId} | No category found");
            $this->info("---------------------------------");
        }

        // نمایش آمار هر 10 محصول
        if ($processed % 10 === 0) {
            $this->newLine();
            $this->info("📊 Progress: Processed {$processed} | Categorized: {$categorized} | Skipped: {$skipped}");
            $this->newLine();
        }
    });


    $this->newLine();
    $this->info("Categorization completed!");
    $this->info("Processed: {$results['processed']}");
    $this->info("Categorized: {$results['categorized']}");
    $this->info("Skipped (Already categorized): {$results['skipped']}");
    $this->info("Errors: {$results['errors']}");
}
}
