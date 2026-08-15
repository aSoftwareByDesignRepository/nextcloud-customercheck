<?php
/**
 * Obvious per-row primary CTA — granny UX: never rely on linked text alone.
 *
 * @var \OCP\IL10N $l
 * @var string $crmRowOpenHref
 * @var string|null $crmRowOpenLabel
 * @var bool|null $crmRowOpenSecondary  when true, use secondary style (workflow stays primary)
 * @var bool|null $crmRowOpenExternal   when true, open in a new tab (Mail / sibling apps)
 */
$__href = trim((string)($crmRowOpenHref ?? ''));
$__label = trim((string)($crmRowOpenLabel ?? ''));
if ($__label === '') {
	$__label = $l->t('Open');
}
$__secondary = !empty($crmRowOpenSecondary);
$__external = !empty($crmRowOpenExternal);
$__class = $__secondary
	? 'crm-btn crm-btn--sm crm-btn--secondary'
	: 'crm-btn crm-btn--sm crm-btn--primary';
if ($__href !== ''): ?>
<a class="<?php p($__class); ?>" href="<?php p($__href); ?>"<?php if ($__external): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php p($__label); ?></a>
<?php
endif;
unset($crmRowOpenHref, $crmRowOpenLabel, $crmRowOpenSecondary, $crmRowOpenExternal, $__href, $__label, $__secondary, $__external, $__class);
