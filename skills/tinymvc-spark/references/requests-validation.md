# Requests, Validation, and Input

> **Read when:** validating requests, defining FormRequests, handling errors/uploads, or importing PSR requests.
> **Related:** [Routing and controllers](routing-controllers.md) · [Responses](responses.md) · [Files and storage](files-storage.md) · [Auth and gate](auth-gate.md)
> `validated()` returns `Spark\Http\Input`, not a plain array. Validator factories take rules before data.

Contents:

- [FormRequests](#formrequests)
- [Input shaping and extraction](#input-shaping-and-extraction)
- [Rules and constrained validation](#rules-and-constrained-validation)
- [Errors and uploads](#errors-and-uploads)
- [PSR requests, bodies, and routing boundaries](#psr-requests-bodies-and-routing-boundaries)

## FormRequests

Use `Spark\Foundation\Http\FormRequest` for substantial writes and authorization. Generate with `php spark make:request StorePostRequest`; inject it into the controller action.

```php
namespace App\Http\Requests;

use Spark\Foundation\Http\FormRequest;
use Spark\Facades\Auth;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // Add the operation's ownership/ability check.
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => ['required', 'alpha_dash', ['unique' => ['posts', 'slug']]],
            'category_id' => ['required', 'integer', ['exists' => ['categories', 'id']]],
            'tags' => 'nullable|array',
            'tags.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'A title is required.'];
    }
}
```

Authorization and validation run before the action. False authorization yields 403; validation follows the request consumer: JSON 422, browser redirect/flash, or the installed [FireLine](fireline.md)/[Inertia](inertia.md) protocol. Do not assume every validation failure is JSON.

`attributes()` supplies input data (default: `all()`), not friendly labels. `passedValidation()` runs after non-precognitive success; do not rely on it to supply a missing required field.

```php
public function store(StorePostRequest $request): \Spark\Http\Response
{
    $post = Post::create($request->validated()->merge(['user_id' => auth()->id()]));

    return PostResource::make($post)->response(\Spark\Http\Response::STATUS_CREATED);
}
```

Reserve inline `$request->validate($rules)` for small endpoints such as search filters. It also returns Input. `Validator::make($rules, $data)` is **rules first**.

## Input shaping and extraction

Input supports Arrayable, ArrayAccess, iteration, JSON, string conversion, and property reads. Pass it directly to compatible model/query/auth APIs; convert to an array only for an API that needs one.

```php
$input = $request->validated();
$post = Post::create($input->except('tags')->merge(['user_id' => auth()->id()]));
$credentials = $input->only('email', 'password');
Auth::attempt($credentials, $request->boolean('remember'));
```

`only()`/`except()` accept an array or variadic keys; `merge()`, `mergeRecursive()`, `map()`, and `filter()` return new Input values. Server-owned fields should override client values. Validation is not authorization of related IDs.

| Accessor | Result/use |
| --- | --- |
| `string('title')`, `number('quantity')`, `float('price')` | Typed value or null |
| `boolean('active')` | Boolean conversion |
| `email('email')`, `url('website')` | Sanitized value; still validate input |
| `date('due_date')` | Date string |
| `collect('tags')`, `str('title')` | Collection / Stringable |
| `slug('title')` | Slug conversion |
| `password('password', hash: true)` | Password hash |
| `text()`, `escapeHtml()` | Preserve integer/string zero as `'0'` |

`Request::filled()` accepts `0`, `'0'`, and `false`; missing/null/blank strings/empty arrays are unfilled. `validated()` requires successful validation; failed revalidation or request replacement clears previous results.

## Rules and constrained validation

Rules accept pipe strings, `Spark\Http\Validation\Rule` builders, closures, `Spark\Contracts\Http\RuleContract` implementations, and parameter arrays such as `['in' => ['draft', 'published']]`.

```php
use Spark\Http\Validation\Rule;

$rules = [
    'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($trustedUserId)],
    'category_id' => ['required', Rule::exists('categories', 'id')],
    'role' => ['required', Rule::in(['editor', 'author'])],
    'password' => ['required', Rule::password(8)->letters()->numbers()->symbols()],
    'company' => [Rule::requiredIf(fn() => request('is_business'))],
    'custom' => [function (string $attribute, mixed $value, \Closure $fail) {
        if ($value === 'forbidden') {
            $fail('The :attribute cannot be forbidden.');
        }
    }],
];
```

Keep database rule objects intact when adding `where()` constraints; `ignore()` takes a trusted ID. `letters()` needs any letter; `mixedCase()` requires both cases. The facade and `Spark\Http\Validation\Validator` are native; `Spark\Http\Validator` remains compatible.

All built-in string rules and aliases (`Http/Validation/Validator.php`):

| Family | Supported rules |
| --- | --- |
| Presence | `required`, `required_if`, `required_unless`, `present`, `filled`, `nullable`, `sometimes`, `prohibited` |
| Text | `string` / `text` / `char`, `alpha`, `alpha_num` / `alphanumeric`, `alpha_dash`, `ascii`, `starts_with`, `ends_with`, `contains`, `not_contains`, `lowercase`, `uppercase`, `regex` |
| Numbers | `numeric` / `number`, `integer` / `int`, `float`, `decimal`, `digits`, `digits_between`, `min_digits`, `max_digits`, `min_value`, `max_value` |
| Size/comparison | `min` / `minimum`, `max` / `maximum`, `size` / `length`, `between`, `same` / `equal` / `same_as`, `confirmed`, `gt`, `gte`, `lt` / `ls`, `lte` |
| Database | `['unique' => ['table', 'column', 'ignore_id']]`, `['exists' => ['table', 'column']]`, `not_exists` |
| Collections | `array` / `list`, `distinct` (`strict`, `ignore_case` options), `in`, `not_in`; wildcard fields (`items.*.name`) |
| Formats | `email` / `mail`, `url` / `link`, `ip`, `ipv4`, `ipv6`, `mac_address`, `uuid`, `json`, `date`, `date_format`, `before`, `after` |
| Files/flags | `file`, `image`, `mimes`, `boolean` / `bool`, `accepted`, `declined`, `password` |

Fluent `Rule` factories: `in()`, `notIn()`, `unique()`, `exists()`, `notExists()`, `requiredIf()`, `requiredUnless()`, `prohibitedIf()`, `password()`.

`list` only checks for an array; `decimal` checks numeric input, not decimal places. Unknown string rules pass without validation. Use a supported/custom rule rather than assuming Laravel rules such as `bail`, `enum`, or `required_with` exist.

Use [API discovery](api-discovery.md) for uncertain parameters; Laravel rule syntax is not proof of compatibility. Precognition field lists accept comma-separated names, whitespace, and repeated header values.

## Errors and uploads

`errors()` returns `Spark\Http\InputErrors`: `any()`, `has($field)`, `first($field)`, `get($field)`, `all()`. `addMessage()` appends to a string/list; string conversion yields the first message or `''`.

```blade
@error('email')
    <p>{{ $message }}</p>
@enderror
@errors('tags')
    <p>{{ $message }}</p>
@enderrors
```

File rules accept genuine PHP uploads and PSR `UploadedFileInterface`, not arbitrary local-path arrays. Size rules use actual bytes in KB (1024 bytes); ordinary arrays use item counts. PSR content checks preserve the stream position and require seekable streams.

Use `hasFile()`, `file()`, and `moveFile()`; read [storage](files-storage.md) for disk paths and upload options. Verify invalid types, sparse/nested uploads, and authorization before storage writes.

## PSR requests, bodies, and routing boundaries

- `toPsrRequest()` exports a snapshot through the bound factory, preserving streams, headers, query/body fields, cookies/server values, protocol, request target, and nested uploads. PSR `with*()` methods return new messages; shared streams remain mutable.
- `Request::fromPsrRequest()` avoids globals/subclass constructors. Parsed null/object bodies survive unchanged export; native edits export arrays. General attributes are separate from matched route parameters. FormRequest validation still runs through container resolution.
- `getBody()` returns the stream. `getRawBody()` preserves a seekable cursor; non-seekable streams are consumed. Native capture parses JSON/`+json` and URL-encoded POST/PUT/PATCH/DELETE by Content-Type. Large JSON integers remain strings; invalid/scalar JSON produces empty input. PHP owns multipart parsing. PSR imports use the supplied parsed body without decoding the stream.
- Header names are case-insensitive; CGI server keys normalize on reads/writes. `is('account/*')` ignores surrounding slashes.
- `getUri()` retains the request target/query; `getPath()` is its encoded path. Routing decodes captured parameters once, preserving literal `+` and encoded slash boundaries. Raw URI query text and parsed query parameters remain separate.
- Forwarded HTTPS is trusted only for configured `app.trusted_proxies`; IPv6 hosts and non-default ports are supported.

Verify permitted fields, invalid input, wrong-owner access, failed revalidation, and the real client's headers/response contract.
