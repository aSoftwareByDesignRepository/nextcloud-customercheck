<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\FrontEndAssetService;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Real invokes for FrontEndAssetService::registerCore / registerPage
 * (called from PageShellTrait::registerFrontEndAssets on page routes).
 */
final class FrontEndAssetServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->resetCoreRegisteredFlag();
	}

	protected function tearDown(): void
	{
		$this->resetCoreRegisteredFlag();
	}

	public function testRegisterCoreRegistersSharedScriptsOnce(): void
	{
		FrontEndAssetService::registerCore();
		FrontEndAssetService::registerCore(); // idempotent

		$scripts = Util::getScripts();
		$flat = $this->flattenScripts($scripts);
		$this->assertTrue(
			$this->containsPath($flat, 'customercheck/js/common/navigation'),
			'core navigation script must be registered',
		);
		$this->assertTrue(
			$this->containsPath($flat, 'customercheck/js/common/api'),
			'core api script must be registered',
		);
		$this->assertTrue(
			$this->containsPath($flat, 'customercheck/js/common/list-toolbar-core'),
			'core list-toolbar script must be registered',
		);
	}

	public function testRegisterPageRegistersCoreThenPageAssets(): void
	{
		FrontEndAssetService::registerPage(
			'pages/today',
			'pages/today',
			['pages/extra-style'],
			['pages/extra-script'],
		);

		$scripts = Util::getScripts();
		$flat = $this->flattenScripts($scripts);
		$this->assertTrue($this->containsPath($flat, 'customercheck/js/common/navigation'));
		$this->assertTrue($this->containsPath($flat, 'customercheck/js/pages/extra-script'));
		$this->assertTrue($this->containsPath($flat, 'customercheck/js/pages/today'));
		$this->assertSame(Application::APP_ID, 'customercheck');
	}

	public function testRegisterPageWithEmptyScriptStillRegistersCore(): void
	{
		FrontEndAssetService::registerPage('', null, [], []);
		$flat = $this->flattenScripts(Util::getScripts());
		$this->assertTrue($this->containsPath($flat, 'customercheck/js/common/components'));
	}

	private function resetCoreRegisteredFlag(): void
	{
		$ref = new ReflectionClass(FrontEndAssetService::class);
		$prop = $ref->getProperty('coreRegistered');
		$prop->setAccessible(true);
		$prop->setValue(null, false);
	}

	/**
	 * @param list<mixed> $scripts
	 * @return list<string>
	 */
	private function flattenScripts(array $scripts): array
	{
		$out = [];
		array_walk_recursive($scripts, static function ($v) use (&$out): void {
			if (is_string($v) && $v !== '') {
				$out[] = $v;
			}
		});
		return $out;
	}

	/** @param list<string> $paths */
	private function containsPath(array $paths, string $needle): bool
	{
		foreach ($paths as $path) {
			if (str_contains($path, $needle)) {
				return true;
			}
		}
		return false;
	}
}
