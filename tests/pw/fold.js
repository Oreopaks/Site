// Playwright: на посадочных в первом экране (без скролла) — чип с ценой «₽» и ссылка tel: с номером цифрами, без горизонтального переполнения; на / чипов нет.
// T15 (strategy Р3.2, V6): на / H1 с городом и «Оставить заявку» целиком в первом экране 360×740 и 390×844;
// h1 главной и посадочных на 320–1920 не шире своего блока (scrollWidth ≤ clientWidth) и не рвёт слова между строками.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/fold.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const SIZES = [[360, 740], [390, 844]];
const WIDTHS = [320, 360, 390, 430, 768, 1024, 1440, 1920];
const H1 = 'строим дороги в орле. благоустраиваем территории.';
// h1: переполнение блока и слова, разорванные между строками (overflow-wrap:anywhere — страховка, а не перенос)
const h1probe = () => [...document.querySelectorAll('h1')].map((h) => {
  const broken = [], tw = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
  for (let n; (n = tw.nextNode());) for (const m of n.data.matchAll(/\S+/g)) {
    const r = document.createRange(); r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length);
    if (new Set([...r.getClientRects()].map((x) => Math.round(x.top))).size > 1) broken.push(m[0]);
  }
  return { sw: h.scrollWidth, cw: h.clientWidth, fs: getComputedStyle(h).fontSize, broken };
});

(async () => {
  let fail = 0;
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    for (const [w, h] of SIZES) {
      const page = await browser.newPage({ viewport: { width: w, height: h } });
      for (const u of ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/']) {
        const r = await page.goto(BASE + u);
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(150);
        const m = await page.evaluate(() => {
          const W = innerWidth, H = innerHeight;
          const inView = (el) => { const b = el.getBoundingClientRect(); return b.width > 0 && b.top >= 0 && b.bottom <= H && b.left >= 0 && b.right <= W; };
          const chips = [...document.querySelectorAll('[data-lp-chips] li')];
          const tel = [...document.querySelectorAll('a[href^="tel:"]')]
            .find((a) => /\d{3}-\d{2}-\d{2}/.test(a.textContent) && inView(a));
          const cta = [...document.querySelectorAll('[data-herowrap] a')].find((a) => /оставить заявку/i.test(a.textContent));
          const h1 = document.querySelector('h1');
          return { n: chips.length, price: chips.filter((li) => li.textContent.includes('₽') && inView(li)).length,
            tel: tel && tel.textContent.trim(), ovf: document.documentElement.scrollWidth - W,
            cta: cta ? (inView(cta) ? 'в экране' : 'низ ' + Math.round(cta.getBoundingClientRect().bottom)) : 'нет', h1: h1 && h1.innerText.replace(/\s+/g, ' ').trim().toLowerCase() };
        });
        const ok = r.status() === 200 && m.ovf <= 0 && (u === '' ? m.n === 0 && m.cta === 'в экране' && m.h1 === H1 : m.price === m.n && m.n > 0 && !!m.tel);
        console.log(`/${u} @${w}x${h}: HTTP ${r.status()} chips=${m.n} inView=${m.price} tel="${m.tel || ''}" ovf=${m.ovf}${u === '' ? ` CTA «Оставить заявку» ${m.cta} h1="${m.h1}"` : ''} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
      }
      await page.close();
    }
    for (const w of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      for (const u of ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/']) {
        await page.goto(BASE + u);
        await page.evaluate(() => document.fonts.ready);
        const hs = await page.evaluate(h1probe);
        const ok = hs.length === 1 && hs.every((x) => x.sw <= x.cw && !x.broken.length);
        console.log(`/${u} @${w}: h1 ${hs.map((x) => `${x.fs} scrollWidth=${x.sw} clientWidth=${x.cw}${x.broken.length ? ' разрыв: ' + x.broken.join(',') : ''}`).join('; ')} ${ok ? 'pass' : 'FAIL'}`);
        if (!ok) fail++;
      }
      await page.close();
    }
  } finally {
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
