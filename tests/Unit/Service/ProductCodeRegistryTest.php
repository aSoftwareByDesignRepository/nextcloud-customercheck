<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** CUC M1 S4 / AC9 — custom product codes. */
class ProductCodeRegistryTest extends TestCase
{
	private IConfig&MockObject $config;
	private ProductCodeRegistry $registry;

	protected function setUp(): void
	{
		$this->config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, array $a = []): string => $t);
		$this->registry = new ProductCodeRegistry($this->config, $l10n);
	}

	public function testBuiltinsAlwaysAllowed(): void
	{
		$this->config->method('getAppValue')->willReturn('[]');
		$this->assertTrue($this->registry->isAllowed('azc'));
		$this->assertTrue($this->registry->isAllowed('cp-std'));
		$this->assertTrue($this->registry->isAllowed('dty'));
		$this->assertTrue($this->registry->isAllowed('cuc'));
		$this->assertTrue($this->registry->isAllowed('ic'));
		$this->assertFalse($this->registry->isAllowed('not-a-code'));
	}

	public function testCustomActiveCodeAllowed(): void
	{
		$this->config->method('getAppValue')->willReturn(json_encode([
			['code' => 'workshop-half', 'label' => 'Workshop', 'kind' => 'onetime', 'active' => true],
		]));
		$this->assertTrue($this->registry->isAllowed('workshop-half'));
		$this->assertContains('workshop-half', $this->registry->allowedCodes());
	}

	public function testInactiveCustomRejected(): void
	{
		$this->config->method('getAppValue')->willReturn(json_encode([
			['code' => 'old-pack', 'label' => 'Old', 'kind' => 'onetime', 'active' => false],
		]));
		$this->assertFalse($this->registry->isAllowed('old-pack'));
	}

	public function testKindForBuiltinAndCustom(): void
	{
		$this->config->method('getAppValue')->willReturn(json_encode([
			['code' => 'train-day', 'label' => 'Training', 'kind' => 'partner', 'active' => true],
		]));
		$this->assertSame('license', $this->registry->kindFor('azc'));
		$this->assertSame('license', $this->registry->kindFor('cuc'));
		$this->assertSame('partner', $this->registry->kindFor('train-day'));
		$this->assertSame('onetime', $this->registry->kindFor('missing-code'));
	}

	public function testRejectsInvalidCustomPattern(): void
	{
		$this->config->method('getAppValue')->willReturn('[]');
		$this->expectException(ValidationException::class);
		$this->registry->saveCustom([
			['code' => 'BAD CODE', 'label' => 'Bad'],
		]);
	}

	public function testSavePersistsAndLists(): void
	{
		$stored = null;
		$this->config->method('getAppValue')->willReturnCallback(
			static function () use (&$stored): string {
				return $stored ?? '[]';
			}
		);
		$this->config->expects($this->once())
			->method('setAppValue')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): void {
				$stored = $value;
			});

		$list = $this->registry->saveCustom([
			['code' => 'feat-x', 'label' => 'Feature X', 'kind' => 'onetime', 'active' => true],
		]);
		$codes = array_column($list, 'code');
		$this->assertContains('azc', $codes);
		$this->assertContains('feat-x', $codes);
		$this->assertTrue($this->registry->isAllowed('feat-x'));
		$this->assertSame(count(Constants::PRODUCT_CODES) + 1, count($list));
	}
}
