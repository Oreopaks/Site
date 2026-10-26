// Playwright: заявка из формы «Контакты»; затем владелец видит её в /admin/?p=leads.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/lead.js
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const leadsBy = (name) => Number(execFileSync('php', ['-r',
  '$p=new PDO("sqlite:".$argv[1]); $s=$p->prepare("SELECT COUNT(*) FROM leads WHERE name=?"); $s->execute([$argv[2]]); echo $s->fetchColumn();',
  DB, name]).toString());
const LOGIN = 'pwlead' + Date.now() % 100000, PASS = 'pw lead password 1';
execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), LOGIN, 'owner'], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const page = await browser.newPage({ reducedMotion: 'reduce' });
  const sends = [];
  page.on('request', (r) => { if (r.url().includes('send.php')) sends.push(r); });
  await page.goto(BASE);

  const name = 'PW ' + Date.now();
  const form = page.locator('#contacts form');
  await form.locator('[autocomplete=name]').fill(name);
  await form.locator('[autocomplete=tel]').fill('+7 980 000-00-00');
  await form.locator('button[type=submit]').click();
  await page.waitForTimeout(1000);
  check('без галочки → 0 запросов к send.php', sends.length === 0, String(sends.length));

  await form.locator('input[type=checkbox]').check();
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('send.php')),
    form.locator('button[type=submit]').click(),
  ]);
  const body = await resp.json();
  check('с галочкой → {ok:true}', body.ok === true, JSON.stringify(body));
  await page.locator('#formSuccess').waitFor({ state: 'visible' });
  check('строка в leads', leadsBy(name) === 1, String(leadsBy(name)));

  await page.goto(BASE + 'admin/');
  await page.fill('[name=login]', LOGIN);
  await page.fill('[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('form.login button')]);
  await page.goto(BASE + 'admin/?p=leads');
  const row = page.locator('table.grid tbody tr', { hasText: name });
  check('владелец видит заявку в /admin/?p=leads', await row.count() === 1 && await row.isVisible()
    && (await row.innerText()).includes('+7 980 000-00-00'), page.url());

  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
