<?php

namespace Ginkelsoft\EncryptedSearch\Tests\Unit;

use Ginkelsoft\EncryptedSearch\Services\ElasticsearchService;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Class ElasticsearchServiceTest
 *
 * Unit tests for the ElasticsearchService class.
 *
 * Tests HTTP interactions with Elasticsearch including:
 * - Document indexing
 * - Document deletion
 * - Search queries
 * - Delete by query
 * - Error handling
 *
 * @package Ginkelsoft\EncryptedSearch\Tests\Unit
 * @covers  \Ginkelsoft\EncryptedSearch\Services\ElasticsearchService
 */
class ElasticsearchServiceTest extends TestCase
{
    /**
     * Test that indexDocument sends PUT request to correct URL.
     *
     * @return void
     */
    public function test_index_document_sends_put_request(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_doc/test-id' => Http::response([
                'result' => 'created',
            ], 201),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $service->indexDocument('test_index', 'test-id', ['field' => 'value']);

        // No exception thrown means success
        $this->assertTrue(true);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:9200/test_index/_doc/test-id'
                && $request->method() === 'PUT'
                && $request['field'] === 'value';
        });
    }

    /**
     * Test that indexDocument throws exception on failure.
     *
     * @return void
     */
    public function test_index_document_throws_on_failure(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_doc/test-id' => Http::response([
                'error' => 'index_not_found_exception',
            ], 404),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to index document');

        $service->indexDocument('test_index', 'test-id', ['field' => 'value']);
    }

    /**
     * Test that deleteDocument sends DELETE request to correct URL.
     *
     * @return void
     */
    public function test_delete_document_sends_delete_request(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_doc/test-id' => Http::response([
                'result' => 'deleted',
            ], 200),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $service->deleteDocument('test_index', 'test-id');

        // No exception thrown means success
        $this->assertTrue(true);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:9200/test_index/_doc/test-id'
                && $request->method() === 'DELETE';
        });
    }

    /**
     * Test that deleteDocument throws exception on failure.
     *
     * @return void
     */
    public function test_delete_document_throws_on_failure(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_doc/test-id' => Http::response([
                'error' => 'not_found',
            ], 404),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete document');

        $service->deleteDocument('test_index', 'test-id');
    }

    /**
     * Test that search sends POST request and returns hits.
     *
     * @return void
     */
    public function test_search_sends_post_request_and_returns_hits(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_search' => Http::response([
                'hits' => [
                    'hits' => [
                        ['_id' => '1', '_source' => ['name' => 'John']],
                        ['_id' => '2', '_source' => ['name' => 'Jane']],
                    ],
                ],
            ], 200),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $results = $service->search('test_index', ['query' => ['match_all' => new \stdClass()]]);

        $this->assertCount(2, $results);
        $this->assertEquals('1', $results[0]['_id']);
        $this->assertEquals('John', $results[0]['_source']['name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:9200/test_index/_search'
                && $request->method() === 'POST';
        });
    }

    /**
     * Test that search returns empty array when no hits.
     *
     * @return void
     */
    public function test_search_returns_empty_array_when_no_hits(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_search' => Http::response([
                'hits' => [
                    'hits' => [],
                ],
            ], 200),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $results = $service->search('test_index', ['query' => ['match_all' => new \stdClass()]]);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test that deleteByQuery sends POST request to delete_by_query endpoint.
     *
     * @return void
     */
    public function test_delete_by_query_sends_post_request(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_delete_by_query' => Http::response([
                'deleted' => 5,
            ], 200),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $result = $service->deleteByQuery('test_index', [
            'query' => ['term' => ['user' => 'test']],
        ]);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:9200/test_index/_delete_by_query'
                && $request->method() === 'POST';
        });
    }

    /**
     * Test that deleteByQuery returns false on failure.
     *
     * @return void
     */
    public function test_delete_by_query_returns_false_on_failure(): void
    {
        Http::fake([
            'http://localhost:9200/test_index/_delete_by_query' => Http::response([
                'error' => 'query_error',
            ], 400),
        ]);

        $service = new ElasticsearchService('http://localhost:9200');
        $result = $service->deleteByQuery('test_index', [
            'query' => ['invalid' => 'query'],
        ]);

        $this->assertFalse($result);
    }

    /**
     * Test that service uses configured host from config.
     *
     * @return void
     */
    public function test_service_uses_configured_host(): void
    {
        config()->set('encrypted-search.elasticsearch.host', 'http://custom-host:9200');

        Http::fake([
            'http://custom-host:9200/test_index/_doc/test-id' => Http::response([
                'result' => 'created',
            ], 201),
        ]);

        $service = new ElasticsearchService();
        $service->indexDocument('test_index', 'test-id', ['field' => 'value']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://custom-host:9200');
        });
    }

    /**
     * Test that custom host can be passed to constructor.
     *
     * @return void
     */
    public function test_custom_host_can_be_passed_to_constructor(): void
    {
        Http::fake([
            'http://custom-es:9200/test_index/_doc/test-id' => Http::response([
                'result' => 'created',
            ], 201),
        ]);

        $service = new ElasticsearchService('http://custom-es:9200');
        $service->indexDocument('test_index', 'test-id', ['field' => 'value']);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://custom-es:9200');
        });
    }
}
