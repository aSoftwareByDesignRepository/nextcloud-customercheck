<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud {@see \OCP\IL10N::l()} only accepts mutable {@see \DateTime} or a
 * Unix timestamp. Passing {@see \DateTimeImmutable} casts to int 1 → 1970-01-01.
 *
 * CustomerCheck must never call l('date'|'datetime'|…) with a DateTime object
 * directly — only via DisplayLabelService::l10nDate() with getTimestamp().
 */
class L10nDateSafeContractTest extends TestCase
{
	public function testLibPhpNeverPassesDateObjectsToL10nDateFormatters(): void
	{
		$lib = dirname(__DIR__, 3) . '/lib';
		$hits = [];
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($it as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			if (preg_match_all(
				'/->l\(\s*[\'"](?:date|datetime|time)[\'"]\s*,\s*([^)\n]+)/',
				$src,
				$m,
				PREG_SET_ORDER
			) === false) {
				continue;
			}
			foreach ($m as $row) {
				$arg = trim($row[1]);
				$rel = substr($file->getPathname(), strlen($lib) + 1);
				// Only timestamp / getTimestamp() is allowed.
				if (preg_match('/getTimestamp\s*\(/', $arg) === 1) {
					continue;
				}
				if (preg_match('/^\$(?:ts|timestamp|unix|epoch)\b/', $arg) === 1) {
					continue;
				}
				if (preg_match('/^\d+$/', $arg) === 1) {
					continue;
				}
				$hits[] = $rel . ': l(…, ' . $arg . ')';
			}
		}

		$this->assertSame(
			[],
			$hits,
			'IL10N::l(date|datetime|time) must receive a Unix timestamp, not a DateTime object. Hits: '
			. implode('; ', $hits)
		);
	}

	public function testDisplayLabelServiceCentralizesL10nDateViaTimestamp(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/DisplayLabelService.php');
		$this->assertStringContainsString('function l10nDate', $src);
		$this->assertStringContainsString('$dt->getTimestamp()', $src);
		$this->assertMatchesRegularExpression(
			'/\$this->l10n->l\(\$type,\s*\$dt->getTimestamp\(\)\)/',
			$src
		);
	}
}
