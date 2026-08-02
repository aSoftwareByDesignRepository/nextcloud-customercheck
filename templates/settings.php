<?php
/**
 * Workspace settings — preferences, members, mailbox, integrations.
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
		<a class="crm-btn crm-btn--primary" href="<?php p($wsUrl); ?>" aria-current="page"><?php p($l->t('Workspace')); ?></a>
		<?php if ($canManage && $appSettingsUrl !== ''): ?>
			<a class="crm-btn" href="<?php p($appSettingsUrl); ?>"><?php p($l->t('App')); ?></a>
		<?php endif; ?>
	</nav>

	<?php include __DIR__ . '/settings/preferences.php'; ?>

	<?php if ($canManageWorkspace): ?>
		<?php include __DIR__ . '/settings/workspace-review-sources.php'; ?>
		<?php include __DIR__ . '/settings/workspace-mailbox.php'; ?>
		<?php include __DIR__ . '/settings/circles-roll-call.php'; ?>
	<?php endif; ?>

	<?php include __DIR__ . '/settings/integrations.php'; ?>

	<?php if ($canManage && $appSettingsUrl !== ''): ?>
		<section class="crm-card" id="section-app-settings-link" aria-labelledby="section-app-settings-link-heading">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h2 id="section-app-settings-link-heading" class="crm-card__title"><?php p($l->t('App settings')); ?></h2>
					<p class="crm-card__lead"><?php p($l->t('Access control, reminders, tokens, mobile license, and the agreement catalogue.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<p><a class="crm-btn" href="<?php p($appSettingsUrl); ?>"><?php p($l->t('Open App settings')); ?></a></p>
			</div>
		</section>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
