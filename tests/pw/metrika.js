// Playwright: Метрика и cookie-баннер (план §6, сценарии A и B).
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/metrika.js
// Пишет в БД стенда (metrika_id, consent_stats) — только тестовая БД; metrika_id в конце возвращается в ''.
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
// SQL в БД стенда; результат — JSON всех строк
const sql = (q, ...args) => JSON.parse(execFileSync('php', ['-r',
  '$p=new PDO("sqlite:".$argv[1]); $s=$p->prepare($argv[2]); $s->execute(array_slice($argv,3)); echo json_encode($s->fetchAll(PDO::FETCH_NUM));',
  DB, q, ...args]).toString());
const today = execFileSync('php', ['-r', 'echo gmdate("Y-m-d");']).toString();
const rev = sql("SELECT value FROM settings WHERE key='cookie_text_rev'")[0][0];

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const open = async () => {
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    const ym = [];
    // наружу не ходим: любой запрос к mc.yandex.ru считается и получает пустой 200
    await page.route(/mc\.yandex\./, (r) => { ym.push(r.request().url()); r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }); });
    return { page, ym };
  };
  const beacon = (page) => page.waitForRequest((r) => r.url().endsWith('/consent.php') && r.method() === 'POST');
  const row = async (choice) => {
    for (let i = 0; i < 20; i++) {
      const r = sql('SELECT day, choice, text_rev, n FROM consent_stats WHERE choice = ?', choice);
      if (r.length) return r;
      await new Promise((ok) => setTimeout(ok, 100));
    }
    return [];
  };

  try {
    // A: ID пуст
    sql("UPDATE settings SET value = '' WHERE key = 'metrika_id'");
    let { page, ym } = await open();
    await page.goto(BASE);
    await page.waitForTimeout(500);
    check('A: ID пуст → нет #cookie-bar', await page.locator('#cookie-bar').count() === 0);
    check('A: ID пуст → 0 запросов mc.yandex.ru', ym.length === 0, ym.join(' '));
    check('A: ID пуст → нет «Настройки cookie»', await page.getByText('Настройки cookie').count() === 0);
    await page.close();

    // B: ID=12345
    sql("UPDATE settings SET value = '12345' WHERE key = 'metrika_id'");
    sql('DELETE FROM consent_stats');
    ({ page, ym } = await open());
    await page.goto(BASE);
    await page.waitForTimeout(500);
    check('B: баннер виден', await page.locator('#cookie-bar').isVisible());
    check('B: до выбора 0 запросов mc.yandex.ru', ym.length === 0, ym.join(' '));

    await Promise.all([beacon(page), page.click('#cookie-reject')]);
    await page.waitForTimeout(300);
    check('B: «Отклонить» → баннер скрыт, 0 запросов, disableYaCounter12345', !(await page.locator('#cookie-bar').isVisible())
      && ym.length === 0 && await page.evaluate(() => window.disableYaCounter12345 === true), ym.join(' '));
    await page.reload();
    await page.waitForTimeout(500);
    check('B: reload после «Отклонить» → бара нет, 0 запросов', !(await page.locator('#cookie-bar').isVisible()) && ym.length === 0, ym.join(' '));
    const rj = await row('reject');
    check(`B: consent_stats (${today},'reject',${rev},1)`, JSON.stringify(rj) === JSON.stringify([[today, 'reject', rev, 1]]), JSON.stringify(rj));

    await page.evaluate(() => localStorage.clear());
    await page.reload();
    await page.waitForTimeout(300);
    check('B: localStorage.clear() → баннер снова', await page.locator('#cookie-bar').isVisible());
    await Promise.all([beacon(page), page.waitForRequest(/mc\.yandex\.ru\/metrika\/tag\.js/), page.click('#cookie-accept')]);
    check('B: «Принять» → ≥1 запрос tag.js', ym.filter((u) => u.includes('/metrika/tag.js')).length >= 1, ym.join(' '));
    const n = ym.length;
    await page.reload();
    await page.waitForTimeout(500);
    check('B: reload после «Принять» → бара нет, tag.js грузится сразу', !(await page.locator('#cookie-bar').isVisible()) && ym.length > n, `${n} → ${ym.length}`);
    const ac = await row('accept');
    check(`B: consent_stats (${today},'accept',${rev},1)`, JSON.stringify(ac) === JSON.stringify([[today, 'accept', rev, 1]]), JSON.stringify(ac));

    // «Настройки cookie» в подвале → выбор сброшен, баннер снова
    await Promise.all([page.waitForNavigation(), page.getByText('Настройки cookie').click()]);
    await page.waitForTimeout(300);
    check('B: «Настройки cookie» → баннер снова', await page.locator('#cookie-bar').isVisible());
  } finally {
    sql("UPDATE settings SET value = '' WHERE key = 'metrika_id'");
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
