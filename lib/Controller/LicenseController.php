<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Exception\LicenseException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Settings → License (CUC2) — app-admin only (COMPANION-APP §5.2).
 *
 * The web app itself is never gated by this license; these endpoints only
 * manage the companion entitlement + named seats.
 */
class LicenseController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly LicenseService $license,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		try {
			$this->requireAppAdmin();

			return new JSONResponse($this->license->status());
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		try {
			$uid = $this->requireAppAdmin();
			$key = $this->request->getParam('key');
			if (!is_string($key) || trim($key) === '') {
				return new JSONResponse([
					'ok' => false,
					'error' => 'LICENSE_INVALID',
					'message' => $this->l10n->t('A license key is required.'),
				], Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			return new JSONResponse($this->license->apply($uid, $key));
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		try {
			$this->requireAppAdmin();

			return new JSONResponse($this->license->remove());
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function seats(): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			$limit = (int)$this->request->getParam('limit', 50);
			$offset = (int)$this->request->getParam('offset', 0);

			return new JSONResponse($this->license->listSeats($limit, $offset));
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		try {
			$uid = $this->requireAppAdmin();
			$result = $this->license->assignSeat($uid, $this->request->getParam('userId'));

			return new JSONResponse(
				$result['seat'],
				$result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK,
			);
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	#[NoAdminRequired]
	public function removeSeat(string $uid): JSONResponse
	{
		try {
			$this->requireAppAdmin();
			$this->license->removeSeat($uid);

			return new JSONResponse(['deleted' => true]);
		} catch (LicenseException $e) {
			return $this->fromLicenseException($e);
		}
	}

	private function requireAppAdmin(): string
	{
		$uid = $this->access->currentUserIdOrEmpty();
		if ($uid === '' || !$this->access->canManageAppConfiguration($uid)) {
			throw new LicenseException(
				'ACCESS_DENIED',
				$this->l10n->t('You need CustomerCheck administrator rights for this action.'),
				403,
			);
		}

		return $uid;
	}

	private function fromLicenseException(LicenseException $e): JSONResponse
	{
		return new JSONResponse([
			'ok' => false,
			'error' => $e->getErrorCode(),
			'message' => $e->getMessage(),
		], $e->getHttpStatus());
	}
}
