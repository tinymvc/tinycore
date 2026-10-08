<?php

namespace Tests\Unit;

use Spark\Testing\TestCase;
use Spark\Utils\JWT;

final class JwtTest extends TestCase
{
    public function test_hmac_signatures_reject_tampering_and_wrong_keys(): void
    {
        foreach (['HS256', 'HS384', 'HS512'] as $algorithm) {
            $token = JWT::encode(['sub' => 7, 'scope' => 'read'], 'test-secret', $algorithm);
            $this->assertSame(7, JWT::decode($token, 'test-secret')->sub);
            $this->assertSame($algorithm, JWT::getHeader($token)->alg);
            $this->assertThrows(\UnexpectedValueException::class, fn () => JWT::decode($token, 'wrong-key'));
            [$header, $payload, $signature] = explode('.', $token);
            $payload = rtrim(strtr(base64_encode('{"sub":99}'), '+/', '-_'), '=');
            $this->assertThrows(\UnexpectedValueException::class, fn () => JWT::decode("$header.$payload.$signature", 'test-secret'));
            $this->assertThrows(\DomainException::class, fn () => JWT::decode($token, 'test-secret', allowedAlgorithms: ['RS256']));
        }

        $this->assertThrows(\UnexpectedValueException::class, fn () => JWT::decode('invalid', 'test-secret'));
        $this->assertThrows(\DomainException::class, fn () => JWT::encode(['sub' => 1], 'test-secret', 'none'));
    }

    public function test_rsa_algorithms_require_explicit_allowlisting(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA tests require OpenSSL.');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertTrue($key !== false);
        $this->assertTrue(openssl_pkey_export($key, $private));
        $public = openssl_pkey_get_details($key)['key'];

        foreach (['RS256', 'RS384', 'RS512'] as $algorithm) {
            $token = JWT::encode(['sub' => 11], $private, $algorithm);
            $this->assertSame(11, JWT::decode($token, $public, allowedAlgorithms: [$algorithm])->sub);
            $this->assertThrows(\DomainException::class, fn () => JWT::decode($token, $public));
            $this->assertThrows(\DomainException::class, fn () => JWT::encode(['sub' => 11], $public, 'HS256'));
        }
    }
}
