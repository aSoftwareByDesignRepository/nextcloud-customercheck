<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\DisplayLabelService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Humans never see raw PKs — labels stay name/slug based.
 */
final class DisplayLabelServiceTest extends TestCase
{
	private DisplayLabelService $labels;

	protected function setUp(): void
	{
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$this->labels = new DisplayLabelService($l10n);
	}

	public function testUnknownCompanyNeverUsesHashId(): void
	{
		$this->assertSame('Unknown company', $this->labels->unknownCompany());
		$this->assertSame('Unknown company', $this->labels->companyOrUnknown(''));
		$this->assertSame('Unknown company', $this->labels->companyOrUnknown('   '));
		$this->assertSame('Acme', $this->labels->companyOrUnknown('Acme'));
		$this->assertStringNotContainsString('#', $this->labels->unknownCompany());
	}

	public function testStatusKindAndActivityLabelsAreHuman(): void
	{
		$this->assertSame('Lead', $this->labels->companyStatus('lead'));
		$this->assertSame('Active', $this->labels->companyStatus('active'));
		$this->assertSame('License', $this->labels->agreementKind('license'));
		$this->assertSame('Partner', $this->labels->agreementKind('partner'));
		$this->assertSame('Call', $this->labels->activityType('call'));
		$this->assertSame('Email follow-up', $this->labels->activityType('email'));
		$this->assertSame('One-time', $this->labels->agreementKind('onetime'));
		$this->assertSame('Draft', $this->labels->agreementStatus('draft'));
		$this->assertSame('Expiring', $this->labels->agreementStatus('expiring'));
		$this->assertSame('No invoice', $this->labels->invoiceStatus('none'));
		$this->assertSame('Overdue', $this->labels->invoiceStatus('overdue'));
		$this->assertSame('Quoted', $this->labels->dealStage('quoted'));
		$this->assertSame('Won', $this->labels->dealStage('won'));
		$opts = $this->labels->activityTypeOptions();
		$this->assertCount(4, $opts);
		$this->assertSame('todo', $opts[3]['value']);
		$this->assertSame('To-do', $opts[3]['label']);
	}

	public function testSiblingOptionsSortByLabelAndSkipInvalid(): void
	{
		$opts = $this->labels->siblingCustomerOptions([
			['id' => 2, 'name' => 'Zulu'],
			['id' => 0, 'name' => 'Bad'],
			['id' => 1, 'name' => 'Alpha'],
			['id' => 1, 'name' => 'Dup'],
			['id' => 3, 'name' => ''],
		]);
		$this->assertCount(3, $opts);
		$this->assertSame(1, $opts[0]['id']);
		$this->assertSame('Alpha', $opts[0]['label']);
		$this->assertSame(3, $opts[1]['id']);
		$this->assertSame('Untitled customer', $opts[1]['label']);
		$this->assertSame(2, $opts[2]['id']);
	}

	public function testEnsureLinkedSiblingOptionAddsStaleWithoutShowingId(): void
	{
		$opts = $this->labels->ensureLinkedSiblingOption(
			[['id' => 1, 'label' => 'Alpha']],
			99,
		);
		$this->assertSame(99, $opts[0]['id']);
		$this->assertSame('Linked customer (no longer listed)', $opts[0]['label']);
		$this->assertStringNotContainsString('99', $opts[0]['label']);

		$same = $this->labels->ensureLinkedSiblingOption([['id' => 5, 'label' => 'Keep']], 5);
		$this->assertCount(1, $same);
		$this->assertSame('Keep', $same[0]['label']);
	}

	public function testImportMatchLabelNeverShowsHashId(): void
	{
		$this->assertSame(
			'Acme GmbH (acme-gmbh)',
			$this->labels->importMatchLabel([
				'companyId' => 7,
				'companyName' => 'Acme GmbH',
				'companySlug' => 'acme-gmbh',
			]),
		);
		$this->assertSame(
			'Beta (beta-co)',
			$this->labels->importMatchLabel([
				'companyId' => 3,
				'candidates' => [['id' => 3, 'name' => 'Beta', 'slug' => 'beta-co', 'via' => 'name']],
			]),
		);
		$this->assertSame('new-slug', $this->labels->importMatchLabel(['suggestedSlug' => 'new-slug']));
		$this->assertSame('—', $this->labels->importMatchLabel([]));
		$label = $this->labels->importMatchLabel(['companyId' => 42]);
		$this->assertStringNotContainsString('#', $label);
		$this->assertStringNotContainsString('42', $label);
	}
}
