(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg) => (window.t ? window.t('customercheck', msg) : msg);
  if (C && C.init) { C.init(); }

  document.querySelectorAll('[data-crm-complete-activity]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const id = btn.getAttribute('data-crm-complete-activity');
      if (!id || !Api) return;
      btn.disabled = true;
      try {
        await Api.post('/activities/' + id + '/done', {
          done: true,
          updatedAt: btn.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Activity marked done.');
        const row = btn.closest('.crm-today-row, .crm-list__item');
        if (row) {
          row.classList.add('crm-today-row--done', 'crm-list__item--done');
          const actions = row.querySelector('.crm-table-actions, .crm-list__today-actions');
          if (actions) {
            actions.innerHTML = '<span class="crm-badge crm-badge--success">' + t('Done') + '</span>';
          }
        } else {
          location.reload();
        }
      } catch (e) {
        btn.disabled = false;
        if (C) {
          await C.alertDialog({
            title: t('Update failed'),
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
        }
      }
    });
  });

  document.querySelectorAll('[data-crm-claim-deal]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const id = btn.getAttribute('data-crm-claim-deal');
      if (!id || !Api) return;
      btn.disabled = true;
      try {
        await Api.post('/deals/' + id + '/claim', {
          updatedAt: btn.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Deal claimed.');
        const row = btn.closest('.crm-today-row, .crm-list__item');
        const openHref = btn.getAttribute('data-open-href') || '';
        if (row) {
          const actions = row.querySelector('.crm-table-actions, .crm-list__today-actions');
          if (actions) {
            if (openHref) {
              actions.innerHTML = '<a class="crm-btn crm-btn--sm crm-btn--primary" href="'
                + openHref.replace(/"/g, '&quot;') + '">' + t('Open') + '</a>';
            } else {
              actions.innerHTML = '<span class="crm-badge crm-badge--success">' + t('Claimed') + '</span>';
            }
          }
        } else {
          location.reload();
        }
      } catch (e) {
        btn.disabled = false;
        if (C) {
          await C.alertDialog({
            title: t('Claim failed'),
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
        }
      }
    });
  });
})();
