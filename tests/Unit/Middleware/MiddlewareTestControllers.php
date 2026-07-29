<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * Test-only stubs so middleware unit tests exercise the real class-name gates
 * (PHPUnit mocks do not live under OCA\CustomerCheck\Controller\).
 *
 * @internal
 */
class MiddlewareTestPageController extends Controller
{
	public function __construct(IRequest $request)
	{
		parent::__construct('customercheck', $request);
	}

	public function index(): void
	{
	}

	public function create(): void
	{
	}
}

/**
 * @internal
 */
class MiddlewareTestFeedController extends Controller
{
	public function __construct(IRequest $request)
	{
		parent::__construct('customercheck', $request);
	}

	public function renewals(): void
	{
	}
}
