<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Tests\Unit\Service;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\SeqLogMapper;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class StageSequenceServiceTest extends TestCase
{
	private StageSequenceService $svc;

	protected function setUp(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn ($s) => $s);
		$this->svc = new StageSequenceService(
			$this->createMock(SeqLogMapper::class),
			$this->createMock(ActivityMapper::class),
			$this->createMock(ITimeFactory::class),
			$l,
		);
	}

	public function testQuotedHasThreeSteps(): void
	{
		$steps = $this->svc->stepsFor(Constants::SEQUENCE_QUOTED);
		$this->assertCount(3, $steps);
		$this->assertSame([3, 7, 14], array_column($steps, 'offsetDays'));
	}

	public function testWonHasThreeSteps(): void
	{
		$steps = $this->svc->stepsFor(Constants::SEQUENCE_WON);
		$this->assertCount(3, $steps);
		$this->assertSame([0, 2, 3], array_column($steps, 'offsetDays'));
	}

	public function testOrigins(): void
	{
		$this->assertSame('sequence_quoted', $this->svc->originForSequence(Constants::SEQUENCE_QUOTED));
		$this->assertSame('sequence_won', $this->svc->originForSequence(Constants::SEQUENCE_WON));
	}

	public function testStageMapping(): void
	{
		$this->assertSame(Constants::SEQUENCE_QUOTED, $this->svc->sequenceIdForStage('quoted'));
		$this->assertSame(Constants::SEQUENCE_WON, $this->svc->sequenceIdForStage('won'));
		$this->assertNull($this->svc->sequenceIdForStage('lead'));
	}
}
