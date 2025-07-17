<?php
// test_elasticsearch_connection.php
require 'vendor/autoload.php';

use Elastic\Elasticsearch\ClientBuilder;

try {
    $client = ClientBuilder::create()
        ->setHosts(['localhost:9200'])
        ->build();
    
    // تست اتصال
    $response = $client->info();
    echo "✅ اتصال موفق!\n";
    echo "نسخه Elasticsearch: " . $response['version']['number'] . "\n";
    echo "نام cluster: " . $response['cluster_name'] . "\n";
    
    // تست health
    $health = $client->cluster()->health();
    echo "وضعیت cluster: " . $health['status'] . "\n";
    
} catch (Exception $e) {
    echo "❌ خطا در اتصال: " . $e->getMessage() . "\n";
}
?>
