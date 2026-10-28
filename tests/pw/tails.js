// Playwright: хвосты роя 2026-10-28 (skeptic-A/B): липкая шапка (A6), поле задачи в окне расчёта/аренды (A2),
// русская подсказка у галочки согласия и hover только при (hover:hover) (B1/B2), подчёркнутая «Главная» в крошках (B8),
// «Оставить заявку» первого экрана главной → #contacts (A3), «Планировка участков» в списке услуг формы (B6).
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/tails.js
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/'];
const HINT = 'Поставьте галочку согласия';
let fail = 0;
const check = (name, ok, info = '') => { console.log(`${name}: ${info} ${ok ? 'pass' : 'FAIL'}`); if (!ok) fail++; };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    // A6: шапка липнет при прокрутке, телефон/значок tel: в окне, без горизонтального переполнения и сдвига
    for (const [w, h] of [[390, 844], [1440, 900]]) {
      const page = await browser.newPage({ viewport: { width: w, height: h } });
      for (const u of PAGES) {
        await page.goto(BASE + u);
        const m = await page.evaluate(async () => {
          const hd = document.querySelector('header'), main = document.querySelector('main');
          const tel = document.querySelector(innerWidth <= 1120 ? '[data-hcall]' : '[data-hphone]');
          const before = { mainTop: main.getBoundingClientRect().top + scrollY, hh: hd.offsetHeight };
          window.scrollTo({ top: 1500, behavior: 'instant' });
          await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
          const t = tel.getBoundingClientRect();
          return { y: scrollY, top: hd.getBoundingClientRect().top, telTop: t.top, telBottom: t.bottom, telW: t.width,
            sw: document.documentElement.scrollWidth, W: innerWidth,
            shift: main.getBoundingClientRect().top + scrollY - before.mainTop, hh: hd.offsetHeight - before.hh };
        });
        check(`A6 /${u} @${w}`, m.y === 1500 && Math.abs(m.top) < 1 && m.telW > 0 && m.telTop >= 0 && m.telBottom <= 90 && m.sw <= m.W && m.shift === 0 && m.hh === 0,
          `scrollY=${m.y} header.top=${m.top} tel y${m.telTop}..${m.telBottom} scrollWidth=${m.sw}/${m.W} shift=${m.shift}/${m.hh}`);
      }
      await page.close();
    }

    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    let post = null;
    await page.route('**/send.php', (r) => { post = r.request().postData() || ''; r.fulfill({ contentType: 'application/json', body: '{"ok":true}' }); });
    const field = (p, n) => { const m = new RegExp(`name="${n}"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--`).exec(p || ''); return m ? m[1].replace(/\r\n/g, "\n") : null; };
    const ta = '#modalForm textarea';
    const sendModal = async (text) => {
      post = null;
      const f = page.locator('#modalForm form');
      await f.locator('[autocomplete=name]').fill('Тест');
      await f.locator('[autocomplete=tel]').fill('8 900 000-00-00');
      if (text !== null) await page.fill(ta, text);
      await f.locator('input[type=checkbox]').check();
      await f.locator('button[type=submit]').click();
      await page.waitForTimeout(200);
    };

    // A2: окно расчёта материала — поле задачи есть, уходит в comment
    await page.goto(BASE);
    await page.getByText('Запросить расчёт').first().click();
    const mat = await page.textContent('#modalMatName');
    const vis = () => page.evaluate((s) => { const t = document.querySelector(s); return !!t && t.offsetParent !== null; }, ta);
    let v = await vis();
    if (v) await sendModal('100 т, Орёл, к пятнице');
    check('A2 openCalc', v && field(post, 'source') === 'material' && field(post, 'material') === mat && field(post, 'comment') === '100 т, Орёл, к пятнице',
      `textarea=${v} source=${field(post, 'source')} comment=«${field(post, 'comment')}»`);
    // аренда: «Техника: …» и затем текст пользователя
    await page.goto(BASE);
    const tech = (await page.locator('[data-lift] h3').first().textContent()).trim();
    await page.locator('[data-lift]').first().getByText('В АРЕНДУ').click();
    v = await vis();
    if (v) await sendModal('2 смены, Орёл');
    check('A2 openRent', v && field(post, 'source') === 'calc' && field(post, 'comment') === `Техника: ${tech}\n2 смены, Орёл`,
      `textarea=${v} comment=«${field(post, 'comment')}»`);
    // звонок: поля нет, comment не уходит; поле очищено после прошлой заявки
    await page.goto(BASE);
    await page.evaluate(() => openModal());
    v = await vis();
    await sendModal(null);
    check('A2 openModal', !v && field(post, 'source') === 'call' && !field(post, 'comment'), `textarea=${v} comment=«${field(post, 'comment')}»`);

    // B1/B2: без галочки — русская подсказка рядом с галочкой (aria-live), снимается при отметке; запрос не уходит
    for (const [name, open, sel] of [['форма', null, '#contacts form'], ['окно', () => openModal(), '#modalForm form']]) {
      await page.goto(BASE);
      if (open) await page.evaluate(open);
      const f = page.locator(sel);
      await f.locator('[autocomplete=name]').fill('Тест');
      await f.locator('[autocomplete=tel]').fill('8 900 000-00-00');
      post = null;
      await f.locator('button[type=submit]').click();
      await page.waitForTimeout(150);
      const hint = () => f.evaluate((form) => { const el = form.querySelector('[aria-live]');
        return el ? { t: el.textContent.trim(), vis: el.offsetHeight > 0, inLabel: !!el.closest('label') } : null; });
      const a = await hint();
      await f.locator('input[type=checkbox]').check();
      const b = await hint();
      check(`B1 ${name}`, post === null && a && a.t === HINT && a.vis && a.inLabel && b.t === '' && !b.vis,
        `до: ${JSON.stringify(a)} после: ${JSON.stringify(b)} post=${post === null ? 'нет' : 'есть'}`);
    }

    // B6: «Планировка участков» в списке услуг — как заголовок карточки; асфальтирования нет
    await page.goto(BASE);
    const o = await page.evaluate(() => ({
      opts: [...document.querySelectorAll('#contacts select option')].map((x) => x.textContent.trim()),
      cards: [...document.querySelectorAll('[data-svc] h3')].map((x) => x.textContent.trim()),
    }));
    check('B6 select', o.opts.includes('Планировка участков') && o.cards.includes('Планировка участков') && !o.opts.some((x) => /асфальт/i.test(x)),
      o.opts.join(' | '));

    // A3: «Оставить заявку» первого экрана главной ведёт к #contacts, окно не открывается
    await page.goto(BASE);
    const cta = page.locator('[data-hero-content]').getByText('Оставить заявку');
    const tag = await cta.evaluate((el) => el.tagName + ' ' + (el.getAttribute('href') || ''));
    await cta.click();
    await page.waitForTimeout(150);
    const s = await page.evaluate(() => ({ open: getComputedStyle(document.getElementById('modalOverlay')).display !== 'none',
      hash: location.hash }));
    check('A3 hero CTA', tag === 'A #contacts' && !s.open && s.hash === '#contacts', `${tag} modal=${s.open} hash=${s.hash}`);

    // B8: «Главная» в хлебных крошках подчёркнута
    for (const u of PAGES.slice(1)) {
      await page.goto(BASE + u);
      const d = await page.evaluate(() => getComputedStyle(document.querySelector('nav[aria-label="Хлебные крошки"] a')).textDecorationLine);
      check(`B8 /${u}`, d === 'underline', d);
    }
    await page.close();

    // B2: на сенсорном экране (hover:none) нажатие не оставляет «наведённый» вид кнопки отправки
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const tp = await ctx.newPage();
    await tp.route('**/send.php', (r) => r.fulfill({ contentType: 'application/json', body: '{"ok":false}' }));
    await tp.goto(BASE);
    const btn = tp.locator('#contacts button[type=submit]');
    await btn.scrollIntoViewIfNeeded();
    const st0 = await btn.getAttribute('style');
    await btn.tap();
    await tp.waitForTimeout(150);
    const st1 = await btn.getAttribute('style');
    check('B2 hover:none', st0 === st1, st0 === st1 ? 'style не изменился' : `стало ${st1.slice(-40)}`);
    await ctx.close();
  } finally {
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
