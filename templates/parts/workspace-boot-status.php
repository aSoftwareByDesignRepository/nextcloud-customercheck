<?php
/**
 * Shared workspace boot status/error hosts for every workspace settings section.
 * After the settings split, mailbox / Assign sources / Circles each mount alone —
 * without these nodes, boot failures and early saves were silent.
 *
 * @var \OCP\IL10N $l
 */
?>
<p id="crm-ws-status" class="crm-muted" role="status" aria-live="polite"><?php p($l->t('Loading team settings…')); ?></p>
<p id="crm-ws-error" class="form-error" role="alert" hidden></p>
