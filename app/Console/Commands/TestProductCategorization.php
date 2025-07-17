<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestProductCategorization extends Command
{
    protected $signature = 'products:test {count=10 : Number of products to test}';
    protected $description = 'Test product categorization on a limited number of products';

    public function handle()
    {
        $count = (int) $this->argument('count');
        $bot = new ProductCategorizationBot();

        $this->info("Testing product categorization on {$count} products...");
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
        $headers = ['ID', 'Product Title', 'Suggested Category', 'Score', 'Status'];
        $tableData = [];

        foreach ($products as $product) {
            try {
                // یافتن بهترین دسته‌بندی
                $categoryResult = $bot->findBestCategoryWithScore($product);

                if ($categoryResult) {
                    $category = $categoryResult['category'];
                    $score = $categoryResult['score'];

                    $tableData[] = [
                        $product->id,
                        $this->truncateString($product->title, 40),
                        $category->name,
                        number_format($score, 2),
                        '✅ Success'
                    ];

                    $successCount++;
                } else {
                    $tableData[] = [
                        $product->id,
                        $this->truncateString($product->title, 40),
                        'No match found',
                        '0.00',
                        '❌ Failed'
                    ];
                }

            } catch (\Exception $e) {
                $tableData[] = [
                    $product->id,
                    $this->truncateString($product->title, 40),
                    'Error occurred',
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

        // سوال برای اعمال تغییرات
        if ($successCount > 0) {
            $this->newLine();
            if ($this->confirm('Do you want to apply these categorizations to the database?')) {
                $this->applyChanges($products, $bot);
            }
        }
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

            foreach ($products as $product) {
                $categoryResult = $bot->findBestCategoryWithScore($product);

                if ($categoryResult) {
                    $bot->assignCategoryToProduct($product, $categoryResult['category']);
                    $applied++;
                }
            }

            DB::commit();

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

