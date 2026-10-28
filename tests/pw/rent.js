// Playwright: «В АРЕНДУ →» на карточке техники — кнопка: клик и Tab+Enter открывают форму расчёта
// с услугой «Аренда спецтехники» и названием техники; заявка уходит как calc + service + comment.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/rent.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PAGES = ['', 'arenda-spetstekhniki/'];
const SIZES = [[390, 844], [1440, 900]];
const SVC = 'Аренда спецтехники';

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const modal = (page) => page.evaluate(() => ({
    open: getComputedStyle(document.getElementById('modalOverlay')).display !== 'none',
    lbl: (document.getElementById('modalMatLbl') || {}).textContent,
    name: document.getElementById('modalMatName').textContent,
    shown: document.getElementById('modalMat').style.display !== 'none',
  }));
  try {
    for (const [w, h] of SIZES) {
      const page = await browser.newPage({ viewport: { width: w, height: h } });
      let post = null;
      await page.route('**/send.php', (r) => { post = r.request().postData() || ''; r.fulfill({ contentType: 'application/json', body: '{"ok":true}' }); });
      for (const u of PAGES) {
        const at = `/${u} @${w}`;
        await page.goto(BASE + u);
        const btn = page.locator('[data-lift]').first().getByText('В АРЕНДУ', { exact: false });
        const title = (await page.locator('[data-lift] h3').first().textContent()).trim();
        const tag = await btn.evaluate((el) => el.tagName + '/' + el.type).catch(() => 'нет');
        // клик
        await btn.click();
        await page.waitForTimeout(100);
        let m = await modal(page);
        let ok = tag === 'BUTTON/button' && m.open && m.shown && m.lbl === SVC && m.name === title;
        console.log(`${at} click: tag=${tag} open=${m.open} «${m.lbl}: ${m.name}» (ждём «${SVC}: ${title}») ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
        // заявка из этой формы
        post = null;
        const f = page.locator('#modalForm form');
        await f.locator('[autocomplete=name]').fill('Тест аренда');
        await f.locator('[autocomplete=tel]').fill('8 900 000-00-00');
        await f.locator('input[type=checkbox]').check();
        await f.locator('button[type=submit]').click();
        await page.waitForTimeout(200);
        ok = !!post && post.includes('name="source"\r\n\r\ncalc') && post.includes(`name="service"\r\n\r\n${SVC}`) && post.includes(`Техника: ${title}`);
        console.log(`${at} send: ${ok ? 'calc + service + comment' : String(post).slice(0, 200)} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
        // клавиатура: Tab до кнопки, видимый фокус, Enter открывает форму
        await page.goto(BASE + u);
        let hit = null;
        for (let i = 0; i < 400 && !hit; i++) {
          await page.keyboard.press('Tab');
          hit = await page.evaluate(() => { const a = document.activeElement;
            return a && /В АРЕНДУ/.test(a.textContent) && a.closest('[data-lift]') ? getComputedStyle(a).outlineStyle + ' ' + getComputedStyle(a).outlineWidth : null; });
        }
        if (hit) { await page.keyboard.press('Enter'); await page.waitForTimeout(100); }
        m = await modal(page);
        ok = !!hit && !/^none/.test(hit) && m.open && m.lbl === SVC && m.name === title;
        console.log(`${at} Tab: focus=${hit || 'не достигнут'} open=${m.open} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
      }
      await page.close();
    }
  } finally {
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
