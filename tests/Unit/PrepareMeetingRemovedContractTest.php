<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\PageHelpCatalog;
use OCA\CustomerCheck\Service\PageQuickStartCatalog;
use OCA\CustomerCheck\Support\WorkspaceSwitchUrlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Prepare-meeting was a duplicate read of Company 360. The dedicated page, API,
 * and 360 jump card are gone; old URLs permanently redirect after ACL.
 */
final class PrepareMeetingRemovedContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testServiceAndTemplateAreGone(): void
	{
		$this->assertFileDoesNotExist($this->root . '/lib/Service/PrepareMeetingService.php');
		$this->assertFileDoesNotExist($this->root . '/templates/prepare-meeting.php');
	}

	public function testCompany360HasNoPrepareMeetingSection(): void
	{
		$detail = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringNotContainsString('crm-prepare-meeting', $detail);
		$this->assertStringNotContainsString('prepareMeetingUrl', $detail);
		$this->assertStringNotContainsString('Open prepare meeting', $detail);
		$this->assertStringNotContainsString("\$l->t('Prepare meeting')", $detail);
		$this->assertStringContainsString('id="crm-timeline"', $detail);
		$this->assertStringContainsString('id="crm-persons"', $detail);
		$this->assertStringContainsString('id="crm-communication"', $detail);
		$this->assertStringContainsString('id="crm-billing"', $detail);
	}

	public function testApiRouteGone(): void
	{
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$this->assertStringNotContainsString('persona_api#prepareMeeting', $routes);
		$this->assertStringNotContainsString('/api/v1/companies/{companyId}/prepare-meeting', $routes);
		$this->assertStringContainsString("['name' => 'page#prepareMeeting'", $routes);
		$this->assertStringContainsString('/companies/{id}/prepare-meeting', $routes);
	}

	public function testRedirectGatesOnCompanyAcl(): void
	{
		$method = $this->prepareMeetingMethod();
		$this->assertStringContainsString('$this->requireCompanyPage($id);', $method);
		$requirePos = strpos($method, 'requireCompanyPage');
		$redirectPos = strpos($method, 'new RedirectResponse');
		$this->assertNotFalse($requirePos);
		$this->assertNotFalse($redirectPos);
		$this->assertLessThan($redirectPos, $requirePos, 'ACL must run before redirect (no existence leak)');
		$this->assertStringNotContainsString('TemplateResponse', $method);
		$this->assertStringNotContainsString('prepare-meeting', $method);
	}

	public function testRedirectIsPermanentToCompanyDetail(): void
	{
		$method = $this->prepareMeetingMethod();
		$page = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('function prepareMeeting(int $id): RedirectResponse', $page);
		$this->assertStringContainsString("linkToRoute('customercheck.page.companyDetail'", $method);
		$this->assertStringContainsString("'/apps/customercheck/companies/' . \$id", $method);
		$this->assertStringContainsString('getAbsoluteURL', $method);
		$this->assertStringContainsString('Http::STATUS_MOVED_PERMANENTLY', $method);
		$this->assertStringContainsString("str_contains(\$rel, '/companies/' . \$id)", $method);
		$this->assertStringNotContainsString('customercheck.page.today', $method);
		$this->assertStringNotContainsString('mergeShellPageParams', $method);
	}

	public function testRedirectWorkspaceIdIsStrictInt(): void
	{
		$method = $this->prepareMeetingMethod();
		$this->assertStringContainsString("\$ws = (int)\$this->request->getParam('workspaceId', 0);", $method);
		$this->assertStringContainsString('if ($ws > 0)', $method);
		$this->assertStringContainsString("\$params['workspaceId'] = \$ws;", $method);
	}

	public function testPersonaApiDropsPrepareMeeting(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringNotContainsString('PrepareMeetingService', $ctrl);
		$this->assertStringNotContainsString('function prepareMeeting', $ctrl);
		$this->assertStringNotContainsString('$this->prepare', $ctrl);
	}

	public function testJsDropsPrepareMeetingMount(): void
	{
		$persona = (string)file_get_contents($this->root . '/js/pages/persona.js');
		$this->assertStringNotContainsString('prepare-meeting', $persona);
		$this->assertStringNotContainsString('loadPrepare', $persona);
		$this->assertStringNotContainsString('companyIdFromContext', $persona);
		$focus = (string)file_get_contents($this->root . '/js/common/company360-focus.js');
		$core = (string)file_get_contents($this->root . '/js/common/company360-focus-core.js');
		$this->assertStringNotContainsString('crm-prepare-meeting', $focus);
		$this->assertStringNotContainsString('crm-prepare-meeting', $core);
		$switcher = (string)file_get_contents($this->root . '/js/common/workspace-switcher-core.js');
		$this->assertStringNotContainsString("'prepare-meeting'", $switcher);
	}

	public function testCatalogsAndPageIdsDropPrepareMeeting(): void
	{
		$this->assertNotContains('prepare-meeting', Constants::PAGE_IDS);
		$this->assertNotContains('prepare-meeting', PageQuickStartCatalog::requiredPageIds());
		$this->assertNotContains('prepare-meeting', PageHelpCatalog::requiredPageIds());
		$this->assertArrayNotHasKey('prepare-meeting', WorkspaceSwitchUrlBuilder::ENTITY_FALLBACK);
		$qs = (string)file_get_contents($this->root . '/lib/Service/PageQuickStartCatalog.php');
		$help = (string)file_get_contents($this->root . '/lib/Service/PageHelpCatalog.php');
		$shell = (string)file_get_contents($this->root . '/lib/Controller/PageShellTrait.php');
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		foreach ([$qs, $help, $shell, $nav, $start] as $src) {
			$this->assertStringNotContainsString('prepare-meeting', $src);
		}
	}

	private function prepareMeetingMethod(): string
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$pos = strpos($src, 'function prepareMeeting');
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos);
		if (preg_match('/^function\s+prepareMeeting\b.*?(?=\n\tpublic function|\n\tprivate function|\n\tprotected function|\n}\n)/s', $chunk, $m) === 1) {
			return $m[0];
		}

		return substr($chunk, 0, 2500);
	}
}
