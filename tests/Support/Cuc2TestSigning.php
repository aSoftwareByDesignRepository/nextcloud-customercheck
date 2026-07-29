<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Support;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCA\CustomerCheck\License\Cuc2Codec;

/**
 * Deterministic CUC2 signing for tests — public half is
 * {@see VendorPublicKey::TEST_PUBLIC_KEY_B64}.
 * Seed: sha256("customercheck-cuc2-test-signing-v1"). NEVER use outside tests.
 */
final class Cuc2TestSigning
{
	public const SEED_STRING = 'customercheck-cuc2-test-signing-v1';

	public static function secretKey(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return sodium_crypto_sign_secretkey($keypair);
	}

	public static function publicKeyB64(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($keypair));
	}

	/**
	 * Signs a canonicalised payload into a full CUC2 wire key.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function signPayload(array $payload): string
	{
		return self::signRawBytes(Cuc2Codec::canonicalJson($payload));
	}

	/**
	 * Signs arbitrary bytes (for non-canonical / tampered-payload tests).
	 */
	public static function signRawBytes(string $payloadBytes): string
	{
		$signature = sodium_crypto_sign_detached($payloadBytes, self::secretKey());
		return Cuc2Codec::FORMAT . '.'
			. VendorPublicKey::base64urlEncode($payloadBytes) . '.'
			. VendorPublicKey::base64urlEncode($signature);
	}

	/**
	 * Convenience: a fully valid wire key for the given overrides.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function validKey(array $overrides = []): string
	{
		return self::signPayload(array_merge([
			'v' => 2,
			'product' => 'customercheck',
			'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 5,
		], $overrides));
	}
}
