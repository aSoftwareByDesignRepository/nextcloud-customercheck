<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Command;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCA\CustomerCheck\Service\PlayReviewerSeedService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LOCAL ONLY — wraps {@see PlayReviewerSeedService}.
 */
class SeedPlayReviewerCommand extends Command
{
	public function __construct(
		private readonly PlayReviewerSeedService $seed,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('customercheck:seed-play-reviewer')
			->setDescription('LOCAL ONLY — install golden CUC2 + assign a named mobile seat (requires vendor key override).')
			->addOption('uid', null, InputOption::VALUE_REQUIRED, 'User id to receive the seat', 'play-reviewer')
			->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name when creating the user', 'Play Reviewer')
			->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password when creating the user (printed once if omitted)')
			->addOption('i-understand-local-only', null, InputOption::VALUE_NONE, 'Required acknowledgement');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		if (!$input->getOption('i-understand-local-only')) {
			$io->error('Pass --i-understand-local-only. This installs the PHPUnit golden key, not a production CUC2.');
			return self::FAILURE;
		}

		try {
			$result = $this->seed->seed(
				(string)$input->getOption('uid'),
				(string)$input->getOption('display-name'),
				$input->getOption('password') !== null ? (string)$input->getOption('password') : null,
			);
		} catch (\Throwable $e) {
			$io->error($e->getMessage());
			if (str_contains($e->getMessage(), 'CUC_ALLOW_VENDOR_KEY_OVERRIDE')) {
				$io->writeln('Example:');
				$io->writeln(
					'  CUC_ALLOW_VENDOR_KEY_OVERRIDE=1 CUC_VENDOR_PUBLIC_KEY_B64='
					. VendorPublicKey::TEST_PUBLIC_KEY_B64
					. ' php occ customercheck:seed-play-reviewer --i-understand-local-only',
				);
			}
			return self::FAILURE;
		}

		if ($result['createdUser']) {
			$io->success('Created user ' . $result['uid']);
		} else {
			$io->note('User ' . $result['uid'] . ' already exists — reusing.');
		}
		$io->success('Golden CUC2 applied and seat assigned.');
		$io->writeln('customerId: ' . $result['customerId']);
		$io->writeln('mobileSeats: ' . (string)$result['mobileSeats']);
		$io->writeln('seat uid: ' . $result['uid']);
		if ($result['generatedPassword'] !== null) {
			$io->writeln('generated password (store now): ' . $result['generatedPassword']);
		}
		$io->warning('Override env must NOT stay set on production PHP-FPM. This seed is for local/CI dogfood only.');

		return self::SUCCESS;
	}
}
