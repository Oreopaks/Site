// Playwright: на посадочных в первом экране (без скролла) — чип с ценой «₽» и ссылка tel: с номером цифрами, без горизонтального переполнения; на / чипов нет.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/fold.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const SIZES = [[360, 740], [390, 844]];

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    for (const [w, h] of SIZES) {
      const page = await browser.newPage({ viewport: { width: w, height: h } });
      for (const u of ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/']) {
        const r = await page.goto(BASE + u);
        await page.waitForTimeout(150);
        const m = await page.evaluate(() => {
          const W = innerWidth, H = innerHeight;
          const inView = (el) => { const b = el.getBoundingClientRect(); return b.width > 0 && b.top >= 0 && b.bottom <= H && b.left >= 0 && b.right <= W; };
          const chips = [...document.querySelectorAll('[data-lp-chips] li')];
          const tel = [...document.querySelectorAll('a[href^="tel:"]')]
            .find((a) => /\d{3}-\d{2}-\d{2}/.test(a.textContent) && inView(a));
          return { n: chips.length, price: chips.filter((li) => li.textContent.includes('₽') && inView(li)).length,
            tel: tel && tel.textContent.trim(), ovf: document.documentElement.scrollWidth - W };
        });
        const ok = r.status() === 200 && m.ovf <= 0 && (u === '' ? m.n === 0 : m.price === m.n && m.n > 0 && !!m.tel);
        console.log(`/${u} @${w}x${h}: HTTP ${r.status()} chips=${m.n} inView=${m.price} tel="${m.tel || ''}" ovf=${m.ovf} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
      }
      await page.close();
    }
  } finally {
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
