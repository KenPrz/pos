// Captures real screenshots of both POS apps for the user manual.
//   Preconditions: `make dev` up, and a FRESH `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed`.
//   Run: node docs/user-manual/capture_screenshots.mjs [--only=bo|register]
// Re-runnable; overwrites by name. Figure numbers 019/026 are intentionally
// unassigned (kept for insertion room); do not renumber existing figures.
import { mkdirSync, readdirSync, readFileSync, existsSync } from 'node:fs';
import { chromium } from 'playwright';

const API = 'http://127.0.0.1:8000/api/v1';
// Next.js 16 dev servers block cross-origin dev-resource requests by default
// (`allowedDevOrigins`) and only recognize the "Local" origin they printed at boot
// (`localhost`, not `127.0.0.1`) — hitting either app's UI via 127.0.0.1 in a real
// browser hangs forever on the neutral "Loading…" frame with no console error, since
// hydration never completes. `localhost` is required here; the plain-fetch admin API
// client above is unaffected (no Origin-based dev-resource check on API routes).
const REGISTER = process.env.POS_REGISTER_URL || 'http://localhost:5174';
const BO = process.env.POS_BO_URL || 'http://localhost:5175';
const OUT = new URL('./assets/screenshots/', import.meta.url).pathname;
const ONLY = (process.argv.find(a => a.startsWith('--only=')) || '').split('=')[1];
mkdirSync(OUT, { recursive: true });

// ---- seeded credentials (dev-only, printed by the seeder; never real) ----
const ADMIN = { email: 'admin@pos.test', password: 'admin-dev-password' };
const PIN_CASHIER = '1111';    // Alice
const PIN_SUPERVISOR = '2222'; // Bob

// ---- tiny admin-API client (device enrolment is API-driven where the flow
//      isn't itself the thing being photographed) ----
async function api(path, { method = 'GET', token, body } = {}) {
  const res = await fetch(`${API}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status}: ${await res.text()}`);
  return (await res.json()).data;
}

async function adminToken() {
  return (await api('/admin/login', { method: 'POST', body: ADMIN })).token;
}

async function findRegister(admin, locationCode, name) {
  const locations = (await api('/admin/locations', { token: admin })).items;
  const location = locations.find(l => l.code === locationCode);
  if (!location) throw new Error(`no location ${locationCode} — reseed with all three catalogs`);
  const registers = (await api('/admin/registers', { token: admin })).items;
  const register = registers.find(r => r.location_id === location.id && r.name === name);
  if (!register) throw new Error(`no register ${name} at ${locationCode}`);
  return register;
}

const issueCode = (admin, id) =>
  api(`/admin/registers/${id}/activation-code`, { method: 'POST', token: admin });
const activate = (code) =>
  api('/registers/activate', { method: 'POST', body: { activation_code: code } });

async function shot(page, file) {
  await page.waitForTimeout(400); // let transitions settle
  await page.screenshot({ path: `${OUT}${file}.png` });
  console.log('  ✓', file);
}

const browser = await chromium.launch();

// ============================== REGISTER LEG ==============================
async function registerLeg() {
  const admin = await adminToken();

  // --- retail (GRC Till 1), enrolled through the real activation UI ---
  const grcTill1 = await findRegister(admin, 'GRC', 'Till 1');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(REGISTER);
  await page.getByText('Activation code').waitFor();
  await shot(page, '001-activation');

  const issued = await issueCode(admin, grcTill1.id);
  await page.getByPlaceholder('XXXXX-XXXXX').fill(issued.activation_code);
  await page.getByRole('button', { name: 'Activate' }).click();

  await page.getByText('Enter PIN').waitFor();
  await shot(page, '002-pin');
  await page.getByPlaceholder('••••').fill(PIN_SUPERVISOR);
  await page.getByRole('button', { name: 'Clock in' }).click();

  await page.getByText('Open shift').waitFor();
  await shot(page, '003-open-shift');
  await page.getByLabel('Opening float').fill('200.00');
  await page.getByRole('button', { name: 'Open drawer' }).click();

  const scan = page.getByPlaceholder('Scan or type a barcode…');
  await scan.waitFor();
  await shot(page, '004-retail-empty');

  for (const barcode of ['4809990000016', '4809990000023', '4809990000030']) {
    await scan.fill(barcode);
    await scan.press('Enter');
    await page.waitForTimeout(300);
  }
  await shot(page, '005-retail-cart');

  await page.getByRole('button', { name: 'Discount' }).click();
  await page.getByPlaceholder('Reason (required)…').waitFor();
  await shot(page, '006-retail-discount');
  await page.getByPlaceholder('Reason (required)…').fill('manager comp');
  await page.getByRole('button', { name: '10% off' }).click();
  await page.waitForTimeout(300);
  await shot(page, '007-retail-discounted');

  await page.getByRole('button', { name: /^Pay — / }).click();
  await page.getByRole('group', { name: 'Payment method' }).waitFor();
  await page.getByLabel(/^Cash tendered/).fill('500.00');
  await shot(page, '008-retail-tender');
  await page.getByRole('button', { name: 'Take payment' }).click();
  await page.getByText('Payment complete').waitFor();
  await shot(page, '009-retail-receipt');
  await page.getByRole('button', { name: 'New sale' }).click();

  await page.getByRole('button', { name: 'Refunds' }).click();
  await page.waitForTimeout(400);
  await shot(page, '010-refunds');
  await page.getByRole('button', { name: 'Register' }).click();

  // --- lockout: issuing a new code kills this device's token ---
  await issueCode(admin, grcTill1.id);
  await scan.fill('4809990000016');
  await scan.press('Enter'); // 401 -> stage 'disabled'
  await page.getByText('Terminal disabled').waitFor();
  await shot(page, '020-disabled');
  await ctx.close();

  // --- food (RST Till 1), enrolled via API + injected token so the manual's
  //     activation figures stay the retail ones ---
  const rstTill1 = await findRegister(admin, 'RST', 'Till 1');
  const rstIssued = await issueCode(admin, rstTill1.id);
  const activated = await activate(rstIssued.activation_code);
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page2 = await ctx2.newPage();
  await ctx2.addInitScript(([token, info]) => {
    localStorage.setItem('pos.device_token', token);
    localStorage.setItem('pos.register_info', JSON.stringify(info));
  }, [activated.device_token, activated.register]);

  await page2.goto(REGISTER);
  await page2.getByText('Enter PIN').waitFor();
  await page2.getByPlaceholder('••••').fill(PIN_CASHIER);
  await page2.getByRole('button', { name: 'Clock in' }).click();
  await page2.getByText('Open shift').waitFor();
  await page2.getByLabel('Opening float').fill('200.00');
  await page2.getByRole('button', { name: 'Open drawer' }).click();
  await page2.waitForTimeout(600);
  await shot(page2, '012-menu-grid');

  await page2.getByRole('button', { name: 'Tabs' }).click();
  await page2.waitForTimeout(400);
  await shot(page2, '011-floor');

  await page2.getByRole('button', { name: 'New tab' }).click();
  await page2.getByPlaceholder('Table (optional)…').fill('T1');
  await page2.getByRole('button', { name: 'Open tab' }).click();
  await page2.waitForTimeout(400);

  await page2.getByRole('button', { name: 'Chicken Adobo' }).click();
  await page2.getByRole('button', { name: 'Garlic Rice' }).click();
  await page2.getByRole('button', { name: 'Extra Egg' }).click();
  await shot(page2, '013-modifier-sheet');
  await page2.getByRole('button', { name: /^Add/ }).click();
  await page2.waitForTimeout(300);
  await page2.getByRole('button', { name: 'Halo-Halo' }).click().catch(() => {});
  await page2.waitForTimeout(300);
  await shot(page2, '014-tab-cart');

  await page2.getByRole('button', { name: 'Split bill' }).click();
  await page2.getByRole('group', { name: 'Number of checks' }).waitFor();
  await shot(page2, '015-split');
  await page2.getByRole('button', { name: 'Cancel' }).click();

  await page2.getByRole('button', { name: 'Tabs' }).click();
  await page2.getByRole('button', { name: 'Transfer' }).first().click();
  await page2.getByText('Send to').waitFor();
  await shot(page2, '016-transfer');
  await page2.keyboard.press('Escape');

  await page2.getByRole('button', { name: 'Register' }).click();
  await page2.getByRole('button', { name: 'Close shift' }).click();
  await page2.getByText('Close shift — count the drawer').waitFor();
  await shot(page2, '017-close-shift');
  await page2.getByLabel('Counted cash').fill('150.00'); // deliberately short -> variance needs approval
  await page2.getByRole('button', { name: 'Close', exact: true }).click();
  await page2.getByText('Drawer reconciled').waitFor();
  await shot(page2, '018-z-report');
  await ctx2.close();
}

// ============================== BACK-OFFICE LEG ==============================
async function boLeg() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(BO);
  await page.getByText('Sign in').first().waitFor();
  await shot(page, '021-bo-login');
  await page.locator('#admin-email').fill(ADMIN.email);
  await page.locator('#admin-password').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const nav = (name) => page.getByRole('navigation', { name: 'Sections' }).getByRole('button', { name });

  await page.getByText('Today').first().waitFor();
  await page.waitForTimeout(800);
  await shot(page, '022-bo-today');

  await nav('Catalog').click();
  await page.waitForTimeout(600);
  await shot(page, '023-bo-catalog');
  // Locator fix: same as the registers table below — EntityTable rows have no
  // click handler of their own, only the row's "Edit" button opens the editor.
  await page.getByRole('row', { name: /Chicken Adobo/ }).getByRole('button', { name: 'Edit' }).click();
  await page.getByText('Edit product').waitFor();
  await page.waitForTimeout(400);
  await shot(page, '024-bo-product');

  await nav('Users').click();
  await page.waitForTimeout(600);
  await shot(page, '025-bo-users');

  await nav('Locations & Registers').click();
  await page.waitForTimeout(600);
  await shot(page, '027-bo-locations');
  await page.getByRole('tab', { name: 'Registers' }).click();
  await page.waitForTimeout(400);
  await shot(page, '028-bo-registers');

  // RST Till 2 is not used by the register leg, so revoking its token is harmless.
  // (Locator fix: the register table has no row-level click handler — only its "Edit"
  // button opens the editor — and every location has its own "Till 2", so the row must
  // be disambiguated by location name too, not just Till 2 .last().)
  await page
    .getByRole('row', { name: 'Till 2' })
    .filter({ hasText: 'Manila Restaurant' })
    .getByRole('button', { name: 'Edit' })
    .click();
  await page.getByRole('button', { name: 'Issue activation code' }).waitFor();
  await shot(page, '029-bo-register-editor');
  await page.getByRole('button', { name: 'Issue activation code' }).click();
  await page.getByRole('button', { name: 'Issue code' }).click();
  await page.getByText('Activation code — single use').waitFor();
  await shot(page, '030-bo-activation-code');

  await nav('Reports').click();
  await page.waitForTimeout(800);
  await shot(page, '031-bo-report-sales');
  await page.getByRole('tab', { name: 'Stock' }).click();
  await page.waitForTimeout(600);
  await shot(page, '032-bo-report-stock');

  await nav('Audit').click();
  await page.waitForTimeout(600);
  await shot(page, '033-bo-audit');
  await ctx.close();
}

// ============================== RUN + ORPHAN CHECK ==============================
try {
  if (ONLY !== 'bo') await registerLeg();
  if (ONLY !== 'register') await boLeg();
} finally {
  await browser.close();
}

// Referenced-vs-captured report (spec acceptance): read the four md sources.
const ROOT = new URL('./', import.meta.url).pathname;
const referenced = new Set();
for (const f of ['user-manual.md', 'troubleshooting.md', 'faq.md', 'glossary.md']) {
  if (!existsSync(ROOT + f)) continue;
  for (const m of readFileSync(ROOT + f, 'utf8').matchAll(/\]\(assets\/screenshots\/([^)]+)\)/g)) {
    referenced.add(m[1]);
  }
}
const captured = new Set(readdirSync(OUT).filter(f => f.endsWith('.png')));
const missing = [...referenced].filter(f => !captured.has(f));
const unreferenced = [...captured].filter(f => !referenced.has(f));
if (missing.length) console.error('referenced but MISSING:', missing.join(', '));
if (unreferenced.length) console.log('captured but unreferenced (fine while chapters are unwritten):', unreferenced.join(', '));
console.log(`done — ${captured.size} screenshots on disk, ${referenced.size} referenced`);
process.exitCode = missing.length ? 1 : 0;
