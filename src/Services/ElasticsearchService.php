<?php

namespace Ginkelsoft\EncryptedSearch\Services;

use Illuminate\Support\Facades\Http;

/**
 * Class ElasticsearchService
 *
 * Provides a lightweight integration layer between Laravel and Elasticsearch.
 * This service is used by the Encrypted Search Index package to store and
 * query deterministic encrypted tokens when Elasticsearch mode is enabled.
 *
 * It wraps basic HTTP interactions for indexing, deleting, and searching
 * documents, relying on Laravel's HTTP client for connection handling.
 *
 * Example usage:
 *
 * ```php
 * $es = app(\Ginkelsoft\EncryptedSearch\Services\ElasticsearchService::class);
 * $es->indexDocument('encrypted_search', 'unique-id', ['token' => 'abc123']);
 * $results = $es->search('encrypted_search', [
 *     'query' => ['term' => ['token.keyword' => 'abc123']]
 * ]);
 * ```
 */
class ElasticsearchService
{
    /**
     * The base host URL of the Elasticsearch instance.
     *
     * @var string
     */
    protected string $host;

    /**
     * Create a new ElasticsearchService instance.
     *
     * @param  string|null  $host  Optional custom Elasticsearch host URL.
     */
    public function __construct(?string $host = null)
    {
        $this->host = $host ?? config('encrypted-search.elasticsearch.host', 'http://elasticsearch:9200');
    }

    /**
     * Index or update a document in Elasticsearch.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  string  $id  The unique document ID.
     * @param  array<string, mixed>  $body  The document body to be stored.
     * @return void
     *
     * @throws \RuntimeException if the request fails
     */
    public function indexDocument(string $index, string $id, array $body): void
    {
        $url = "{$this->host}/{$index}/_doc/{$id}";
        $response = Http::put($url, $body);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to index document to Elasticsearch [{$url}]: " . $response->body()
            );
        }
    }

    /**
     * Delete a document from Elasticsearch by its ID.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  string  $id  The document ID to delete.
     * @return void
     *
     * @throws \RuntimeException if the request fails
     */
    public function deleteDocument(string $index, string $id): void
    {
        $url = "{$this->host}/{$index}/_doc/{$id}";
        $response = Http::delete($url);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to delete document from Elasticsearch [{$url}]: " . $response->body()
            );
        }
    }

    /**
     * Execute a search query against an Elasticsearch index.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  array<string, mixed>  $query  The Elasticsearch query body.
     * @return array<int, mixed>  The array of matching documents (hits).
     *
     * @throws \RuntimeException if the request fails
     */
    public function search(string $index, array $query): array
    {
        $url = "{$this->host}/{$index}/_search";
        $response = Http::post($url, $query);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to search Elasticsearch [{$url}]: " . $response->body()
            );
        }

        return $response->json('hits.hits', []);
    }
}
