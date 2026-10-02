// RSS Media Hub – browser E2E (mobile rendering, admin flow, CSRF, headers, age gate).
// Usage: node tests/e2e/run.mjs   (env: RMH_BASE, RMH_USER, RMH_PASS, RMH_MEDIA_DIR, RMH_SHOTS, CHROMIUM)
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.RMH_BASE || 'http://localhost:8089';
const USER = process.env.RMH_USER || 'admin';
const PASS = process.env.RMH_PASS || 'admin-pass-123';
const MEDIA = process.env.RMH_MEDIA_DIR;
const SHOTS = process.env.RMH_SHOTS || 'e2e-shots';
const MOBILE = { width: 390, height: 844 };
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}

// Serve synthetic media for the fictional hosts used by the fixtures (no real network access).
async function routeMedia(ctx) {
  await ctx.route(/^https:\/\/[^/]*\.example\//, (route) => {
    const url = route.request().url();
    const file = (name, type) => route.fulfill({ status: 200, contentType: type, body: fs.readFileSync(path.join(MEDIA, name)) });
    if (url.endsWith('.webm')) return file('clip.webm', 'video/webm');
    if (url.endsWith('.mp4')) return file('clip.mp4', 'video/mp4');
    if (/poster|\.jpg|\.png|\.webp/.test(url)) {
      const pick = ['img-cc6633.jpg', 'img-33aa66.jpg', 'img-aa3366.jpg', 'img-6633cc.jpg'][url.length % 4];
      return file(url.includes('poster') ? 'poster.jpg' : pick, 'image/jpeg');
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await ctx.route(/youtube-nocookie\.com|vimeo\.com|dailymotion\.com/, (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><body style="margin:0;background:#111;color:#eee;font:16px sans-serif;display:grid;place-items:center;height:100vh">Embedded player (stub)</body>' }));
}

async function noHorizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

// ---------------------------------------------------------------- admin flow (desktop)
const admin = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await admin.newPage();
// The sandbox has no internet access: ignore failures to reach third-party hosts (gravatar, wordpress.org),
// but record every other console error and every failing same-origin request.
const sandboxNoise = (t) => /ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED/.test(t);
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error' && !sandboxNoise(m.text()) && !/Failed to load resource/.test(m.text())) consoleErrors.push(m.text()); });
page.on('response', (r) => { if (r.url().startsWith(BASE) && r.status() >= 400 && !r.url().includes('/wp-json/') && !r.url().includes('admin-post.php')) consoleErrors.push(`${r.status()} ${r.url()}`); });

await page.goto(`${BASE}/wp-login.php`);
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await page.click('#wp-submit');
await page.waitForURL(/wp-admin/);
check('admin login', page.url().includes('/wp-admin/'));

// Every admin page renders a heading (no blank/placeholder pages).
for (const slug of ['dashboard', 'feeds', 'queue', 'media', 'moderation', 'restrictions', 'settings', 'logs']) {
  const r = await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-${slug}`);
  const h1 = (await page.locator('.wrap h1').first().textContent()) || '';
  check(`admin page rmh-${slug}`, r.status() === 200 && h1.trim().length > 0, h1.trim());
}

// Add feeds through the real form (nonce-protected).
for (const [url, name] of [['https://mrss.example/feed', 'MRSS Network'], ['https://embeds.example/feed', 'Embeds'], ['https://photos.example/feed', 'Photo Sets'], ['https://audio.example/feed', 'Night Stories']]) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-feeds&action=new`);
  await page.fill('#rmh-url', url);
  await page.fill('#rmh-name', name);
  await page.selectOption('select[name="config[import_mode]"]', 'auto');
  await page.click('button:has-text("Save feed")');
  await page.waitForURL(/action=edit/);
  check(`add feed via form: ${name}`, (await page.locator('.notice-success').count()) > 0);
}

// "Test" button = dry run.
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-feeds`);
await page.locator('tr:has-text("MRSS Network") button:has-text("Test")').click();
await page.waitForURL(/action=test/);
check('feed test (dry run) report', (await page.locator('text=Feed is valid').count()) > 0);

// "Run all feeds now" queues a background run; execute WP-Cron like a system cron would.
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-dashboard`);
await page.click('button:has-text("Run all feeds now")');
await page.waitForLoadState();
const cron = await page.request.get(`${BASE}/wp-cron.php`); // exactly what a system cron job calls
check('wp-cron.php executes queued run', cron.status() === 200);
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-dashboard`);
const published = await page.locator('.rmh-card:has-text("Published posts") .rmh-card-num').textContent();
check('posts imported by scheduled run', parseInt(published, 10) >= 4, `published=${published}`);
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-logs&tab=jobs`);
check('import history recorded', (await page.locator('table.rmh-table tbody tr').count()) >= 4);

// REST: cookie auth without nonce is refused; with nonce it works; responses are not cacheable.
const noNonce = await page.request.post(`${BASE}/wp-json/rmh/v1/feeds`, { data: { url: 'https://csrf.example/feed' } });
check('REST cookie request without nonce rejected (CSRF)', noNonce.status() === 401, `status=${noNonce.status()}`);
const nonce = await (await page.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text();
const withNonce = await page.request.get(`${BASE}/wp-json/rmh/v1/status`, { headers: { 'X-WP-Nonce': nonce } });
check('REST with nonce succeeds', withNonce.status() === 200);
check('REST responses are no-store', (withNonce.headers()['cache-control'] || '').includes('no-store'), withNonce.headers()['cache-control']);
const forged = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'rmh_feed_action', op: 'delete', id: '1', _rmh_nonce: 'forged' } });
check('admin-post with forged nonce rejected', forged.status() === 403, `status=${forged.status()}`);

// Admin on a phone.
const adminMobile = await browser.newContext({ viewport: MOBILE, storageState: await admin.storageState(), isMobile: true, hasTouch: true });
const am = await adminMobile.newPage();
await am.goto(`${BASE}/wp-admin/admin.php?page=rmh-dashboard`);
await am.screenshot({ path: `${SHOTS}/admin-dashboard-mobile.png`, fullPage: true });
check('admin dashboard fits mobile width', await noHorizontalOverflow(am));
await am.goto(`${BASE}/wp-admin/admin.php?page=rmh-queue&status=all`);
await am.screenshot({ path: `${SHOTS}/admin-queue-mobile.png`, fullPage: false });
check('admin queue table stacks on mobile', await noHorizontalOverflow(am));

// ---------------------------------------------------------------- public reader (mobile)
const visitor = await browser.newContext({ viewport: MOBILE, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
await routeMedia(visitor);
const v = await visitor.newPage();
const vErrors = [];
v.on('console', (m) => { if (m.type() === 'error' && !sandboxNoise(m.text()) && !/Failed to load resource/.test(m.text())) vErrors.push(m.text()); });
v.on('response', (r) => { if (r.url().startsWith(BASE) && r.status() >= 400 && !r.url().endsWith('favicon.ico')) vErrors.push(`${r.status()} ${r.url()}`); });

let res = await v.goto(`${BASE}/media/`);
check('archive /media/ responds 200', res.status() === 200);
const cards = await v.locator('.rmh-card').count();
check('archive shows media cards', cards >= 4, `cards=${cards}`);
check('archive fits mobile width', await noHorizontalOverflow(v));
check('archive has no inline players', (await v.locator('.rmh-reader video, .rmh-reader iframe').count()) === 0);
await v.screenshot({ path: `${SHOTS}/archive-mobile.png`, fullPage: true });

await v.goto(`${BASE}/media/?view=list&mtype=video`);
check('filter by media type + list view', (await v.locator('.rmh-card--list').count()) >= 1 && (await v.locator('.rmh-card').count()) === (await v.locator('.rmh-badge--video').count()));

res = await v.goto(`${BASE}/media/feature-presentation/`);
check('single video post 200', res.status() === 200);
const csp = res.headers()['content-security-policy'] || '';
check('frame-src CSP limited to allowlisted providers', csp.includes('frame-src https://www.youtube-nocookie.com') && !csp.includes('*'), csp);
const video = v.locator('video.rmh-video');
await video.waitFor();
const box = await video.boundingBox();
check('video player visible and within viewport', box && box.width > 300 && box.x >= 0 && box.x + box.width <= MOBILE.width + 1, JSON.stringify(box));
const ready = await v.evaluate(async () => {
  const el = document.querySelector('video.rmh-video');
  for (let i = 0; i < 50 && el.readyState < 1; i++) await new Promise((r) => setTimeout(r, 100));
  return { readyState: el.readyState, src: el.currentSrc, playsinline: el.hasAttribute('playsinline'), w: el.videoWidth };
});
check('video loads metadata (HTML5 player works)', ready.readyState >= 1 && ready.w > 0, JSON.stringify(ready));
check('video uses playsinline for iOS', ready.playsinline);
check('single post fits mobile width', await noHorizontalOverflow(v));
check('attribution visible', await v.locator('.rmh-attribution a').isVisible());
await v.screenshot({ path: `${SHOTS}/single-video-mobile.png`, fullPage: true });

// Embed post.
await v.goto(`${BASE}/media/trusted-provider/`);
const frame = v.locator('iframe.rmh-embed');
await frame.waitFor();
const fbox = await frame.boundingBox();
check('embed iframe sized to viewport', fbox && fbox.width <= MOBILE.width && fbox.height > 150, JSON.stringify(fbox));
check('embed iframe is sandboxed', ((await frame.getAttribute('sandbox')) || '').includes('allow-scripts'));
await v.goto(`${BASE}/media/untrusted-iframe/`);
check('untrusted iframe not rendered', (await v.locator('iframe').count()) === 0);
await v.screenshot({ path: `${SHOTS}/untrusted-iframe-fallback-mobile.png`, fullPage: true });

// Gallery + keyboard-accessible lightbox.
await v.goto(`${BASE}/media/set-7-golden-hour/`);
const thumbs = v.locator('.rmh-gallery a');
check('gallery renders thumbnails', (await thumbs.count()) === 4);
await v.screenshot({ path: `${SHOTS}/gallery-mobile.png`, fullPage: true });
await thumbs.first().click();
const lb = v.locator('.rmh-lightbox');
check('lightbox opens', await lb.isVisible());
await v.keyboard.press('ArrowRight');
check('lightbox keyboard navigation', ((await v.locator('.rmh-lightbox-count').textContent()) || '').startsWith('2 /'));
await v.screenshot({ path: `${SHOTS}/lightbox-mobile.png` });
await v.keyboard.press('Escape');
check('lightbox closes with Escape', await lb.isHidden());

// Audio post.
await v.goto(`${BASE}/media/episode-12/`);
check('audio player rendered', (await v.locator('audio[controls]').count()) === 1);

// Report form (JS submit with status message).
await v.goto(`${BASE}/media/feature-presentation/`);
await v.locator('.rmh-report summary').click();
await v.selectOption('.rmh-report-form select[name="reason"]', 'broken_media');
await v.click('.rmh-report-form button[type="submit"]');
await v.waitForFunction(() => { const t = document.querySelector('.rmh-report-form .rmh-form-status').textContent; return t.length > 0 && !/Sending/.test(t); });
const statusText = await v.locator('.rmh-report-form .rmh-form-status').textContent();
check('report form submits in place', /Thank you/.test(statusText), statusText);

// Feed directory.
res = await v.goto(`${BASE}/media/sources/`);
check('feed directory page', res.status() === 200 && (await v.locator('.rmh-source').count()) >= 4);
check('directory does not expose feed URLs', !(await v.content()).includes('mrss.example/feed'));

check('no console errors on public pages', vErrors.length === 0, vErrors.join(' | '));

// ---------------------------------------------------------------- age gate (server mode)
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-settings`);
await page.check('#rmh-age_gate_enabled');
await page.selectOption('#rmh-age_gate_scope', 'all');
await page.selectOption('#rmh-age_gate_mode', 'server');
await page.click('#submit');
await page.waitForLoadState();
check('settings saved via Settings API', (await page.locator('#setting-error-settings_updated, .notice-success, .updated').count()) > 0);

const anon = await browser.newContext({ viewport: MOBILE, isMobile: true });
await routeMedia(anon);
const g = await anon.newPage();
res = await g.goto(`${BASE}/media/feature-presentation/`);
const gateHtml = await g.content();
check('age gate shown to unverified visitor', (await g.locator('.rmh-agegate').count()) === 1);
check('no media URL leaked before verification', !gateHtml.includes('1080.webm') && !gateHtml.includes('<video'));
check('gate response not cacheable', (res.headers()['cache-control'] || '').includes('no-store'), res.headers()['cache-control']);
await g.screenshot({ path: `${SHOTS}/age-gate-mobile.png` });
await g.click('button[name="confirm"]');
await g.waitForLoadState();
check('after confirmation the media page loads', (await g.locator('video.rmh-video').count()) === 1, g.url());

// Restore settings.
await page.goto(`${BASE}/wp-admin/admin.php?page=rmh-settings`);
await page.uncheck('#rmh-age_gate_enabled');
await page.click('#submit');

check('no console errors in admin', consoleErrors.length === 0, consoleErrors.join(' | '));
await browser.close();

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
fs.writeFileSync(path.join(SHOTS, 'results.json'), JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);
