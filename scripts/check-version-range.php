<?php

declare(strict_types=1);

/**
 * Version-range consistency gate.
 *
 * README.md and store descriptions must claim the same Nextcloud range that
 * appinfo/info.xml declares. A stale "32–34" in the README while info.xml says
 * max-version="35" is a published-facts defect (found by functional critic r7).
 *
 * Usage: php scripts/check-version-range.php
 */

$base = dirname(__DIR__);

$info = (string) file_get_contents($base . '/appinfo/info.xml');
if (!preg_match('/<nextcloud\s+min-version="(\d+)"\s+max-version="(\d+)"/', $info, $m)) {
	fwrite(STDERR, "info.xml nextcloud range not found\n");
	exit(1);
}
[$min, $max] = [(int) $m[1], (int) $m[2]];

$targets = [$base . '/README.md'];
foreach (glob($base . '/appinfo/*.xml') ?: [] as $x) {
	if (basename($x) !== 'info.xml') {
		$targets[] = $x;
	}
}
if (is_dir($base . '/docs')) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/docs', FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if (preg_match('/\.(md|txt)$/', $f->getFilename())) {
			$targets[] = $f->getPathname();
		}
	}
}

$failures = [];
foreach ($targets as $file) {
	$text = (string) file_get_contents($file);
	// Any "Nextcloud A–B" / "Nextcloud A-B" range claim must match info.xml.
	if (preg_match_all('/Nextcloud\s+(\d+)\s*[–-]\s*(\d+)/u', $text, $mm, PREG_OFFSET_CAPTURE)) {
		foreach ($mm[0] as $i => [$claim, $pos]) {
			if ((int) $mm[1][$i][0] !== $min || (int) $mm[2][$i][0] !== $max) {
				$line = substr_count(substr($text, 0, $pos), "\n") + 1;
				$failures[] = basename($file) . ":{$line}: \"{$claim}\" but info.xml declares {$min}–{$max}";
			}
		}
	}
}

if ($failures !== []) {
	fwrite(STDERR, "version-range check FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
	exit(1);
}
echo "version-range OK (Nextcloud {$min}–{$max}, " . count($targets) . " files scanned).\n";
exit(0);
