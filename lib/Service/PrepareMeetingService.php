<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;
use OCP\IURLGenerator;

/** Prepare meeting pack — product §12.5. */
class PrepareMeetingService
{
	public function __construct(
		private readonly CoverageService $coverage,
		private readonly PersonService $persons,
		private readonly PromiseService $promises,
		private readonly CompanyCommandService $command,
		private readonly AccountPlanService $plans,
		private readonly DisplayLabelService $labels,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return array<string, mixed> */
	public function build(int $companyId, string $userId): array
	{
		$command = $this->command->build($companyId, $userId);
		$coverage = $this->coverage->forCompany($companyId);
		if (!isset($coverage['flagLabels']) || !is_array($coverage['flagLabels'])) {
			$flagLabels = [];
			foreach ($coverage['flags'] ?? [] as $flag) {
				$flagLabels[] = $this->labels->coverageFlag((string)$flag);
			}
			$coverage['flagLabels'] = $flagLabels;
		}
		if (!isset($coverage['missingLabels']) || !is_array($coverage['missingLabels'])) {
			$missingLabels = [];
			foreach ($coverage['missing'] ?? [] as $slot) {
				$missingLabels[] = $this->labels->coverageMissing((string)$slot);
			}
			$coverage['missingLabels'] = $missingLabels;
		}

		$people = [];
		foreach ($this->persons->listForCompany($companyId) as $p) {
			$role = (string)($p->getBuyingRole() ?? '');
			$status = (string)($p->getStatus() ?? 'active');
			$people[] = [
				'id' => (int)$p->getId(),
				'name' => (string)$p->getName(),
				'buyingRole' => $role !== '' ? $role : null,
				'buyingRoleLabel' => $role !== '' ? $this->labels->buyingRole($role) : null,
				'status' => $status,
				'statusLabel' => $this->labels->personStatus($status),
			];
		}
		$promises = array_values(array_filter(
			$this->promises->listForCompany($companyId),
			static fn (array $row): bool => ($row['status'] ?? '') === 'open',
		));
		foreach ($promises as &$promise) {
			$st = (string)($promise['status'] ?? 'open');
			$promise['statusLabel'] = $this->labels->promiseStatus($st);
		}
		unset($promise);

		$comm = $this->command->communication($companyId, $userId, 5);
		$commItems = array_slice($comm['items'] ?? [], 0, 5);
		foreach ($commItems as &$item) {
			$kind = (string)($item['kind'] ?? '');
			$direction = (string)($item['direction'] ?? '');
			$item['kindLabel'] = $this->labels->communicationKind($kind);
			$item['directionLabel'] = $direction !== ''
				? $this->labels->mailDirection($direction)
				: '';
		}
		unset($item);

		$plan = $this->plans->forCompany($companyId);
		$map = $plan['mapSteps'] ?? ($command['accountPlan']['mapSteps'] ?? []);
		if (!is_array($map)) {
			$map = [];
		}
		foreach ($map as &$step) {
			if (!is_array($step)) {
				continue;
			}
			$st = (string)($step['status'] ?? '');
			$step['statusLabel'] = $this->labels->mapStepStatus($st);
		}
		unset($step);
		$planGoal = $plan['goal'] ?? ($command['accountPlan']['goal'] ?? null);

		$companyUrl = $this->urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $companyId]);
		$todayUrl = $this->urlGenerator->linkToRoute('customercheck.page.today');

		return [
			'title' => $this->l10n->t('Prepare meeting'),
			'companyId' => $companyId,
			'companyName' => (string)($command['name'] ?? ''),
			'coverage' => $coverage,
			'people' => $people,
			'communication' => $commItems,
			'openPromises' => $promises,
			'glance' => $command,
			'map' => $map,
			'renewals90d' => $command['runway'] ?? null,
			'partnerHours' => $command['partnerHours'] ?? null,
			'money' => $command['billing'] ?? null,
			'waitingTickets' => (int)($command['counts']['waitingTickets'] ?? $command['waitingTickets'] ?? 0),
			'planGoal' => $planGoal,
			'ctas' => [
				[
					'label' => $this->l10n->t('Open customer'),
					'href' => $companyUrl,
				],
				[
					'label' => $this->l10n->t('Open Today'),
					'href' => $todayUrl,
				],
			],
		];
	}
}
