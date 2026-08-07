#!/usr/bin/env python3
"""Kill-only runner for shared-identity critical mutants (proof of MSI)."""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

CASES = [
	{
		"file": ROOT / "lib/Service/SiblingCustomerWriteService.php",
		"find": (
			"if ($this->identityHealth !== null && !$this->identityHealth->renamePushEnabled()) {\n"
			"\t\t\treturn ['pushed' => false, 'skipped' => true, 'warning' => null, 'code' => null, 'pcCustomerId' => $pcId];\n"
			"\t\t}"
		),
		"replace": (
			"if (false && $this->identityHealth !== null && !$this->identityHealth->renamePushEnabled()) {\n"
			"\t\t\treturn ['pushed' => false, 'skipped' => true, 'warning' => null, 'code' => null, 'pcCustomerId' => $pcId];\n"
			"\t\t}"
		),
		"test": "SiblingCustomerWriteServiceIdentityTest",
		"label": "rename push ignores identity_rename_push flag off",
	},
	{
		"file": ROOT / "lib/Service/SiblingCustomerWriteService.php",
		"find": (
			"if ($idempotencyKey !== null && $this->idempotency !== null) {\n"
			"\t\t\t$replay = $this->idempotency->findReplay($actorUid, $idempotencyKey, LinkIdempotencyService::SIBLING_PC);"
		),
		"replace": (
			"if (false && $idempotencyKey !== null && $this->idempotency !== null) {\n"
			"\t\t\t$replay = $this->idempotency->findReplay($actorUid, $idempotencyKey, LinkIdempotencyService::SIBLING_PC);"
		),
		"test": "SiblingCustomerWriteServiceIdentityTest",
		"label": "createOrLink ignores Idempotency-Key replay",
	},
	{
		"file": ROOT / "lib/Service/IdentityLinkHealthService.php",
		"find": (
			"if ($dto === null) {\n"
			"\t\t\treturn [\n"
			"\t\t\t\t'state' => self::STATE_BROKEN,\n"
			"\t\t\t\t'pcCustomerId' => $pcCustomerId,"
		),
		"replace": (
			"if ($dto === null) {\n"
			"\t\t\treturn [\n"
			"\t\t\t\t'state' => self::STATE_LINKED,\n"
			"\t\t\t\t'pcCustomerId' => $pcCustomerId,"
		),
		"test": "IdentityLinkHealthServiceTest",
		"label": "missing PC classified as linked not broken",
	},
]


def main() -> int:
	killed = 0
	failed = 0
	for case in CASES:
		original = case["file"].read_text()
		if case["find"] not in original:
			print(f"SKIP missing needle: {case['label']}", file=sys.stderr)
			continue
		case["file"].write_text(original.replace(case["find"], case["replace"], 1))
		try:
			proc = subprocess.run(
				["php", "vendor/bin/phpunit", "--filter", case["test"], "tests/Unit"],
				cwd=str(ROOT),
				capture_output=True,
				text=True,
			)
		finally:
			case["file"].write_text(original)
		if proc.returncode != 0:
			print(f"KILLED  {case['label']}")
			killed += 1
		else:
			print(f"SURVIVED {case['label']}")
			failed += 1
	total = killed + failed
	print(f"identity MSI: {killed}/{total}")
	return 0 if failed == 0 and killed > 0 else 1


if __name__ == "__main__":
	raise SystemExit(main())
