// Playwright: цена, изменённая владельцем в админке, видна на /.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/price.js
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const LOGIN = 'pwowner' + Date.now() % 100000, PASS = 'pw owner password 1';
execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), LOGIN, 'owner'], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const page = await browser.newPage({ reducedMotion: 'reduce' });

  await page.goto(BASE + 'admin/');
  await page.fill('[name=login]', LOGIN);
  await page.fill('[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('form.login button')]);
  check('владелец вошёл', page.url().includes('p=home'), page.url());

  await page.goto(BASE + 'admin/?p=content&t=fleet_prices');
  const row = page.locator('form.fields').filter({ has: page.locator('input[name=model][value="Hyundai 170W-7"]') });
  await row.locator('[name=price]').fill('99999');
  await Promise.all([page.waitForNavigation(), row.locator('button').click()]);
  check('сохранено', await page.locator('p.ok').isVisible());
  await Promise.all([page.waitForNavigation(), page.click('form[action*="a=publish"] button')]); // сохранение — в черновик; на / только после «Опубликовать»

  await page.goto(BASE);
  const dd = page.locator('#fleet dl > div', { hasText: 'Hyundai 170W-7' });
  const txt = (await dd.locator('dd').innerText()).replace(/ /g, ' ');
  check('на / видно «99 999 ₽»', txt.includes('99 999 ₽') && await dd.isVisible(), txt);

  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
