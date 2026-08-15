<?php
/**
 * In-page App settings sub-navigation (BudgetCheck / SETTINGS-PAGES-STANDARD).
 *
 * Labels and URLs come from the controller (AppSettingsSectionCatalog).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var string $crmRequestedSection
 */

$crmNavLabels = (array) ($_['appSettingsSectionLabels'] ?? []);
$crmNavUrls = (array) (($_['urls']['appSettingsSections'] ?? []) ?: []);
if ($crmNavLabels === []) {
	return;
}
?>
<nav class="crm-settings-nav" id="crm-app-settings-pages" aria-label="<?php p($l->t('App settings pages')); ?>">
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
