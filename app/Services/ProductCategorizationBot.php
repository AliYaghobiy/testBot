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
            $searchText = $this->prepareSearchText($product);

            $searchParams = [
                'index' => $this->index,
                'body' => [
                    'query' => [
                        'bool' => [
                            'should' => [
                                [
                                    'match' => [
                                        'category_name' => [
                                            'query' => $searchText,
                                            'boost' => 3.0
                                        ]
                                    ]
                                ],
                                [
                                    'match' => [
                                        'category_keywords' => [
                                            'query' => $searchText,
                                            'boost' => 2.0
                                        ]
                                    ]
                                ],
                                [
                                    'match' => [
                                        'category_description' => [
                                            'query' => $searchText,
                                            'boost' => 1.0
                                        ]
                                    ]
                                ]
                            ],
                            'minimum_should_match' => 1
                        ]
                    ],
                    'size' => 1
                ]
            ];

            $response = $this->client->search($searchParams);

            if (empty($response['hits']['hits'])) {
                return null;
            }

            $bestMatch = $response['hits']['hits'][0];
            $categoryId = $bestMatch['_source']['category_id'];
            $score = $bestMatch['_score'];

            $category = Category::find($categoryId);

            if (!$category) {
                return null;
            }

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
        $searchParts = [
            $product->title,
            $product->titleSeo,
            $product->keyword,
            $product->body
        ];

        return implode(' ', array_filter($searchParts));
    }

    /**
     * اختصاص دسته‌بندی به محصول
     */
    public function assignCategoryToProduct(Product $product, Category $category): void
    {
        try {
            // حذف دسته‌بندی‌های قبلی
            $product->catables()->delete();

            // اختصاص دسته‌بندی جدید
            $product->catables()->create([
                'category_id' => $category->id,
                'catables_id' => $product->id,
                'catables_type' => Product::class
            ]);

            Log::info("Product {$product->id} assigned to category {$category->id}");
        } catch (Exception $e) {
            Log::error("Error assigning category to product {$product->id}: " . $e->getMessage());
            throw $e;
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
                            'analyzer' => [
                                'persian_analyzer' => [
                                    'type' => 'custom',
                                    'tokenizer' => 'standard',
                                    'filter' => ['lowercase', 'persian_normalization']
                                ]
                            ]
                        ]
                    ],
                    'mappings' => [
                        'properties' => [
                            'category_id' => ['type' => 'integer'],
                            'category_name' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer'
                            ],
                            'category_keywords' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer'
                            ],
                            'category_description' => [
                                'type' => 'text',
                                'analyzer' => 'persian_analyzer'
                            ]
                        ]
                    ]
                ]
            ];

            // بررسی وجود index
            $indexExists = $this->client->indices()->exists(['index' => $this->index]);
            
            if ($indexExists) {
                $this->client->indices()->delete(['index' => $this->index]);
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

            foreach ($categories as $category) {
                $this->client->index([
                    'index' => $this->index,
                    'id' => $category->id,
                    'body' => [
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'category_keywords' => $category->keyword ?? '',
                        'category_description' => $category->nameSeo ?? ''
                    ]
                ]);
            }

            $this->client->indices()->refresh(['index' => $this->index]);
            Log::info('Categories indexed successfully');
        } catch (Exception $e) {
            Log::error('Error indexing categories: ' . $e->getMessage());
            throw $e;
        }
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
}
