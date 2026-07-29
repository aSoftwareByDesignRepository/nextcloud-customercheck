<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Integration\SiblingFacadeContracts;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * CRM → sibling write-link orchestrator (CHECK-SUITE F4/F5 / FC-PC-WRITE / FC-TC-WRITE).
 *
 * Never opens a DB transaction that spans apps. CRM optimistic lock runs first;
 * sibling facade is called outside CRM TX; then CRM stores the link id.
 */
class SiblingCustomerWriteService
{
	/** @var callable():object|null */
	private $pcFacadeFactory;

	/** @var callable():object|null */
	private $tcFacadeFactory;

	/** @var callable(array):object|null */
	private $pcRequestFactory;

	/** @var callable(array):object|null */
	private $tcRequestFactory;

	/**
	 * @param callable():object|null $pcFacadeFactory
	 * @param callable():object|null $tcFacadeFactory
	 * @param callable(array<string,mixed>):object|null $pcRequestFactory
	 * @param callable(array<string,mixed>):object|null $tcRequestFactory
	 */
	public function __construct(
		private readonly CompanyService $companies,
		private readonly CompanyMapper $companyMapper,
		private readonly FacadeProbeService $facades,
		private readonly TimelineService $timeline,
		private readonly PersonMapper $personMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		$pcFacadeFactory = null,
		$tcFacadeFactory = null,
		$pcRequestFactory = null,
		$tcRequestFactory = null,
	) {
		$this->pcFacadeFactory = $pcFacadeFactory;
		$this->tcFacadeFactory = $tcFacadeFactory;
		$this->pcRequestFactory = $pcRequestFactory;
		$this->tcRequestFactory = $tcRequestFactory;
	}

	public function projectWriteAvailable(): bool
	{
		return $this->facades->projectcheckWriteAvailable();
	}

	public function ticketWriteAvailable(): bool
	{
		return $this->facades->ticketcheckWriteAvailable();
	}

	/**
	 * Create a ProjectCheck customer from a CRM company, or link an existing one.
	 *
	 * @return array{company: Company, pcCustomerId: int, created: bool}
	 */
	public function createOrLinkPcCustomer(
		int $companyId,
		string $actorUid,
		?int $existingPcCustomerId,
		?string $updatedAtPrecondition,
	): array {
		$company = $this->companies->get($companyId);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		if (!$this->projectWriteAvailable()) {
			throw new ValidationException(
				Constants::ERR_SIBLING_UNAVAILABLE,
				$this->l10n->t('ProjectCheck is not available for creating customers on this instance.'),
			);
		}

		// Idempotent guard: never create a second PC customer when CRM already linked.
		// Concurrent create still relies on PC name UNIQUE + CRM optimistic lock.
		$alreadyLinked = $company->getPcCustomerId();
		if ($alreadyLinked !== null && (int)$alreadyLinked > 0) {
			if ($existingPcCustomerId !== null && $existingPcCustomerId > 0
				&& (int)$existingPcCustomerId !== (int)$alreadyLinked) {
				throw new ConflictException(
					$this->l10n->t('This company is already linked to a different ProjectCheck customer.'),
					Constants::ERR_LINK_CONFLICT,
					['existingPcCustomerId' => (int)$alreadyLinked],
				);
			}
			$existingPcCustomerId = (int)$alreadyLinked;
		}

		$facade = $this->resolvePcFacade();
		$contact = $this->primaryContactPayload($companyId);

		$payload = [
			'actorUid' => $actorUid,
			'displayName' => (string)$company->getName(),
			'email' => $contact['email'],
			'phone' => $contact['phone'],
			'contactPerson' => $contact['contactPerson'],
			'crmCompanyId' => (int)$company->getId(),
			'crmCompanySlug' => (string)$company->getSlug(),
			'crmUpdatedAt' => $company->getUpdatedAt()?->format('Y-m-d H:i:s'),
		];

		if ($existingPcCustomerId !== null && $existingPcCustomerId > 0) {
			$this->assertPcLinkFree($companyId, $existingPcCustomerId);
			$payload['existingPcCustomerId'] = $existingPcCustomerId;
			$request = $this->buildPcRequest($payload);
			$result = $facade->ensureLink($request);
		} else {
			$request = $this->buildPcRequest($payload);
			$result = $facade->createCustomer($request);
			if (!$result->ok && $result->code === 'duplicate_name') {
				$existingId = (int)($result->data['existingPcCustomerId'] ?? 0);
				throw new ConflictException(
					$this->l10n->t('A ProjectCheck customer with this name already exists. Link the existing customer instead.'),
					Constants::ERR_LINK_CONFLICT,
					$existingId > 0 ? ['existingPcCustomerId' => $existingId] : [],
				);
			}
		}

		if (!$result->ok) {
			$this->mapFacadeFailure($result->code, $result->message, is_array($result->data) ? $result->data : []);
		}

		$pcId = (int)($result->data['pcCustomerId'] ?? 0);
		if ($pcId <= 0) {
			throw new ValidationException(Constants::ERR_VALIDATION, $this->l10n->t('ProjectCheck did not return a customer id.'));
		}

		$this->assertPcLinkFree($companyId, $pcId);

		$created = (bool)($result->data['created'] ?? false);
		// Already-linked idempotent path: skip CRM write + timeline noise.
		if ($alreadyLinked !== null && (int)$alreadyLinked === $pcId) {
			return [
				'company' => $company,
				'pcCustomerId' => $pcId,
				'created' => false,
			];
		}

		try {
			$updated = $this->companies->update($companyId, [
				'pcCustomerId' => $pcId,
			], $actorUid, $updatedAtPrecondition);
		} catch (\Throwable $e) {
			$current = $this->companies->get($companyId);
			if ((int)($current->getPcCustomerId() ?? 0) === $pcId) {
				return [
					'company' => $current,
					'pcCustomerId' => $pcId,
					'created' => $created,
				];
			}
			$this->logger->error('ProjectCheck customer created but CRM link persistence failed', [
				'companyId' => $companyId,
				'pcCustomerId' => $pcId,
				'exception' => $e,
			]);
			throw new ConflictException(
				$this->l10n->t('The ProjectCheck customer was created, but CustomerCheck could not save the link. Link the existing ProjectCheck customer on retry.') . ' #' . $pcId,
				Constants::ERR_LINK_CONFLICT,
				['existingPcCustomerId' => $pcId],
			);
		}

		$this->timeline->append($companyId, 'company.pc_linked', $actorUid, [
			'pcCustomerId' => $pcId,
			'created' => $created,
		]);

		return [
			'company' => $updated,
			'pcCustomerId' => $pcId,
			'created' => $created,
		];
	}

	/**
	 * @return array{company: Company, tcCustomerId: int, created: bool}
	 */
	public function createOrLinkTcCustomer(
		int $companyId,
		string $actorUid,
		?int $existingTcCustomerId,
		?string $updatedAtPrecondition,
	): array {
		$company = $this->companies->get($companyId);
		OptimisticLock::assertFresh($company->getUpdatedAt(), $updatedAtPrecondition);

		if (!$this->ticketWriteAvailable()) {
			throw new ValidationException(
				Constants::ERR_SIBLING_UNAVAILABLE,
				$this->l10n->t('TicketCheck is not available for creating customers on this instance.'),
			);
		}

		$alreadyLinked = $company->getTcCustomerId();
		if ($alreadyLinked !== null && (int)$alreadyLinked > 0) {
			if ($existingTcCustomerId !== null && $existingTcCustomerId > 0
				&& (int)$existingTcCustomerId !== (int)$alreadyLinked) {
				throw new ConflictException(
					$this->l10n->t('This company is already linked to a different TicketCheck customer.'),
					Constants::ERR_LINK_CONFLICT,
					['existingTcCustomerId' => (int)$alreadyLinked],
				);
			}
			$existingTcCustomerId = (int)$alreadyLinked;
		}

		$facade = $this->resolveTcFacade();
		$contact = $this->primaryContactPayload($companyId);

		$payload = [
			'actorUid' => $actorUid,
			'displayName' => (string)$company->getName(),
			'email' => $contact['email'],
			'phone' => $contact['phone'],
			'crmCompanyId' => (int)$company->getId(),
			'crmCompanySlug' => (string)$company->getSlug(),
		];

		if ($existingTcCustomerId !== null && $existingTcCustomerId > 0) {
			$this->assertTcLinkFree($companyId, $existingTcCustomerId);
			$payload['existingTcCustomerId'] = $existingTcCustomerId;
			$request = $this->buildTcRequest($payload);
			$result = $facade->ensureLink($request);
		} else {
			$request = $this->buildTcRequest($payload);
			$result = $facade->createCustomer($request);
			if (!$result->ok && $result->code === 'duplicate_name') {
				$existingId = (int)($result->data['existingTcCustomerId'] ?? 0);
				throw new ConflictException(
					$this->l10n->t('A TicketCheck customer with this name already exists. Link the existing customer instead.'),
					Constants::ERR_LINK_CONFLICT,
					$existingId > 0 ? ['existingTcCustomerId' => $existingId] : [],
				);
			}
		}

		if (!$result->ok) {
			$this->mapFacadeFailure($result->code, $result->message, is_array($result->data) ? $result->data : []);
		}

		$tcId = (int)($result->data['tcCustomerId'] ?? 0);
		if ($tcId <= 0) {
			throw new ValidationException(Constants::ERR_VALIDATION, $this->l10n->t('TicketCheck did not return a customer id.'));
		}

		$this->assertTcLinkFree($companyId, $tcId);

		$created = (bool)($result->data['created'] ?? false);
		if ($alreadyLinked !== null && (int)$alreadyLinked === $tcId) {
			return [
				'company' => $company,
				'tcCustomerId' => $tcId,
				'created' => false,
			];
		}

		try {
			$updated = $this->companies->update($companyId, [
				'tcCustomerId' => $tcId,
			], $actorUid, $updatedAtPrecondition);
		} catch (\Throwable $e) {
			$current = $this->companies->get($companyId);
			if ((int)($current->getTcCustomerId() ?? 0) === $tcId) {
				return [
					'company' => $current,
					'tcCustomerId' => $tcId,
					'created' => $created,
				];
			}
			$this->logger->error('TicketCheck customer created but CRM link persistence failed', [
				'companyId' => $companyId,
				'tcCustomerId' => $tcId,
				'exception' => $e,
			]);
			throw new ConflictException(
				$this->l10n->t('The TicketCheck customer was created, but CustomerCheck could not save the link. Link the existing TicketCheck customer on retry.') . ' #' . $tcId,
				Constants::ERR_LINK_CONFLICT,
				['existingTcCustomerId' => $tcId],
			);
		}

		$this->timeline->append($companyId, 'company.tc_linked', $actorUid, [
			'tcCustomerId' => $tcId,
			'created' => $created,
		]);

		return [
			'company' => $updated,
			'tcCustomerId' => $tcId,
			'created' => $created,
		];
	}

	/**
	 * @return array{email:?string,phone:?string,contactPerson:?string}
	 */
	private function primaryContactPayload(int $companyId): array
	{
		$primary = $this->personMapper->findPrimary($companyId);
		if ($primary === null) {
			return ['email' => null, 'phone' => null, 'contactPerson' => null];
		}
		$email = trim((string)($primary->getEmail() ?? ''));
		$phone = trim((string)($primary->getPhone() ?? ''));
		$name = trim((string)($primary->getName() ?? ''));

		return [
			'email' => $email !== '' ? $email : null,
			'phone' => $phone !== '' ? $phone : null,
			'contactPerson' => $name !== '' ? $name : null,
		];
	}

	private function resolvePcFacade(): object
	{
		if ($this->pcFacadeFactory !== null) {
			return ($this->pcFacadeFactory)();
		}
		return \OCP\Server::get(SiblingFacadeContracts::PROJECT_CHECK_WRITE_FACADE);
	}

	private function resolveTcFacade(): object
	{
		if ($this->tcFacadeFactory !== null) {
			return ($this->tcFacadeFactory)();
		}
		return \OCP\Server::get(SiblingFacadeContracts::TICKET_CHECK_WRITE_FACADE);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function buildPcRequest(array $payload): object
	{
		if ($this->pcRequestFactory !== null) {
			return ($this->pcRequestFactory)($payload);
		}
		$class = SiblingFacadeContracts::PROJECT_CHECK_WRITE_REQUEST;
		return $class::fromArray($payload);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function buildTcRequest(array $payload): object
	{
		if ($this->tcRequestFactory !== null) {
			return ($this->tcRequestFactory)($payload);
		}
		$class = SiblingFacadeContracts::TICKET_CHECK_WRITE_REQUEST;
		return $class::fromArray($payload);
	}

	private function assertPcLinkFree(int $companyId, int $pcCustomerId): void
	{
		$other = $this->companyMapper->findByPcCustomerId($pcCustomerId);
		if ($other !== null && (int)$other->getId() !== $companyId) {
			throw new ConflictException(
				$this->l10n->t('This ProjectCheck customer is already linked to another company.'),
				Constants::ERR_LINK_CONFLICT,
			);
		}
	}

	private function assertTcLinkFree(int $companyId, int $tcCustomerId): void
	{
		$other = $this->companyMapper->findByTcCustomerId($tcCustomerId);
		if ($other !== null && (int)$other->getId() !== $companyId) {
			throw new ConflictException(
				$this->l10n->t('This TicketCheck customer is already linked to another company.'),
				Constants::ERR_LINK_CONFLICT,
			);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return never
	 */
	private function mapFacadeFailure(?string $code, ?string $message, array $data = []): void
	{
		$code = $code ?? 'validation_failed';
		$msg = $message !== null && $message !== '' ? $message : $code;
		if ($code === 'permission_denied') {
			throw new ValidationException(Constants::ERR_ACCESS_DENIED, $msg);
		}
		if ($code === 'not_found') {
			throw new NotFoundException($msg, Constants::ERR_NOT_FOUND);
		}
		if ($code === 'link_conflict' || $code === 'duplicate_name') {
			$fields = [];
			if (isset($data['existingPcCustomerId']) && (int)$data['existingPcCustomerId'] > 0) {
				$fields['existingPcCustomerId'] = (int)$data['existingPcCustomerId'];
			}
			if (isset($data['existingTcCustomerId']) && (int)$data['existingTcCustomerId'] > 0) {
				$fields['existingTcCustomerId'] = (int)$data['existingTcCustomerId'];
			}
			throw new ConflictException($msg, Constants::ERR_LINK_CONFLICT, $fields);
		}
		if ($code === 'sibling_unavailable') {
			throw new ValidationException(Constants::ERR_SIBLING_UNAVAILABLE, $msg);
		}
		$this->logger->warning('Sibling write facade failed', ['code' => $code, 'message' => $msg]);
		throw new ValidationException(Constants::ERR_VALIDATION, $msg);
	}
}
