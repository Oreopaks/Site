// Playwright: прайс — «договорная / ед.» (без «договорная смена»); текст согласия в обеих формах >= 13px и
// контраст WCAG >= 4.5:1 к фону ближайшего непрозрачного предка; нижняя строка подвала — контраст >= 4.5:1.
// Главная и посадочные. Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/a11y.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/'];

// в странице: [{t, fs, cr}] для элементов по селектору; cr — контраст WCAG цвета текста к фону предка
const measure = (sel) => {
  const rgb = (s) => s.match(/[\d.]+/g).map(Number);
  const lum = ([r, g, b]) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const bg = (el) => { for (; el; el = el.parentElement) { const c = rgb(getComputedStyle(el).backgroundColor); if (c.length < 4 || c[3] > 0) return c; } return [255, 255, 255]; };
  return [...document.querySelectorAll(sel)].map((el) => {
    const cs = getComputedStyle(el), a = lum(rgb(cs.color)), b = lum(bg(el));
    return { t: el.textContent.trim().slice(0, 30), fs: parseFloat(cs.fontSize), cr: (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05) };
  });
};

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    for (const u of PAGES) {
      await page.goto(BASE + u);
      const dd = await page.$$eval('#fleet dl dd', (a) => a.map((d) => d.innerText.replace(/\s+/g, ' ')));
      const bad = dd.filter((t) => /договорная\s+[^\s/]/i.test(t));
      const nc = dd.filter((t) => /договорная/i.test(t)).length;
      if (u === '') check('/: в прайсе есть договорные', nc > 0, String(nc));
      check(`/${u}: договорная с «/ ед.» (${nc})`, bad.length === 0, JSON.stringify(bad));

      const ok = (m) => m.length > 0 && m.every((x) => x.fs >= 13 && x.cr >= 4.5);
      const show = (m) => JSON.stringify(m.map((x) => [x.t, x.fs, +x.cr.toFixed(2)]));
      const form = await page.evaluate(measure, '#contacts label:has(input[type=checkbox]) > span, #contacts .pd-policy');
      check(`/${u}: согласие в контактах >=13px и >=4.5:1`, ok(form), show(form));
      await page.evaluate(() => openModal());
      const modal = await page.evaluate(measure, '#modalForm label:has(input[type=checkbox]) > span, #modalForm .pd-policy');
      check(`/${u}: согласие в окне звонка >=13px и >=4.5:1`, ok(modal), show(modal));
      await page.evaluate(() => closeModal());
      const foot = await page.evaluate(measure, 'footer > div > div:last-child > span');
      check(`/${u}: нижняя строка подвала >=4.5:1`, foot.length >= 3 && foot.every((x) => x.cr >= 4.5), show(foot));
    }
  } finally { await browser.close(); }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
