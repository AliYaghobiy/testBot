<?php

namespace App\Console\Commands;

use App\Services\ProductCategorizationBot;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Console\Command;

class AnalyzeCategorization extends Command
{
    protected $signature = 'products:analyze {count=20 : Number of products to analyze}';
    protected $description = 'Analyze categorization quality and suggest improvements';

    public function handle()
    {
        $count = (int) $this->argument('count');
        $bot = new ProductCategorizationBot();

        $this->info("Analyzing categorization quality for {$count} products...");
        $this->newLine();

        $products = Product::inRandomOrder()->limit($count)->get();

        if ($products->isEmpty()) {
            $this->error('No products found!');
            return;
        }

        $qualityAnalysis = [
            'excellent' => 0,    // Score > 300
            'good' => 0,         // Score 200-300
            'fair' => 0,         // Score 100-200
            'poor' => 0,         // Score < 100
            'no_match' => 0
        ];

        $detailedResults = [];

        foreach ($products as $product) {
            $categoryResult = $bot->findBestCategoryWithScore($product);
            
            if ($categoryResult) {
                $score = $categoryResult['score'];
                $category = $categoryResult['category'];
                
                // تحلیل کیفیت
                if ($score > 300) {
                    $quality = 'excellent';
                    $qualityAnalysis['excellent']++;
                } elseif ($score >= 200) {
                    $quality = 'good';
                    $qualityAnalysis['good']++;
                } elseif ($score >= 100) {
                    $quality = 'fair';
                    $qualityAnalysis['fair']++;
                } else {
                    $quality = 'poor';
                    $qualityAnalysis['poor']++;
                }

                $detailedResults[] = [
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'category_name' => $category->name,
                    'score' => $score,
                    'quality' => $quality,
                    'search_text' => $this->prepareSearchText($product)
                ];
            } else {
                $qualityAnalysis['no_match']++;
                $detailedResults[] = [
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'category_name' => 'No match',
                    'score' => 0,
                    'quality' => 'no_match',
                    'search_text' => $this->prepareSearchText($product)
                ];
            }
        }

        // نمایش نتایج
        $this->displayResults($detailedResults, $qualityAnalysis);
        
        // پیشنهادات بهبود
        $this->provideSuggestions($qualityAnalysis, $detailedResults);
    }

    private function displayResults(array $results, array $qualityAnalysis): void
    {
        // جدول نتایج
        $tableData = [];
        foreach ($results as $result) {
            $qualityIcon = $this->getQualityIcon($result['quality']);
            $tableData[] = [
                $result['product_id'],
                $this->truncate($result['product_title'], 25),
                $this->truncate($result['category_name'], 20),
                number_format($result['score'], 1),
                $qualityIcon . ' ' . ucfirst($result['quality'])
            ];
        }

        $this->table([
            'Product ID',
            'Product Title',
            'Category',
            'Score',
            'Quality'
        ], $tableData);

        // آمار کیفیت
        $this->newLine();
        $this->info("=== Quality Analysis ===");
        $total = array_sum($qualityAnalysis);
        
        foreach ($qualityAnalysis as $quality => $count) {
            $percentage = $total > 0 ? ($count / $total) * 100 : 0;
            $icon = $this->getQualityIcon($quality);
            $this->info($icon . ' ' . ucfirst($quality) . ': ' . $count . ' (' . number_format($percentage, 1) . '%)');
        }
    }

    private function getQualityIcon(string $quality): string
    {
        switch ($quality) {
            case 'excellent':
                return '🟢';
            case 'good':
                return '🟡';
            case 'fair':
                return '🟠';
            case 'poor':
                return '🔴';
            case 'no_match':
                return '⚫';
            default:
                return '❓';
        }
    }

    private function provideSuggestions(array $qualityAnalysis, array $results): void
    {
        $this->newLine();
        $this->info("=== Suggestions for Improvement ===");

        $total = array_sum($qualityAnalysis);
        $poorQuality = ($qualityAnalysis['poor'] + $qualityAnalysis['no_match']) / $total * 100;

        if ($poorQuality > 30) {
            $this->warn('⚠️ High rate of poor matches (' . number_format($poorQuality, 1) . '%)');
            $this->info("Consider:");
            $this->info("• Lowering min_score threshold");
            $this->info("• Adding more category keywords");
            $this->info("• Improving product descriptions");
        }

        // تحلیل دسته‌بندی‌های مشکل‌دار
        $problemCategories = [];
        foreach ($results as $result) {
            if ($result['quality'] === 'poor' || $result['quality'] === 'no_match') {
                $searchText = $result['search_text'];
                if (strlen($searchText) < 10) {
                    $this->warn('Product ' . $result['product_id'] . ': Very short search text');
                }
            }
        }

        // پیشنهاد تنظیمات
        $this->newLine();
        $this->info("=== Recommended Settings ===");
        
        if ($qualityAnalysis['excellent'] > $total * 0.3) {
            $this->info("✅ Current settings work well");
        } else {
            $this->info("🔧 Consider adjusting:");
            $this->info("• min_score: Lower from 0.5 to 0.3");
            $this->info("• boost values: Increase category_name boost");
            $this->info("• Add fuzzy matching for typos");
        }
    }

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

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }
}
