<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Nextcloud window.t often leaves %s — page helpers must fill placeholders locally. */
final class L10nPlaceholderFillContractTest extends TestCase
{
	public function testPersonaAndPeersFillSprintfPlaceholders(): void
	{
		$files = [
			'js/pages/persona.js',
			'js/pages/company-detail.js',
			'js/pages/pipeline.js',
			'js/pages/import.js',
			'js/pages/agreements.js',
			'js/pages/agreement-form.js',
			'js/common/folder-picker.js',
			'js/pages/settings.js',
		];
		$root = dirname(__DIR__, 2);
		foreach ($files as $rel) {
			$js = (string)file_get_contents($root . '/' . $rel);
			$this->assertStringContainsString('/%(?:\d+\$)?s/g', $js, $rel . ' must fill %s locally');
			$this->assertStringContainsString("out.indexOf('%')", $js, $rel);
		}
		$persona = (string)file_get_contents($root . '/js/pages/persona.js');
		$this->assertStringContainsString("t('%s items need a decision'", $persona);
	}
}
