<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class JsonResourceTest extends FrameworkTestCase
{
    public function test_json_resources(): void
    {
        $user = ['id' => 1, 'name' => 'Ada', 'secret' => 'hidden'];
        $resource = CoreFixtureResource::make($user);
        $expected = ['id' => 1, 'name' => 'Ada'];
        $this->assertSame($expected, $resource->resolve());
        $this->assertSame($expected, json_decode($resource->toJson(), true));
        $this->assertSame(['data' => $expected], json_decode($resource->response()->getContent(), true));
        $this->assertSame(['user' => $expected], json_decode(response(['user' => $resource])->getContent(), true));
        $this->assertSame(['user' => $expected], json_decode(json(['user' => $resource])->getContent(), true));
        $this->assertSame([$expected], json_decode(response(collect([$resource]))->getContent(), true));
        $this->assertSame([$expected], \Spark\Http\Resources\JsonResource::make(collect([$resource]))->resolve());
        $this->assertSame(['data' => $expected, 'meta' => ['version' => 1]], $resource->additional(['data' => ['secret' => 'bad'], 'meta' => ['version' => 1]])->responseData());
        $this->assertSame($expected, CoreFixtureResource::make($user)->withoutWrapping()->responseData());
        $this->assertSame(['user' => $expected], CoreFixtureResource::make($user)->wrap('user')->responseData());
        $this->assertSame(['data' => $expected, 'ok' => true], CoreFixtureResource::make($user)->withoutWrapping()->additional(['ok' => true])->responseData());

        $model = (new CoreFixtureResourceModel)->fill(['id' => 1, 'name' => 'Ada', 'posts_count' => 0]);
        $modelResource = CoreFixtureResource::make($model);
        $this->assertSame([...$expected, 'posts_count' => 0], $modelResource->resolve());
        $model->setRelation('child', null);
        $this->assertSame([...$expected, 'child' => null, 'posts_count' => 0], $modelResource->resolve());
        $model->setRelation('child', (new CoreFixtureResourceModel)->fill(['id' => 2, 'name' => 'Grace']));
        $this->assertSame(['id' => 2, 'name' => 'Grace'], $modelResource->resolve()['child']);

        $conditional = new class([]) extends \Spark\Http\Resources\JsonResource {
            public function toArray(?\Spark\Http\Request $request = null): array {
                return [
                    'omit' => $this->when(false, fn () => throw new \LogicException('Not lazy')),
                    'keep_null' => $this->when(true, null),
                    'zero' => $this->whenNotNull(0),
                    'false' => $this->unless(false, false),
                    'default' => $this->when(false, 'unused', fn () => 'fallback'),
                    'deferred_omit' => fn () => $this->when(false, 'unused'),
                    'list' => [1, $this->whenNotNull(null), 3],
                    'date' => new \DateTimeImmutable('2026-09-29T00:00:00+00:00'),
                ];
            }
        };
        $this->assertSame(['keep_null' => null, 'zero' => 0, 'false' => false, 'default' => 'fallback', 'list' => [1, 3], 'date' => '2026-09-29T00:00:00+00:00'], $conditional->resolve());
        $arrayable = new class implements \Spark\Contracts\Support\Arrayable {
            public function toArray(): array
            {
                return ['name' => 'Ada'];
            }
        };
        $this->assertSame('Ada', \Spark\Http\Resources\JsonResource::make($arrayable)->whenHas('name'));

        $collection = CoreFixtureResource::collection(collect([9 => $user]));
        $this->assertSame([$expected], $collection->resolve());
        $this->assertSame([9 => $expected], $collection->preserveKeys()->resolve());
        $generator = (function () use ($user) { yield $user; })();
        $generated = CoreFixtureResource::collection($generator);
        $this->assertSame($generated->resolve(), $generated->resolve());
        $this->assertSame(['data' => []], CoreFixtureResource::collection([])->responseData());

        request()->setQueryParam('page', 2);
        $page = new \Spark\Utils\Paginator(total: 3, limit: 2);
        $page->setData([$user]);
        $document = CoreFixtureResource::collection($page)->withoutWrapping()->additional(['meta' => ['version' => 1]])->responseData();
        $this->assertSame([$expected], $document['data']);
        $this->assertSame(2, $document['meta']['current_page']);
        $this->assertSame(3, $document['meta']['total']);
        $this->assertSame(1, $document['meta']['version']);
        $this->assertNull($document['links']['next']);
        $this->assertSame($user, $page->items()[0]);

        $this->app->withMiddleware(register: ['cors' => CoreFixtureCors::class]);
        \Spark\Facades\Route::get('/resources', fn () => CoreFixtureResource::make($user))->middleware('cors');
        $this->getJson('/resources', ['Origin' => 'https://app.example.com'])->assertOk()
            ->assertJson(['data' => $expected])->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $this->getJson('/resources', ['X-Show-Secret' => 'yes'])->assertJsonPath('data.secret', 'hidden');
        \Spark\Facades\Route::post('/resources', fn () => CoreFixtureResource::make($user)->response(201, ['X-Resource' => 'created']));
        $this->postJson('/resources')->assertCreated()->assertHeader('X-Resource', 'created')->assertJson(['data' => $expected]);
    }
}
