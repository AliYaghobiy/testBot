<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use Illuminate\Console\Command;

class CategorizeProducts extends Command
{
    protected $signature = 'products:categorize
                       {--setup : Setup Elasticsearch index}
                       {--reset-progress : Reset progress and start from beginning}
                       {--show-progress : Show current progress}';

// متدهای جدید برای اضافه کردن به Command:

    /**
     * نمایش پیشرفت فعلی
     */
    private function showProgress()
    {
        $progressFile = storage_path('app/categorization_progress.json');

        if (!file_exists($progressFile)) {
            $this->info('No progress found. Process hasn\'t started yet or completed successfully.');
            return;
        }

        try {
            $data = json_decode(file_get_contents($progressFile), true);

            $this->info('📊 Current Progress Information:');
            $this->info('Last Processed Product ID: ' . ($data['last_processed_id'] ?? 'Unknown'));
            $this->info('Last Update: ' . ($data['timestamp'] ?? 'Unknown'));
            $this->info('Process ID: ' . ($data['process_id'] ?? 'Unknown'));

            // محاسبه محصولات باقیمانده
            $remaining = \App\Models\Product::where('id', '>', $data['last_processed_id'])->count();
            $this->info("Remaining products to process: {$remaining}");

        } catch (Exception $e) {
            $this->error('Error reading progress file: ' . $e->getMessage());
        }
    }

    /**
     * ریست کردن پیشرفت
     */
    private function resetProgress()
    {
        $progressFile = storage_path('app/categorization_progress.json');

        if (file_exists($progressFile)) {
            unlink($progressFile);
            $this->info('✅ Progress has been reset. Next run will start from the beginning.');
        } else {
            $this->info('ℹ️  No progress file found to reset.');
        }
    }

// متد handle اصلاح شده:
    public function handle()
    {
        $bot = new ProductCategorizationBot();

        // مدیریت options
        if ($this->option('show-progress')) {
            $this->showProgress();
            return;
        }

        if ($this->option('reset-progress')) {
            $this->resetProgress();
            return;
        }

        if ($this->option('setup')) {
            $this->info('Setting up Elasticsearch index...');
            $bot->createIndex();
            $bot->indexCategories();
            $this->info('Setup completed!');
            return;
        }

        $this->info('Starting product categorization...');
        $this->newLine();

        // نمایش وضعیت Resume
        $progressFile = storage_path('app/categorization_progress.json');
        if (file_exists($progressFile)) {
            $this->warn('⚠️  Previous progress detected. Continuing from last processed product...');
            $data = json_decode(file_get_contents($progressFile), true);
            $this->info('Last processed ID: ' . ($data['last_processed_id'] ?? 'Unknown'));
            $this->newLine();
        }

        // باقی کد handle بدون تغییر...
        $results = $bot->processAllProducts(function($productId, $categoryResult, $processed, $categorized, $skipped = 0) {
            // همان callback قبلی
            if ($categoryResult && isset($categoryResult['already_categorized'])) {
                $this->error("Product ID: {$productId} | ALREADY HAS CATEGORY - SKIPPED");
                $this->info("---------------------------------");
                return;
            }

            if ($categoryResult && isset($categoryResult['category']) && $categoryResult['category']) {
                $category = $categoryResult['category'];
                $score = number_format($categoryResult['score'], 1);

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

            if ($processed % 10 === 0) {
                $this->newLine();
                $this->info("📊 Progress: Processed {$processed} | Categorized: {$categorized} | Skipped: {$skipped}");
                $this->newLine();
            }
        });

        $this->newLine();
        $this->info("✅ Categorization completed!");
        $this->info("Processed: {$results['processed']}");
        $this->info("Categorized: {$results['categorized']}");
        $this->info("Skipped (Already categorized): {$results['skipped']}");
        $this->info("Errors: {$results['errors']}");
    }
}
