(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  document.querySelectorAll('[data-crm-complete-activity]').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const id = btn.getAttribute('data-crm-complete-activity');
      if (!id || !Api) return;
      btn.disabled = true;
      try {
        await Api.post('/activities/' + id + '/done', { done: true });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = 'Activity marked done.';
        const row = btn.closest('.crm-list__item');
        if (row) {
          row.classList.add('crm-list__item--done');
          row.setAttribute('aria-hidden', 'false');
          const actions = row.querySelector('.crm-list__today-actions');
          if (actions) {
            actions.innerHTML = '<span class="crm-badge crm-badge--success">Done</span>';
          }
        } else {
          location.reload();
        }
      } catch (e) {
        btn.disabled = false;
        if (C) {
          await C.alertDialog({
            title: 'Update failed',
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
        }
      }
    });
  });
})();
