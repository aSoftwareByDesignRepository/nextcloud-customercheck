<?php
/**
 * CustomerCheck page chrome (open) — PSD §9.1
 * @var array $_
 * @var \OCP\IL10N $l
 */
use OCA\CustomerCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'today');
$shellWidth = (string)($_['shellWidth'] ?? 'wide');
$shellWidthClass = match ($shellWidth) {
	'wide' => ' crm-shell--wide',
	'constrained' => ' crm-shell--constrained',
	'minimal' => ' crm-shell--minimal',
	default => '',
};
$pageTitle = (string)($_['pageTitle'] ?? '');
$pageHelp = (string)($_['pageHelp'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? '');
$roleSlug = (string)($_['roleSlug'] ?? 'crm-user');
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en', 'htmlLang' => 'en', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en');
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$l = $_['l'] ?? $l;
$urlGenerator = $_['urlGenerator'];
$settingsSection = (string)($_['settingsSection'] ?? '');
$breadcrumbParent = is_array($_['breadcrumbParent'] ?? null) ? $_['breadcrumbParent'] : null;

$pageIcons = [
	'today' => 'sun',
	'pulse' => 'activity',
	'brief' => 'clipboard-list',
	'my-accounts' => 'users',
	'team' => 'user-cog',
	'workload' => 'user-cog',
	'strategy' => 'map',
	'mail-triage' => 'scroll-text',
	'review' => 'list',
	'conflicts' => 'alert-triangle',
	'companies' => 'building-2', 'company-detail' => 'building-2', 'company-form' => 'building-2',
	'pipeline' => 'kanban', 'agreements' => 'file-text', 'agreement-form' => 'file-text',
	'money' => 'coins', 'activities' => 'check-square', 'import' => 'upload',
	'settings' => 'settings', 'app-settings' => 'shield-check', 'support-us' => 'heart-handshake', 'get-the-app' => 'smartphone', 'access-denied' => 'lock',
];
$headerIcon = $pageIcons[$pageId] ?? 'layout-grid';
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<div id="customercheck-app" class="customercheck-app">
<a href="#app-navigation" class="skip-link crm-skip-link--nav"><?php p($l->t('Skip to navigation')); ?></a>
<?php include __DIR__ . '/navigation.php'; ?>
<div id="app-content" class="crm-app crm-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-crm-page="<?php p($pageId); ?>"
	data-crm-role="<?php p($roleSlug); ?>"
	data-crm-can-write="<?php p(!empty($_['canWrite']) ? '1' : '0'); ?>"
	data-crm-can-delete="<?php p(!empty($_['canDelete']) ? '1' : '0'); ?>"
	data-crm-can-move="<?php p(!empty($_['canMove']) ? '1' : '0'); ?>"
	data-crm-can-brief="<?php p(!empty($_['canBriefConfirm']) ? '1' : '0'); ?>"
	data-crm-can-coach="<?php p(!empty($_['canTeamCoach']) ? '1' : '0'); ?>"
	data-crm-can-bulk="<?php p(!empty($_['canBulk']) ? '1' : '0'); ?>"
	<?php if ($pageId === 'settings' && $settingsSection !== ''): ?>
	data-crm-settings-section="<?php p($settingsSection); ?>"
	<?php endif; ?>
	<?php if ($pageId === 'app-settings' && $settingsSection !== ''): ?>
	data-crm-app-settings-section="<?php p($settingsSection); ?>"
	<?php endif; ?>
	data-crm-urls="<?php print_unescaped($urlsJson); ?>"
	data-crm-timezone="<?php p($timezone); ?>">
	<a class="crm-skip-link" href="#crm-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="crm-live-region" class="crm-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="crm-alert-region" class="crm-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="crm-shell<?php p($shellWidthClass); ?>">
		<header class="crm-page-header" aria-labelledby="crm-page-title">
			<nav class="crm-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="crm-breadcrumb__list">
					<li class="crm-breadcrumb__item"><a href="<?php p($urls['today'] ?? '#'); ?>"><?php p($l->t('CustomerCheck')); ?></a></li>
					<?php if (is_array($breadcrumbParent) && ($breadcrumbParent['url'] ?? '') !== '' && ($breadcrumbParent['label'] ?? '') !== ''): ?>
					<li class="crm-breadcrumb__item"><a href="<?php p((string)$breadcrumbParent['url']); ?>"><?php p((string)$breadcrumbParent['label']); ?></a></li>
					<?php endif; ?>
					<li class="crm-breadcrumb__item" aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="crm-page-header__main">
				<div class="crm-page-header__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($headerIcon, 'crm-page-header__icon-svg')); ?></div>
				<div class="crm-page-header__text">
					<h1 id="crm-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?><p class="crm-page-header__lead"><?php p($pageHelp); ?></p><?php endif; ?>
				</div>
				<div id="crm-page-actions" class="crm-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="crm-scope-strip" aria-label="<?php p($l->t('Context')); ?>" hidden>
				<span class="crm-badge"><?php p($roleLabel); ?></span>
			</div>
		</header>
		<main id="crm-main-content" class="crm-main" tabindex="-1" aria-labelledby="crm-page-title">
<?php include __DIR__ . '/crm-quickstart.php'; ?>
