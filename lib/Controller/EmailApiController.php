<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\EmailTouchService;
use OCA\CustomerCheck\Service\MailRelatedService;
use OCA\CustomerCheck\Service\MailTemplateService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Company email log + Related messages — PSD §14.8.
 * Workspace-scoped.
 */
class EmailApiController extends Controller
{
	use ApiControllerTrait;
	use WorkspaceScopedTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
		private readonly CompanyService $companies,
		private readonly EmailTouchService $emails,
		private readonly MailRelatedService $mailRelated,
		private readonly MailTemplateService $templates,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse
	{
		try {
			$this->requireCompanyInResolvedWorkspace($id);

			return $this->ok($this->mailRelated->forCompany($id));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$touch = $this->emails->createManual($id, is_array($body) ? $body : [], $ws['uid']);

			return $this->ok([
				'id' => (int)$touch->getId(),
				'direction' => (string)$touch->getDirection(),
				'fromAddr' => (string)$touch->getFromAddr(),
				'toAddr' => (string)$touch->getToAddr(),
				'subject' => (string)$touch->getSubject(),
				'occurredAt' => $touch->getOccurredAt()?->format(\DateTimeInterface::ATOM),
				'source' => (string)$touch->getSource(),
				'snippet' => $touch->getSnippet(),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function composeTemplate(int $id): JSONResponse
	{
		try {
			$company = $this->requireCompanyInResolvedWorkspace($id);
			$ws = $this->assertLoadedCompanyInWorkspace($company, 'write');
			$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
			$agreementId = isset($body['agreementId']) ? (int)$body['agreementId'] : null;
			if ($agreementId !== null && $agreementId <= 0) {
				$agreementId = null;
			}

			return $this->ok($this->templates->build(
				(string)($body['template'] ?? ''),
				$id,
				$agreementId,
				$ws['uid'],
				is_array($body) ? $body : [],
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
