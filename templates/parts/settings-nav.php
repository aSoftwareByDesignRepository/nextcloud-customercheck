<?php
/**
 * In-page Workspace settings sub-navigation (BudgetCheck / SETTINGS-PAGES-STANDARD).
 *
 * Complements the sidebar: Nextcloud collapses #app-navigation below ~1024px,
 * so without this chip bar members cannot reach sibling settings pages on
 * phones/tablets. Labels and URLs come from the controller
 * (WorkspaceSettingsSectionCatalog) — never hardcoded here. Only VISIBLE
 * sections are present in settingsSectionLabels.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $crmRequestedSection
 */

$crmNavLabels = (array) ($_['settingsSectionLabels'] ?? []);
$crmNavUrls = (array) (($_['urls']['settingsSections'] ?? []) ?: []);
if ($crmNavLabels === []) {
	return;
}
?>
<nav class="crm-settings-nav" id="crm-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($crmNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string) $sectionId;
		$href = (string) ($crmNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $crmRequestedSection === $sectionId;
		?>
		<a class="crm-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string) $sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>
