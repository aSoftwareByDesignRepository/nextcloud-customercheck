<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Controller\PageController;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\CompanyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Old /companies/{id}/prepare-meeting URLs must 301 to Company 360 after ACL.
 */
final class PrepareMeetingRedirectIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	private ?int $companyId = null;
	private string $slug = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		if (class_exists(\OC_User::class)) {
			\OC_User::setIncognitoMode(false);
		}
		$user = \OC::$server->get(IUserManager::class)->get(self::ACTOR);
		if ($user === null) {
			$this->markTestSkipped('admin user missing');
		}
		\OC::$server->get(IUserSession::class)->setUser($user);
	}

	protected function tearDown(): void
	{
		if ($this->companyId !== null) {
			try {
				\OC::$server->get(CompanyService::class)->gdprErase($this->companyId, $this->slug, self::ACTOR);
			} catch (\Throwable) {
			}
			$this->companyId = null;
		}
	}

	public function testAccessibleCompanyRedirectsPermanentlyToCompany360(): void
	{
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$this->slug = 'prep-redir-' . $suffix;
		$company = \OC::$server->get(CompanyService::class)->create([
			'name' => 'Prep Redirect ' . $suffix,
			'slug' => $this->slug,
		], self::ACTOR);
		$this->companyId = (int)$company->getId();

		$res = \OC::$server->get(PageController::class)->prepareMeeting($this->companyId);
		$this->assertInstanceOf(RedirectResponse::class, $res);
		$this->assertSame(Http::STATUS_MOVED_PERMANENTLY, $res->getStatus());
		$url = $res->getRedirectURL();
		$this->assertNotSame('', $url);
		$this->assertMatchesRegularExpression(
			'#/(?:apps/)?customercheck/companies/' . preg_quote((string)$this->companyId, '#') . '(?:[/?]|$)#',
			$url,
		);
		$this->assertStringNotContainsString('prepare-meeting', $url);
	}

	public function testUnknownCompanyDoesNotRedirect(): void
	{
		$this->expectException(NotFoundException::class);
		\OC::$server->get(PageController::class)->prepareMeeting(2147483000);
	}

	public function testPersonaApiNoLongerExposesPrepareMeeting(): void
	{
		$this->assertFalse(method_exists(
			\OC::$server->get(\OCA\CustomerCheck\Controller\PersonaApiController::class),
			'prepareMeeting',
		));
	}
}
