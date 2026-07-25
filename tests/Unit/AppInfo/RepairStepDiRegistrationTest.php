<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards against production upgrade fatals when repair-step constructors gain
 * dependencies but Application.php factories are not updated.
 */
final class RepairStepDiRegistrationTest extends TestCase
{
	/**
	 * @return list<class-string>
	 */
	public static function repairStepClassesFromInfoXml(): array
	{
		$infoPath = dirname(__DIR__, 3) . '/appinfo/info.xml';
		$contents = file_get_contents($infoPath);
		if ($contents === false) {
			throw new \RuntimeException('Could not read appinfo/info.xml at ' . $infoPath);
		}
		$xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET);
		if ($xml === false) {
			throw new \RuntimeException('Could not parse appinfo/info.xml at ' . $infoPath);
		}

		$classes = [];
		foreach ($xml->{'repair-steps'}->children() as $phase) {
			foreach ($phase->step as $step) {
				$classes[] = (string)$step;
			}
		}

		$unique = array_values(array_unique($classes));
		return array_combine($unique, array_map(static fn (string $class): array => [$class], $unique));
	}

	/**
	 * @dataProvider repairStepClassesFromInfoXml
	 */
	public function testRepairStepIsRegisteredInApplication(string $class): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		$this->assertIsString($source);

		$short = (new ReflectionClass($class))->getShortName();
		$this->assertMatchesRegularExpression(
			'/registerService\((?:\\\\?' . preg_quote($short, '/') . '|' . preg_quote($class, '/') . ')::class/',
			$source,
			$class . ' must be registered in Application.php (occ upgrade resolves repair steps from the container)',
		);
	}

	/**
	 * @dataProvider repairStepClassesFromInfoXml
	 */
	public function testRepairStepFactoryPassesEnoughConstructorArguments(string $class): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		$this->assertIsString($source);

		$short = (new ReflectionClass($class))->getShortName();
		$args = $this->extractFactoryArgs($source, $short);
		if ($args === null) {
			$this->fail($class . ' factory block not found in Application.php');
		}

		$required = (new ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0;
		$passed = substr_count($args, '$c->get(') + substr_count($args, '$c->query(');

		$this->assertGreaterThanOrEqual(
			$required,
			$passed,
			sprintf('%s requires %d constructor argument(s) but Application.php passes %d', $class, $required, $passed),
		);
	}

	public function testEnsureCustomerCheckSchemaWiresIConfig(): void
	{
		$source = file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		$this->assertIsString($source);

		$args = $this->extractFactoryArgs($source, 'EnsureCustomerCheckSchema');
		if ($args === null) {
			$this->fail('EnsureCustomerCheckSchema factory block not found in Application.php');
		}

		$this->assertStringContainsString(
			'IConfig::class',
			$args,
			'EnsureCustomerCheckSchema must receive IConfig',
		);
	}

	private function extractFactoryArgs(string $source, string $short): ?string
	{
		// Arrow: fn ($c) => new Foo(...)
		$arrow = '/registerService\(' . preg_quote($short, '/') . '::class,\s*fn\s*\(\$c\)(?:\s*:\s*[\w\\\\]+)?\s*=>\s*new ' . preg_quote($short, '/') . '\((.*?)\);/s';
		if (preg_match($arrow, $source, $matches)) {
			return $matches[1];
		}

		// Classic: function ($c): Foo { return new Foo(...); }
		$classic = '/registerService\(' . preg_quote($short, '/') . '::class,\s*function\s*\(\$c\)(?:\s*:\s*[\w\\\\]+)?\s*\{\s*return new ' . preg_quote($short, '/') . '\((.*?)\);/s';
		if (preg_match($classic, $source, $matches)) {
			return $matches[1];
		}

		return null;
	}
}
