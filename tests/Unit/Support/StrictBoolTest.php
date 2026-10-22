<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\StrictBool;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the canMailSend "false"-string coercion hole (Atlas 3.5.14
 * live probe): `!empty("false")` is true, so a JSON/form boolean sent as the
 * literal string "false" silently granted the capability. StrictBool::of must
 * only enable on explicit truthy tokens — everything else is fail-closed.
 */
final class StrictBoolTest extends TestCase
{
	public function testStringFalseAndFriendsAreFalse(): void
	{
		foreach (['false', 'FALSE', 'False', '0', 'off', 'no', '', 'banana', '0.0'] as $v) {
			$this->assertFalse(StrictBool::of($v), "string {$v} must be false");
		}
		$this->assertFalse(StrictBool::of(false));
		$this->assertFalse(StrictBool::of(0));
		$this->assertFalse(StrictBool::of(0.0));
		$this->assertFalse(StrictBool::of(null));
		$this->assertFalse(StrictBool::of([]));
	}

	public function testTruthyTokensAreTrue(): void
	{
		foreach (['1', 'true', 'TRUE', 'True', 'yes', 'on', '  true  '] as $v) {
			$this->assertTrue(StrictBool::of($v), "string {$v} must be true");
		}
		$this->assertTrue(StrictBool::of(true));
		$this->assertTrue(StrictBool::of(1));
		$this->assertTrue(StrictBool::of(2));
		$this->assertTrue(StrictBool::of(-1));
	}

	public function testFieldHonoursKeyAbsenceDefault(): void
	{
		$this->assertTrue(StrictBool::field(['x' => 1], 'missing', true));
		$this->assertFalse(StrictBool::field(['x' => 1], 'missing', false));
		$this->assertFalse(StrictBool::field(['canMailSend' => 'false'], 'canMailSend', true));
		$this->assertTrue(StrictBool::field(['canMailSend' => 'false'], 'canMailSend', true) === false);
		$this->assertTrue(StrictBool::field(['learn' => 'true'], 'learn'));
		$this->assertFalse(StrictBool::field(['learn' => 'false'], 'learn'));
		// null/[] present is a real (false) value, not "absent".
		$this->assertFalse(StrictBool::field(['flag' => null], 'flag', true));
	}
}
