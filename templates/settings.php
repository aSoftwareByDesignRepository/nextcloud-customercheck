<?php
/**
 * Workspace settings shell — one sub-page per section (SETTINGS-PAGES-STANDARD).
 *
 * The controller validates `settingsSection` against
 * {@see \OCA\CustomerCheck\Service\WorkspaceSettingsSectionCatalog} and this
 * template dispatches through a literal slug → file map, so no request value
 * is ever used to build an include path.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
$canManage = !empty($_['canManage']);
$canManageWorkspace = !empty($_['canManageWorkspace']);
$urls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$appSettingsUrl = (string)($urls['appSettings'] ?? '');
$wsUrl = (string)($urls['settings'] ?? '');
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-settings-root"
	data-settings-page="workspace"
	data-can-manage="<?php p($canManage ? '1' : '0'); ?>"
	data-can-manage-workspace="<?php p($canManageWorkspace ? '1' : '0'); ?>"
	data-pipeline-view="<?php p($settings['pipelineView'] ?? 'table'); ?>">

	<nav class="crm-settings-switch" aria-label="<?php p($l->t('Settings areas')); ?>">
		<a class="crm-btn crm-btn--primary" href="<?php p($wsUrl); ?>" aria-current="page"><?php p($l->t('Team & mailbox')); ?></a>
		<?php if ($canManage && $appSettingsUrl !== ''): ?>
			<a class="crm-btn" href="<?php p($appSettingsUrl); ?>"><?php p($l->t('App')); ?></a>
		<?php endif; ?>
	</nav>

<?php
	$crmSettingsSectionFiles = [
		'mailbox' => 'workspace-mailbox.php',
		'preferences' => 'preferences.php',
		'checklists' => 'workspace-checklists.php',
		'review-sources' => 'workspace-review-sources.php',
		'circles' => 'circles-roll-call.php',
		'integrations' => 'integrations.php',
	];
	$crmRequestedSection = (string) ($_['settingsSection'] ?? '');
	include __DIR__ . '/parts/settings-nav.php';
	if (!isset($crmSettingsSectionFiles[$crmRequestedSection])) {
		throw new \RuntimeException('CustomerCheck workspace settings: unknown section reached the template dispatcher.');
	}
	include __DIR__ . '/settings/' . $crmSettingsSectionFiles[$crmRequestedSection];
?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
