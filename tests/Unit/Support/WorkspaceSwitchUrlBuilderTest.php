<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\WorkspaceSwitchUrlBuilder;
use PHPUnit\Framework\TestCase;

final class WorkspaceSwitchUrlBuilderTest extends TestCase
{
	public function testListPageKeepsPathAndSetsWorkspaceId(): void
	{
		$map = WorkspaceSwitchUrlBuilder::buildMap(
			'pulse',
			['pulse' => '/apps/customercheck/pulse', 'today' => '/apps/customercheck/today'],
			[['id' => 2], ['id' => 9]],
			'/apps/customercheck/pulse?foo=1',
		);
		$this->assertSame('/apps/customercheck/pulse?foo=1&workspaceId=2', $map[2]);
		$this->assertSame('/apps/customercheck/pulse?foo=1&workspaceId=9', $map[9]);
	}

	public function testEntityPageFallsBackToCompaniesList(): void
	{
		$map = WorkspaceSwitchUrlBuilder::buildMap(
			'company-detail',
			[
				'companies' => '/apps/customercheck/companies',
				'today' => '/apps/customercheck/today',
			],
			[['id' => 4]],
			'/apps/customercheck/companies/12?workspaceId=1',
		);
		$this->assertSame('/apps/customercheck/companies?workspaceId=4', $map[4]);
	}

	public function testAgreementFormFallsBackToAgreements(): void
	{
		$map = WorkspaceSwitchUrlBuilder::buildMap(
			'agreement-form',
			[
				'agreements' => '/apps/customercheck/agreements',
				'today' => '/apps/customercheck/today',
			],
			[['id' => 7]],
			'/apps/customercheck/agreements/3/edit',
		);
		$this->assertSame('/apps/customercheck/agreements?workspaceId=7', $map[7]);
	}

	public function testRejectsExternalAndSchemeRelativeBases(): void
	{
		$this->assertSame('#', WorkspaceSwitchUrlBuilder::withWorkspaceId('https://evil.example/x', 1));
		$this->assertSame('#', WorkspaceSwitchUrlBuilder::withWorkspaceId('//evil.example/x', 1));
		$this->assertSame('#', WorkspaceSwitchUrlBuilder::withWorkspaceId('', 1));
		$this->assertSame('#', WorkspaceSwitchUrlBuilder::withWorkspaceId('/apps/customercheck/today', 0));
	}

	public function testSanitizeRequestPathRejectsProtocolRelative(): void
	{
		$this->assertSame('', WorkspaceSwitchUrlBuilder::sanitizeRequestPath('//evil.example/apps/x'));
		$this->assertSame('/apps/customercheck/today', WorkspaceSwitchUrlBuilder::sanitizeRequestPath('/apps/customercheck/today'));
		$this->assertSame(
			'/apps/customercheck/today?workspaceId=1',
			WorkspaceSwitchUrlBuilder::sanitizeRequestPath('/apps/customercheck/today?workspaceId=1'),
		);
	}

	public function testSkipsInvalidWorkspaceIds(): void
	{
		$map = WorkspaceSwitchUrlBuilder::buildMap(
			'today',
			['today' => '/apps/customercheck/today'],
			[['id' => 0], ['id' => -1], []],
			'/apps/customercheck/today',
		);
		$this->assertSame([], $map);
	}

	public function testUrlKeyForPageAliases(): void
	{
		$this->assertSame('myAccounts', WorkspaceSwitchUrlBuilder::urlKeyForPage('my-accounts'));
		$this->assertSame('mailTriage', WorkspaceSwitchUrlBuilder::urlKeyForPage('mail-triage'));
		$this->assertSame('supportUs', WorkspaceSwitchUrlBuilder::urlKeyForPage('support-us'));
		$this->assertSame('getTheApp', WorkspaceSwitchUrlBuilder::urlKeyForPage('get-the-app'));
		$this->assertSame('companies', WorkspaceSwitchUrlBuilder::urlKeyForPage('company-detail'));
		$this->assertArrayNotHasKey('prepare-meeting', WorkspaceSwitchUrlBuilder::ENTITY_FALLBACK);
		$this->assertSame('pulse', WorkspaceSwitchUrlBuilder::urlKeyForPage('pulse'));
	}
}
