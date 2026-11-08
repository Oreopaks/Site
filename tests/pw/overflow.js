// Playwright: нет горизонтального переполнения (scrollWidth <= clientWidth) на 320, 360, 390 и 1440.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/overflow.js
// Создаёт владельца в RDS_DB (страницы админки после входа); на время «/ с баннером» ставит metrika_id=12345, потом ''.
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const setMetrika = (v) => execFileSync('php', ['-r',
  '(new PDO("sqlite:".$argv[1]))->prepare("UPDATE settings SET value=? WHERE key=\'metrika_id\'")->execute([$argv[2]]);', DB, v]);
const LOGIN = 'pwover' + Date.now() % 100000, PASS = 'pw overflow password 1';
execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), LOGIN, 'owner'], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });

const PUBLIC = ['', 'privacy.php', 'consent.php', 'no-such-page', 'admin/?p=login'];
const ADMIN = ['home', 'content', 'content&t=fleet_prices', 'settings', 'leads', 'stats', 'users', 'audit'].map((p) => 'admin/?p=' + p);

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const probe = async (page, url, w, label = url) => {
    const r = await page.goto(BASE + url);
    await page.waitForTimeout(200);
    const [sw, cw] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    const ok = sw <= cw;
    console.log(`/${label} @${w}: HTTP ${r.status()} scrollWidth=${sw} clientWidth=${cw} ${ok ? 'pass' : 'FAIL'}`);
    if (!ok) fail++;
  };
  try {
    for (const w of [320, 360, 390, 1440]) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
      await page.route(/mc\.yandex\./, (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
      for (const u of PUBLIC) await probe(page, u, w);
      setMetrika('12345');
      await probe(page, '', w, ' (ID задан, баннер виден)');
      setMetrika('');
      await page.goto(BASE + 'admin/?p=login');
      await page.fill('[name=login]', LOGIN);
      await page.fill('[name=password]', PASS);
      await Promise.all([page.waitForNavigation(), page.click('form.login button')]);
      for (const u of ADMIN) await probe(page, u, w);
      await page.close();
    }
  } finally {
    setMetrika('');
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
