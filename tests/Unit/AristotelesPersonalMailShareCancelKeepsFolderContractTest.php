<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Share-dialog cancel must keep the folder in the PUT payload (omit = sync delete).
 */
final class AristotelesPersonalMailShareCancelKeepsFolderContractTest extends TestCase
{
	public function testShareCancelPushesPrivateFolderRow(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$pos = strpos($js, 'async function collectPersonalFolderPayload');
		$this->assertNotFalse($pos);
		$chunk = substr($js, (int)$pos, 4500);
		$this->assertStringContainsString('Keep the folder in the PUT payload', $chunk);
		$this->assertMatchesRegularExpression(
			'/if \(!ack \|\| !ack\.confirmed\) \{[\s\S]{0,200}folder\.shareWithWorkspace = false;[\s\S]{0,80}out\.push\(folder\);/',
			$chunk,
		);
		$this->assertMatchesRegularExpression(
			'/Type SHARE to share Inbox or Sent[\s\S]{0,250}folder\.shareWithWorkspace = false;[\s\S]{0,80}out\.push\(folder\);/',
			$chunk,
		);
	}
}
