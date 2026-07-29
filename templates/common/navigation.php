<?php
use OCA\CustomerCheck\Service\IconCatalog;
use OCP\Util;
Util::addScript('customercheck', 'common/navigation');
Util::addScript('customercheck', 'common/navigation-icons');
/** @var array $_ */
$l = $_['l'];
$urlGenerator = $_['urlGenerator'];
$urls = $_['urls'] ?? [];
$pageId = (string)($_['pageId'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? '');
$workspaceName = (string)($_['workspaceName'] ?? '');
$workspaceId = (int)($_['workspaceId'] ?? 0);
$needsPicker = !empty($_['needsPicker']);
/** @var list<array{id: int, name: string, slug: string, archived: bool, isDefault: bool}> $workspaces */
$workspaces = is_array($_['workspaces'] ?? null) ? $_['workspaces'] : [];
/** @var array<int, string> $workspaceSwitchUrls */
$workspaceSwitchUrls = is_array($_['workspaceSwitchUrls'] ?? null) ? $_['workspaceSwitchUrls'] : [];
$icon = static fn(string $n): string => IconCatalog::render($n, 'crm-nav__icon-svg');
$is = static fn(string ...$ids): bool => in_array($pageId, $ids, true);
$toolsOpen = $is('import', 'settings', 'app-settings', 'support-us', 'mail-triage');
$canManageNav = !empty($_['canManage']);
$wsQ = $workspaceId > 0 ? ('?workspaceId=' . $workspaceId) : '';
$u = static function (string $key) use ($urls, $wsQ): string {
	$base = $urls[$key] ?? '#';
	if ($base === '#' || $wsQ === '') {
		return $base;
	}
	return str_contains($base, '?') ? ($base . '&workspaceId=' . ltrim($wsQ, '?workspaceId=')) : ($base . $wsQ);
};
$switchable = count($workspaces) > 1;
$currentLabel = $workspaceName !== '' ? $workspaceName : $l->t('Current workspace');
?>
<div id="app-navigation" class="crm-nav" role="navigation" aria-label="<?php p($l->t('CustomerCheck')); ?>">
	<div class="sidebar-header">
		<div class="app-brand">
			<span class="app-brand__icon" aria-hidden="true"><?php print_unescaped($icon('building-2')); ?></span>
			<div class="app-brand__text">
				<h3><?php p($l->t('CustomerCheck')); ?></h3>
				<p class="app-brand__subtitle"><?php p($l->t('Customers & renewals')); ?></p>
			</div>
		</div>

		<section
			class="crm-workspace-switcher<?php p($needsPicker ? ' crm-workspace-switcher--needs-picker' : ''); ?><?php p($switchable ? ' crm-workspace-switcher--multi' : ' crm-workspace-switcher--single'); ?>"
			data-crm-workspace-switch="1"
			data-crm-active-workspace="<?php p((string)$workspaceId); ?>"
			data-crm-needs-picker="<?php p($needsPicker ? '1' : '0'); ?>"
			aria-labelledby="crm-workspace-switch-label"
		>
			<p class="crm-workspace-switcher__label" id="crm-workspace-switch-label"><?php p($l->t('Workspace')); ?></p>

			<?php if ($workspaceId <= 0 && $workspaces === []): ?>
				<p class="crm-workspace-switcher__empty" role="status"><?php p($l->t('No workspace yet')); ?></p>
			<?php elseif (!$switchable): ?>
				<div
					class="crm-workspace-switcher__current"
					id="crm-workspace-switch"
					role="group"
					aria-labelledby="crm-workspace-switch-label crm-workspace-label"
				>
					<span class="crm-workspace-switcher__name" id="crm-workspace-label"><?php p($currentLabel); ?></span>
					<?php if ($roleLabel !== ''): ?>
						<span class="crm-workspace-switcher__role"><?php p($l->t('Your role: %s', [$roleLabel])); ?></span>
					<?php endif; ?>
				</div>
			<?php else: ?>
				<button
					type="button"
					class="crm-workspace-switcher__trigger"
					id="crm-workspace-switch"
					aria-expanded="false"
					aria-controls="crm-workspace-menu"
					aria-labelledby="crm-workspace-switch-label crm-workspace-label"
				>
					<span class="crm-workspace-switcher__trigger-text">
						<span class="crm-workspace-switcher__name" id="crm-workspace-label"><?php p($currentLabel); ?></span>
						<?php if ($roleLabel !== ''): ?>
							<span class="crm-workspace-switcher__role"><?php p($l->t('Your role: %s', [$roleLabel])); ?></span>
						<?php endif; ?>
					</span>
					<span class="crm-workspace-switcher__chevron" aria-hidden="true"><?php print_unescaped($icon('chevron-down')); ?></span>
				</button>
				<ul
					id="crm-workspace-menu"
					class="crm-workspace-switcher__menu"
					aria-labelledby="crm-workspace-switch-label"
					hidden
				>
					<?php foreach ($workspaces as $ws):
						$wsId = (int)($ws['id'] ?? 0);
						if ($wsId <= 0) {
							continue;
						}
						$active = $wsId === $workspaceId;
						$href = (string)($workspaceSwitchUrls[$wsId] ?? ('?workspaceId=' . $wsId));
						$name = (string)($ws['name'] ?? '');
						$archived = !empty($ws['archived']);
						?>
						<li>
							<a
								class="crm-workspace-switcher__option<?php p($active ? ' is-active' : ''); ?>"
								href="<?php p($href); ?>"
								data-crm-workspace-id="<?php p((string)$wsId); ?>"
								<?php if ($active): ?>aria-current="true"<?php endif; ?>
							>
								<span class="crm-workspace-switcher__option-name"><?php p($name !== '' ? $name : $l->t('Workspace')); ?></span>
								<?php if ($archived): ?>
									<span class="crm-workspace-switcher__option-meta"><?php p($l->t('Archived')); ?></span>
								<?php elseif ($active): ?>
									<span class="crm-workspace-switcher__option-meta"><?php p($l->t('Current')); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ($needsPicker): ?>
				<p class="crm-workspace-switcher__hint" role="status"><?php p($l->t('Pick which customer book you are working in.')); ?></p>
			<?php elseif ($workspaceName !== ''): ?>
				<p class="crm-workspace-switcher__sr-context crm-sr-only"><?php p($l->t('Working in workspace %s.', [$workspaceName])); ?></p>
			<?php endif; ?>
		</section>
	</div>
	<ul class="nav-menu">
		<li class="<?php p($is('today') ? 'active' : ''); ?>" <?php if ($is('today')): ?>aria-current="page"<?php endif; ?>>
			<a href="<?php p($u('today')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('sun')); ?></span><span><?php p($l->t('Today')); ?></span></a>
		</li>
		<li class="<?php p($is('pulse') ? 'active' : ''); ?>">
			<a href="<?php p($u('pulse')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('activity')); ?></span><span><?php p($l->t('Pulse')); ?></span></a>
		</li>
		<li class="<?php p($is('brief') ? 'active' : ''); ?>">
			<a href="<?php p($u('brief')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('clipboard-list')); ?></span><span><?php p($l->t('Brief')); ?></span></a>
		</li>
		<li class="<?php p($is('my-accounts') ? 'active' : ''); ?>">
			<a href="<?php p($u('myAccounts')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('users')); ?></span><span><?php p($l->t('My Accounts')); ?></span></a>
		</li>
		<?php if (!empty($_['showTeamNav'])): ?>
		<li class="<?php p($is('team', 'workload') ? 'active' : ''); ?>">
			<a href="<?php p($u('team')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('user-cog')); ?></span><span><?php p($l->t('Team')); ?></span></a>
		</li>
		<?php endif; ?>
		<li class="<?php p($is('companies','company-detail','company-form','prepare-meeting') ? 'active' : ''); ?>">
			<a href="<?php p($u('companies')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('building-2')); ?></span><span><?php p($l->t('Companies')); ?></span></a>
		</li>
		<li class="<?php p($is('strategy') ? 'active' : ''); ?>">
			<a href="<?php p($u('strategy')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('map')); ?></span><span><?php p($l->t('Strategy')); ?></span></a>
		</li>
		<li class="<?php p($is('pipeline') ? 'active' : ''); ?>">
			<a href="<?php p($u('pipeline')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('kanban')); ?></span><span><?php p($l->t('Pipeline')); ?></span></a>
		</li>
		<li class="<?php p($is('agreements','agreement-form') ? 'active' : ''); ?>">
			<a href="<?php p($u('agreements')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('file-text')); ?></span><span><?php p($l->t('Agreements')); ?></span></a>
		</li>
		<li class="<?php p($is('money') ? 'active' : ''); ?>">
			<a href="<?php p($u('money')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('coins')); ?></span><span><?php p($l->t('Money')); ?></span></a>
		</li>
		<li class="<?php p($is('activities') ? 'active' : ''); ?>">
			<a href="<?php p($u('activities')); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('check-square')); ?></span><span><?php p($l->t('Activities')); ?></span></a>
		</li>
		<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
		<li class="nav-item-has-children <?php p($toolsOpen ? 'is-open' : ''); ?>">
			<button type="button" class="nav-parent-toggle app-navigation-entry-button" aria-expanded="<?php p($toolsOpen ? 'true' : 'false'); ?>" aria-controls="tools-subnav">
				<span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('wrench')); ?></span>
				<span><?php p($l->t('Tools')); ?></span>
				<span class="nav-parent-chevron" aria-hidden="true"></span>
			</button>
			<ul id="tools-subnav" class="nav-submenu" <?php if (!$toolsOpen): ?>hidden<?php endif; ?>>
				<li class="<?php p($is('mail-triage') ? 'active' : ''); ?>"><a href="<?php p($u('mailTriage')); ?>"><?php p($l->t('Mail triage')); ?></a></li>
				<li class="<?php p($is('import') ? 'active' : ''); ?>"><a href="<?php p($u('import')); ?>"><?php p($l->t('Import')); ?></a></li>
				<li class="<?php p($is('settings') ? 'active' : ''); ?>"><a href="<?php p($u('settings')); ?>"><?php p($l->t('Workspace settings')); ?></a></li>
				<?php if ($canManageNav): ?>
				<li class="<?php p($is('app-settings') ? 'active' : ''); ?>"><a href="<?php p($u('appSettings')); ?>"><?php p($l->t('App settings')); ?></a></li>
				<?php endif; ?>
				<li class="<?php p($is('support-us') ? 'active' : ''); ?>"><a href="<?php p($u('supportUs')); ?>"><?php p($l->t('Support & us')); ?></a></li>
			</ul>
		</li>
	</ul>
</div>
