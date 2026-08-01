<?php
/**
 * Dismissible Quick start how-to (DutyCheck-style).
 *
 * Expects $_['quickstart'] from PageQuickStartCatalog::forPage().
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$quickstart = $_['quickstart'] ?? null;
if (!is_array($quickstart) || empty($quickstart['steps']) || !is_array($quickstart['steps'])) {
	return;
}
$qsKey = (string)($quickstart['key'] ?? 'quickstart_v1');
$qsId = (string)($quickstart['domId'] ?? 'crm-quickstart');
$qsLead = (string)($quickstart['lead'] ?? '');
$qsTitleId = $qsId . '-title';
?>
<section
	class="crm-card crm-quickstart"
	id="<?php p($qsId); ?>"
	hidden
	aria-labelledby="<?php p($qsTitleId); ?>">
	<header class="crm-card__header crm-quickstart__header">
		<div class="crm-card__header-text">
			<h2 id="<?php p($qsTitleId); ?>" class="crm-card__title"><?php p($l->t('Quick start')); ?></h2>
			<?php if ($qsLead !== ''): ?>
				<p class="crm-card__lead"><?php p($qsLead); ?></p>
			<?php endif; ?>
		</div>
		<div class="crm-card__header-actions">
			<button
				type="button"
				class="crm-btn crm-btn--ghost crm-hint-dismiss"
				data-crm-dismiss-hint="<?php p($qsKey); ?>"
				aria-describedby="<?php p($qsTitleId); ?>">
				<?php p($l->t('Hide tips')); ?>
			</button>
		</div>
	</header>
	<div class="crm-card__body">
		<ol class="crm-quickstart__list">
			<?php foreach ($quickstart['steps'] as $step):
				if (!is_array($step)) {
					continue;
				}
				$title = (string)($step['title'] ?? '');
				$text = (string)($step['text'] ?? '');
				$href = trim((string)($step['href'] ?? ''));
				$linkLabel = (string)($step['linkLabel'] ?? '');
				if ($title === '' && $text === '') {
					continue;
				}
				?>
				<li class="crm-quickstart__item">
					<?php if ($title !== ''): ?>
						<strong class="crm-quickstart__item-title"><?php p($title); ?></strong>
					<?php endif; ?>
					<?php if ($text !== ''): ?>
						<p class="crm-quickstart__item-text"><?php p($text); ?></p>
					<?php endif; ?>
					<?php if ($href !== '' && $linkLabel !== ''): ?>
						<a class="crm-btn crm-btn--secondary crm-btn--sm" href="<?php p($href); ?>"><?php p($linkLabel); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>
