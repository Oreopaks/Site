// Playwright: лок-апы «логотип + название» (шапка, «Почему мы», подвал) не ломаются на 320–1920:
// шапка без переполнения (header.scrollWidth <= clientWidth — overflow-x:clip обёртки прячет его от documentElement),
// каждая строка лок-апа (текст, Range) целиком во вьюпорте, строки шапки не переносятся,
// центр текстового блока по вертикали = центр логотипа ±1px, соседние строки не пересекаются.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/brand.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const WIDTHS = [320, 360, 390, 640, 641, 768, 1024, 1025, 1120, 1121, 1440, 1920];

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    for (const w of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
      for (const u of ['', 'arenda-spetstekhniki/']) {
        const r = await page.goto(BASE + u);
        await page.evaluate(() => document.fonts.ready);
        const out = [];
        let bad = r.status() !== 200;
        const hdr = await page.evaluate(() => { const h = document.querySelector('header'); return [h.scrollWidth, h.clientWidth]; });
        if (hdr[0] > hdr[1]) bad = true;
        out.push(`header sw=${hdr[0]} cw=${hdr[1]}`);
        for (const [name, sel] of [['header', 'header img[src*="logo-rds"]'], ['why', '#why img[src*="logo-rds"]'], ['footer', 'footer img[src*="logo-rds"]']]) {
          const loc = page.locator(sel).first();
          if (!(await loc.count())) { if (name !== 'why' || u === '') { bad = true; out.push(`${name}: нет`); } continue; }
          await loc.scrollIntoViewIfNeeded();
          await page.waitForTimeout(100);
          const m = await loc.evaluate((img) => {
            const W = document.documentElement.clientWidth, H = innerHeight;
            const box = img.nextElementSibling;
            const lines = box.children.length ? [...box.children] : [box];
            const L = lines.map((el) => {
              const rg = document.createRange(); rg.selectNodeContents(el);
              const t = rg.getBoundingClientRect(), e = el.getBoundingClientRect();
              const rows = new Set([...rg.getClientRects()].filter((q) => q.width > 0).map((q) => Math.round(q.top))).size;
              return { t: el.textContent.trim(), in: t.left >= 0 && t.right <= W && t.top >= 0 && t.bottom <= H, rows, top: e.top, bottom: e.bottom, l: t.left, r: t.right };
            });
            const i = img.getBoundingClientRect(), b = box.getBoundingClientRect();
            const overlap = L.some((x, k) => k && L[k - 1].bottom > x.top + 0.5);
            return { dc: (b.top + b.bottom) / 2 - (i.top + i.bottom) / 2, overlap, L };
          });
          const out1 = m.L.filter((x) => !x.in), wrap = name === 'header' ? m.L.filter((x) => x.rows > 1) : [];
          const ok = Math.abs(m.dc) <= 1 && !m.overlap && !out1.length && !wrap.length;
          if (!ok) bad = true;
          out.push(`${name} dc=${m.dc.toFixed(2)}${m.overlap ? ' OVERLAP' : ''}${out1.map((x) => ` OUT«${x.t.slice(0, 24)}»[${x.l.toFixed(0)}..${x.r.toFixed(0)}]`).join('')}${wrap.map((x) => ` WRAP«${x.t.slice(0, 24)}»`).join('')}`);
        }
        console.log(`/${u} @${w}: HTTP ${r.status()} ${out.join('; ')} ${bad ? 'FAIL' : 'pass'}`);
        if (bad) fail++;
      }
      await page.close();
    }
  } finally {
    await browser.close();
  }
  console.log(fail ? `FAIL ${fail}` : 'OK');
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
