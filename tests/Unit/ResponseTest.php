<?php

namespace Tests\Unit;

use Spark\Contracts\Support\Arrayable;
use Spark\Http\Response;
use Spark\Http\Resources\JsonResource;
use Spark\Testing\{TestCase, TestResponse};

final class ResponseTest extends TestCase
{
    public function testStringsAndStringableBodiesDefaultToHtml(): void
    {
        foreach ([
            '<h1>Hello</h1>',
            'plain-looking text',
            '',
            '0',
            null,
            42,
            new class implements \Stringable {
                public function __toString(): string
            {
                return '<p>Stringable</p>';
            }
            },
        ] as $content) {
            $response = new Response($content);
            (new TestResponse($response))->assertHeader('Content-Type', 'text/html; charset=utf-8');
            $this->assertSame((string) $content, $response->getContent());
            $this->assertSame($response->getHeaders(), $response->getHeaders());
        }
    }

    public function testExplicitMediaTypesAndCharsetsArePreserved(): void
    {
        foreach ([
            'text/plain',
            'text/html; charset=iso-8859-1',
            'text/csv; charset=utf-8',
            'application/xml',
            'application/octet-stream',
            'application/problem+json'
        ] as $type) {
            $response = new Response('data', headers: ['cOnTeNt-TyPe' => $type]);
            (new TestResponse($response))->assertHeader('Content-Type', $type);
            $this->assertSame(1, count($response->getHeaders()));
        }
    }

    public function testArrayArrayableAndResourceResponsesDefaultToJson(): void
    {
        foreach ([
            ['name' => 'বাংলা'],
            new class implements Arrayable {
                public function toArray(): array
            {
                return ['name' => 'বাংলা'];
            }
            }
        ] as $content) {
            $response = new Response($content);
            (new TestResponse($response))->assertHeader('Content-Type', 'application/json; charset=utf-8')
                ->assertExactJson(['name' => 'বাংলা']);
            $this->assertStringContainsString('বাংলা', $response->getContent());
        }
        (new TestResponse(new Response(new JsonResource(['id' => 7]))))
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertExactJson(['data' => ['id' => 7]]);
    }

    public function testAutomaticJsonRespectsExplicitVendorContentType(): void
    {
        (new TestResponse(new Response(['message' => 'failed'], 400, ['content-type' => 'application/problem+json'])))
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson(['message' => 'failed']);
    }

    public function testHeaderReplacementIsCaseInsensitiveIncludingJsonHelper(): void
    {
        $response = new Response('old', headers: ['content-type' => 'text/plain', 'Content-Type' => 'text/csv']);
        $this->assertSame(1, count($response->getHeaders()));
        $response->json(['ok' => true])->setHeader('X-Test', 'first')->setHeader('x-test', 'last');
        (new TestResponse($response))->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertHeader('X-Test', 'last')->assertExactJson(['ok' => true]);
        $this->assertSame(2, count($response->getHeaders()));
    }

    public function testInferredTypesFollowReplacementContentAfterPreparation(): void
    {
        $response = new Response('<p>old</p>');
        $check = new TestResponse($response);
        $check->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setContent(['ok' => true]);
        $check->assertHeader('Content-Type', 'application/json; charset=utf-8')->assertExactJson(['ok' => true]);
        $response->withData('<p>new</p>');
        $check->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertContent('<p>new</p>');
        $response->setHeader('CONTENT-TYPE', 'text/plain')->setContent('literal <p>');
        $check->assertHeader('Content-Type', 'text/plain');
    }

    public function testBodylessStatusesDoNotInventContentTypesOrSendContent(): void
    {
        foreach ([100, 101, 103, 204, 205, 304] as $status) {
            $response = new Response(['should' => 'not encode'], $status);
            (new TestResponse($response))->assertContent('')->assertHeaderMissing('Content-Type');
        }
        $response = new Response(['ok' => true]);
        $response->getHeaders();
        $response->noContent();
        (new TestResponse($response))->assertNoContent()->assertHeaderMissing('Content-Type');
    }

    public function testBodylessResponsesPreserveExplicitMetadataAndCorrectLengths(): void
    {
        foreach ([103, 204] as $status) {
            (new TestResponse(new Response('body', $status, ['content-length' => '4'])))
                ->assertContent('')->assertHeaderMissing('Content-Length');
        }
        (new TestResponse(new Response('body', 205, ['content-length' => '4'])))
            ->assertContent('')->assertHeader('Content-Length', '0');
        (new TestResponse(new Response('', 304, ['Content-Length' => '123', 'Content-Type' => 'text/plain', 'ETag' => '"v1"'])))
            ->assertContent('')->assertHeader('Content-Length', '123')
            ->assertHeader('Content-Type', 'text/plain')->assertHeader('ETag', '"v1"');
    }

    public function testRedirectKeepsItsStatusAndLocationDuringPreparation(): void
    {
        (new TestResponse((new Response())->redirect('/next', 303)))
            ->assertRedirect('/next', 303)->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function testInvalidAutomaticJsonFailsExplicitly(): void
    {
        $this->expectException(\JsonException::class);
        (new Response(['invalid' => "\xB1\x31"]))->getContent();
    }
    public function testAppendingToPreparedJsonPreservesItsRepresentationType(): void
    {
        $response = new Response(['ok' => true]);
        $response->getContent();
        $response->write("\n");
        (new TestResponse($response))->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertExactJson(['ok' => true]);
    }

    public function testInvalidExplicitJsonFailsExplicitly(): void
    {
        $this->expectException(\JsonException::class);
        (new Response())->json(['invalid' => "\xB1\x31"]);
    }

    public function testJsonEncodingFailureDoesNotPartiallyMutateResponse(): void
    {
        $response = new Response('before', 418, ['Content-Type' => 'text/plain']);
        try {
            $response->json(['invalid' => "\xB1\x31"], 201);
            $this->fail('Expected JSON encoding to fail.');
        } catch (\JsonException) {
            (new TestResponse($response))->assertStatus(418)->assertContent('before')
                ->assertHeader('Content-Type', 'text/plain');
        }
    }

}
