<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class FilesAccessMailAttachListTest extends TestCase
{
	public function testRejectsPathTraversal(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$svc = new FilesAccessService($this->createMock(IRootFolder::class), $l10n);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('That folder path is not allowed.');
		$svc->listAttachableMailFiles('alex', '../etc');
	}

	public function testFac16SanitizeStripsScriptOnReadPath(): void
	{
		$san = new HtmlSanitizeService();
		$out = $san->sanitize('<p>Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>');
		$this->assertStringContainsString('<p>Hi</p>', $out);
		$this->assertStringNotContainsString('<script', strtolower($out));
		$this->assertStringNotContainsString('javascript:', strtolower($out));
		$this->assertSame(Constants::MAIL_ATTACH_ALLOWED_MIMES[0] ?? 'application/pdf', Constants::MAIL_ATTACH_ALLOWED_MIMES[0]);
	}
}
