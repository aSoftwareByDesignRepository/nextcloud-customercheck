<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\Controller;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\AppAccessDeniedException;
use OCA\CustomerCheck\Exception\ConflictException;
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
		return new JSONResponse($payload, $status);
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
		], $status);
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
		return $this->fail('SERVER_ERROR', 'Unexpected error', [], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
