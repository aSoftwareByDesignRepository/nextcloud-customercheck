<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Service\ReviewIngressService;
use OCA\CustomerCheck\Service\ReviewService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class ReviewIngressServiceTest extends TestCase
{
	public function testSyncWorkspaceSharedMailOnlyWhenEnabled(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('getReviewSources')->with(3)->willReturn([
			'shared_mail' => ['enabled' => true, 'dsgvoAckAt' => null],
			'file' => ['enabled' => false, 'dsgvoAckAt' => null],
		]);

		$review = $this->createMock(ReviewService::class);
		$review->expects($this->once())->method('syncSharedMail')->with(3)->willReturn(2);

		$l10n = $this->createMock(IL10N::class);
		$svc = new ReviewIngressService(
			$ws,
			$review,
			$this->createMock(IRootFolder::class),
			$l10n,
		);

		$this->assertSame(2, $svc->syncWorkspace(3));
	}

	public function testSyncAllAggregatesWorkspaces(): void
	{
		$w1 = new Workspace();
		$w1->setId(1);
		$w2 = new Workspace();
		$w2->setId(2);

		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('listAll')->willReturn([$w1, $w2]);
		$ws->method('getReviewSources')->willReturn([
			'shared_mail' => ['enabled' => true],
			'file' => ['enabled' => false],
		]);

		$review = $this->createMock(ReviewService::class);
		$review->method('syncSharedMail')->willReturnOnConsecutiveCalls(1, 3);

		$svc = new ReviewIngressService(
			$ws,
			$review,
			$this->createMock(IRootFolder::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame(4, $svc->syncAll());
	}

	public function testFileIngressSkippedWithoutFolderConfig(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('getReviewSources')->willReturn([
			'shared_mail' => ['enabled' => false],
			'file' => [
				'enabled' => true,
				'dsgvoAckAt' => '2026-08-01T00:00:00+00:00',
				// missing rootFolderId / scanActor
			],
		]);
		$review = $this->createMock(ReviewService::class);
		$review->expects($this->never())->method('syncSharedMail');
		$review->expects($this->never())->method('enqueueSourceItem');

		$svc = new ReviewIngressService(
			$ws,
			$review,
			$this->createMock(IRootFolder::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame(0, $svc->syncWorkspace(9));
	}

	public function testFileIngressSkippedWhenScanActorNotMember(): void
	{
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('getReviewSources')->willReturn([
			'shared_mail' => ['enabled' => false],
			'file' => [
				'enabled' => true,
				'dsgvoAckAt' => '2026-08-01T00:00:00+00:00',
				'rootFolderId' => 42,
				'scanActor' => 'outsider',
			],
		]);
		$ws->method('listMembers')->with(9)->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$review = $this->createMock(ReviewService::class);
		$review->expects($this->never())->method('enqueueSourceItem');

		$svc = new ReviewIngressService(
			$ws,
			$review,
			$root,
			$this->createMock(IL10N::class),
		);

		$this->assertSame(0, $svc->syncWorkspace(9));
	}

	public function testCalendarIngressSkippedWhenScanActorNotMember(): void
	{
		$member = new WorkspaceMember();
		$member->setUserId('alice');

		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('getReviewSources')->willReturn([
			'shared_mail' => ['enabled' => false],
			'calendar_event' => [
				'enabled' => true,
				'dsgvoAckAt' => '2026-08-01T00:00:00+00:00',
				'calendarUris' => ['personal'],
				'scanActor' => 'outsider',
			],
		]);
		$ws->method('listMembers')->willReturn([$member]);

		$review = $this->createMock(ReviewService::class);
		$review->expects($this->never())->method('enqueueSourceItem');

		$svc = new ReviewIngressService(
			$ws,
			$review,
			$this->createMock(IRootFolder::class),
			$this->createMock(IL10N::class),
			null,
		);

		$this->assertSame(0, $svc->syncWorkspace(4));
	}
}
