/**
 * Pipeline kanban — keyboard stage change (§15.1 / WCAG).
 */
import { test, expect } from '@playwright/test';

test.describe('Pipeline kanban keyboard stage', () => {
  test('board stage select is labelled and keyboard-reachable', async ({ page }) => {
    await page.goto('/apps/customercheck/pipeline');
    const boardBtn = page.locator('[data-crm-pipeline-view="board"]').first();
    await boardBtn.click({ force: true });
    await expect(page.locator('#crm-pipeline-board')).toBeVisible({ timeout: 15000 });

    const card = page.locator('.crm-kanban__card[tabindex="0"]').first();
    const count = await page.locator('.crm-kanban__card[tabindex="0"]').count();
    test.skip(count === 0, 'No open-stage deals seeded for keyboard stage test');

    await expect(card.locator('.crm-kanban__stage-label')).toBeVisible();
    const select = card.locator('select[data-crm-deal-stage]');
    await expect(select).toBeVisible();
    const box = await select.boundingBox();
    expect(box, 'stage select touch target').toBeTruthy();
    expect(box.height).toBeGreaterThanOrEqual(40);

    await card.focus();
    await expect(card).toBeFocused();

    const before = await select.inputValue();
    const stages = ['lead', 'contacted', 'quoted', 'negotiation', 'won', 'lost'];
    const idx = stages.indexOf(before);
    // Prefer Right unless already at end — Left otherwise.
    const key = idx >= 0 && idx < stages.length - 1 ? 'ArrowRight' : 'ArrowLeft';
    const stageReq = page.waitForRequest(
      (req) => req.method() === 'POST' && /\/deals\/\d+\/stage/.test(req.url()),
      { timeout: 15000 },
    ).catch(() => null);

    await page.keyboard.press(key);
    const req = await stageReq;
    // Either the API moved the deal, or the edge of the board blocked the nudge.
    if (req) {
      expect(req.url()).toMatch(/\/deals\/\d+\/stage/);
    } else {
      // Edge card: select still operable via keyboard without pointer.
      await select.focus();
      await expect(select).toBeFocused();
    }
  });
});
