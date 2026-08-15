<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: php-imap sync must reopen each folder via $mailboxPath.$folderPath.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mailbox-sync-folder-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function mailbox_sync_folder_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "\$folderPath = (string)\$folder['folder_path'];\n\t\t\t\tif (!ImapConnection::isSafeImapFolder(\$folderPath)) {\n\t\t\t\t\t\$this->logger->warning('CustomerCheck mailbox sync skipped unsafe IMAP folder', [\n\t\t\t\t\t\t'mailboxId' => \$mailboxId,\n\t\t\t\t\t\t'workspaceId' => \$workspaceId,\n\t\t\t\t\t]);\n\t\t\t\t\tcontinue;\n\t\t\t\t}\n\t\t\t\t\$full = \$mailboxPath . \$folderPath;\n\t\t\t\tif (@imap_reopen(\$stream, \$full) === false) {",
			'to' => "// mutated: reopen with undefined folder\n\t\t\t\tif (@imap_reopen(\$stream, \$full) === false) {",
			'label' => 'php_imap_reopen_drops_folder_path',
			'filter' => 'MailboxSyncServiceTest::testMailboxSpecBuilderUsesNovalidateFlag',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "return \$this->doSync(\$mailboxId, \$workspaceId);\n\t\t} catch (\\Throwable \$e) {\n\t\t\t\$this->logger->error('CustomerCheck mailbox syncOne failed', [\n\t\t\t\t'mailboxId' => \$mailboxId,\n\t\t\t\t'workspaceId' => \$workspaceId,\n\t\t\t\t'exception' => \$e,\n\t\t\t]);\n\n\t\t\treturn ['ingested' => 0, 'status' => 'error'];\n\t\t} finally {",
			'to' => "return \$this->doSync(\$mailboxId, \$workspaceId);\n\t\t} finally {",
			'label' => 'syncOne_rethrows_instead_of_status_error',
			'filter' => 'MailboxSyncServiceTest::testMailboxSpecBuilderUsesNovalidateFlag',
		],
		[
			'file' => $appRoot . '/lib/Controller/ApiControllerTrait.php',
			'from' => "if (\$e instanceof NotAuthenticatedException) {\n\t\t\treturn \$this->fail(Constants::ERR_NOT_AUTHENTICATED, \$e->getMessage() !== '' ? \$e->getMessage() : 'Authentication required', [], Http::STATUS_UNAUTHORIZED);\n\t\t}",
			'to' => "// mutated: not authenticated falls through to SERVER_ERROR\n\t\t",
			'label' => 'not_authenticated_maps_to_server_error',
			'filter' => 'MailboxSyncServiceTest::testApiMapsNotAuthenticatedToUnauthorizedNotServerError',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $mut
 */
function apply_mutation(array $mut): string
{
	$src = (string)file_get_contents($mut['file']);
	if (!str_contains($src, $mut['from'])) {
		throw new RuntimeException('Mutation source not found for ' . $mut['label']);
	}
	$once = preg_replace('/' . preg_quote($mut['from'], '/') . '/', $mut['to'], $src, 1, $count);
	if ($once === null || $count !== 1) {
		throw new RuntimeException('Mutation apply failed for ' . $mut['label'] . ' (count=' . $count . ')');
	}
	file_put_contents($mut['file'], $once);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_filtered_tests(string $phpunit, string $appRoot, string $filter): bool
{
	$cmd = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter)
		. ' 2>&1';
	exec($cmd, $out, $code);

	return $code === 0;
}

$mutations = mailbox_sync_folder_mutations($appRoot);
$killed = 0;
$survived = 0;

foreach ($mutations as $mut) {
	$original = apply_mutation($mut);
	$testsGreen = run_filtered_tests($phpunit, $appRoot, $mut['filter']);
	if ($testsGreen) {
		fwrite(STDERR, "SURVIVED: {$mut['label']}\n");
		$survived++;
	} else {
		fwrite(STDOUT, "KILLED: {$mut['label']}\n");
		$killed++;
	}
	restore_file($mut['file'], $original);
}

$total = count($mutations);
fwrite(STDOUT, "\nMailbox sync folder mutations: {$killed}/{$total} killed, {$survived} survived.\n");

exit($survived > 0 ? 1 : 0);
