<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class RequestBoundaryTest extends FrameworkTestCase
{
    public function test_bearer_valid(): void
    {
        request()->headers->put('authorization', 'Bearer abc.def');
        $this->assertSame('abc.def', request()->bearerToken());
        $this->assertSame('Bearer abc.def', request()->header('Authorization'));
    }

    public function test_bearer_lowercase(): void
    {
        request()->headers->put('authorization', 'bearer token');
        $this->assertSame('token', request()->bearerToken());
        $this->assertSame('bearer token', request()->header('Authorization'));
    }

    public function test_bearer_tab(): void
    {
        request()->headers->put('authorization', "Bearer\ttoken");
        $this->assertSame('token', request()->bearerToken());
        $this->assertSame("Bearer\ttoken", request()->header('Authorization'));
    }

    public function test_bearer_surrounding_whitespace(): void
    {
        request()->headers->put('authorization', '  Bearer token  ');
        $this->assertSame('token', request()->bearerToken());
        $this->assertSame('  Bearer token  ', request()->header('Authorization'));
    }

    public function test_bearer_empty(): void
    {
        request()->headers->put('authorization', 'Bearer ');
        $this->assertSame(null, request()->bearerToken());
        $this->assertSame('Bearer ', request()->header('Authorization'));
    }

    public function test_bearer_wrong_scheme(): void
    {
        request()->headers->put('authorization', 'Basic dXNlcjpwYXNz');
        $this->assertSame(null, request()->bearerToken());
        $this->assertSame('Basic dXNlcjpwYXNz', request()->header('Authorization'));
    }

    public function test_bearer_embedded_scheme(): void
    {
        request()->headers->put('authorization', 'x Bearer token');
        $this->assertSame(null, request()->bearerToken());
        $this->assertSame('x Bearer token', request()->header('Authorization'));
    }

    public function test_bearer_extra_word(): void
    {
        request()->headers->put('authorization', 'Bearer token extra');
        $this->assertSame(null, request()->bearerToken());
        $this->assertSame('Bearer token extra', request()->header('Authorization'));
    }

    public function test_bearer_line_break(): void
    {
        request()->headers->put('authorization', "Bearer tok\nen");
        $this->assertSame(null, request()->bearerToken());
        $this->assertSame("Bearer tok\nen", request()->header('Authorization'));
    }

    public function test_basic_valid(): void
    {
        request()->headers->put('authorization', 'Basic dXNlcjpwYXNzOndvcmQ=');
        $this->assertSame(['username' => 'user', 'password' => 'pass:word'], request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_lowercase(): void
    {
        request()->headers->put('authorization', 'basic dXNlcjpwYXNzOndvcmQ=');
        $this->assertSame(['username' => 'user', 'password' => 'pass:word'], request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_leading_spaces(): void
    {
        request()->headers->put('authorization', '  Basic dXNlcjpwYXNzOndvcmQ=  ');
        $this->assertSame(['username' => 'user', 'password' => 'pass:word'], request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_invalid_base64(): void
    {
        request()->headers->put('authorization', 'Basic !!!!');
        $this->assertSame(null, request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_missing_colon(): void
    {
        request()->headers->put('authorization', 'Basic dXNlcg==');
        $this->assertSame(null, request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_embedded_scheme(): void
    {
        request()->headers->put('authorization', 'x Basic dXNlcjpwYXNzOndvcmQ=');
        $this->assertSame(null, request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_trailing_word(): void
    {
        request()->headers->put('authorization', 'Basic dXNlcjpwYXNzOndvcmQ= extra');
        $this->assertSame(null, request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_basic_empty_password(): void
    {
        request()->headers->put('authorization', 'Basic dXNlcjo=');
        $this->assertSame(['username' => 'user', 'password' => ''], request()->basicAuth());
        $this->assertNull(request()->bearerToken());
    }

    public function test_method_flags_get(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'get';
        $r = new \Spark\Http\Request();
        $this->assertSame('GET', $r->getMethod());
        $this->assertSame(false, $r->isPostBack());
        $this->assertSame(true, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $r = new \Spark\Http\Request();
        $this->assertSame('POST', $r->getMethod());
        $this->assertSame(true, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(true, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_put(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'put';
        $r = new \Spark\Http\Request();
        $this->assertSame('PUT', $r->getMethod());
        $this->assertSame(true, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(true, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_patch(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'patch';
        $r = new \Spark\Http\Request();
        $this->assertSame('PATCH', $r->getMethod());
        $this->assertSame(true, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_delete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'delete';
        $r = new \Spark\Http\Request();
        $this->assertSame('DELETE', $r->getMethod());
        $this->assertSame(true, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(true, $r->isDelete());
    }

    public function test_method_flags_head(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'head';
        $r = new \Spark\Http\Request();
        $this->assertSame('HEAD', $r->getMethod());
        $this->assertSame(false, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_options(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'options';
        $r = new \Spark\Http\Request();
        $this->assertSame('OPTIONS', $r->getMethod());
        $this->assertSame(false, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_method_flags_trace(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'trace';
        $r = new \Spark\Http\Request();
        $this->assertSame('TRACE', $r->getMethod());
        $this->assertSame(false, $r->isPostBack());
        $this->assertSame(false, $r->isGet());
        $this->assertSame(false, $r->isPost());
        $this->assertSame(false, $r->isPut());
        $this->assertSame(false, $r->isDelete());
    }

    public function test_proxy_untrusted_v4(): void
    {
        config(['app.trusted_proxies' => []]);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $r = new \Spark\Http\Request();
        $this->assertSame('192.0.2.1', $r->ip());
        $this->assertSame('192.0.2.1', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_trusted_v4_range(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $r = new \Spark\Http\Request();
        $this->assertSame('198.51.100.1', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_outside_v4_range(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.1.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $r = new \Spark\Http\Request();
        $this->assertSame('10.0.1.5', $r->ip());
        $this->assertSame('10.0.1.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_trusted_v6_range(): void
    {
        config(['app.trusted_proxies' => ['2001:db8::/64']]);
        $_SERVER['REMOTE_ADDR'] = '2001:db8::5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $r = new \Spark\Http\Request();
        $this->assertSame('198.51.100.1', $r->ip());
        $this->assertSame('2001:db8::5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_malformed_rightmost(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, invalid';
        $r = new \Spark\Http\Request();
        $this->assertSame('10.0.0.5', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_quoted_address(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '"198.51.100.1"';
        $r = new \Spark\Http\Request();
        $this->assertSame('198.51.100.1', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_ipv6_with_port(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::1]:443';
        $r = new \Spark\Http\Request();
        $this->assertSame('2001:db8::1', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_invalid_port(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1:99999';
        $r = new \Spark\Http\Request();
        $this->assertSame('10.0.0.5', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

    public function test_proxy_stop_at_untrusted_hop(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.0/24']]);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1, 198.51.100.1';
        $r = new \Spark\Http\Request();
        $this->assertSame('198.51.100.1', $r->ip());
        $this->assertSame('10.0.0.5', $r->server('REMOTE_ADDR'));
    }

}
