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
     * @return bool  True if successful, false otherwise.
     */
    public function indexDocument(string $index, string $id, array $body): bool
    {
        $url = "{$this->host}/{$index}/_doc/{$id}";
        $response = Http::put($url, $body);

        return $response->successful();
    }

    /**
     * Delete a document from Elasticsearch by its ID.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  string  $id  The document ID to delete.
     * @return bool  True if successful, false otherwise.
     */
    public function deleteDocument(string $index, string $id): bool
    {
        $url = "{$this->host}/{$index}/_doc/{$id}";
        $response = Http::delete($url);

        return $response->successful();
    }

    /**
     * Execute a search query against an Elasticsearch index.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  array<string, mixed>  $query  The Elasticsearch query body.
     * @return array<int, mixed>  The array of matching documents (hits).
     */
    public function search(string $index, array $query): array
    {
        $url = "{$this->host}/{$index}/_search";
        $response = Http::post($url, $query);

        return $response->json('hits.hits', []);
    }

    /**
     * Delete documents matching a query from an Elasticsearch index.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  array<string, mixed>  $query  The Elasticsearch query body.
     * @return bool  True if successful, false otherwise.
     */
    public function deleteByQuery(string $index, array $query): bool
    {
        $url = "{$this->host}/{$index}/_delete_by_query";
        $response = Http::post($url, $query);

        return $response->successful();
    }
}
