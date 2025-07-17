#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import mysql.connector
from elasticsearch import Elasticsearch
import re
import sys
import json

class QuickCategorizer:
    def __init__(self):
        # تنظیمات دیتابیس - اینجا را تغییر دهید
        self.db_config = {
            'host': 'localhost',

        'user': 'root',

        'password': 'AliMysql707!',

        'database': 'sareban_torob',
        'charset': 'utf8mb4'
        }
        
        # اتصال به دیتابیس
        self.db = mysql.connector.connect(**self.db_config)
        self.cursor = self.db.cursor(dictionary=True)
        
        # اتصال به Elasticsearch
        try:
            self.es = Elasticsearch(["http://localhost:9200"])
            # تست اتصال
            self.es.ping()
            print("✓ اتصال به Elasticsearch برقرار شد")
        except Exception as e:
            print(f"✗ خطا در اتصال به Elasticsearch: {e}")
            sys.exit(1)
        
        self.setup_indexes()
    
    def setup_indexes(self):
        """راه‌اندازی indexes"""
        try:
            # حذف indexes قدیمی
            if self.es.indices.exists(index="categories"):
                self.es.indices.delete(index="categories")
            
            # ایجاد index جدید
            self.es.indices.create(
                index="categories",
                body={
                    "mappings": {
                        "properties": {
                            "id": {"type": "integer"},
                            "name": {"type": "text", "analyzer": "standard"},
                            "keywords": {"type": "text", "analyzer": "standard"},
                            "content": {"type": "text", "analyzer": "standard"}
                        }
                    }
                }
            )
            print("✓ Index ایجاد شد")
        except Exception as e:
            print(f"خطا در ایجاد index: {e}")
    
    def clean_text(self, text):
        """پاکسازی متن"""
        if not text:
            return ""
        text = str(text)
        text = re.sub(r'<[^>]+>', '', text)
        text = re.sub(r'[^\u0600-\u06FF\u0750-\u077F\w\s]', ' ', text)
        return ' '.join(text.split())
    
    def index_categories(self):
        """نمایه‌سازی دسته‌بندی‌ها"""
        print("در حال نمایه‌سازی دسته‌بندی‌ها...")
        
        self.cursor.execute("SELECT * FROM categories")
        categories = self.cursor.fetchall()
        
        for cat in categories:
            doc = {
                "id": cat['id'],
                "name": self.clean_text(cat['name']),
                "keywords": self.clean_text(cat.get('keyword', '')),
                "content": self.clean_text(cat.get('body', ''))
            }
            
            self.es.index(index="categories", id=cat['id'], body=doc)
        
        print(f"✓ {len(categories)} دسته‌بندی نمایه‌سازی شد")
        return len(categories)
    
    def find_best_category(self, product_text):
        """پیدا کردن بهترین دسته‌بندی"""
        try:
            query = {
                "query": {
                    "multi_match": {
                        "query": product_text,
                        "fields": ["name^3", "keywords^2", "content"],
                        "type": "best_fields"
                    }
                },
                "size": 1
            }
            
            result = self.es.search(index="categories", body=query)
            
            if result['hits']['hits']:
                hit = result['hits']['hits'][0]
                return {
                    "category_id": hit['_source']['id'],
                    "category_name": hit['_source']['name'],
                    "score": hit['_score']
                }
        except Exception as e:
            print(f"خطا در جستجو: {e}")
        
        return None
    
    def categorize_products(self, limit=None):
        """دسته‌بندی محصولات"""
        
        # ابتدا دسته‌بندی‌ها را نمایه‌سازی کنید
        self.index_categories()
        
        # دریافت محصولات
        query = "SELECT id, title, body, keyword FROM products"
        if limit:
            query += f" LIMIT {limit}"
        
        self.cursor.execute(query)
        products = self.cursor.fetchall()
        
        print(f"شروع دسته‌بندی {len(products)} محصول...")
        
        success_count = 0
        
        for i, product in enumerate(products):
            try:
                # ترکیب متن محصول
                product_text = f"{product['title']} {product.get('body', '')} {product.get('keyword', '')}"
                product_text = self.clean_text(product_text)
                
                if not product_text.strip():
                    continue
                
                # پیدا کردن دسته‌بندی
                suggestion = self.find_best_category(product_text)
                
                if suggestion and suggestion['score'] > 1.0:
                    # بررسی وجود رابطه قبلی
                    self.cursor.execute("""
                        SELECT id FROM catables 
                        WHERE catables_id = %s AND catables_type = 'App\\\\Models\\\\Product'
                    """, (product['id'],))
                    
                    existing = self.cursor.fetchone()
                    
                    if existing:
                        # آپدیت
                        self.cursor.execute("""
                            UPDATE catables 
                            SET category_id = %s 
                            WHERE catables_id = %s AND catables_type = 'App\\\\Models\\\\Product'
                        """, (suggestion['category_id'], product['id']))
                    else:
                        # اضافه کردن
                        self.cursor.execute("""
                            INSERT INTO catables (category_id, catables_id, catables_type)
                            VALUES (%s, %s, 'App\\\\Models\\\\Product')
                        """, (suggestion['category_id'], product['id']))
                    
                    self.db.commit()
                    success_count += 1
                    
                    print(f"✓ [{i+1}/{len(products)}] {product['title'][:40]}... → {suggestion['category_name']}")
                else:
                    print(f"✗ [{i+1}/{len(products)}] {product['title'][:40]}... → دسته‌بندی نشد")
                
            except Exception as e:
                print(f"خطا در محصول {product['id']}: {e}")
                continue
        
        print(f"\n🎉 تمام شد! {success_count} محصول دسته‌بندی شد.")
        return success_count
    
    def show_stats(self):
        """نمایش آمار"""
        self.cursor.execute("SELECT COUNT(*) as total FROM products")
        total = self.cursor.fetchone()['total']
        
        self.cursor.execute("""
            SELECT COUNT(*) as categorized 
            FROM catables 
            WHERE catables_type = 'App\\\\Models\\\\Product'
        """)
        categorized = self.cursor.fetchone()['categorized']
        
        print(f"📊 آمار دسته‌بندی:")
        print(f"   کل محصولات: {total}")
        print(f"   دسته‌بندی شده: {categorized}")
        print(f"   درصد: {(categorized/total)*100:.1f}%")
    
    def test_single_product(self, product_id):
        """تست یک محصول"""
        self.cursor.execute("SELECT * FROM products WHERE id = %s", (product_id,))
        product = self.cursor.fetchone()
        
        if not product:
            print("محصول پیدا نشد!")
            return
        
        product_text = f"{product['title']} {product.get('body', '')} {product.get('keyword', '')}"
        product_text = self.clean_text(product_text)
        
        print(f"محصول: {product['title']}")
        print(f"متن تمیز: {product_text[:100]}...")
        
        suggestion = self.find_best_category(product_text)
        
        if suggestion:
            print(f"دسته‌بندی پیشنهادی: {suggestion['category_name']}")
            print(f"امتیاز: {suggestion['score']}")
        else:
            print("دسته‌بندی پیدا نشد!")
    
    def close(self):
        """بستن اتصالات"""
        self.cursor.close()
        self.db.close()

def main():
    """تابع اصلی"""
    
    # ایجاد دسته‌بندی‌کننده
    categorizer = QuickCategorizer()
    
    try:
        if len(sys.argv) > 1:
            command = sys.argv[1]
            
            if command == "stats":
                categorizer.show_stats()
            elif command == "test":
                if len(sys.argv) > 2:
                    product_id = int(sys.argv[2])
                    categorizer.test_single_product(product_id)
                else:
                    categorizer.categorize_products(limit=5)
            elif command.isdigit():
                limit = int(command)
                categorizer.categorize_products(limit=limit)
            else:
                print("استفاده:")
                print("  python3 script.py 10      # دسته‌بندی 10 محصول")
                print("  python3 script.py stats   # نمایش آمار")
                print("  python3 script.py test 9  # تست محصول شماره 9")
        else:
            categorizer.categorize_products()
    
    finally:
        categorizer.close()

if __name__ == "__main__":
    main()
