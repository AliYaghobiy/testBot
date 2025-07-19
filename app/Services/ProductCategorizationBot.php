<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use App\Models\Catable;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class ProductCategorizationBot
{
    private Client $client;
    private string $index = 'product_categorization';

    public function __construct()
    {
        try {
            $this->client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->build();

            // تست اتصال
            $response = $this->client->info();
            Log::info('Connected to Elasticsearch: ' . $response['version']['number']);

        } catch (Exception $e) {
            Log::error('Failed to connect to Elasticsearch: ' . $e->getMessage());
            throw new Exception('Elasticsearch connection failed: ' . $e->getMessage());
        }
    }

    /**
     * یافتن بهترین دسته‌بندی همراه با امتیاز
     */
    public function findBestCategoryWithScore(Product $product): ?array
    {
        try {
            if (!$this->indexExists()) {
                Log::warning('Index does not exist. Please run setup first.');
                return null;
            }

            $searchText = $this->prepareSearchText($product);

            if (empty(trim($searchText))) {
                Log::warning("Empty search text for product {$product->id}");
                return null;
            }

            // استخراج کلمات کلیدی از عنوان محصول
            $titleKeywords = $this->extractKeywords($product->title ?? '');
            $productKeywords = $this->extractKeywords($product->keyword ?? '');

            // جستجو با تنظیمات بهینه‌تر
            $searchParams = [
                'index' => $this->index,
                'body' => [
                    'query' => [
                        'bool' => [
                            'should' => [
                                // تطابق دقیق با نام دسته‌بندی
                                [
                                    'match' => [
                                        'category_name' => [
                                            'query' => $searchText,
                                            'boost' => 3.0,
                                            'operator' => 'or'
                                        ]
                                    ]
                                ],
                                // تطابق فازی با نام دسته‌بندی
                                [
                                    'match' => [
                                        'category_name' => [
                                            'query' => $searchText,
                                            'boost' => 2.0,
                                            'fuzziness' => 'AUTO'
                                        ]
                                    ]
                                ],
                                // تطابق با کلمات کلیدی دسته‌بندی
                                [
                                    'match' => [
                                        'category_keywords' => [
                                            'query' => $searchText,
                                            'boost' => 2.5
                                        ]
                                    ]
                                ],
                                // تطابق phrase برای عبارات
                                [
                                    'match_phrase' => [
                                        'category_name' => [
                                            'query' => implode(' ', array_slice($titleKeywords, 0, 3)),
                                            'boost' => 4.0
                                        ]
                                    ]
                                ],
                                // تطابق با توضیحات
                                [
                                    'match' => [
                                        'category_description' => [
                                            'query' => $searchText,
                                            'boost' => 1.0
                                        ]
                                    ]
                                ],
                                // تطابق دقیق کلمات کلیدی محصول
                                [
                                    'terms' => [
                                        'category_keywords' => $productKeywords,
                                        'boost' => 3.0
                                    ]
                                ],
                                // جستجو با wildcard برای تطابق جزئی
                                [
                                    'wildcard' => [
                                        'category_name.keyword' => [
                                            'value' => '*' . $this->getMainKeyword($searchText) . '*',
                                            'boost' => 1.5
                                        ]
                                    ]
                                ],
                                // جستجو در slug
                                [
                                    'match' => [
                                        'category_slug' => [
                                            'query' => $searchText,
                                            'boost' => 1.5
                                        ]
                                    ]
                                ]
                            ],
                            'minimum_should_match' => 1
                        ]
                    ],
                    'size' => 20,
                    'min_score' => 0.01, // کاهش بیشتر حداقل امتیاز
                    '_source' => ['category_id', 'category_name', 'category_keywords', 'category_slug']
                ]
            ];

            $response = $this->client->search($searchParams);

            if (empty($response['hits']['hits'])) {
                Log::info("No matching category found for product {$product->id} with search text: {$searchText}");
                return null;
            }

            // انتخاب بهترین نتیجه
            $bestMatch = $this->selectBestMatchImproved($response['hits']['hits'], $searchText, $titleKeywords);

            if (!$bestMatch) {
                Log::info("No suitable match found after scoring for product {$product->id}");
                return null;
            }

            $categoryId = $bestMatch['_source']['category_id'];
            $score = $bestMatch['_score'];

            $category = Category::find($categoryId);

            if (!$category) {
                Log::warning("Category {$categoryId} not found in database");
                return null;
            }

            Log::info("Found category for product {$product->id}: {$category->name} (Score: {$score})");

            return [
                'category' => $category,
                'score' => $score
            ];
        } catch (Exception $e) {
            Log::error('Error in findBestCategoryWithScore: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * استخراج کلمه کلیدی اصلی از متن
     */
    private function getMainKeyword(string $text): string
    {
        $words = explode(' ', $text);
        $mainWords = [];

        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) > 2) {
                $mainWords[] = $word;
            }
        }

        return $mainWords[0] ?? '';
    }

    /**
     * انتخاب بهترین نتیجه با الگوریتم بهبود یافته
     */
    private function selectBestMatchImproved(array $hits, string $searchText, array $titleKeywords): ?array
    {
        if (empty($hits)) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($hits as $hit) {
            $score = $hit['_score'];
            $categoryName = $hit['_source']['category_name'];
            $categoryKeywords = $hit['_source']['category_keywords'] ?? '';

            // بررسی تطابق مستقیم
            $directMatch = $this->calculateDirectMatch($searchText, $categoryName, $titleKeywords);

            // بررسی تطابق کلمات کلیدی
            $keywordMatch = $this->calculateKeywordMatch($titleKeywords, $categoryKeywords);

            // محاسبه امتیاز نهایی با ضرایب متعادل‌تر
            $finalScore = $score + ($directMatch * 10) + ($keywordMatch * 5);

            if ($finalScore > $bestScore) {
                $bestScore = $finalScore;
                $bestMatch = $hit;
                $bestMatch['_score'] = $finalScore;
            }
        }

        return $bestMatch;
    }

    /**
     * محاسبه تطابق مستقیم
     */
    private function calculateDirectMatch(string $searchText, string $categoryName, array $titleKeywords): float
    {
        $matchScore = 0;

        // تطابق دقیق نام دسته‌بندی
        if (stripos($searchText, $categoryName) !== false || stripos($categoryName, $searchText) !== false) {
            $matchScore += 5;
        }

        // تطابق کلمات کلیدی عنوان
        foreach ($titleKeywords as $keyword) {
            if (stripos($categoryName, $keyword) !== false) {
                $matchScore += 2;
            }
        }

        // تطابق طول نام
        $lengthSimilarity = 1 - abs(mb_strlen($searchText) - mb_strlen($categoryName)) / max(mb_strlen($searchText), mb_strlen($categoryName));
        $matchScore += $lengthSimilarity;

        return $matchScore;
    }

    /**
     * محاسبه تطابق کلمات کلیدی
     */
    private function calculateKeywordMatch(array $titleKeywords, string $categoryKeywords): float
    {
        if (empty($categoryKeywords)) return 0;

        $categoryKeywordArray = explode(' ', $categoryKeywords);
        $matchCount = 0;

        foreach ($titleKeywords as $titleKeyword) {
            foreach ($categoryKeywordArray as $catKeyword) {
                if (stripos($catKeyword, $titleKeyword) !== false || stripos($titleKeyword, $catKeyword) !== false) {
                    $matchCount++;
                    break;
                }
            }
        }

        return $matchCount / max(count($titleKeywords), 1);
    }

    /**
     * استخراج کلمات کلیدی
     */
    private function extractKeywords(string $text): array
    {
        if (empty($text)) return [];

        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $words = explode(' ', trim($text));

        $stopWords = ['و', 'در', 'با', 'به', 'از', 'که', 'این', 'آن', 'را', 'تا', 'برای'];
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) > 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }

        return array_slice($keywords, 0, 10);
    }

    /**
     * یافتن بهترین دسته‌بندی (متد قدیمی)
     */
    public function findBestCategory(Product $product): ?Category
    {
        $result = $this->findBestCategoryWithScore($product);
        return $result ? $result['category'] : null;
    }

    /**
     * آماده‌سازی متن جستجو از محصول
     */
    private function prepareSearchText(Product $product): string
    {
        $searchParts = [];

        if ($product->title) {
            $searchParts[] = $product->title;
        }

        if ($product->keyword) {
            $searchParts[] = $product->keyword;
        }

        if ($product->titleSeo) {
            $searchParts[] = $product->titleSeo;
        }

        if ($product->body) {
            $bodyText = strip_tags($product->body);
            $bodyWords = explode(' ', $bodyText);
            $importantWords = array_slice($bodyWords, 0, 15);
            $searchParts[] = implode(' ', $importantWords);
        }

        $searchText = implode(' ', array_filter($searchParts));
        $searchText = $this->normalizeText($searchText);

        return trim($searchText);
    }

    /**
     * نرمال‌سازی متن
     */
    private function normalizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $text = str_replace($englishNumbers, $persianNumbers, $text);

        $text = str_replace(['ك', 'ي'], ['ک', 'ی'], $text);

        return $text;
    }

    /**
     * اختصاص دسته‌بندی به محصول - نسخه اصلاح شده برای جدول بدون timestamps
     */
    public function assignCategoryToProduct(Product $product, Category $category): void
    {
        try {
            DB::beginTransaction();

            // حذف دسته‌بندی‌های قبلی محصول
            DB::table('catables')
               ->where('catables_id', $product->id)
               ->where('catables_type', Product::class)
               ->delete();

            // دریافت تمام دسته‌های مادر با ترتیب صحیح
            $parentCategories = $category->getAllParentCategories();

            // اختصاص دسته‌های مادر (از کلی به خاص)
            foreach ($parentCategories as $parentCategory) {
                DB::table('catables')->insert([
                    'category_id' => $parentCategory['id'],
                    'catables_id' => $product->id,
                    'catables_type' => Product::class
                ]);
            }

            // اختصاص دسته اصلی (خاص‌ترین دسته)
            DB::table('catables')->insert([
                'category_id' => $category->id,
                'catables_id' => $product->id,
                'catables_type' => Product::class
            ]);

            DB::commit();

            $totalAssigned = 1 + count($parentCategories);
            Log::info("Product {$product->id} assigned to category {$category->id} and {$totalAssigned} total categories (including parents)");

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error assigning category to product {$product->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * بررسی وجود ایندکس
     */
    private function indexExists(): bool
    {
        try {
            $response = $this->client->indices()->exists(['index' => $this->index]);
            return $response->getStatusCode() === 200;
        } catch (Exception $e) {
            Log::error('Error checking index existence: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ایجاد ایندکس برای دسته‌بندی محصولات
     */
    public function createIndex(): void
    {
        try {
            $params = [
                'index' => $this->index,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                        'analysis' => [
                            'filter' => [
                                'persian_stop' => [
                                    'type' => 'stop',
                                    'stopwords' => ['و', 'در', 'با', 'به', 'از', 'که', 'این', 'آن', 'را', 'تا', 'برای', 'یا', 'اما', 'اگر']
                                ],
                                'persian_stemmer' => [
                                    'type' => 'stemmer',
                                    'language' => 'persian'
                                ]
                            ],
                            'analyzer' => [
                                'persian_analyzer' => [
                                    'type' => 'custom',
                                    'tokenizer' => 'standard',
                                    'filter' => [
                                        'lowercase',
                                        'persian_normalization',
                                        'persian_stop',
                                        'persian_stemmer'
                                    ]
                                ],
                                'persian_search_analyzer' => [
                                    'type' => 'custom',
                                    'tokenizer' => 'standard',
                                    'filter' => [
                                        'lowercase',
                                        'persian_normalization'
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'mappings' => [
                        'properties' => [
                            'category_id' => ['type' => 'integer'],
                            'category_name' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer',
                                'search_analyzer' => 'persian_search_analyzer',
                                'fields' => [
                                    'keyword' => [
                                        'type' => 'keyword'
                                    ],
                                    'ngram' => [
                                        'type' => 'text',
                                        'analyzer' => 'persian_analyzer',
                                        'search_analyzer' => 'persian_search_analyzer'
                                    ]
                                ]
                            ],
                            'category_keywords' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer',
                                'search_analyzer' => 'persian_search_analyzer',
                                'fields' => [
                                    'keyword' => [
                                        'type' => 'keyword'
                                    ]
                                ]
                            ],
                            'category_description' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer'
                            ],
                            'category_slug' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer'
                            ],
                            'category_type' => [
                                'type' => 'keyword'
                            ]
                        ]
                    ]
                ]
            ];

            if ($this->indexExists()) {
                $this->client->indices()->delete(['index' => $this->index]);
                Log::info('Existing index deleted');
            }

            $this->client->indices()->create($params);
            Log::info('Elasticsearch index created successfully');
        } catch (Exception $e) {
            Log::error('Error creating Elasticsearch index: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ایندکس کردن دسته‌بندی‌ها
     */
    public function indexCategories(): void
    {
        try {
            $categories = Category::all();

            if ($categories->isEmpty()) {
                Log::warning('No categories found to index');
                return;
            }

            $indexedCount = 0;
            foreach ($categories as $category) {
                $enrichedKeywords = $this->enrichCategoryKeywords($category);

                $body = [
                    'category_id' => $category->id,
                    'category_name' => $category->name ?? '',
                    'category_keywords' => $enrichedKeywords,
                    'category_description' => $this->prepareCategoryDescription($category),
                    'category_slug' => $category->slug ?? '',
                    'category_type' => $category->type ?? ''
                ];

                $this->client->index([
                    'index' => $this->index,
                    'id' => $category->id,
                    'body' => $body
                ]);

                $indexedCount++;
            }

            $this->client->indices()->refresh(['index' => $this->index]);
            Log::info("Categories indexed successfully. Total: {$indexedCount}");
        } catch (Exception $e) {
            Log::error('Error indexing categories: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * غنی‌سازی کلمات کلیدی دسته‌بندی
     */
    private function enrichCategoryKeywords(Category $category): string
    {
        $keywords = [];

        if ($category->keyword) {
            $keywords[] = $category->keyword;
        }

        $nameWords = explode(' ', $category->name ?? '');
        $keywords = array_merge($keywords, $nameWords);

        $synonyms = $this->getCategorySynonyms($category->name ?? '');
        $keywords = array_merge($keywords, $synonyms);

        $keywords = array_unique($keywords);
        $keywords = array_filter($keywords, function($word) {
            return mb_strlen(trim($word)) > 1;
        });

        return implode(' ', $keywords);
    }

    /**
     * دریافت مترادف‌های عمومی
     */
    private function getCategorySynonyms(string $categoryName): array
    {
        $synonymMap = [
            'موبایل' => ['گوشی', 'تلفن هوشمند', 'smartphone'],
            'لپ تاپ' => ['laptop', 'نوت بوک', 'رایانه همراه'],
            'کتاب' => ['book', 'کتب', 'نشریه'],
            'لباس' => ['پوشاک', 'dress', 'clothing'],
            'کفش' => ['shoes', 'پادوش'],
            'ساعت' => ['watch', 'clock', 'زمان سنج'],
            'عطر' => ['perfume', 'ادکلن', 'fragrance'],
            'کیف' => ['bag', 'کیسه', 'ساک'],
            'عینک' => ['glasses', 'عینک آفتابی', 'eyewear'],
            'گوشواره' => ['earrings', 'jewelry'],
            'انگشتر' => ['ring', 'حلقه', 'jewelry'],
            'گردنبند' => ['necklace', 'زنجیر', 'jewelry']
        ];

        $synonyms = [];
        foreach ($synonymMap as $word => $wordSynonyms) {
            if (stripos($categoryName, $word) !== false) {
                $synonyms = array_merge($synonyms, $wordSynonyms);
            }
        }

        return $synonyms;
    }

    /**
     * آماده‌سازی توضیحات دسته‌بندی
     */
    private function prepareCategoryDescription(Category $category): string
    {
        $description = [];

        if ($category->nameSeo) {
            $description[] = $category->nameSeo;
        }

        if ($category->bodySeo) {
            $description[] = strip_tags($category->bodySeo);
        }

        if ($category->body) {
            $description[] = strip_tags($category->body);
        }

        return implode(' ', $description);
    }

    /**
     * پردازش تمام محصولات - نسخه کاملاً اصلاح شده
     */


     /**
 * بررسی اینکه آیا محصول از قبل دسته‌بندی دارد یا نه
 */
private function productHasCategory(Product $product): bool
{
    return DB::table('catables')
              ->where('catables_id', $product->id)
              ->where('catables_type', Product::class)
              ->exists();
}


    public function hasExistingCategory(Product $product): bool
    {
        try {
            // بررسی با قفل برای جلوگیری از race condition
            $categoryCount = DB::table('catables')
                ->where('catables_id', $product->id)
                ->where('catables_type', Product::class)
                ->lockForUpdate()
                ->count();

            $hasCategory = $categoryCount > 0;

            // لاگ دقیق‌تر برای debugging
            if ($hasCategory) {
                Log::info("Product {$product->id} already has {$categoryCount} category assignment(s) - SKIPPING");
            }

            return $hasCategory;

        } catch (Exception $e) {
            Log::error("Error checking category for product {$product->id}: " . $e->getMessage());
            // در صورت خطا، فرض کنیم محصول دسته‌بندی دارد تا از تکرار جلوگیری شود
            return true;
        }
    }


    /**
     * ذخیره آخرین محصول پردازش شده
     */
    private function saveLastProcessedProduct(int $productId): void
    {
        try {
            $progressFile = storage_path('app/categorization_progress.json');
            $data = [
                'last_processed_id' => $productId,
                'timestamp' => now()->toDateTimeString(),
                'process_id' => getmypid()
            ];

            file_put_contents($progressFile, json_encode($data, JSON_PRETTY_PRINT));

        } catch (Exception $e) {
            Log::warning('Could not save progress: ' . $e->getMessage());
        }
    }

    /**
     * دریافت آخرین محصول پردازش شده
     */
    private function getLastProcessedProduct(): ?int
    {
        try {
            $progressFile = storage_path('app/categorization_progress.json');

            if (!file_exists($progressFile)) {
                return null;
            }

            $data = json_decode(file_get_contents($progressFile), true);

            if (!$data || !isset($data['last_processed_id'])) {
                return null;
            }

            Log::info("Found previous progress: Last processed product ID was {$data['last_processed_id']}");
            return (int)$data['last_processed_id'];

        } catch (Exception $e) {
            Log::warning('Could not read progress file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * پاک کردن فایل پیشرفت (در صورت اتمام موفقیت‌آمیز)
     */
    private function clearProgress(): void
    {
        try {
            $progressFile = storage_path('app/categorization_progress.json');
            if (file_exists($progressFile)) {
                unlink($progressFile);
                Log::info('Progress file cleared successfully');
            }
        } catch (Exception $e) {
            Log::warning('Could not clear progress file: ' . $e->getMessage());
        }
    }

    /**
     * نمایش نوار پیشرفت
     */
    private function displayProgressBar(int $current, int $total, int $categorized, int $skipped, int $errors): void
    {
        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;
        $barLength = 50;
        $filledLength = round(($percentage / 100) * $barLength);

        $bar = str_repeat('█', $filledLength) . str_repeat('░', $barLength - $filledLength);

        $progressLine = sprintf(
            "\r🤖 Progress: [%s] %s%% (%d/%d) | ✅ %d | ⏭️ %d | ❌ %d",
            $bar,
            $percentage,
            $current,
            $total,
            $categorized,
            $skipped,
            $errors
        );

        // اگر از command line استفاده می‌شود
        if (defined('STDOUT')) {
            fwrite(STDOUT, $progressLine);
            fflush(STDOUT);
        }

        // لاگ هر 1% پیشرفت
        if ($percentage > 0 && $percentage % 1 === 0.0) {
            Log::info("Categorization Progress: {$percentage}% completed ({$current}/{$total})");
        }
    }
    
    /**
     * پردازش تمام محصولات با قابلیت Resume - نسخه کاملاً اصلاح شده
     */
    public function processAllProducts(callable $progressCallback = null): array
    {
        $results = [
            'processed' => 0,
            'categorized' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        try {
            if (!$this->indexExists()) {
                throw new Exception('Index does not exist. Please run setup first.');
            }

            // دریافت آخرین محصول پردازش شده
            $lastProcessedId = $this->getLastProcessedProduct();
            $startFromId = $lastProcessedId ? $lastProcessedId + 1 : 0;

            if ($lastProcessedId) {
                Log::info("Resuming categorization from product ID: {$startFromId}");
            } else {
                Log::info("Starting fresh categorization process");
            }

            // شمارش کل محصولات باقیمانده
            $totalRemainingProducts = Product::where('id', '>=', $startFromId)->count();
            Log::info("Total products to process: {$totalRemainingProducts}");

            // پردازش محصولات از آخرین نقطه
            Product::where('id', '>=', $startFromId)
                ->orderBy('id')
                ->chunk(1, function ($products) use (&$results, $progressCallback) {
                    $product = $products->first();

                    try {
                        $results['processed']++;

                        // ذخیره پیشرفت هر 5 محصول
                        if ($results['processed'] % 5 === 0) {
                            $this->saveLastProcessedProduct($product->id);
                        }

                        // بررسی دقیق‌تر وجود دسته‌بندی
                        if ($this->hasExistingCategory($product)) {
                            $results['skipped']++;
                            Log::info("Product {$product->id} already categorized - SKIPPED");

                            if ($progressCallback) {
                                $progressCallback($product->id, ['already_categorized' => true], $results['processed'], $results['categorized'], $results['skipped']);
                            }
                            return;
                        }

                        // آماده‌سازی متن جستجو
                        $searchText = $this->prepareSearchText($product);

                        if (empty(trim($searchText))) {
                            Log::warning("Empty search text for product {$product->id}");
                            if ($progressCallback) {
                                $progressCallback($product->id, null, $results['processed'], $results['categorized'], $results['skipped']);
                            }
                            return;
                        }

                        // یافتن دسته‌بندی
                        $categoryResult = $this->findBestCategoryWithScore($product);

                        if ($categoryResult && isset($categoryResult['category']) && $categoryResult['category'] instanceof Category) {
                            try {
                                // بررسی مجدد قبل از اختصاص (double-check)
                                if ($this->hasExistingCategory($product)) {
                                    $results['skipped']++;
                                    Log::warning("Product {$product->id} got categorized by another process - SKIPPED");
                                    return;
                                }

                                // اختصاص دسته‌بندی به محصول
                                $this->assignCategoryToProduct($product, $categoryResult['category']);
                                $results['categorized']++;

                                Log::info("✅ Product {$product->id} successfully categorized to {$categoryResult['category']->name} with score {$categoryResult['score']}");

                                if ($progressCallback) {
                                    $progressCallback($product->id, $categoryResult, $results['processed'], $results['categorized'], $results['skipped']);
                                }

                            } catch (Exception $assignError) {
                                $results['errors']++;
                                Log::error("❌ Error assigning category to product {$product->id}: " . $assignError->getMessage());

                                if ($progressCallback) {
                                    $progressCallback($product->id, null, $results['processed'], $results['categorized'], $results['skipped']);
                                }
                            }
                        } else {
                            Log::info("No category found for product {$product->id}");

                            if ($progressCallback) {
                                $progressCallback($product->id, null, $results['processed'], $results['categorized'], $results['skipped']);
                            }
                        }

                        // تاخیر کوچک
                        usleep(50000); // 50ms

                    } catch (Exception $e) {
                        $results['errors']++;
                        Log::error("❌ Error processing product {$product->id}: " . $e->getMessage());

                        if ($progressCallback) {
                            $progressCallback($product->id, null, $results['processed'], $results['categorized'], $results['skipped']);
                        }
                    }
                });

            // در صورت اتمام موفقیت‌آمیز، فایل پیشرفت را پاک کن
            $this->clearProgress();

            Log::info("ProcessAllProducts completed successfully. Processed: {$results['processed']}, Categorized: {$results['categorized']}, Skipped: {$results['skipped']}, Errors: {$results['errors']}");

        } catch (Exception $e) {
            Log::error('Error in processAllProducts: ' . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    /**
     * متد جایگزین برای بررسی سریع‌تر وجود دسته‌بندی
     */
    private function productHasCategoryFast(int $productId): bool
    {
        try {
            return DB::table('catables')
                ->where('catables_id', $productId)
                ->where('catables_type', Product::class)
                ->exists();
        } catch (Exception $e) {
            Log::error("Fast category check failed for product {$productId}: " . $e->getMessage());
            return true; // در صورت شک، فرض کنیم دسته‌بندی دارد
        }
    }

    /**
     * تست یک محصول خاص برای debugging
     */
    public function testSingleProduct(int $productId): array
    {
        $product = Product::find($productId);

        if (!$product) {
            return ['error' => 'Product not found'];
        }

        $searchText = $this->prepareSearchText($product);
        Log::info("Testing product {$productId} with search text: {$searchText}");

        $categoryResult = $this->findBestCategoryWithScore($product);

        if ($categoryResult) {
            Log::info("Found category for product {$productId}: {$categoryResult['category']->name} with score {$categoryResult['score']}");
            return [
                'success' => true,
                'product_id' => $productId,
                'category' => $categoryResult['category']->name,
                'score' => $categoryResult['score'],
                'search_text' => $searchText
            ];
        } else {
            Log::warning("No category found for product {$productId}");
            return [
                'success' => false,
                'product_id' => $productId,
                'search_text' => $searchText,
                'message' => 'No category found'
            ];
        }
    }

    /**
     * بررسی وضعیت اتصال به Elasticsearch
     */
    public function checkConnection(): bool
    {
        try {
            $response = $this->client->info();
            return isset($response['version']['number']);
        } catch (Exception $e) {
            Log::error('Elasticsearch connection check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * دریافت آمار ایندکس
     */
    public function getIndexStats(): array
    {
        try {
            $indexExistsResponse = $this->client->indices()->exists(['index' => $this->index]);
            $indexExists = $indexExistsResponse->getStatusCode() === 200;

            if (!$indexExists) {
                return ['exists' => false];
            }

            $stats = $this->client->indices()->stats(['index' => $this->index]);
            $count = $this->client->count(['index' => $this->index]);

            return [
                'exists' => true,
                'document_count' => $count['count'],
                'size' => $stats['indices'][$this->index]['total']['store']['size_in_bytes'] ?? 0
            ];
        } catch (Exception $e) {
            Log::error('Error getting index stats: ' . $e->getMessage());
            return ['exists' => false, 'error' => $e->getMessage()];
        }
    }
}
