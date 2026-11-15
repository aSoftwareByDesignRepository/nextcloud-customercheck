// @ts-check
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

const NEXTCLOUD_DIR = join(dirname(fileURLToPath(import.meta.url)), '../../../../');
const API = '/index.php/apps/customercheck/api/v1/license';

/**
 * Mints a real CUC2 wire key in the NC container (sodium Ed25519, test seed
 * sha256("customercheck-cuc2-test-signing-v1") — mirrors Cuc2TestSigning).
 * The dev instance accepts it via CUC_ALLOW_VENDOR_KEY_OVERRIDE=1.
 */
function mintLicenseKey(customerId) {
	const code = [
		'$p = json_encode(["v"=>2,"product"=>"customercheck","customerId"=>"' + customerId + '",',
		'"issuedAt"=>"2026-01-01","validUntil"=>"2099-12-31","mobileSeats"=>5],',
		'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);',
		'$kp = sodium_crypto_sign_seed_keypair(hash("sha256","customercheck-cuc2-test-signing-v1",true));',
		'$b = fn($s)=>rtrim(strtr(base64_encode($s),"+/","-_"),"=");',
		'echo "CUC2.".$b($p).".".$b(sodium_crypto_sign_detached($p, sodium_crypto_sign_secretkey($kp)));',
	].join('');
	return execFileSync(
		'docker',
		['compose', 'exec', '-T', 'nextcloud', 'php', '-r', code],
		{ cwd: NEXTCLOUD_DIR, encoding: 'utf8' },
	).trim();
}

async function requestToken(page) {
	return page.evaluate(
		() => document.head?.dataset?.requesttoken || (window.OC && window.OC.requestToken) || '',
	);
}

async function applyLicense(page, key) {
	const token = await requestToken(page);
	return page.context().request.fetch(API, {
		method: 'POST',
		data: { key },
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
}

async function removeLicense(page) {
	const token = await requestToken(page);
	return page.context().request.fetch(API, {
		method: 'DELETE',
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
}

test.describe('Mobile app license settings (CUC2)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');

	test('license section is visible for app admins and explains the free web app', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/customercheck/app-settings/license');
		await page.waitForSelector('#crm-license-page', { timeout: 30000 });

		const section = page.locator('#section-license');
		await expect(section).toBeVisible();
		await expect(section.getByRole('heading', { level: 2 }).first()).toBeVisible();
		await expect(page.locator('#crm-license-key-input')).toBeVisible();
		await expect(page.locator('#crm-license-save')).toBeVisible();
		// The key input must be described for screen readers.
		await expect(page.locator('#crm-license-key-input')).toHaveAttribute(
			'aria-describedby',
			/crm-license-key-hint/,
		);
	});

	test('rejects a malformed license key with an accessible inline error', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/customercheck/app-settings/license');
		await page.waitForSelector('#crm-license-key-input', { timeout: 30000 });

		await page.locator('#crm-license-key-input').fill('CUC2.this-is.not-a-valid-key');
		await page.locator('#crm-license-save').click();

		const error = page.locator('#crm-license-feedback');
		await expect(error).toBeVisible({ timeout: 15000 });
		await expect(error).toHaveAttribute('role', 'alert');
		await expect(error).not.toHaveText('');
		// Still on settings — a bad key never navigates away or breaks the page.
		await expect(page.locator('#section-license')).toBeVisible();
	});

	test('empty key submit shows a hint without a server round-trip crash', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/customercheck/app-settings/license');
		await page.waitForSelector('#crm-license-key-input', { timeout: 30000 });

		await page.locator('#crm-license-key-input').fill('   ');
		await page.locator('#crm-license-save').click();
		await expect(page.locator('#crm-license-feedback')).toBeVisible({ timeout: 15000 });
	});

	test('seat assign/search/remove round-trip on a real license stays on the page', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/customercheck/app-settings/license');
		await page.waitForSelector('#crm-license-page', { timeout: 30000 });

		const admin = credsFromEnv('ADMIN');
		const key = mintLicenseKey('cuc-e2e-seats');
		const applied = await applyLicense(page, key);
		test.skip(applied.status() !== 200, 'test vendor key override unavailable');
		const body = await applied.json();
		expect(body.ok, 'apply must return the shared-pack ok contract').toBe(true);

		try {
			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#crm-mobile-seats-section', { timeout: 30000 });
			await expect(page.locator('#crm-license-active-badge')).toBeVisible();

			// Seat search finds directory users through the real users API.
			const search = page.locator('#crm-seat-user-search');
			await search.fill(admin.username);
			const results = page.locator('#crm-seat-search-results');
			await expect(results).toBeVisible({ timeout: 15000 });
			const option = results
				.locator(`li[role="option"][data-user-id="${admin.username}"]`)
				.first();
			await expect(option).toBeVisible({ timeout: 10000 });
			await option.locator('input[type="checkbox"]').check();

			await page.locator('#crm-license-seats-heading').scrollIntoViewIfNeeded();
			const scrollBefore = await page.evaluate(() => window.scrollY);

			const assignBtn = page.locator('#crm-seat-assign-selected');
			await expect(assignBtn).toBeVisible();
			await assignBtn.click();
			await expect(page.locator('#crm-license-confirm-modal[open]')).toBeVisible({ timeout: 5000 });
			await page.locator('#crm-license-confirm-ok').click();

			// Assigned row persists in the seat table; counter bumps; no reload.
			const row = page.locator(`#crm-seat-list-body tr[data-user-id="${admin.username}"]`);
			await expect(row).toBeVisible({ timeout: 15000 });
			await expect(page.locator('#crm-license-mobile-used')).toHaveText('1');
			await expect(page).toHaveURL(/app-settings/);
			const scrollAfter = await page.evaluate(() => window.scrollY);
			expect(Math.abs(scrollAfter - scrollBefore)).toBeLessThan(160);

			// Already-seated users must not re-appear in the picker.
			await search.fill(admin.username);
			await page.waitForTimeout(900);
			await expect(
				results.locator(`li[role="option"][data-user-id="${admin.username}"]`),
			).toHaveCount(0);

			// Remove the seat via the row action + confirm modal.
			await page.locator(`.crm-seat-remove[data-user-id="${admin.username}"]`).click();
			await expect(page.locator('#crm-license-confirm-modal[open]')).toBeVisible({ timeout: 5000 });
			await page.locator('#crm-license-confirm-ok').click();
			await expect(row).toHaveCount(0, { timeout: 15000 });
		} finally {
			// Restore instance state — leave no license behind.
			await removeLicense(page).catch(() => {});
		}
	});

	test('settings page (including license section) passes axe WCAG 2.1 AA', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'));
		await page.goto('/apps/customercheck/app-settings/license');
		await page.waitForSelector('#crm-main-content', { timeout: 30000 });
		const results = await new AxeBuilder({ page })
			.include('#crm-main-content')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
			.analyze();
		const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
		expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
	});

	test('license section is hidden from non-admin CRM users', async ({ page }) => {
		test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC employee credentials');
		await login(page, credsFromEnv('EMPLOYEE'));
		await page.goto('/apps/customercheck/app-settings/license');
		// Fail-closed allowlist may deny the employee entirely — that also means no license UI.
		const denied = page.locator('.crm-access-denied, [data-crm-access-denied="1"]');
		const main = page.locator('#crm-main-content');
		await expect(denied.or(main).first()).toBeVisible({ timeout: 30000 });
		if (await denied.count()) {
			await expect(page.locator('#section-license')).toHaveCount(0);
			return;
		}
		await expect(page.locator('#section-license')).toHaveCount(0);
		await expect(page.locator('#crm-license-key-input')).toHaveCount(0);
	});
});
