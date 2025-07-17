<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use Elasticsearch\ClientBuilder;
use Illuminate\Support\Facades\Log;

class ProductCategorizationBot
{
    private $client;
    private $index = 'product_categorization';

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->setHosts(['localhost:9200'])
            ->build();
    }

    /**
     * یافتن بهترین دسته‌بندی همراه با امتیاز
     */
    public function findBestCategoryWithScore(Product $product): ?array
    {
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
                        ]
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
        // حذف دسته‌بندی‌های قبلی
        $product->catables()->delete();

        // اختصاص دسته‌بندی جدید
        $product->catables()->create([
            'category_id' => $category->id,
            'catables_id' => $product->id,
            'catables_type' => 'App\Models\Product'
        ]);

        Log::info("Product {$product->id} assigned to category {$category->id}");
    }

    /**
     * ایجاد ایندکس برای دسته‌بندی محصولات
     */
    public function createIndex(): void
    {
        $params = [
            'index' => $this->index,
            'body' => [
                'mappings' => [
                    'properties' => [
                        'category_id' => ['type' => 'integer'],
                        'category_name' => [
                            'type' => 'text',
                            'analyzer' => 'standard'
                        ],
                        'category_keywords' => [
                            'type' => 'text',
                            'analyzer' => 'standard'
                        ],
                        'category_description' => [
                            'type' => 'text',
                            'analyzer' => 'standard'
                        ]
                    ]
                ]
            ]
        ];

        if ($this->client->indices()->exists(['index' => $this->index])) {
            $this->client->indices()->delete(['index' => $this->index]);
        }

        $this->client->indices()->create($params);
    }

    /**
     * ایندکس کردن دسته‌بندی‌ها
     */
    public function indexCategories(): void
    {
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

        Log::info('Categories indexed successfully');
    }
}
