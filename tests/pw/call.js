// Playwright: на узких телефонах в первом экране видна ссылка tel: в шапке, бургер не обрезан справа.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/call.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/'];
const SIZES = [[360, 740], [375, 812], [390, 844]];

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    for (const [w, h] of SIZES) {
      const page = await browser.newPage({ viewport: { width: w, height: h } });
      for (const u of PAGES) {
        const r = await page.goto(BASE + u);
        await page.waitForTimeout(150);
        const m = await page.evaluate(() => {
          const box = (el) => { const b = el.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, left: b.left, right: b.right, w: b.width, h: b.height }; };
          const W = innerWidth, H = innerHeight;
          const tel = [...document.querySelectorAll('a[href^="tel:"]')].map(box)
            .find((b) => b.w > 0 && b.h > 0 && b.top >= 0 && b.bottom <= H && b.left >= 0 && b.right <= W);
          const call = document.querySelector('[data-hcall]');
          return { W, H, tel, call: call && box(call), label: call && call.getAttribute('aria-label'),
            burger: box(document.querySelector('[data-burger]')) };
        });
        const ok = r.status() === 200 && m.tel && m.label && m.call.w > 0 && m.burger.w > 0 && m.burger.right <= m.W && m.burger.left >= 0;
        console.log(`/${u} @${w}x${h}: HTTP ${r.status()} tel=${m.tel ? `${m.tel.left}..${m.tel.right} y${m.tel.top}..${m.tel.bottom}` : 'нет'} call="${m.label}" burger=${m.burger.left}..${m.burger.right} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
      }
      await page.close();
    }
  } finally {
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
