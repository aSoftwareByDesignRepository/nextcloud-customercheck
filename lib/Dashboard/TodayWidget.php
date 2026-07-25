<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Dashboard;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\TodayService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Today urgency strip for the Nextcloud Dashboard — PSD §7.4 / S8.
 *
 * Registered only when the current user may use CustomerCheck (fail-closed).
 */
class TodayWidget implements IAPIWidgetV2, IIconWidget, IConditionalWidget, IReloadableWidget
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly TodayService $todayService,
		private readonly AccessControlService $accessControl,
		private readonly IUserSession $userSession,
		private readonly AppIconService $appIconService,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-today';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('CustomerCheck Today');
	}

	public function getOrder(): int
	{
		return 45;
	}

	public function getIconClass(): string
	{
		return 'icon-calendar-dark';
	}

	public function getIconUrl(): string
	{
		return $this->appIconService->absoluteSurfaceIconUrl();
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->linkToRouteAbsolute('customercheck.page.today');
	}

	public function getReloadInterval(): int
	{
		return 300;
	}

	public function isEnabled(): bool
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		return $this->accessControl->canUseApp($user->getUID());
	}

	public function load(): void
	{
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		if (!$this->accessControl->canUseApp($userId)) {
			return new WidgetItems([], $this->l10n->t('CustomerCheck is not available for your account.'));
		}

		$limit = max(1, min(20, $limit));
		$queue = $this->todayService->build();
		$items = [];
		$url = $this->getUrl() ?? '';
		$icon = $this->getIconUrl();
		$ts = (string)time();

		foreach (array_slice($queue['items'], 0, $limit) as $i => $row) {
			$kind = (string)($row['kind'] ?? '');
			$label = (string)($row['label'] ?? '');
			$due = (string)($row['dueDate'] ?? '');
			$companyId = (int)($row['companyId'] ?? 0);
			$link = $url;
			if ($companyId > 0) {
				$link = $this->urlGenerator->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId]);
			} elseif (!empty($row['deepLink']) && is_string($row['deepLink'])) {
				$link = $row['deepLink'];
			}
			$subtitle = match ($kind) {
				'agreement_expiry' => $this->composeSubtitle(
					(string)($row['companyName'] ?? ''),
					$due !== '' ? $this->l10n->t('Expires %s', [$due]) : $this->l10n->t('Agreement renewal'),
				),
				'ticket_waiting' => $this->composeSubtitle(
					(string)($row['companyName'] ?? ''),
					$this->l10n->t('Ticket waiting on us'),
				),
				'activity_overdue' => $this->composeSubtitle(
					(string)($row['companyName'] ?? ''),
					$due !== '' ? $this->l10n->t('Overdue since %s', [$due]) : $this->l10n->t('Overdue activity'),
				),
				'activity_due' => $this->composeSubtitle(
					(string)($row['companyName'] ?? ''),
					$due !== '' ? $this->l10n->t('Due %s', [$due]) : $this->l10n->t('Due today'),
				),
				default => $kind,
			};
			$items[] = new WidgetItem($label !== '' ? $label : $this->l10n->t('Open item'), $subtitle, $link, $icon, $ts . '-' . $i);
		}

		$empty = $this->l10n->t('Nothing urgent today.');
		return new WidgetItems($items, $items === [] ? $empty : '');
	}

	private function composeSubtitle(string $companyName, string $detail): string
	{
		$companyName = trim($companyName);
		if ($companyName === '') {
			return $detail;
		}
		return $companyName . ' · ' . $detail;
	}
}
