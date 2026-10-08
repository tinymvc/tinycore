<?php



final class HttpClientResponseTest extends \Spark\Testing\TestCase
{
    public function test_status_0_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 0);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_199_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 199);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_200_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 200);
        $this->assertSame(true, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(false, $response->failed());
    }

    public function test_status_204_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 204);
        $this->assertSame(true, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(false, $response->failed());
    }

    public function test_status_299_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 299);
        $this->assertSame(true, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(false, $response->failed());
    }

    public function test_status_300_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 300);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(true, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_304_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 304);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(true, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_399_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 399);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(true, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_400_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 400);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(true, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_422_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 422);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(true, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_499_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 499);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(true, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_500_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 500);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(true, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_503_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 503);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(true, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_599_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 599);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(true, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_status_600_classification(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(status: 600);
        $this->assertSame(false, $response->isSuccessful());
        $this->assertSame(false, $response->isRedirect());
        $this->assertSame(false, $response->isClientError());
        $this->assertSame(false, $response->isServerError());
        $this->assertSame(true, $response->failed());
    }

    public function test_json_object(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '{"data":{"id":1}}');
        $this->assertSame(['data' => ['id' => 1]], $response->json());
        $this->assertSame('{"data":{"id":1}}', $response->body());
    }

    public function test_json_list(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '[1,2]');
        $this->assertSame([1, 2], $response->json());
        $this->assertSame('[1,2]', $response->body());
    }

    public function test_json_null(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: 'null');
        $this->assertSame(null, $response->json());
        $this->assertSame('null', $response->body());
    }

    public function test_json_false(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: 'false');
        $this->assertSame(false, $response->json());
        $this->assertSame('false', $response->body());
    }

    public function test_json_number(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '42');
        $this->assertSame(42, $response->json());
        $this->assertSame('42', $response->body());
    }

    public function test_json_string(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '"text"');
        $this->assertSame('text', $response->json());
        $this->assertSame('"text"', $response->body());
    }

    public function test_json_malformed(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '{');
        $this->assertSame([], $response->json());
        $this->assertSame('{', $response->body());
    }

    public function test_json_empty(): void
    {
        $response = new \Spark\Http\Client\HttpResponse(body: '');
        $this->assertSame([], $response->json());
        $this->assertSame('', $response->body());
    }

    public function test_body_mutation_invalidates_decoded_cache(): void
    {
        $r = new \Spark\Http\Client\HttpResponse(body: '{"id":1}');
        $this->assertSame(1, $r->json('id'));
        $r->body = '{"id":2}';
        $this->assertSame(2, $r->json('id'));
    }

    public function test_nested_null_is_present(): void
    {
        $r = new \Spark\Http\Client\HttpResponse(body: '{"data":{"value":null}}');
        $this->assertTrue($r->has('data.value'));
        $this->assertNull($r->get('data.value', 'fallback'));
        $this->assertSame('fallback', $r->get('missing', 'fallback'));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $r = new \Spark\Http\Client\HttpResponse(headers: ['Content-Type' => 'application/json']);
        $this->assertSame('application/json', $r->header('content-type'));
        $this->assertSame('fallback', $r->header('Missing', 'fallback'));
    }

}
