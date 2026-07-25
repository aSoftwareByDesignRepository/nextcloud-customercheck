<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\PersonMapper;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * Related messages without IMAP sync — PSD §14.8.
 *
 * Preferred: probe Mail searchable API when available.
 * Fallback: deep-link search buttons per person email.
 * Always: manual + compose email touches remain authoritative in CRM.
 */
class MailRelatedService
{
	public function __construct(
		private readonly FacadeProbeService $facades,
		private readonly PersonMapper $persons,
		private readonly EmailTouchService $emailTouches,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_MAIL_RELATED_ENABLED,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_MAIL_RELATED_ENABLED],
		) === '1';
	}

	/**
	 * @return array{
	 *   mode: 'list'|'deep_link'|'disabled'|'manual_only',
	 *   title: string,
	 *   headline: string,
	 *   note: string,
	 *   steps: list<array{title:string,text:string}>,
	 *   related: list<array<string,mixed>>,
	 *   deepLinks: list<array{email:string,url:string,label:string,personName:string}>,
	 *   touches: list<array<string,mixed>>,
	 *   summary: array{touchCount:int,contactCount:int,lastContactAt:?string,lastContactWith:?string,lastContactLabel:?string}
	 * }
	 */
	public function forCompany(int $companyId): array
	{
		/** @var array<string, array{email:string,name:string}> $contacts keyed by lowercase email */
		$contacts = [];
		foreach ($this->persons->findByCompany($companyId) as $person) {
			$email = trim((string)($person->getEmail() ?? ''));
			$name = trim((string)$person->getName());
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$key = strtolower($email);
			if (!isset($contacts[$key])) {
				$contacts[$key] = [
					'email' => $email,
					'name' => $name !== '' ? $name : $email,
				];
			}
		}

		$emailToName = [];
		foreach ($contacts as $key => $row) {
			$emailToName[$key] = $row['name'];
		}

		$rawTouches = $this->emailTouches->listForCompany($companyId, 100);
		$touches = [];
		foreach ($rawTouches as $e) {
			$direction = (string)$e->getDirection();
			$from = (string)$e->getFromAddr();
			$to = (string)$e->getToAddr();
			$withWhom = EmailOverviewPresenter::withWhom($direction, $from, $to);
			$touches[] = [
				'id' => (int)$e->getId(),
				'direction' => $direction,
				'directionLabel' => EmailOverviewPresenter::directionLabel($this->l10n, $direction),
				'fromAddr' => $from,
				'toAddr' => $to,
				'withWhom' => $withWhom,
				'withLabel' => EmailOverviewPresenter::withLabel($withWhom, $emailToName),
				'subject' => (string)$e->getSubject(),
				'occurredAt' => $e->getOccurredAt()?->format(\DateTimeInterface::ATOM),
				'whenLabel' => EmailOverviewPresenter::whenLabel($e->getOccurredAt()),
				'source' => (string)$e->getSource(),
				'sourceLabel' => EmailOverviewPresenter::sourceLabel($this->l10n, (string)$e->getSource()),
				'snippet' => $e->getSnippet(),
			];
		}

		$summary = $this->buildSummary($touches, count($contacts));
		$mailOn = $this->isEnabled() && $this->facades->mailAvailable();

		if (!$mailOn) {
			return $this->payload(
				'manual_only',
				$this->l10n->t('Email log'),
				$this->l10n->t('Your short memory of who you wrote — not a copy of your inbox.'),
				$this->l10n->t('Mail app is not available here. Log important messages so you still know what you wrote with whom.'),
				[],
				[],
				$touches,
				$summary,
			);
		}

		$emailList = array_values(array_map(static fn (array $c): string => $c['email'], $contacts));
		$relatedRaw = $this->facades->searchMailMessages($emailList, 50);
		$related = [];
		foreach ($relatedRaw as $row) {
			if (!is_array($row)) {
				continue;
			}
			$direction = (string)($row['direction'] ?? 'outbound');
			$from = (string)($row['fromAddr'] ?? '');
			$to = (string)($row['toAddr'] ?? '');
			$withWhom = EmailOverviewPresenter::withWhom($direction, $from, $to);
			$occurred = isset($row['occurredAt']) ? (string)$row['occurredAt'] : null;
			$when = '—';
			if ($occurred !== null && $occurred !== '') {
				try {
					$when = EmailOverviewPresenter::whenLabel(new \DateTimeImmutable($occurred));
				} catch (\Throwable) {
					$when = substr($occurred, 0, 10);
				}
			}
			$related[] = [
				'direction' => $direction,
				'directionLabel' => EmailOverviewPresenter::directionLabel($this->l10n, $direction),
				'fromAddr' => $from,
				'toAddr' => $to,
				'withWhom' => $withWhom,
				'withLabel' => EmailOverviewPresenter::withLabel($withWhom, $emailToName),
				'subject' => (string)($row['subject'] ?? ''),
				'occurredAt' => $occurred,
				'whenLabel' => $when,
				'source' => 'mail_app',
				'sourceLabel' => EmailOverviewPresenter::sourceLabel($this->l10n, 'mail_app'),
				'deepLink' => isset($row['deepLink']) ? (string)$row['deepLink'] : null,
			];
		}

		if ($related !== []) {
			return $this->payload(
				'list',
				$this->l10n->t('Who wrote what'),
				$this->l10n->t('Recent Mail hits for this company’s people, plus everything you logged in CRM.'),
				$this->l10n->t('Messages from your Mail accounts only.'),
				$related,
				[],
				$touches,
				$summary,
			);
		}

		$deepLinks = [];
		foreach ($contacts as $contact) {
			$email = $contact['email'];
			$name = $contact['name'];
			$deepLinks[] = [
				'email' => $email,
				'personName' => $name !== $email ? $name : '',
				'url' => $this->urlGenerator->linkToRoute('mail.home.index') . '#search=' . rawurlencode('from:' . $email . ' OR to:' . $email),
				'label' => $name !== '' && $name !== $email
					? $this->l10n->t('Open in Mail: %1$s (%2$s)', [$name, $email])
					: $this->l10n->t('Open in Mail: %s', [$email]),
			];
		}

		if ($deepLinks === []) {
			return $this->payload(
				'manual_only',
				$this->l10n->t('Who wrote what'),
				$this->l10n->t('Add a contact email under People — then you can jump into Mail and keep a short CRM log here.'),
				$this->l10n->t('Add a contact email to open Mail threads for this company.'),
				[],
				[],
				$touches,
				$summary,
			);
		}

		return $this->payload(
			'deep_link',
			$this->l10n->t('Who wrote what'),
			$this->l10n->t('CRM keeps a short log. Mail holds the full threads — open a contact below when you need them.'),
			$this->l10n->t('Messages from your Mail accounts only.'),
			[],
			$deepLinks,
			$touches,
			$summary,
		);
	}

	/**
	 * @param list<array<string,mixed>> $touches
	 * @param list<array<string,mixed>> $related
	 * @param list<array{email:string,url:string,label:string,personName:string}> $deepLinks
	 * @param array{touchCount:int,contactCount:int,lastContactAt:?string,lastContactWith:?string,lastContactLabel:?string} $summary
	 * @return array<string,mixed>
	 */
	private function payload(
		string $mode,
		string $title,
		string $headline,
		string $note,
		array $related,
		array $deepLinks,
		array $touches,
		array $summary,
	): array {
		return [
			'mode' => $mode,
			'title' => $title,
			'headline' => $headline,
			'note' => $note,
			'steps' => EmailOverviewPresenter::workflowSteps($this->l10n, $mode),
			'related' => $related,
			'deepLinks' => $deepLinks,
			'touches' => $touches,
			'summary' => $summary,
		];
	}

	/**
	 * @param list<array<string,mixed>> $touches newest first
	 * @return array{touchCount:int,contactCount:int,lastContactAt:?string,lastContactWith:?string,lastContactLabel:?string}
	 */
	private function buildSummary(array $touches, int $contactCount): array
	{
		$first = $touches[0] ?? null;
		return [
			'touchCount' => count($touches),
			'contactCount' => $contactCount,
			'lastContactAt' => is_array($first) ? (isset($first['whenLabel']) ? (string)$first['whenLabel'] : null) : null,
			'lastContactWith' => is_array($first) ? (isset($first['withWhom']) ? (string)$first['withWhom'] : null) : null,
			'lastContactLabel' => is_array($first) ? (isset($first['withLabel']) ? (string)$first['withLabel'] : null) : null,
		];
	}
}
