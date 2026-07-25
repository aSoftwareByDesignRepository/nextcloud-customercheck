export async function login(page, { username, password }) {
  await page.goto('/login');
  const userInput = page
    .getByRole('textbox', { name: /account name|email|benutzername|e-mail/i })
    .or(page.locator('#user'))
    .or(page.locator('input[name="user"]'))
    .first();
  const passInput = page
    .getByRole('textbox', { name: /^password$|^passwort$/i })
    .or(page.locator('#password'))
    .or(page.locator('input[name="password"]'))
    .first();
  await userInput.fill(username);
  await passInput.fill(password);
  const submit = page
    .getByRole('button', { name: /^log in$|^anmelden$/i })
    .or(page.locator('button[type="submit"]'))
    .first();
  await submit.click();
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 });
  await page.waitForLoadState('networkidle');
}

export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`];
  const p = process.env[`NC_${role}_PASS`];
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`);
  }
  return { username: u, password: p };
}
