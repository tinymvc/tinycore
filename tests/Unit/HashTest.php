<?php

use Spark\Hash;
use Spark\Testing\TestCase;
use Spark\Exceptions\Hash\{DecryptionFailedException, EncryptionFailedException, InvalidEncryptingKeyException};

final class HashTest extends TestCase
{
    private function hash(): Hash { return new Hash(str_repeat('k', 32)); }

    public function test_short_key_is_rejected(): void
    {
        $this->assertThrows(InvalidEncryptingKeyException::class, fn () => new Hash(str_repeat('k', 31)));
    }

    public function test_binary_and_empty_plaintexts_roundtrip_with_random_ivs(): void
    {
        $hash = $this->hash();
        foreach (['', "\0\xffhello", 'বাংলা'] as $plain) {
            $encrypted = $hash->encrypt($plain);
            $this->assertSame($plain, $hash->decrypt($encrypted));
            $this->assertNotSame($encrypted, $hash->encrypt($plain));
        }
    }

    public function test_wrong_key_is_rejected(): void
    {
        $encrypted = $this->hash()->encrypt('private');
        $this->assertThrows(DecryptionFailedException::class, fn () => (new Hash(str_repeat('x', 32)))->decrypt($encrypted));
    }

    public function test_every_authenticated_component_rejects_tampering(): void
    {
        $hash = $this->hash();
        $original = json_decode(base64_decode($hash->encrypt('private')), true);
        foreach (['iv', 'cipherText', 'hmac'] as $key) {
            $data = $original;
            $bytes = base64_decode($data[$key]);
            $bytes[0] = chr(ord($bytes[0]) ^ 1);
            $data[$key] = base64_encode($bytes);
            $this->assertThrows(DecryptionFailedException::class, fn () => $hash->decrypt(base64_encode(json_encode($data))));
        }
    }

    public function test_malformed_envelopes_have_consistent_exception_type(): void
    {
        $hash = $this->hash();
        foreach (['!', '', base64_encode('null'), base64_encode('{}'), base64_encode('{')] as $value) {
            $this->assertThrows(DecryptionFailedException::class, fn () => $hash->decrypt($value));
        }
        foreach (['iv', 'cipherText', 'hmac'] as $key) {
            $data = json_decode(base64_decode($hash->encrypt('private')), true);
            $data[$key] = ['unexpected'];
            $this->assertThrows(DecryptionFailedException::class, fn () => $hash->decrypt(base64_encode(json_encode($data))));
        }
    }

    public function test_array_roundtrip_preserves_types(): void
    {
        $hash = $this->hash();
        $data = ['zero' => 0, 'false' => false, 'null' => null, 'nested' => ['বাংলা']];
        $this->assertSame($data, $hash->decryptArray($hash->encryptArray($data)));
        $this->assertThrows(DecryptionFailedException::class, fn () => $hash->decryptArray($hash->encrypt('42')));
    }

    public function test_unencodable_array_is_rejected_instead_of_losing_data(): void
    {
        $this->assertThrows(EncryptionFailedException::class, fn () => $this->hash()->encryptArray(['invalid' => "\xff"]));
    }

    public function test_password_verification_and_cost_upgrade(): void
    {
        $hash = $this->hash();
        $hash->setPasswordAlgorithm(PASSWORD_BCRYPT);
        $hash->setPasswordOptions(['cost' => 4]);
        $encoded = $hash->password('secret');
        $this->assertTrue($hash->isHashed($encoded));
        $this->assertTrue($hash->verify('secret', $encoded));
        $this->assertFalse($hash->password('wrong', $encoded));
        $this->assertFalse($hash->needsRehash($encoded));
        $hash->setPasswordOptions(['cost' => 5]);
        $this->assertTrue($hash->needsRehash($encoded));
        $this->assertFalse($hash->isHashed('plain text'));
        $this->assertThrows(InvalidArgumentException::class, fn () => $hash->setPasswordAlgorithm('invalid'));
    }

    public function test_hmac_is_keyed_and_validates_exact_input(): void
    {
        $hash = $this->hash();
        $digest = $hash->make('message');
        $this->assertTrue($hash->check('message', $digest));
        $this->assertFalse($hash->check('Message', $digest));
        $this->assertNotSame($digest, (new Hash(str_repeat('x', 32)))->make('message'));
    }
}
