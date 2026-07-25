<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Service;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\EmailTouch;
use OCA\CustomerCheck\Db\EmailTouchMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

class EmailTouchService
{
	public function __construct(
		private readonly EmailTouchMapper $mapper,
		private readonly CompanyService $companyService,
		private readonly PersonService $personService,
		private readonly TimelineService $timelineService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {}

	/** @return EmailTouch[] */
	public function listForCompany(int $companyId, int $limit = 100): array
	{
		$this->companyService->get($companyId);
		return $this->mapper->findByCompany($companyId, $limit);
	}

	/** @param array<string,mixed> $data */
	public function createManual(int $companyId, array $data, string $actor): EmailTouch
	{
		$this->companyService->get($companyId);
		$direction = (string)($data['direction'] ?? 'outbound');
		if (!in_array($direction, Constants::EMAIL_DIRECTIONS, true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'direction', $this->l10n->t('Choose inbound or outbound.'));
		}

		$from = trim((string)($data['fromAddr'] ?? ''));
		$to = trim((string)($data['toAddr'] ?? ''));
		$personId = isset($data['personId']) ? (int)$data['personId'] : 0;

		// Simplified UX: personId + subject fills addresses (from/to optional).
		if ($personId > 0 && ($from === '' || $to === '')) {
			try {
				$person = $this->personService->get($personId);
			} catch (NotFoundException $e) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'personId', $this->l10n->t('Pick a person on this company.'));
			}
			if ((int)$person->getCompanyId() !== $companyId) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'personId', $this->l10n->t('Pick a person on this company.'));
			}
			$personEmail = trim((string)($person->getEmail() ?? ''));
			if ($personEmail === '' || !filter_var($personEmail, FILTER_VALIDATE_EMAIL)) {
				throw ValidationException::field(Constants::ERR_EMAIL_INVALID, 'personId', $this->l10n->t('That person needs an email address first.'));
			}
			if ($direction === 'inbound') {
				$from = $from !== '' ? $from : $personEmail;
				$to = $to !== '' ? $to : 'crm@local';
			} else {
				$from = $from !== '' ? $from : 'crm@local';
				$to = $to !== '' ? $to : $personEmail;
			}
		}

		$subject = trim((string)($data['subject'] ?? ''));
		$occurred = DateMath::parseStrictDate(substr((string)($data['occurredAt'] ?? ''), 0, 10));
		if (!$this->isAllowedEmail($from) || !$this->isAllowedEmail($to)) {
			throw ValidationException::field(Constants::ERR_EMAIL_INVALID, 'fromAddr', $this->l10n->t('Enter valid email addresses.'));
		}
		if ($subject === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'subject', $this->l10n->t('Enter a subject.'));
		}
		$now = $this->timeFactory->getDateTime();
		$touch = new EmailTouch();
		$touch->setCompanyId($companyId);
		$touch->setDirection($direction);
		$touch->setFromAddr($from);
		$touch->setToAddr($to);
		$touch->setSubject(mb_substr($subject, 0, 255));
		$touch->setOccurredAt($occurred !== null ? new \DateTime($occurred->format('Y-m-d') . ' 12:00:00') : $now);
		$touch->setSource('manual');
		$touch->setSnippet(isset($data['snippet']) ? mb_substr(trim((string)$data['snippet']), 0, 500) : null);
		if ($personId > 0) {
			$touch->setPersonId($personId);
		}
		$touch->setCreatedBy($actor);
		$touch->setCreatedAt($now);
		$stored = $this->mapper->insert($touch);
		$this->timelineService->append($companyId, 'email.logged', $actor, ['emailId' => (int)$stored->getId()]);
		return $stored;
	}

	private function isAllowedEmail(string $email): bool
	{
		if ($email === 'crm@local') {
			return true;
		}
		return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	public function logCompose(int $companyId, string $to, string $subject, string $actor, string $from = ''): EmailTouch
	{
		$now = $this->timeFactory->getDateTime();
		$touch = new EmailTouch();
		$touch->setCompanyId($companyId);
		$touch->setDirection('outbound');
		$touch->setFromAddr($from !== '' ? $from : 'crm@local');
		$touch->setToAddr($to);
		$touch->setSubject(mb_substr($subject, 0, 255));
		$touch->setOccurredAt($now);
		$touch->setSource('compose');
		$touch->setCreatedBy($actor);
		$touch->setCreatedAt($now);
		$stored = $this->mapper->insert($touch);
		$this->timelineService->append($companyId, 'email.composed', $actor, ['emailId' => (int)$stored->getId()]);
		return $stored;
	}
}
