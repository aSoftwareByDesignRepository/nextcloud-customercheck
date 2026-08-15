<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Review API literal bulk paths must register before /items/{id}/… siblings.
 */
final class ReviewRouteOrderingContractTest extends TestCase
{
	/** @return list<array<string, mixed>> */
	private static function routes(): array
	{
		/** @var array{routes: list<array<string, mixed>>} $config */
		$config = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return $config['routes'];
	}

	public function testBulkLiteralRoutesPrecedeParameterizedReviewItemRoutes(): void
	{
		$firstParamIdx = null;
		$discardBulkIdx = null;
		$markSpamBulkIdx = null;

		foreach (self::routes() as $i => $route) {
			$url = (string)($route['url'] ?? '');
			if (!str_contains($url, '/review/items/')) {
				continue;
			}
			if (str_contains($url, '/review/items/{id}/')) {
				$firstParamIdx ??= $i;
			}
			if ($url === '/api/v1/workspaces/{workspaceId}/review/items/discard-bulk') {
				$discardBulkIdx = $i;
			}
			if ($url === '/api/v1/workspaces/{workspaceId}/review/items/mark-spam-bulk') {
				$markSpamBulkIdx = $i;
			}
		}

		self::assertNotNull($discardBulkIdx, 'discard-bulk route missing');
		self::assertNotNull($markSpamBulkIdx, 'mark-spam-bulk route missing');
		self::assertNotNull($firstParamIdx, 'parameterized review item route missing');
		self::assertLessThan($firstParamIdx, $discardBulkIdx, 'discard-bulk must precede /items/{id}/ routes');
		self::assertLessThan($firstParamIdx, $markSpamBulkIdx, 'mark-spam-bulk must precede /items/{id}/ routes');
		self::assertLessThan($markSpamBulkIdx, $discardBulkIdx, 'discard-bulk should register before mark-spam-bulk');
	}

	public function testBulkRoutesArePostOnly(): void
	{
		foreach (['review_api#discardBulk', 'review_api#markSpamBulk'] as $name) {
			$route = $this->routeByName($name);
			self::assertSame('POST', $route['verb'] ?? null, $name);
		}
	}

	/** @return array<string, mixed> */
	private function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route {$name} not found");
	}
}
