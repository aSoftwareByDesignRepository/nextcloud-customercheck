<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Production JS must not use `new Date().toISOString().slice(0, 10)` for local
 * calendar "today" / "tomorrow" — UTC can shift the day in evening timezones.
 */
class LocalCalendarDateContractTest extends TestCase
{
	/** @return list<string> */
	private function pageScripts(): array
	{
		$root = dirname(__DIR__, 3) . '/js/pages';
		return [
			$root . '/company-detail.js',
			$root . '/persona.js',
			$root . '/agreements.js',
		];
	}

	public function testPagesDefineLocalYmdHelper(): void
	{
		foreach ($this->pageScripts() as $path) {
			$this->assertFileExists($path);
			$src = (string)file_get_contents($path);
			$this->assertStringContainsString(
				'function localYmd',
				$src,
				basename($path) . ' must define localYmd()'
			);
		}
	}

	public function testPagesNeverUseToIsoStringForLocalToday(): void
	{
		foreach ($this->pageScripts() as $path) {
			$src = (string)file_get_contents($path);
			$this->assertStringNotContainsString(
				'new Date().toISOString().slice(0, 10)',
				$src,
				basename($path) . ' must not derive local today via toISOString()'
			);
		}
	}

	public function testSharedUtilExportsLocalYmd(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/js/utils/local-ymd.js');
		$this->assertStringContainsString('export function localYmd', $src);
		$this->assertStringContainsString('getFullYear()', $src);
		$this->assertDoesNotMatchRegularExpression(
			'/localYmd[\s\S]*?\{[\s\S]*?toISOString/',
			$src,
			'localYmd must not call toISOString'
		);
	}
}
