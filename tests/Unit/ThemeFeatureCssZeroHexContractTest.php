<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Feature CSS must not hard-code hex (even as var() fallbacks).
 * Only css/common/tokens.css may keep intentional NC notif fallbacks.
 */
final class ThemeFeatureCssZeroHexContractTest extends TestCase
{
	public function testFeatureStylesheetsContainNoHexLiterals(): void
	{
		$root = dirname(__DIR__, 2) . '/css';
		$offenders = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'css') {
				continue;
			}
			$rel = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
			if ($rel === 'css/common/tokens.css') {
				continue;
			}
			$css = (string)file_get_contents($file->getPathname());
			$css = preg_replace('/\/\*[\s\S]*?\*\//', ' ', $css) ?? $css;
			if (preg_match('/#[0-9a-fA-F]{3,8}\b|(?:rgb|rgba|hsl|hsla)\(\s*[\d.]/', $css, $m)) {
				$offenders[] = $rel . ' → ' . $m[0];
			}
		}
		$this->assertSame([], $offenders, "Hex outside tokens.css:\n" . implode("\n", $offenders));
	}

	public function testSupportBrandTokensAreThemeAdaptive(): void
	{
		$tokens = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/tokens.css');
		$this->assertStringContainsString('--crm-support-brand-plate: var(--crm-bg-card);', $tokens);
		$this->assertStringContainsString('--crm-support-brand-ink: var(--crm-text);', $tokens);
		$this->assertStringContainsString('--crm-support-brand-muted: var(--crm-muted);', $tokens);
		$support = (string)file_get_contents(dirname(__DIR__, 2) . '/css/admin-support-us.css');
		$this->assertStringContainsString('var(--crm-support-brand-plate', $support);
		$this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}/', $support);
	}
}
