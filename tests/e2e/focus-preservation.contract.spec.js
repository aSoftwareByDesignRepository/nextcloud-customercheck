// @ts-check
/**
 * FOCUS_PRESERVATION_CONTRACT — customercheck permanent harness.
 *
 * Standing guard for the shared focus-rescue observer
 * (apps/_shared/focus-preservation, synced to js/common/focus-preservation.js).
 * Probes use DOM surgery (clone -> wipe -> re-add) so they do not depend on
 * seeded companies/deals: the observer reacts to mutation records, so an
 * in-page rebuild exercises the exact machinery a real re-render would.
 *
 * Class refs: focus_loss_on_rebuild, focus_steal_after_leave,
 * interim_decoy_ends_rescue, landmark_uniqueness (COMPANION-DESIGN-SYSTEM §8).
 */
import { createRequire } from 'node:module';
import { test } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

const require = createRequire(import.meta.url);
const {
	assertRebuildPreservesFocus,
	assertLeaveThenWipeDoesNotSteal,
	assertInterimDecoyDoesNotEndRescue,
	assertUniqueLandmarks,
} = require('../../../_shared/e2e/focus-preservation-contract.js');

const FOCUSABLE = 'button, summary, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
const TARGET = `#crm-main-content ${FOCUSABLE}`;

// Inner list/group container holding the focused element — never the app
// root itself (detaching #app-content exercises the stale-arm path, which
// is a different class).
const SURGERY_CONTAINER = `a.closest('ul, ol, tbody, [role="list"], [role="group"], [id$="-page-actions"], .crm-page-stack') || a.parentElement`;

const REBUILD = `(() => {
	const a = document.activeElement;
	const container = ${SURGERY_CONTAINER};
	const clone = container.cloneNode(true);
	container.replaceWith(clone);
	return true;
})()`;

test.describe('FOCUS_PRESERVATION_CONTRACT', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await login(page, credsFromEnv('ADMIN'));
		await gotoCrm(page, '/apps/customercheck/');
		await page.waitForTimeout(1200);
	});

	test('rebuild under focus rescues to rebuilt equivalent (never body)', async ({ page }) => {
		await assertRebuildPreservesFocus(page, {
			target: TARGET,
			rebuild: () => page.evaluate(REBUILD),
		});
	});

	test('genuine leave then wipe does not steal focus back', async ({ page }) => {
		await assertLeaveThenWipeDoesNotSteal(page, {
			target: TARGET,
			leave: async () => {
				// Trusted gesture on neutral NC chrome (top-left header area).
				await page.mouse.click(10, 8);
				await page.waitForTimeout(150);
			},
			rebuild: () => page.evaluate(REBUILD),
		});
	});

	test('interim decoy inside wiped hood does not end the rescue', async ({ page }) => {
		const sig = await page.evaluate(`(() => {
			const el = document.querySelector(${JSON.stringify(TARGET)});
			if (!el) return null;
			el.focus();
			return el.getAttribute('aria-label') || (el.textContent || '').trim().slice(0, 80);
		})()`);
		test.skip(!sig, 'no focusable target in main content');

		await assertInterimDecoyDoesNotEndRescue(page, {
			target: TARGET,
			wipe: () => page.evaluate(`(() => {
				const a = document.activeElement;
				const container = ${SURGERY_CONTAINER};
				window.__probeClone = container.cloneNode(true);
				window.__probeContainer = container;
				container.textContent = '';
				return true;
			})()`),
			paintInterim: () => page.evaluate(`(() => {
				const decoy = document.createElement('button');
				decoy.textContent = 'Loading…';
				decoy.setAttribute('aria-label', 'probe decoy');
				window.__probeContainer.appendChild(decoy);
				return true;
			})()`),
			rebuild: () => page.evaluate(`(() => {
				window.__probeContainer.replaceChildren(...window.__probeClone.childNodes);
				return true;
			})()`),
			expectSignature: sig,
		});
	});

	test('landmark uniqueness: no in-app banner, no top-level <footer>', async ({ page }) => {
		await assertUniqueLandmarks(page);
	});
});
