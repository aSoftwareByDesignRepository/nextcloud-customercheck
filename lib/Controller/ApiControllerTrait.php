<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotAuthenticatedException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

trait ApiControllerTrait
{
	protected function ok(mixed $data = null, int $status = Http::STATUS_OK, ?array $meta = null): JSONResponse
	{
		$payload = ['success' => true, 'data' => $data];
		if ($meta !== null) {
			$payload['meta'] = $meta;
		}
		// Defense in depth: never let malformed UTF-8 (e.g. IMAP bodies) turn
		// JSON_THROW_ON_ERROR into an HTML 500 during JSONResponse::render().
		return new JSONResponse($payload, $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * Optimistic-lock precondition from body `updatedAt` or `If-Match-Updated-At` (§4.8).
	 *
	 * @param array<string, mixed> $body
	 */
	protected function updatedAtPrecondition(array $body): ?string
	{
		$header = trim((string)$this->request->getHeader('If-Match-Updated-At'));
		if ($header !== '') {
			return $header;
		}
		if (isset($body['updatedAt']) && is_string($body['updatedAt']) && trim($body['updatedAt']) !== '') {
			return trim($body['updatedAt']);
		}

		return null;
	}

	protected function fail(string $error, string $message, array $fields = [], int $status = Http::STATUS_BAD_REQUEST): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => $error,
			'message' => $message,
			'fields' => $fields === [] ? new \stdClass() : $fields,
		], $status, [], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	protected function fromThrowable(\Throwable $e): JSONResponse
	{
		if ($e instanceof ValidationException) {
			$status = match ($e->getErrorCode()) {
				Constants::ERR_HOOK_TOKEN_INVALID => Http::STATUS_UNAUTHORIZED,
				Constants::ERR_HOOK_RATE_LIMITED, Constants::ERR_RATE_LIMITED => Http::STATUS_TOO_MANY_REQUESTS,
				Constants::ERR_FEED_TOKEN_REVOKED => Http::STATUS_GONE,
				Constants::ERR_ATTACHMENT_TOO_LARGE,
				Constants::ERR_ATTACHMENT_DENIED,
				Constants::ERR_FILES_NOT_FILE,
				Constants::ERR_FILES_NOT_FOLDER,
				Constants::ERR_FILES_NOT_FOUND,
				Constants::ERR_FILES_PERMISSION_DENIED => Http::STATUS_UNPROCESSABLE_ENTITY,
				// FAC-01: personal sync disabled is a policy deny, not a form validation miss.
				\OCA\CustomerCheck\Service\PersonalMailService::ERR_PERSONAL_MAIL_DISABLED => Http::STATUS_FORBIDDEN,
				// FAC-19: same address cannot be personal + team.
				\OCA\CustomerCheck\Service\PersonalMailService::ERR_MAIL_ACCOUNT_CONFLICT => Http::STATUS_CONFLICT,
				default => Http::STATUS_BAD_REQUEST,
			};
			return $this->fail($e->getErrorCode(), $e->getMessage(), $e->getFields(), $status);
		}
		if ($e instanceof ConflictException) {
			$code = $e->getErrorCode() !== '' ? $e->getErrorCode() : Constants::ERR_CONFLICT_STALE;
			return $this->fail(
				$code,
				$e->getMessage() !== '' ? $e->getMessage() : 'Conflict',
				$e->getFields(),
				Http::STATUS_CONFLICT,
			);
		}
		if ($e instanceof NotFoundException) {
			return $this->fail($e->getErrorCode() ?: Constants::ERR_NOT_FOUND, $e->getMessage(), [], Http::STATUS_NOT_FOUND);
		}
		if ($e instanceof AccessDeniedException || $e instanceof AppAccessDeniedException) {
			return $this->fail(Constants::ERR_ACCESS_DENIED, $e->getMessage() !== '' ? $e->getMessage() : 'Access denied', [], Http::STATUS_FORBIDDEN);
		}
		if ($e instanceof NotAuthenticatedException) {
			return $this->fail(Constants::ERR_NOT_AUTHENTICATED, $e->getMessage() !== '' ? $e->getMessage() : 'Authentication required', [], Http::STATUS_UNAUTHORIZED);
		}
		// Never leak internals to the client; always log for ops (DI / SQL / race bugs).
		try {
			if (class_exists(\OC::class) && isset(\OC::$server)) {
				\OC::$server->get(\Psr\Log\LoggerInterface::class)->error(
					'CustomerCheck API unexpected error: ' . $e->getMessage(),
					['app' => 'customercheck', 'exception' => $e],
				);
			}
		} catch (\Throwable) {
			// Logging must never mask the client response.
		}
		return $this->fail('SERVER_ERROR', 'Unexpected error', [], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
