<?php
/**
 * App settings shell — one sub-page per section (SETTINGS-PAGES-STANDARD).
 *
 * The controller validates `settingsSection` against
 * {@see \OCA\CustomerCheck\Service\AppSettingsSectionCatalog} and this
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
$restrictionOn = !empty($settings['access_restriction_enabled']);
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-settings-root"
	data-settings-page="app"
	data-can-manage="<?php p($canManage ? '1' : '0'); ?>"
	data-can-manage-workspace="<?php p($canManageWorkspace ? '1' : '0'); ?>"
	data-pipeline-view="<?php p($settings['pipelineView'] ?? 'table'); ?>">

	<nav class="crm-settings-switch" aria-label="<?php p($l->t('Settings areas')); ?>">
		<a class="crm-btn" href="<?php p($wsUrl); ?>"><?php p($l->t('Workspace')); ?></a>
		<a class="crm-btn crm-btn--primary" href="<?php p($appSettingsUrl !== '' ? $appSettingsUrl : '#'); ?>" aria-current="page"><?php p($l->t('App')); ?></a>
	</nav>

	<?php if (!$canManage): ?>
		<section class="crm-card" id="section-app-settings-denied" role="alert">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h2 class="crm-card__title"><?php p($l->t('App settings')); ?></h2>
					<p class="crm-card__lead"><?php p($l->t('Only CustomerCheck app administrators can change access policy, tokens, license, and the agreement catalogue.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<p><a class="crm-btn" href="<?php p($wsUrl); ?>"><?php p($l->t('Back to Workspace settings')); ?></a></p>
			</div>
		</section>
	<?php else: ?>

		<?php if (!empty($settings['failClosedEmptyAllowlists'])): ?>
			<div class="crm-callout crm-callout--danger" role="alert">
				<p><?php p($l->t('Access restriction is on and no users or groups are allowlisted. Only app administrators can use CustomerCheck. Add colleagues below.')); ?></p>
			</div>
		<?php endif; ?>
		<?php if (!$restrictionOn && (string)($_['settingsSection'] ?? '') === 'access'): ?>
			<div class="crm-callout crm-callout--warning" role="status">
				<p><?php p($l->t('Access restriction is off — every logged-in user can see sales data. Not recommended.')); ?></p>
			</div>
		<?php endif; ?>

<?php
	$crmAppSettingsSectionFiles = [
		'access' => 'access.php',
		'jobs' => 'jobs.php',
		'tokens' => 'tokens.php',
		'license' => 'license.php',
		'product-codes' => 'product-codes.php',
	];
	$crmRequestedSection = (string) ($_['settingsSection'] ?? '');
	include __DIR__ . '/parts/app-settings-nav.php';
	if (!isset($crmAppSettingsSectionFiles[$crmRequestedSection])) {
		throw new \RuntimeException('CustomerCheck app settings: unknown section reached the template dispatcher.');
	}
	include __DIR__ . '/settings/' . $crmAppSettingsSectionFiles[$crmRequestedSection];
?>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
