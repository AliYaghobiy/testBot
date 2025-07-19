<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
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
                    'min_score' => 0.1, // کاهش شدید حداقل امتیاز
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
     * اختصاص دسته‌بندی به محصول
     */
    public function assignCategoryToProduct(Product $product, Category $category): void
    {
        try {
            // حذف دسته‌بندی‌های قبلی محصول
            $product->catables()->delete();

            // دریافت تمام دسته‌های مادر
            $parentCategories = $category->getAllParentCategories();

            // اختصاص دسته اصلی
            $product->catables()->create([
                'category_id' => $category->id,
                'catables_id' => $product->id,
                'catables_type' => Product::class
            ]);

            // اختصاص دسته‌های مادر
            foreach ($parentCategories as $parentCategory) {
                // بررسی اینکه این دسته قبلاً اختصاص داده نشده باشد
                $exists = $product->catables()
                    ->where('category_id', $parentCategory['id'])
                    ->exists();

                if (!$exists) {
                    $product->catables()->create([
                        'category_id' => $parentCategory['id'],
                        'catables_id' => $product->id,
                        'catables_type' => Product::class
                    ]);
                }
            }

            $totalAssigned = 1 + count($parentCategories);
            Log::info("Product {$product->id} assigned to category {$category->id} and {$totalAssigned} total categories (including parents)");

        } catch (Exception $e) {
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
     * پردازش تمام محصولات
     */
    public function processAllProducts(): array
    {
        $results = [
            'processed' => 0,
            'categorized' => 0,
            'errors' => 0
        ];

        try {
            if (!$this->indexExists()) {
                throw new Exception('Index does not exist. Please run setup first.');
            }

            Product::chunk(100, function ($products) use (&$results) {
                foreach ($products as $product) {
                    try {
                        $results['processed']++;

                        $categoryResult = $this->findBestCategoryWithScore($product);

                        if ($categoryResult) {
                            $this->assignCategoryToProduct($product, $categoryResult['category']);
                            $results['categorized']++;
                        }
                    } catch (Exception $e) {
                        $results['errors']++;
                        Log::error("Error processing product {$product->id}: " . $e->getMessage());
                    }
                }
            });
        } catch (Exception $e) {
            Log::error('Error in processAllProducts: ' . $e->getMessage());
            throw $e;
        }

        return $results;
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
