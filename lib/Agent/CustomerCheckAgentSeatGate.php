<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Agent;

use OCA\CustomerCheck\Service\LicenseService;

/**
 * Per-request seat entitlement for agent requests. Delegates to the app's
 * mobile-seat authority — a revoked seat kills the token on its next call.
 * Any entitlement failure collapses to one uniform 403.
 */
final class CustomerCheckAgentSeatGate implements AgentSeatGate
{
	public function __construct(
		private readonly LicenseService $license,
	) {
	}

	public function assertEntitled(string $uid): void
	{
		try {
			$this->license->assertMobileAccess($uid);
		} catch (AgentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new AgentException(
				'agent_seat_revoked',
				'This agent token has no active entitlement.',
				403,
			);
		}
	}
}
