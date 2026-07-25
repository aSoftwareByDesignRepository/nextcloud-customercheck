<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Notification;
use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier
{
	public function __construct(private readonly IFactory $l10nFactory, private readonly IURLGenerator $url) {}
	public function getID(): string { return Application::APP_ID; }
	public function getName(): string { return $this->l10nFactory->get(Application::APP_ID)->t("CustomerCheck"); }
	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new \InvalidArgumentException("wrong app");
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$company = (string)($p['companyName'] ?? $p['name'] ?? '');
		$label = (string)($p['label'] ?? '');
		$offset = (int)($p['offsetDays'] ?? $p['offset'] ?? 0);
		$text = match ($notification->getSubject()) {
			Constants::NOTIFY_REMINDER => $this->reminderSubject($l, $company, $label, $offset, $p),
			Constants::NOTIFY_QUOTA80 => $l->t(
				'Check Partner quota reached 80%% for %s (%s)',
				[$company !== '' ? $company : $l->t('a customer'), $label !== '' ? $label : $l->t('package')],
			),
			Constants::NOTIFY_DIGEST => $l->t(
				'CustomerCheck weekly: %1$d renewals ≤30d, %2$d overdue activities, %3$d unpaid, %4$d Partner ≥80%%',
				[
					(int)($p['renewals'] ?? 0),
					(int)($p['overdue'] ?? 0),
					(int)($p['unpaid'] ?? 0),
					(int)($p['quota80'] ?? 0),
				],
			),
			Constants::NOTIFY_LEAD => $l->t('New website lead: %s', [(string)($p['name'] ?? $company)]),
			Constants::NOTIFY_INVOICE_PAID => $l->t(
				'Invoice paid for %s',
				[$company !== '' ? $company : $l->t('a customer')],
			),
			Constants::NOTIFY_KEY_GENERATED => $l->t(
				'New key generated for %s — review draft agreement.',
				[(string)($p['companyName'] ?? $l->t('company'))],
			),
			default => $l->t('CustomerCheck'),
		};
		$companyId = (int)($p['companyId'] ?? 0);
		$link = $companyId > 0
			? $this->url->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId])
			: $this->url->linkToRouteAbsolute('customercheck.page.today');
		$notification->setParsedSubject($text)->setLink($link);
		return $notification;
	}

	/**
	 * @param array<string,mixed> $p
	 */
	private function reminderSubject(\OCP\IL10N $l, string $company, string $label, int $offset, array $p): string
	{
		$who = $company !== '' ? $company : $l->t('Customer');
		$what = $label !== '' ? $label : $l->t('agreement');
		$grace = (string)($p['graceUntil'] ?? '');
		return match (true) {
			$offset === -60 => $l->t('%1$s: %2$s expires in 60 days', [$who, $what]),
			$offset === -30 => $l->t('%1$s: %2$s expires in 30 days', [$who, $what]),
			$offset === -7 => $l->t('%1$s: %2$s expires in 7 days — customer clients will hit 402 at expiry', [$who, $what]),
			$offset === 0 => $grace !== ''
				? $l->t('%1$s: %2$s expired today (grace until %3$s)', [$who, $what, $grace])
				: $l->t('%1$s: %2$s expired today', [$who, $what]),
			$offset === 7 => $l->t('%1$s: %2$s grace ended — access stopped', [$who, $what]),
			default => $l->t('%1$s: %2$s renewal reminder', [$who, $what]),
		};
	}
}
