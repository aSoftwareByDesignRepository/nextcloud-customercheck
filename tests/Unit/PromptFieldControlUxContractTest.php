<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Case-by-case: narrative prompt fields use textarea; true one-liners stay single-line inputs.
 */
final class PromptFieldControlUxContractTest extends TestCase
{
	private function companyDetailJs(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
	}

	public function testPromptRendererSupportsTextareaRowsAndFormTextareaClass(): void
	{
		$js = $this->companyDetailJs();
		$this->assertStringContainsString("f.type === 'textarea'", $js);
		$this->assertStringContainsString('f.rows != null ? f.rows : 3', $js);
		$this->assertMatchesRegularExpression(
			'/rowsRaw >= 2 \? Math\.min\(rowsRaw, 12\) : 3/',
			$js
		);
		// Exact promptFields renderer class (not other form-textarea usages).
		$this->assertStringContainsString(
			"'<textarea class=\"crm-input form-input form-textarea\" id=\"' + id + '-' + fname + '\" name=\"' + fname + '\" rows=\"' + rows + '\"'",
			$js
		);
	}

	public function testNarrativeTitlesUseTextareaNotBareTextInput(): void
	{
		$js = $this->companyDetailJs();

		$addTodo = $this->promptChunk($js, "promptFields(t('Add to-do')", 700);
		$this->assertStringContainsString("name: 'title'", $addTodo);
		$this->assertStringContainsString("type: 'textarea'", $addTodo);
		$this->assertStringContainsString('rows: 2', $addTodo);
		$this->assertStringContainsString('maxlength: 255', $addTodo);

		$newTask = $this->promptChunk($js, "promptFields(t('New task')", 700);
		$this->assertStringContainsString("name: 'title'", $newTask);
		$this->assertStringContainsString("type: 'textarea'", $newTask);
		$this->assertStringContainsString('rows: 2', $newTask);
		$this->assertStringContainsString('maxlength: 255', $newTask);

		$promise = $this->promptChunk($js, "promptFields(t('Told the customer')", 800);
		$this->assertStringContainsString("t('What did you tell them?')", $promise);
		$this->assertStringContainsString("type: 'textarea'", $promise);
		$this->assertStringContainsString('rows: 3', $promise);
		$this->assertStringContainsString('maxlength: 255', $promise);
		$this->assertStringContainsString("t('Write it in plain words — a short sentence is enough.')", $promise);

		$hours = $this->promptChunk($js, "promptFields(t('Log hours')", 900);
		$this->assertStringContainsString("name: 'topic'", $hours);
		$this->assertStringContainsString("type: 'textarea'", $hours);
		$this->assertStringContainsString('rows: 2', $hours);
		$this->assertStringContainsString("t('What did you work on? A short sentence is fine.')", $hours);
	}

	private function promptChunk(string $js, string $needle, int $len): string
	{
		$pos = strpos($js, $needle);
		$this->assertNotFalse($pos, $needle);
		return substr($js, (int)$pos, $len);
	}

	public function testTrueOneLinersStaySingleLineInputs(): void
	{
		$js = $this->companyDetailJs();

		$addDealStart = strpos($js, "promptFields(t('Add deal')");
		$this->assertNotFalse($addDealStart);
		$addDealChunk = substr($js, (int)$addDealStart, 900);
		$this->assertStringContainsString("name: 'title'", $addDealChunk);
		$this->assertStringContainsString('maxlength: 255', $addDealChunk);
		$this->assertStringContainsString("name: 'notes'", $addDealChunk);
		$this->assertStringContainsString("type: 'textarea'", $addDealChunk);
		$titlePos = strpos($addDealChunk, "name: 'title'");
		$notesPos = strpos($addDealChunk, "name: 'notes'");
		$this->assertNotFalse($titlePos);
		$this->assertNotFalse($notesPos);
		$this->assertGreaterThan($titlePos, $notesPos);
		$titleOnly = substr($addDealChunk, (int)$titlePos, (int)$notesPos - (int)$titlePos);
		$this->assertStringNotContainsString("type: 'textarea'", $titleOnly);

		// Email subject stays one line; optional snippet is the multi-line field.
		$this->assertMatchesRegularExpression(
			"/name: 'subject', label: t\('Subject'\), required: true/",
			$js
		);
		$subjectPos = strpos($js, "name: 'subject', label: t('Subject')");
		$snippetPos = strpos($js, "name: 'snippet', label: t('Short note (optional)')");
		$this->assertNotFalse($subjectPos);
		$this->assertNotFalse($snippetPos);
		$this->assertGreaterThan($subjectPos, $snippetPos);
		$subjectChunk = substr($js, (int)$subjectPos, (int)$snippetPos - (int)$subjectPos);
		$this->assertStringNotContainsString("type: 'textarea'", $subjectChunk);
		$this->assertStringContainsString("name: 'snippet', label: t('Short note (optional)'), type: 'textarea'", $js);

		$this->assertStringContainsString("name: 'q', label: t('Search folder by name'), type: 'text', required: true", $js);

		// Person identity stays single-line on the inline 360 form (no prompt modal).
		$this->assertStringNotContainsString("promptFields(t('Add person')", $js);
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-person-name"', $html);
		$this->assertStringContainsString('id="crm-person-email"', $html);
		$this->assertStringContainsString('type="email"', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/<textarea[^>]+id="crm-person-name"/',
			$html,
		);
	}

	public function testTodoListTitlesWrapMultilinePromptText(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-todo-list__title\s*\{[^}]*white-space:\s*pre-wrap/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-todo-list__title\s*\{[^}]*overflow-wrap:\s*anywhere/s',
			$css
		);
	}

	public function testGermanL10nCoversNewPromptCopy(): void
	{
		$de = (string)file_get_contents(dirname(__DIR__, 2) . '/l10n/de.json');
		$this->assertStringContainsString('"What did you tell them?": "Was hast du ihnen gesagt?"', $de);
		$this->assertStringContainsString(
			'"Write it in plain words — a short sentence is enough.": "Schreib es in einfachen Worten — ein kurzer Satz reicht."',
			$de
		);
		$this->assertStringContainsString(
			'"What did you work on? A short sentence is fine.": "Woran hast du gearbeitet? Ein kurzer Satz reicht."',
			$de
		);
	}
}
