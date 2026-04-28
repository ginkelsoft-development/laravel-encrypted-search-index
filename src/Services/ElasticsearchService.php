<?php

namespace Ginkelsoft\EncryptedSearch\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Class ElasticsearchService
 *
 * Provides a lightweight integration layer between Laravel and Elasticsearch.
 * This service is used by the Encrypted Search Index package to store and
 * query deterministic encrypted tokens when Elasticsearch mode is enabled.
 *
 * Supports authentication via basic auth, API key, or bearer token.
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
     * Build an HTTP request with authentication if configured.
     *
     * @return PendingRequest
     */
    protected function request(): PendingRequest
    {
        $request = Http::asJson();

        $authType = config('encrypted-search.elasticsearch.auth.type');

        return match ($authType) {
            'basic' => $request->withBasicAuth(
                config('encrypted-search.elasticsearch.auth.username', ''),
                config('encrypted-search.elasticsearch.auth.password', ''),
            ),
            'api_key' => $request->withHeaders([
                'Authorization' => 'ApiKey ' . config('encrypted-search.elasticsearch.auth.api_key', ''),
            ]),
            'bearer' => $request->withToken(
                config('encrypted-search.elasticsearch.auth.token', ''),
            ),
            default => $request,
        };
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
        $url = "{$this->host}/{$index}/_doc/" . urlencode($id);
        $response = $this->request()->put($url, $body);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to index document to Elasticsearch [{$url}]: " . $response->body()
            );
        }
    }

    /**
     * Index multiple documents in a single bulk request.
     *
     * @param  string  $index  The Elasticsearch index name.
     * @param  array<int, array{id: string, body: array<string, mixed>}>  $documents
     * @return void
     *
     * @throws \RuntimeException if the request fails
     */
    public function bulkIndex(string $index, array $documents): void
    {
        if (empty($documents)) {
            return;
        }

        $ndjson = '';
        foreach ($documents as $doc) {
            $ndjson .= json_encode(['index' => ['_index' => $index, '_id' => $doc['id']]]) . "\n";
            $ndjson .= json_encode($doc['body']) . "\n";
        }

        $url = "{$this->host}/_bulk";
        $response = $this->request()
            ->withBody($ndjson, 'application/x-ndjson')
            ->post($url);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to bulk index to Elasticsearch [{$url}]: " . $response->body()
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
        $url = "{$this->host}/{$index}/_doc/" . urlencode($id);
        $response = $this->request()->delete($url);

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
        $response = $this->request()->post($url, $query);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Failed to search Elasticsearch [{$url}]: " . $response->body()
            );
        }

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
        $response = $this->request()->post($url, $query);

        return $response->successful();
    }
}
