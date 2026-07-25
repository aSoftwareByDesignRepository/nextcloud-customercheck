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
$icon = static fn(string $n): string => IconCatalog::render($n, 'crm-nav__icon-svg');
$is = static fn(string ...$ids): bool => in_array($pageId, $ids, true);
$toolsOpen = $is('import', 'settings', 'support-us');
?>
<div id="app-navigation" class="crm-nav" role="navigation" aria-label="<?php p($l->t('CustomerCheck')); ?>">
	<div class="sidebar-header">
		<div class="app-brand">
			<span class="app-brand__icon" aria-hidden="true"><?php print_unescaped($icon('building-2')); ?></span>
			<div class="app-brand__text">
				<h3><?php p($l->t('CustomerCheck')); ?></h3>
				<p class="app-brand__subtitle"><?php p($l->t('Customers & renewals')); ?></p>
				<?php if ($roleLabel !== ''): ?><p class="app-brand__role"><?php p($roleLabel); ?></p><?php endif; ?>
			</div>
		</div>
	</div>
	<ul class="nav-menu">
		<li class="<?php p($is('today') ? 'active' : ''); ?>" <?php if ($is('today')): ?>aria-current="page"<?php endif; ?>>
			<a href="<?php p($urls['today'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('sun')); ?></span><span><?php p($l->t('Today')); ?></span></a>
		</li>
		<li class="<?php p($is('companies','company-detail','company-form') ? 'active' : ''); ?>">
			<a href="<?php p($urls['companies'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('building-2')); ?></span><span><?php p($l->t('Companies')); ?></span></a>
		</li>
		<li class="<?php p($is('agreements','agreement-form') ? 'active' : ''); ?>">
			<a href="<?php p($urls['agreements'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('file-text')); ?></span><span><?php p($l->t('Agreements')); ?></span></a>
		</li>
		<li class="<?php p($is('money') ? 'active' : ''); ?>">
			<a href="<?php p($urls['money'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('coins')); ?></span><span><?php p($l->t('Money')); ?></span></a>
		</li>
		<li class="<?php p($is('activities') ? 'active' : ''); ?>">
			<a href="<?php p($urls['activities'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('check-square')); ?></span><span><?php p($l->t('Activities')); ?></span></a>
		</li>
		<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
		<li class="<?php p($is('pipeline') ? 'active' : ''); ?>">
			<a href="<?php p($urls['pipeline'] ?? '#'); ?>"><span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('kanban')); ?></span><span><?php p($l->t('Pipeline')); ?></span></a>
		</li>
		<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
		<li class="nav-item-has-children <?php p($toolsOpen ? 'is-open' : ''); ?>">
			<button type="button" class="nav-parent-toggle app-navigation-entry-button" aria-expanded="<?php p($toolsOpen ? 'true' : 'false'); ?>" aria-controls="tools-subnav">
				<span class="crm-nav__icon" aria-hidden="true"><?php print_unescaped($icon('wrench')); ?></span>
				<span><?php p($l->t('Tools')); ?></span>
				<span class="nav-parent-chevron" aria-hidden="true"></span>
			</button>
			<ul id="tools-subnav" class="nav-submenu" <?php if (!$toolsOpen): ?>hidden<?php endif; ?>>
				<li class="<?php p($is('import') ? 'active' : ''); ?>"><a href="<?php p($urls['import'] ?? '#'); ?>"><?php p($l->t('Import')); ?></a></li>
				<li class="<?php p($is('settings') ? 'active' : ''); ?>"><a href="<?php p($urls['settings'] ?? '#'); ?>"><?php p($l->t('Settings')); ?></a></li>
				<li class="<?php p($is('support-us') ? 'active' : ''); ?>"><a href="<?php p($urls['supportUs'] ?? '#'); ?>"><?php p($l->t('Support & us')); ?></a></li>
			</ul>
		</li>
	</ul>
</div>
