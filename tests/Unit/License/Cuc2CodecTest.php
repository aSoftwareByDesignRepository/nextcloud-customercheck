<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\License;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCA\CustomerCheck\License\Cuc2Codec;
use OCA\CustomerCheck\Tests\Support\Cuc2TestSigning;
use PHPUnit\Framework\TestCase;

/**
 * CUC2 wire format verification (COMPANION-APP §5.1). Uses the deterministic
 * fixture signing key via the CUC_VENDOR_PUBLIC_KEY_B64 override.
 */
final class Cuc2CodecTest extends TestCase
{
	/** @var array<string, mixed> */
	private static array $fixture;

	public static function setUpBeforeClass(): void
	{
		$raw = file_get_contents(dirname(__DIR__, 2) . '/fixtures/license_cuc2_golden.json');
		self::assertNotFalse($raw);
		self::$fixture = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
	}

	public static function tearDownAfterClass(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function validPayload(): array
	{
		return [
			'v' => 2,
			'product' => 'customercheck',
			'customerId' => 'test',
			'issuedAt' => '2026-06-10',
			'validUntil' => '2027-12-31',
			'mobileSeats' => 5,
		];
	}

	// ── Happy path ──────────────────────────────────────────────────────

	public function testFixtureKeyMatchesTestSigner(): void
	{
		$this->assertSame(self::$fixture['publicKeyB64'], Cuc2TestSigning::publicKeyB64());
		$this->assertSame(VendorPublicKey::TEST_PUBLIC_KEY_B64, Cuc2TestSigning::publicKeyB64());
	}

	public function testFixtureWireKeyVerifies(): void
	{
		$wireKey = (string)self::$fixture['wireKey'];
		$this->assertSame('', Cuc2Codec::classifyError($wireKey));

		$verified = Cuc2Codec::parseAndVerify($wireKey);
		$this->assertNotNull($verified);
		$this->assertSame(self::$fixture['payload'], $verified['payload']);
		$this->assertSame(self::$fixture['payloadB64'], $verified['payloadB64']);
		$this->assertSame(self::$fixture['signatureB64'], $verified['signatureB64']);
	}

	public function testFreshlySignedKeyVerifies(): void
	{
		$wireKey = Cuc2TestSigning::signPayload($this->validPayload());
		$this->assertSame('', Cuc2Codec::classifyError($wireKey));
		$this->assertNotNull(Cuc2Codec::parseAndVerify($wireKey));
	}

	public function testWhitespaceInWireKeyIsTolerated(): void
	{
		$wireKey = Cuc2TestSigning::signPayload($this->validPayload());
		$wrapped = substr($wireKey, 0, 20) . "\n  " . substr($wireKey, 20) . " \n";
		$this->assertSame('', Cuc2Codec::classifyError($wrapped));
	}

	// ── Format failures ─────────────────────────────────────────────────

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformedKeys(): array
	{
		return [
			'empty' => [''],
			'random text' => ['not-a-license'],
			'wrong prefix' => ['PC2.abc.def'],
			'lowercase prefix' => ['cuc2.abc.def'],
			'two parts' => ['CUC2.onlypayload'],
			'four parts' => ['CUC2.a.b.c'],
			'invalid base64 payload' => ['CUC2.$$$$.AAAA'],
			'invalid base64 signature' => ['CUC2.eyJ2IjoyfQ.$$$$'],
			'short signature' => ['CUC2.eyJ2IjoyfQ.QUJD'],
		];
	}

	/** @dataProvider malformedKeys */
	public function testMalformedKeysClassifyAsInvalidFormat(string $wireKey): void
	{
		$this->assertSame(Cuc2Codec::ERROR_INVALID_FORMAT, Cuc2Codec::classifyError($wireKey));
		$this->assertNull(Cuc2Codec::parseAndVerify($wireKey));
	}

	// ── Signature failures ──────────────────────────────────────────────

	public function testTamperedPayloadFailsSignature(): void
	{
		$wireKey = Cuc2TestSigning::signPayload($this->validPayload());
		[$fmt, $payloadB64, $sigB64] = explode('.', $wireKey);

		$tampered = $this->validPayload();
		$tampered['mobileSeats'] = 10000;
		$tamperedB64 = VendorPublicKey::base64urlEncode(Cuc2Codec::canonicalJson($tampered));

		$this->assertSame(
			Cuc2Codec::ERROR_INVALID_SIGNATURE,
			Cuc2Codec::classifyError($fmt . '.' . $tamperedB64 . '.' . $sigB64),
		);
	}

	public function testTamperedSignatureFails(): void
	{
		$wireKey = Cuc2TestSigning::signPayload($this->validPayload());
		[$fmt, $payloadB64, $sigB64] = explode('.', $wireKey);
		$raw = VendorPublicKey::base64urlDecode($sigB64);
		$this->assertNotFalse($raw);
		$raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";
		$flipped = VendorPublicKey::base64urlEncode($raw);

		$this->assertSame(
			Cuc2Codec::ERROR_INVALID_SIGNATURE,
			Cuc2Codec::classifyError($fmt . '.' . $payloadB64 . '.' . $flipped),
		);
	}

	public function testKeySignedByDifferentKeyFails(): void
	{
		$seed = hash('sha256', 'some-other-vendor-key', true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		$payloadBytes = Cuc2Codec::canonicalJson($this->validPayload());
		$signature = sodium_crypto_sign_detached($payloadBytes, sodium_crypto_sign_secretkey($keypair));
		$wireKey = 'CUC2.' . VendorPublicKey::base64urlEncode($payloadBytes)
			. '.' . VendorPublicKey::base64urlEncode($signature);

		$this->assertSame(Cuc2Codec::ERROR_INVALID_SIGNATURE, Cuc2Codec::classifyError($wireKey));
	}

	public function testProjectCheckKeyIsRejectedByProductField(): void
	{
		// Same vendor key family, wrong product — cross-product replay must fail.
		$payload = array_merge($this->validPayload(), ['product' => 'projectcheck']);
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($json);
		$wireKey = Cuc2TestSigning::signRawBytes($json);
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	// ── Payload failures (correctly signed, invalid content) ────────────

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidPayloads(): array
	{
		$base = [
			'v' => 2,
			'product' => 'customercheck',
			'customerId' => 'test',
			'issuedAt' => '2026-06-10',
			'validUntil' => '2027-12-31',
			'mobileSeats' => 5,
		];
		return [
			'wrong version' => [array_merge($base, ['v' => 1])],
			'version as string' => [array_merge($base, ['v' => '2'])],
			'wrong product' => [array_merge($base, ['product' => 'budgetcheck'])],
			'customerId too short' => [array_merge($base, ['customerId' => 'ab'])],
			'customerId uppercase' => [array_merge($base, ['customerId' => 'Test'])],
			'customerId too long' => [array_merge($base, ['customerId' => str_repeat('a', 65)])],
			'customerId not a string' => [array_merge($base, ['customerId' => 12345])],
			'issuedAt not a date' => [array_merge($base, ['issuedAt' => '2026-13-40'])],
			'validUntil not a date' => [array_merge($base, ['validUntil' => 'never'])],
			'validUntil before issuedAt' => [array_merge($base, ['issuedAt' => '2027-01-01', 'validUntil' => '2026-01-01'])],
			'zero seats' => [array_merge($base, ['mobileSeats' => 0])],
			'negative seats' => [array_merge($base, ['mobileSeats' => -1])],
			'seats above cap' => [array_merge($base, ['mobileSeats' => 10001])],
			'seats as string' => [array_merge($base, ['mobileSeats' => '5'])],
		];
	}

	/** @dataProvider invalidPayloads */
	public function testSignedButInvalidPayloadIsRejected(array $payload): void
	{
		// Bypass canonicalJson type coercion: sign raw JSON so the invalid
		// value survives to validatePayloadFields.
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($json);
		$wireKey = Cuc2TestSigning::signRawBytes($json);
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	public function testValidPayloadBoundaries(): void
	{
		$this->assertTrue(Cuc2Codec::validatePayloadFields(array_merge($this->validPayload(), ['mobileSeats' => 1])));
		$this->assertTrue(Cuc2Codec::validatePayloadFields(array_merge($this->validPayload(), ['mobileSeats' => 10000])));
		$this->assertTrue(Cuc2Codec::validatePayloadFields(array_merge($this->validPayload(), ['customerId' => 'abc'])));
		$this->assertTrue(Cuc2Codec::validatePayloadFields(
			array_merge($this->validPayload(), ['issuedAt' => '2026-06-10', 'validUntil' => '2026-06-10']),
		));
		$this->assertFalse(Cuc2Codec::validatePayloadFields([]));
	}

	public function testNonCanonicalKeyOrderIsRejected(): void
	{
		// Same data, different key order → signed bytes ≠ canonical bytes.
		$reordered = [
			'product' => 'customercheck',
			'v' => 2,
			'customerId' => 'test',
			'issuedAt' => '2026-06-10',
			'validUntil' => '2027-12-31',
			'mobileSeats' => 5,
		];
		$json = json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($json);
		$wireKey = Cuc2TestSigning::signRawBytes($json);
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	public function testExtraFieldsAreRejectedByCanonicalisation(): void
	{
		$payload = array_merge($this->validPayload(), ['bundle' => true]);
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($json);
		$wireKey = Cuc2TestSigning::signRawBytes($json);
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	public function testNonJsonPayloadBytesAreRejected(): void
	{
		$wireKey = Cuc2TestSigning::signRawBytes('this is not json');
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	public function testJsonScalarPayloadIsRejected(): void
	{
		$wireKey = Cuc2TestSigning::signRawBytes('42');
		$this->assertSame(Cuc2Codec::ERROR_INVALID_PAYLOAD, Cuc2Codec::classifyError($wireKey));
	}

	// ── Gate-time validity ──────────────────────────────────────────────

	public function testIsValidOnIsInclusiveOfLastDay(): void
	{
		$this->assertTrue(Cuc2Codec::isValidOn('2027-12-31', '2027-12-30'));
		$this->assertTrue(Cuc2Codec::isValidOn('2027-12-31', '2027-12-31'));
		$this->assertFalse(Cuc2Codec::isValidOn('2027-12-31', '2028-01-01'));
	}

	public function testExpiredKeyStillPassesStructuralVerification(): void
	{
		// Dates never block acceptance — expiry is gate-time only.
		$expired = array_merge($this->validPayload(), [
			'issuedAt' => '2020-01-01',
			'validUntil' => '2020-12-31',
		]);
		$this->assertSame('', Cuc2Codec::classifyError(Cuc2TestSigning::signPayload($expired)));
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	public function testNormalizeWireKeyStripsAllWhitespace(): void
	{
		$this->assertSame('CUC2.a.b', Cuc2Codec::normalizeWireKey(" CUC2\n.a\t. b \r\n"));
	}

	public function testBase64urlRoundTrip(): void
	{
		$bytes = random_bytes(37);
		$encoded = VendorPublicKey::base64urlEncode($bytes);
		$this->assertStringNotContainsString('+', $encoded);
		$this->assertStringNotContainsString('/', $encoded);
		$this->assertStringNotContainsString('=', $encoded);
		$this->assertSame($bytes, VendorPublicKey::base64urlDecode($encoded));
	}

	public function testCanonicalJsonFailsLoudOnUnencodableValue(): void
	{
		$this->expectException(\RuntimeException::class);
		Cuc2Codec::canonicalJson(array_merge($this->validPayload(), ['customerId' => "\xB1\x31"]));
	}

	public function testVendorKeyEnvOverrideIsHonouredUnderPhpunit(): void
	{
		$this->assertTrue(VendorPublicKey::envOverrideAllowed(), 'PHPUnit must allow the fixture key');
		$this->assertSame(Cuc2TestSigning::publicKeyB64(), VendorPublicKey::publicKeyB64());
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
		try {
			$this->assertSame(VendorPublicKey::DEFAULT_PUBLIC_KEY_B64, VendorPublicKey::publicKeyB64());
		} finally {
			putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
		}
	}

	public function testBlankEnvOverrideFallsBackToDefault(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=   ');
		try {
			$this->assertSame(VendorPublicKey::DEFAULT_PUBLIC_KEY_B64, VendorPublicKey::publicKeyB64());
		} finally {
			putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
		}
	}

	public function testVendorKeyBytesAreSodiumSized(): void
	{
		$this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(VendorPublicKey::bytes()));
	}

	public function testInvalidVendorKeyConfigurationThrows(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=too-short');
		try {
			$this->expectException(\RuntimeException::class);
			VendorPublicKey::bytes();
		} finally {
			putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
		}
	}
}
