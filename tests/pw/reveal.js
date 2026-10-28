// Playwright: карточки [data-anim] не прячутся scroll-reveal — через 100 ms после прокрутки всё видимое в viewport
// имеет opacity >= 0.99; прокрутка назад-вперёд не прячет. Главная и посадочные, 390x844.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/reveal.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/'];

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    for (const u of PAGES) {
      const r = await page.goto(BASE + u);
      await page.waitForTimeout(100);
      const n = await page.evaluate(() => document.querySelectorAll('[data-anim]').length);
      // цели: #fleet и прайс на главной; на посадочной — середина и низ страницы; затем назад к верху и снова вниз
      const steps = await page.evaluate(() => {
        const f = document.getElementById('fleet'), H = document.documentElement.scrollHeight;
        return f ? [f.offsetTop, f.offsetTop + f.offsetHeight - innerHeight, 0, f.offsetTop + 400] : [H / 2, H - innerHeight, 0, H / 2];
      });
      let bad = [], seen = 0;
      for (const y of steps) {
        await page.evaluate((y) => window.scrollTo(0, y), y);
        await page.waitForTimeout(100);
        const m = await page.evaluate(() => {
          const v = [...document.querySelectorAll('[data-anim]')].filter((el) => { const b = el.getBoundingClientRect(); return b.bottom > 0 && b.top < innerHeight && b.width > 0; });
          return { seen: v.length, bad: v.filter((el) => parseFloat(getComputedStyle(el).opacity) < 0.99).map((el) => el.tagName + ':' + el.textContent.trim().slice(0, 30)) };
        });
        seen += m.seen; bad = bad.concat(m.bad);
      }
      const ok = r.status() === 200 && n > 0 && seen > 0 && bad.length === 0;
      console.log(`/${u}: HTTP ${r.status()} data-anim=${n} видимых по шагам=${seen} скрытых=${bad.length}${bad.length ? ' ' + bad.slice(0, 3).join(' | ') : ''} ${ok ? 'pass' : 'FAIL'}`);
      if (!ok) fail++;
    }
  } finally { await browser.close(); }
  process.exit(fail ? 1 : 0);
})();
