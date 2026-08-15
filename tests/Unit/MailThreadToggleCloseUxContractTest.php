<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Accordion: second click / Enter on an open mail row collapses the reader. */
final class MailThreadToggleCloseUxContractTest extends TestCase
{
	public function testCompanyTalkFeedTogglesCloseOnSecondActivation(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('function closeTalkReader(focusRow)', $js);
		$this->assertStringContainsString("li.classList.contains('is-selected')", $js);
		$this->assertStringContainsString('closeTalkReader(li)', $js);
		$this->assertStringContainsString('talkMailPreviewGeneration += 1', $js);
		$this->assertStringContainsString("t('Select a message to read.')", $js);
		// Detail actions must not collapse the row via the row handler.
		$this->assertMatchesRegularExpression(
			"/function openRow\(ev\) \{[\s\S]{0,200}closest\('a, input, button'\)/",
			$js,
		);
	}

	public function testReviewInboxTogglesCloseOnSecondActivation(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function clearReviewSelection(opts)', $js);
		$this->assertStringContainsString("selectedId === String(id)", $js);
		$this->assertStringContainsString('clearReviewSelection(opts)', $js);
		$this->assertStringContainsString('bumpMailPreviewRequest()', $js);
		$this->assertStringContainsString("opts.forceOpen", $js);
		$this->assertStringContainsString("t('Select a message to read and decide.')", $js);
		// Collapsed list keeps one row tabbable.
		$this->assertStringContainsString("if (id === '' && firstVisible)", $js);
		$this->assertStringContainsString('firstVisible.tabIndex = 0', $js);
	}
}
