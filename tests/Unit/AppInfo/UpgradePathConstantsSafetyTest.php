<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\AppInfo;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Repair\BootstrapDefaultWorkspace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the upgrade path against the "stale Constants class" fatal.
 *
 * During an in-request app update (settings/apps/update or occ upgrade) the
 * previously installed copy of OCA\CustomerCheck\Constants is already loaded
 * in PHP memory when new repair steps and migrations run. Any reference to a
 * constant added in the same release resolves against the *old* class and
 * throws "Undefined constant", leaving the instance stuck in maintenance mode
 * (arbeitszeitcheck 1.9.4 / GH #45 — same latent pattern existed here).
 *
 * Rule: files under lib/Repair and lib/Migration must not reference
 * OCA\CustomerCheck\Constants at all. Stable wire/DB values (config keys,
 * seeded defaults) are inlined as literals or private consts instead.
 */
final class UpgradePathConstantsSafetyTest extends TestCase
{
	/**
	 * @return list<string>
	 */
	private static function upgradePathFiles(): array
	{
		$root = dirname(__DIR__, 3) . '/lib';
		$files = array_merge(
			glob($root . '/Repair/*.php') ?: [],
			glob($root . '/Migration/*.php') ?: [],
		);
		sort($files);
		return $files;
	}

	public function testUpgradePathDoesNotReferenceAppConstants(): void
	{
		$offenders = [];
		foreach (self::upgradePathFiles() as $file) {
			$source = file_get_contents($file);
			$this->assertIsString($source, $file);
			if (preg_match('/\bConstants::|use\s+OCA\\\\CustomerCheck\\\\Constants\b/', $source) === 1) {
				$offenders[] = basename($file);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Upgrade-path code must not reference OCA\CustomerCheck\Constants — '
			. 'the previous release\'s copy may already be loaded when repair steps '
			. 'and migrations run during an app update. Inline stable values.',
		);
	}

	public function testInlinedConfigKeysMatchConstants(): void
	{
		$refl = new ReflectionClass(BootstrapDefaultWorkspace::class);

		$this->assertSame(
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			$refl->getReflectionConstant('CONFIG_ACCESS_ALLOWED_USER_IDS')->getValue(),
			'BootstrapDefaultWorkspace::CONFIG_ACCESS_ALLOWED_USER_IDS drifted from Constants',
		);
		$this->assertSame(
			Constants::CONFIG_APP_ADMIN_USER_IDS,
			$refl->getReflectionConstant('CONFIG_APP_ADMIN_USER_IDS')->getValue(),
			'BootstrapDefaultWorkspace::CONFIG_APP_ADMIN_USER_IDS drifted from Constants',
		);
		$this->assertSame(
			'[]',
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_ACCESS_ALLOWED_USER_IDS],
			'CONFIG_DEFAULTS access_allowed_user_ids default changed — inlined literal in BootstrapDefaultWorkspace must be updated',
		);
		$this->assertSame(
			'[]',
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_APP_ADMIN_USER_IDS],
			'CONFIG_DEFAULTS app_admin_user_ids default changed — inlined literal in BootstrapDefaultWorkspace must be updated',
		);
		$this->assertSame(5, Constants::WIP_LIMIT_DEFAULT, 'WIP_LIMIT_DEFAULT changed — inlined literal in BootstrapDefaultWorkspace must be updated');
		$this->assertSame(24, Constants::LEAD_SLA_HOURS_DEFAULT, 'LEAD_SLA_HOURS_DEFAULT changed — inlined literal in BootstrapDefaultWorkspace must be updated');
	}
}
