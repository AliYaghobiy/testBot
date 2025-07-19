<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestProductCategorization extends Command
{
    protected $signature = 'products:test {count=10 : Number of products to test} {--show-details : Show detailed information}';
    protected $description = 'Test product categorization on a limited number of products';

    public function handle()
    {
        $count = (int) $this->argument('count');
        $showDetails = $this->option('show-details');
        $bot = new ProductCategorizationBot();

        $this->info("Testing product categorization on {$count} products...");

        // بررسی وضعیت سیستم
        if (!$this->checkSystemStatus($bot)) {
            return;
        }

        $this->newLine();

        // انتخاب محصولات تصادفی برای تست
        $products = Product::inRandomOrder()
            ->limit($count)
            ->get();

        if ($products->isEmpty()) {
            $this->error('No products found in database!');
            return;
        }

        $results = [];
        $successCount = 0;

        // ایجاد جدول نتایج
        $headers = ['ID', 'Product Title', 'Search Text Preview', 'Suggested Category', 'Parent Categories', 'Score', 'Status'];
        $tableData = [];

        foreach ($products as $product) {
            try {
                // یافتن بهترین دسته‌بندی
                $categoryResult = $bot->findBestCategoryWithScore($product);
                $searchText = $this->prepareSearchText($product);

                if ($categoryResult) {
                    $category = $categoryResult['category'];
                    $score = $categoryResult['score'];

                    // دریافت دسته‌های مادر
                    $parentCategories = $category->getAllParentCategories();
                    $parentNames = array_map(function($cat) {
                        return $cat['name'];
                    }, $parentCategories);

                    // ایجاد مسیر کامل
                    $fullPath = array_merge($parentNames, [$category->name]);
                    $pathString = implode(' / ', $fullPath);

                    $tableData[] = [
                        $product->id,
                        $this->truncateString($product->title, 25),
                        $this->truncateString($searchText, 20),
                        $category->name,
                        count($parentNames) > 0 ? implode(', ', array_slice($parentNames, 0, 2)) . (count($parentNames) > 2 ? '...' : '') : 'None',
                        number_format($score, 2),
                        '✅ Success'
                    ];

                    if ($showDetails) {
                        $this->info("Full Category Path: {$pathString}");
                        $this->info("Total Categories (including parents): " . (count($parentCategories) + 1));
                    }

                    $successCount++;
                } else {
                    $tableData[] = [
                        $product->id,
                        $this->truncateString($product->title, 25),
                        $this->truncateString($searchText, 20),
                        'No match found',
                        'N/A',
                        '0.00',
                        '❌ Failed'
                    ];
                }

                if ($showDetails) {
                    $this->showProductDetails($product, $categoryResult);
                }

            } catch (\Exception $e) {
                $tableData[] = [
                    $product->id,
                    $this->truncateString($product->title, 25),
                    'Error',
                    'Error occurred',
                    'N/A',
                    '0.00',
                    '⚠️ Error'
                ];

                $this->error("Error processing product {$product->id}: " . $e->getMessage());
            }
        }
        // نمایش جدول نتایج
        $this->table($headers, $tableData);

        // آمار کلی
        $this->newLine();
        $this->info("=== Test Results ===");
        $this->info("Total products tested: {$count}");
        $this->info("Successfully categorized: {$successCount}");
        $this->info("Success rate: " . number_format(($successCount / $count) * 100, 1) . "%");

        // پیشنهادات بهبود
        if ($successCount < $count * 0.5) {
            $this->warn("Success rate is low. Consider:");
            $this->warn("1. Adding more descriptive category keywords");
            $this->warn("2. Improving product titles and descriptions");
            $this->warn("3. Lowering the minimum score threshold");
        }

        // سوال برای اعمال تغییرات
        if ($successCount > 0) {
            $this->newLine();
            if ($this->confirm('Do you want to apply these categorizations to the database?')) {
                $this->applyChanges($products, $bot);
            }
        }
    }

    /**
     * بررسی وضعیت سیستم
     */
    private function checkSystemStatus(ProductCategorizationBot $bot): bool
    {
        // بررسی اتصال به Elasticsearch
        if (!$bot->checkConnection()) {
            $this->error('❌ Cannot connect to Elasticsearch');
            return false;
        }

        // بررسی وجود ایندکس
        $stats = $bot->getIndexStats();
        if (!$stats['exists']) {
            $this->error('❌ Index does not exist. Please run: php artisan products:categorize --setup');
            if (isset($stats['error'])) {
                $this->error('Error: ' . $stats['error']);
            }
            return false;
        }

        $this->info("✅ Elasticsearch connection: OK");
        $this->info("✅ Index exists with {$stats['document_count']} categories");

        // بررسی وجود دسته‌بندی‌ها
        $categoryCount = Category::count();
        if ($categoryCount === 0) {
            $this->error('❌ No categories found in database');
            return false;
        }

        $this->info("✅ Database has {$categoryCount} categories");

        return true;
    }

    /**
     * نمایش جزئیات محصول
     */
    private function showProductDetails(Product $product, ?array $categoryResult): void
    {
        $this->newLine();
        $this->info("=== Product Details: {$product->id} ===");
        $this->info("Title: {$product->title}");
        $this->info("Keywords: " . ($product->keyword ?? 'N/A'));
        $this->info("Search Text: " . $this->prepareSearchText($product));

        if ($categoryResult) {
            $this->info("✅ Matched Category: {$categoryResult['category']->name}");
            $this->info("Score: {$categoryResult['score']}");
        } else {
            $this->warn("❌ No match found");
        }

        $this->newLine();
    }

    /**
     * آماده‌سازی متن جستجو از محصول
     */
    private function prepareSearchText(Product $product): string
    {
        $searchParts = [
            $product->title ?? '',
            $product->titleSeo ?? '',
            $product->keyword ?? '',
            $product->body ?? ''
        ];

        $searchText = implode(' ', array_filter($searchParts));
        $searchText = strip_tags($searchText);
        $searchText = preg_replace('/\s+/', ' ', $searchText);

        return trim($searchText);
    }

    /**
     * اعمال تغییرات به دیتابیس
     */
    private function applyChanges($products, ProductCategorizationBot $bot): void
    {
        $this->info('Applying categorizations to database...');

        DB::beginTransaction();

        try {
            $applied = 0;
            $bar = $this->output->createProgressBar(count($products));

            foreach ($products as $product) {
                $categoryResult = $bot->findBestCategoryWithScore($product);

                if ($categoryResult) {
                    $bot->assignCategoryToProduct($product, $categoryResult['category']);
                    $applied++;
                }

                $bar->advance();
            }

            $bar->finish();
            DB::commit();

            $this->newLine();
            $this->info("✅ Successfully applied {$applied} categorizations!");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Error applying changes: " . $e->getMessage());
        }
    }

    /**
     * کوتاه کردن متن برای نمایش در جدول
     */
    private function truncateString(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }
}
