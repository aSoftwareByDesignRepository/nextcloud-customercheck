<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * Contact emails must be trimmed + lowercased on write and lookup.
 */
final class PersonMapperEmailNormalizeTest extends TestCase
{
	public function testInsertAndUpdateNormalizeStoredEmail(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/PersonMapper.php');
		$this->assertStringContainsString('function normalizeStoredEmail', $src);
		$this->assertStringContainsString('$entity->setEmail(self::normalizeStoredEmail($entity->getEmail()));', $src);
		$this->assertGreaterThanOrEqual(2, substr_count($src, 'normalizeStoredEmail($entity->getEmail())'));
	}

	public function testFindByEmailComparesTrimmedLowerEmail(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/PersonMapper.php');
		$this->assertStringContainsString('$email = mb_strtolower(trim($email));', $src);
		$this->assertStringContainsString("\$qb->func()->trim('p.email')", $src);
	}
}
