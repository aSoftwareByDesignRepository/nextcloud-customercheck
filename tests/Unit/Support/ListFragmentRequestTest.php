<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\ListFragmentRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Fragment opt-in is header-only. A query flag would bookmark a chrome-less page.
 */
final class ListFragmentRequestTest extends TestCase
{
	public function testNullRequestIsNeverAFragment(): void
	{
		$this->assertFalse(ListFragmentRequest::wants(null));
		$this->assertFalse(ListFragmentRequest::prefersMinimal(null));
		$this->assertFalse(ListFragmentRequest::isXmlHttpRequest(null));
	}

	/**
	 * @return list<array{0:string,1:bool}>
	 */
	public function headerProvider(): array
	{
		return [
			['1', true],
			['true', true],
			['TRUE', true],
			[' True ', true],
			['', false],
			['0', false],
			['false', false],
			['yes', false],
			['fragment', false],
		];
	}

	/**
	 * @dataProvider headerProvider
	 */
	public function testWantsOnlyTrustedHeaderValues(string $header, bool $expected): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($header): string {
				if (strcasecmp($name, ListFragmentRequest::HEADER) === 0) {
					return $header;
				}

				return '';
			},
		);
		$request->expects($this->never())->method('getParam');
		$this->assertSame($expected, ListFragmentRequest::wants($request));
	}

	public function testPreferMinimalWithXhrIsAFragment(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, ListFragmentRequest::HEADER) === 0) {
					return '';
				}
				if (strcasecmp($name, ListFragmentRequest::REQUESTED_WITH_HEADER) === 0) {
					return 'XMLHttpRequest';
				}
				if (strcasecmp($name, ListFragmentRequest::PREFER_HEADER) === 0) {
					return 'handling=strict, return=minimal';
				}

				return '';
			},
		);
		$request->expects($this->never())->method('getParam');
		$this->assertTrue(ListFragmentRequest::wants($request));
	}

	public function testPreferMinimalWithoutXhrIsNotAFragment(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, ListFragmentRequest::PREFER_HEADER) === 0) {
					return 'return=minimal';
				}

				return '';
			},
		);
		$this->assertFalse(ListFragmentRequest::wants($request));
	}

	public function testXhrWithoutPreferIsNotAFragment(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, ListFragmentRequest::REQUESTED_WITH_HEADER) === 0) {
					return 'XMLHttpRequest';
				}

				return '';
			},
		);
		$this->assertFalse(ListFragmentRequest::wants($request));
	}

	public function testPreferRepresentationIsNotMinimal(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('return=representation');
		$this->assertFalse(ListFragmentRequest::prefersMinimal($request));
		$requestMinimalist = $this->createMock(IRequest::class);
		$requestMinimalist->method('getHeader')->willReturn('return=minimalist');
		$this->assertFalse(ListFragmentRequest::prefersMinimal($requestMinimalist));
	}

	public function testQuotedPreferMinimal(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('return="minimal"');
		$this->assertTrue(ListFragmentRequest::prefersMinimal($request));
	}

	public function testVaryListsAllOptInHeaders(): void
	{
		$vary = ListFragmentRequest::varyValue();
		$this->assertStringContainsString(ListFragmentRequest::HEADER, $vary);
		$this->assertStringContainsString(ListFragmentRequest::PREFER_HEADER, $vary);
		$this->assertStringContainsString(ListFragmentRequest::REQUESTED_WITH_HEADER, $vary);
	}

	public function testSourceNeverReadsQueryParams(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/ListFragmentRequest.php');
		$this->assertStringContainsString("HEADER = 'X-CustomerCheck-Fragment'", $src);
		$this->assertStringContainsString('getHeader(self::HEADER)', $src);
		$this->assertStringContainsString('prefersMinimal', $src);
		$this->assertStringContainsString('isXmlHttpRequest', $src);
		$this->assertStringNotContainsString('getParam', $src);
		$this->assertStringNotContainsString('$_GET', $src);
		$this->assertStringNotContainsString('getRequestUri', $src);
	}
}
