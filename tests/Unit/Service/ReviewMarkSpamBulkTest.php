<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Bulk Review mark-as-spam — same id rules as discard-bulk; surfaces IMAP failures.
 */
final class ReviewMarkSpamBulkTest extends TestCase
{
	public function testEmptyIdsRejected(): void
	{
		$svc = $this->serviceMockingMarkSpam();
		$svc->expects($this->never())->method('markSpam');
		$this->expectException(ValidationException::class);
		$svc->markSpamBulk(1, [0, -1, 'nope'], 'alex');
	}

	public function testTooManyIdsRejected(): void
	{
		$svc = $this->serviceMockingMarkSpam();
		$svc->expects($this->never())->method('markSpam');
		$ids = range(1, Constants::REVIEW_DISCARD_BULK_MAX + 1);
		$this->expectException(ValidationException::class);
		try {
			$svc->markSpamBulk(1, $ids, 'alex');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_BULK_TOO_LARGE, $e->getErrorCode());
			throw $e;
		}
	}

	public function testBulkSkipsClosedOrMissingAndCountsSuccess(): void
	{
		$svc = $this->serviceMockingMarkSpam();
		$svc->expects($this->exactly(3))->method('markSpam')
			->willReturnCallback(function (int $ws, int $id, string $actor): array {
				$this->assertSame(7, $ws);
				$this->assertSame('alex', $actor);
				if ($id === 2) {
					throw new NotFoundException('missing');
				}
				if ($id === 3) {
					throw ValidationException::field(Constants::ERR_ALREADY_TERMINAL, 'status', 'closed');
				}

				return ['id' => $id, 'status' => 'no_relation'];
			});

		$result = $svc->markSpamBulk(7, [1, 2, 3, 1], 'alex');
		$this->assertSame(1, $result['discarded']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame(1, $result['failed']);
		$this->assertSame([1], $result['ids']);
		$this->assertCount(1, $result['errors']);
		$this->assertSame(3, $result['errors'][0]['id']);
	}

	public function testBulkAllImapFailuresSurfaceAsValidationError(): void
	{
		$svc = $this->serviceMockingMarkSpam();
		$svc->expects($this->exactly(2))->method('markSpam')
			->willThrowException(ValidationException::field(Constants::ERR_VALIDATION, 'imap', 'No Junk folder'));

		$this->expectException(ValidationException::class);
		try {
			$svc->markSpamBulk(7, [10, 11], 'alex');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('No Junk folder', $e->getMessage());
			throw $e;
		}
	}

	/** @return ReviewService&\PHPUnit\Framework\MockObject\MockObject */
	private function serviceMockingMarkSpam(): ReviewService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $msg, array $args = []): string {
			if ($args === []) {
				return $msg;
			}

			return str_replace('%s', (string)($args[0] ?? ''), $msg);
		});

		return $this->getMockBuilder(ReviewService::class)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$this->createMock(ITimeFactory::class),
				$l10n,
				$this->createMock(MailboxService::class),
				$this->createMock(CompanyService::class),
				$this->createMock(CompanyMapper::class),
				$this->createMock(PersonMapper::class),
				$this->createMock(WorkspaceService::class),
			])
			->onlyMethods(['markSpam'])
			->getMock();
	}
}
