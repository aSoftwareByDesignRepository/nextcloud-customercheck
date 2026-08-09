<?php
use OCA\CustomerCheck\Service\IconCatalog;
use OCP\Util;
Util::addScript('customercheck', 'common/navigation');
Util::addScript('customercheck', 'common/navigation-icons');
// Soft keyboard: keep focused notes/inputs above the IME on phones.
Util::addScript('customercheck', 'common/keep-focused-visible');
/** @var array $_ */
$l = $_['l'];
$urlGenerator = $_['urlGenerator'];
$urls = $_['urls'] ?? [];
$pageId = (string)($_['pageId'] ?? '');
$workspaceName = (string)($_['workspaceName'] ?? '');
$workspaceId = (int)($_['workspaceId'] ?? 0);
$icon = static fn(string $n): string => IconCatalog::render($n, 'crm-nav__icon-svg');
$is = static fn(string ...$ids): bool => in_array($pageId, $ids, true);
$toolsOpen = $is('import', 'settings', 'app-settings', 'support-us', 'mail-triage', 'review', 'conflicts');
$canManageNav = !empty($_['canManage']);
$canWriteNav = !empty($_['canWrite']);
$wsQ = $workspaceId > 0 ? ('?workspaceId=' . $workspaceId) : '';
$u = static function (string $key) use ($urls, $wsQ): string {
	$base = $urls[$key] ?? '#';
	if ($base === '#' || $wsQ === '') {
		return $base;
	}
	return str_contains($base, '?') ? ($base . '&workspaceId=' . ltrim($wsQ, '?workspaceId=')) : ($base . $wsQ);
};
$navItem = static function (
	bool $active,
	string $href,
	string $iconName,
	string $label,
	string $jobTitle,
) use ($icon): void {
	?>
	<li class="<?php p($active ? 'active' : ''); ?>" <?php if ($active): ?>aria-current="page"<?php endif; ?>>
		<a href="<?php p($href); ?>" title="<?php p($jobTitle); ?>">
			<span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon($iconName)); ?></span>
			<span><?php p($label); ?></span>
		</a>
	</li>
	<?php
};
?>
<div id="app-navigation" class="crm-nav" role="navigation" aria-label="<?php p($l->t('CustomerCheck')); ?>">
	<div class="sidebar-header">
		<div class="app-brand">
			<span class="app-brand__icon" aria-hidden="true"><?php print_unescaped($icon('building-2')); ?></span>
			<div class="app-brand__text">
				<h3><?php p($l->t('CustomerCheck')); ?></h3>
				<p class="app-brand__subtitle"><?php p($l->t('Deals, customers & money')); ?></p>
			</div>
		</div>

		<?php // Single-org: keep active workspace id for client APIs; no switcher chrome. ?>
		<div
			class="crm-workspace-context crm-sr-only"
			data-crm-workspace-switch="1"
			data-crm-active-workspace="<?php p((string)$workspaceId); ?>"
			data-crm-needs-picker="0"
			aria-hidden="true"
		><?php p($workspaceName !== '' ? $workspaceName : $l->t('Organisation')); ?></div>
	</div>
	<ul class="nav-menu">
		<li class="crm-nav__group">
			<span class="crm-nav__group-label" id="crm-nav-group-now"><?php p($l->t('Do now')); ?></span>
		</li>
		<?php
		$navItem($is('today'), $u('today'), 'sun', $l->t('Today'), $l->t('Clear what needs you now'));
		$navItem($is('pulse'), $u('pulse'), 'activity', $l->t('Overview'), $l->t('See how your customers are doing'));
		$navItem($is('brief'), $u('brief'), 'clipboard-list', $l->t('This week'), $l->t('This week’s owned promises'));
		?>

		<li class="crm-nav__group">
			<span class="crm-nav__group-label" id="crm-nav-group-customers"><?php p($l->t('Customers')); ?></span>
		</li>
		<?php
		$navItem($is('my-accounts'), $u('myAccounts'), 'users', $l->t('My customers'), $l->t('Companies you own or plan'));
		$navItem($is('companies', 'company-detail', 'company-form', 'prepare-meeting'), $u('companies'), 'building-2', $l->t('Companies'), $l->t('Full customer registry'));
		$navItem($is('strategy'), $u('strategy'), 'map', $l->t('Plans'), $l->t('Account plans for your customers'));
		if (!empty($_['showTeamNav'])) {
			$navItem($is('team', 'workload'), $u('team'), 'user-cog', $l->t('Team'), $l->t('Who needs help — managers only'));
		}
		?>

		<li class="crm-nav__group">
			<span class="crm-nav__group-label" id="crm-nav-group-deals"><?php p($l->t('Deals & money')); ?></span>
		</li>
		<?php
		$navItem($is('pipeline'), $u('pipeline'), 'kanban', $l->t('Deals'), $l->t('All deals — table or board'));
		$navItem($is('agreements', 'agreement-form'), $u('agreements'), 'file-text', $l->t('Agreements'), $l->t('Licenses and renewals'));
		$navItem($is('money'), $u('money'), 'coins', $l->t('Money'), $l->t('Invoices and overdue cash'));
		$navItem($is('activities'), $u('activities'), 'check-square', $l->t('Activities'), $l->t('Logged calls and to-dos'));
		?>

		<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
		<li class="nav-item-has-children <?php p($toolsOpen ? 'is-open' : ''); ?>">
			<button type="button" class="nav-parent-toggle app-navigation-entry-button" aria-expanded="<?php p($toolsOpen ? 'true' : 'false'); ?>" aria-controls="tools-subnav">
				<span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('wrench')); ?></span>
				<span><?php p($l->t('Tools')); ?></span>
				<span class="nav-parent-chevron" aria-hidden="true"></span>
			</button>
			<ul id="tools-subnav" class="nav-submenu" <?php if (!$toolsOpen): ?>hidden<?php endif; ?>>
				<li class="<?php p($is('review', 'mail-triage') ? 'active' : ''); ?>"><a href="<?php p($u('review')); ?>" title="<?php p($l->t('Assign unmatched shared mail and review items')); ?>"><?php p($l->t('Review')); ?></a></li>
				<li class="<?php p($is('conflicts') ? 'active' : ''); ?>"><a href="<?php p($u('conflicts')); ?>" title="<?php p($l->t('Resolve parallel edits')); ?>"><?php p($l->t('Conflicts')); ?></a></li>
				<?php if ($canWriteNav): ?>
				<li class="<?php p($is('import') ? 'active' : ''); ?>"><a href="<?php p($u('import')); ?>" title="<?php p($l->t('Import companies and deals')); ?>"><?php p($l->t('Import')); ?></a></li>
				<?php endif; ?>
				<li class="<?php p($is('settings') ? 'active' : ''); ?>"><a href="<?php p($u('settings')); ?>" title="<?php p($l->t('Members and mailbox')); ?>"><?php p($l->t('Team & mailbox')); ?></a></li>
				<?php if ($canManageNav): ?>
				<li class="<?php p($is('app-settings') ? 'active' : ''); ?>"><a href="<?php p($u('appSettings')); ?>" title="<?php p($l->t('Access, seats, and tokens')); ?>"><?php p($l->t('App settings')); ?></a></li>
				<?php endif; ?>
				<li class="<?php p($is('support-us') ? 'active' : ''); ?>"><a href="<?php p($u('supportUs')); ?>" title="<?php p($l->t('Optional project support')); ?>"><?php p($l->t('Support & us')); ?></a></li>
			</ul>
		</li>
	</ul>
</div>
