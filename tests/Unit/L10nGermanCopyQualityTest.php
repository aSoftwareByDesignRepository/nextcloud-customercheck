<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Native-speaker German UI copy must not regress to English leftovers or
 * machine-translation calques (e.g. Advanced → Fortschrittlich).
 */
final class L10nGermanCopyQualityTest extends TestCase
{
	/** @return array<string, string> */
	private function deTranslations(): array
	{
		$path = dirname(__DIR__, 2) . '/l10n/de.json';
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($data['translations'] ?? null);

		/** @var array<string, string> $translations */
		$translations = $data['translations'];

		return $translations;
	}

	/** @return array<string, string> */
	private function deMap(): array
	{
		$path = dirname(__DIR__, 2) . '/tests/fixtures/l10n-de-map.json';
		self::assertFileExists($path);
		/** @var array<string, string> $map */
		$map = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		return $map;
	}

	public function testAdvancedDisclosureUsesErweitertNotFortschrittlich(): void
	{
		$t = $this->deTranslations();
		self::assertSame('Erweitert', $t['Advanced']);
		self::assertSame('Erweiterte Optionen', $t['Advanced options']);
		self::assertStringNotContainsString('Fortschrittlich', $t['Advanced']);
		self::assertStringContainsString(
			'Erweitert',
			$t['That is enough — press Save. Optional details are under Advanced.']
		);
	}

	public function testCoreActionsKeepNativeGermanSense(): void
	{
		$t = $this->deTranslations();
		self::assertSame('Speichern', $t['Save']);
		self::assertSame('Abbrechen', $t['Cancel']);
		self::assertSame('Einstellungen', $t['Settings']);
		self::assertSame('Schließen', $t['Close']);
	}

	public function testDeMapPinsAdvancedSoSyncCannotReviveMtCalque(): void
	{
		$map = $this->deMap();
		self::assertArrayHasKey('Advanced', $map);
		self::assertSame('Erweitert', $map['Advanced']);
	}

	public function testBannedCalquesAbsent(): void
	{
		$t = $this->deTranslations();
		$parts = [];
		foreach ($t as $value) {
			if (is_string($value)) {
				$parts[] = $value;
			}
		}
		$blob = implode("\n", $parts);
		foreach (['Fortschrittlich', 'Armaturenbrett', 'Beladung'] as $bad) {
			self::assertStringNotContainsString($bad, $blob, "Banned calque still present: {$bad}");
		}
	}

	public function testPdfLinkStringsUseNaturalGerman(): void
	{
		$t = $this->deTranslations();
		$map = $this->deMap();
		$pairs = [
			'Link a PDF from Files (optional). We check you can read it.' =>
				'Optional: PDF aus Dateien verknüpfen. Wir prüfen, ob du sie lesen kannst.',
			'Pick a PDF file you can read in Files.' =>
				'Wähle eine PDF-Datei, die du in Dateien lesen kannst.',
		];
		foreach ($pairs as $key => $want) {
			self::assertSame($want, $t[$key], "de.json {$key}");
			self::assertSame($want, $map[$key], "de-map {$key}");
		}
	}

	public function testDeQualityScriptPasses(): void
	{
		$script = dirname(__DIR__, 2) . '/scripts/check-l10n-de-quality.php';
		self::assertFileExists($script);
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
		exec($cmd . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}

	public function testReviewSpamAndBulkDeleteStringsTranslatedInGerman(): void
	{
		$t = $this->deTranslations();
		$map = $this->deMap();
		$pairs = [
			'Mark as spam' => 'Als Spam markieren',
			'Delete selected' => 'Ausgewählte löschen',
			'Delete forever' => 'Endgültig löschen',
			'Clear selection' => 'Auswahl löschen',
			'Marked as spam.' => 'Als Spam markiert.',
			'Select messages to delete or mark as spam' => 'Nachrichten zum Löschen oder als Spam markieren auswählen',
			'Could not delete the selected messages.' => 'Die ausgewählten Nachrichten konnten nicht gelöscht werden.',
			'Could not mark the selected messages as spam.' => 'Die ausgewählten Nachrichten konnten nicht als Spam markiert werden.',
			'Only shared-mailbox messages can be marked as spam.' => 'Nur Nachrichten aus dem gemeinsamen Postfach können als Spam markiert werden.',
			'This mailbox has no Spam/Junk folder. Delete the message instead.' => 'Dieses Postfach hat keinen Spam-/Junk-Ordner. Lösche die Nachricht stattdessen.',
		];
		foreach ($pairs as $key => $want) {
			self::assertSame($want, $t[$key], "de.json {$key}");
			self::assertSame($want, $map[$key], "de-map {$key}");
		}
	}

	public function testSecondaryLocalesRejectKnownSaveSenseErrors(): void
	{
		$root = dirname(__DIR__, 2) . '/l10n';
		$expect = [
			'da' => ['Advanced' => 'Avanceret', 'Save' => 'Gem', 'Cancel' => 'Annuller'],
			'es' => ['Save' => 'Guardar'],
			'it' => ['Cancel' => 'Annulla'],
			'sv' => ['Cancel' => 'Avbryt'],
			'nb' => ['Save' => 'Lagre'],
			'nl' => ['Save' => 'Opslaan'],
			'pl' => ['Save' => 'Zapisz'],
			'fr' => ['Save' => 'Enregistrer'],
		];
		foreach ($expect as $lang => $pairs) {
			$data = json_decode((string) file_get_contents($root . '/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($data['translations'] ?? null);
			foreach ($pairs as $key => $want) {
				self::assertSame(
					$want,
					$data['translations'][$key],
					"{$lang}.json {$key} must keep UI sense"
				);
			}
		}
	}
}
