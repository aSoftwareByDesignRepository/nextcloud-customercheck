<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Support\Ics;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Read-only ICS renewal feed — PSD §7.1.
 * Always regenerated on request so calendar clients never see stale events.
 */
class FeedController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly AgreementService $agreements,
		private readonly CompanyService $companies,
		private readonly ActivityService $activities,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function renewals(string $token) {
		if (!$this->settings->tokenMatches(Constants::CONFIG_ICS_TOKEN, $token)) {
			return $this->fail(Constants::ERR_FEED_TOKEN_REVOKED, 'Feed token revoked', [], Http::STATUS_GONE);
		}

		$events = [];
		$companyCache = [];
		$agreementEvents = 0;
		$maxAgreements = 500;

		foreach ($this->agreements->search([], 2000) as $a) {
			$status = (string)$a->getStatus();
			if (in_array($status, Constants::AGREEMENT_TERMINAL_STATUSES, true)) {
				continue;
			}
			if ((string)$a->getKind() === 'onetime') {
				continue;
			}
			$until = $a->getValidUntil();
			if ($until === null) {
				continue;
			}
			if ($agreementEvents >= $maxAgreements) {
				break;
			}
			$companyId = (int)$a->getCompanyId();
			if (!isset($companyCache[$companyId])) {
				try {
					$companyCache[$companyId] = (string)$this->companies->get($companyId)->getName();
				} catch (\Throwable) {
					$companyCache[$companyId] = '#' . $companyId;
				}
			}
			$companyName = $companyCache[$companyId];
			$label = (string)$a->getLabel();
			$summary = '⚠ ' . $companyName . ' — ' . $label . ' läuft aus / expires';
			$events[] = Ics::vevent(
				'crm-agr-' . (int)$a->getId() . '@customercheck',
				$summary,
				$until->format('Ymd'),
				'Agreement renewal · ' . $label,
			);
			$agreementEvents++;
		}

		// Open activity due dates — omit first when over capacity (§15).
		$activityBudget = max(0, 500 - count($events));
		if ($activityBudget > 0) {
			$added = 0;
			foreach ($this->activities->search(['openOnly' => true], 500) as $act) {
				if ($added >= $activityBudget) {
					break;
				}
				$due = $act->getDueDate();
				if ($due === null) {
					continue;
				}
				$companyId = (int)$act->getCompanyId();
				if (!isset($companyCache[$companyId])) {
					try {
						$companyCache[$companyId] = (string)$this->companies->get($companyId)->getName();
					} catch (\Throwable) {
						$companyCache[$companyId] = '#' . $companyId;
					}
				}
				$summary = $companyCache[$companyId] . ' — ' . (string)$act->getTitle();
				$events[] = Ics::vevent(
					'crm-act-' . (int)$act->getId() . '@customercheck',
					$summary,
					$due->format('Ymd'),
					(string)($act->getDetails() ?? 'CRM activity'),
				);
				$added++;
			}
		}

		$body = Ics::calendar('CustomerCheck renewals', $events);
		return new DataDisplayResponse($body, Http::STATUS_OK, [
			'Content-Type' => 'text/calendar; charset=utf-8',
			'Content-Disposition' => 'inline; filename="renewals.ics"',
		]);
	}
}
