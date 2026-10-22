<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Support\CountryDirectory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Small lookup endpoints (non-entity data the UI needs on demand).
 */
class MetaApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Localized ISO 3166-1 (+XK) country list for dynamically built pickers
	 * (server-rendered pickers carry their options in markup already).
	 */
	#[NoAdminRequired]
	public function countries(): JSONResponse
	{
		try {
			$this->access->currentUserId();
			$lang = method_exists($this->l10n, 'getLanguageCode')
				? (string)$this->l10n->getLanguageCode()
				: 'en';
			$items = [];
			foreach (CountryDirectory::options($lang) as $code => $label) {
				$items[] = ['code' => $code, 'label' => $label];
			}

			return $this->ok(['items' => $items]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
