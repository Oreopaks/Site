// Playwright: воронка заявки (studio M2, strategy §2 Р2.2–Р2.7, §8). Тест написан до правок: метки задач, которые ещё
// не закрыты, красные. Метка → задача: A1, A2 → T06; A3 → T13 (и T06); A4 → T07; B1–B4, B7 — регрессия; B2p → T13; B5 → T08;
// B6 → T11; C1–C4 → T09; D1, D2, E1 → T14; F1–F4 → T05; F5 → T39; G1–G3 → T10; G4–G6 → T12; G7 → T11.
// Контракт разметки: окно — #modalForm form / #modalSuccess, нижняя форма — #contacts form / #formSuccess,
// блок ошибки — #contacts [role=alert][data-code=invalid|rate|server|offline], панель — nav[aria-label="Связаться"],
// три пути — [data-paths], цели — window.goal + ym(ID,'reachGoal',цель,параметры), чип — radio «Завтра», строка прайса — #fleet dl button.
// Стенд: RDS_DB=<свежая db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<та же db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/funnel.js   (ONLY=<буква> — одна секция)
// Пишет в БД стенда (заявки, metrika_id, whatsapp) — только тестовая БД; metrika_id и whatsapp в конце возвращаются.
const { chromium, devices } = require('playwright-core');
const { execFileSync } = require('child_process');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
const ONLY = (process.env.ONLY || '').toUpperCase();
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const sql = (q, ...args) => JSON.parse(execFileSync('php', ['-r',
  '$p=new PDO("sqlite:".$argv[1]); $s=$p->prepare($argv[2]); $s->execute(array_slice($argv,3)); echo json_encode($s->fetchAll(PDO::FETCH_NUM));',
  DB, q, ...args]).toString());
execFileSync('php', ['-r', 'file_get_contents($argv[1]);', BASE]); // первый запрос создаёт схему и сид в свежей БД
const setting = (k) => (sql('SELECT value FROM settings WHERE key = ?', k)[0] || [null])[0];
const setSetting = (k, v) => sql('UPDATE settings SET value = ? WHERE key = ?', v, k);
const lead = (name) => sql('SELECT source, service, material, comment FROM leads WHERE name = ?', name)[0] || null;

const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/'];
const SIZES = [[360, 740], [390, 844], [768, 1024], [1440, 900]];
const MOBILE = [[360, 740], [390, 844], [768, 1024]];
const PHONE = '+7 980 000-00-00';
const STAMP = Date.now();
// стаб tag.js: очередь ym.a и все следующие вызовы ym(...) уходят в Node через __ymRec
const TAG = 'var q=(window.ym&&window.ym.a)||[];window.ym=function(){window.__ymRec([].slice.call(arguments))};for(var i=0;i<q.length;i++)window.ym.apply(null,q[i]);';

const failed = [];
let passed = 0;
const mark = (label, ok, why = '') => { console.log(`${label} ${ok ? 'pass' : 'FAIL'}${why ? ' — ' + why : ''}`); if (ok) passed++; else failed.push(label); };
const run = async (label, fn) => {
  try { const [ok, why] = await fn(); mark(label, ok, why); } catch (e) { mark(label, false, 'исключение теста: ' + String(e.message).split('\n')[0]); }
};
// проблема → где: «текст [/@360, /@390]»
const group = (m) => [...m].map(([k, v]) => `${k} [${[...new Set(v)].join(', ')}]`).join('; ');
const add = (m, k, where) => m.set(k, [...(m.get(k) || []), where]);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const open = async (w = 390, h = 844) => {
    const page = await browser.newPage({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    page.setDefaultTimeout(5000);
    page.dialogs = [];
    page.on('dialog', (d) => { page.dialogs.push(d.message()); d.dismiss().catch(() => {}); });
    return page;
  };
  const modal = (page) => page.locator('#modalForm form');
  const card = (page, sec) => page.locator(`#${sec} [data-lift]`).first();
  const openCall = async (page) => {
    const b = page.getByRole('button', { name: /заказать звонок/i }).filter({ visible: true });
    if (!(await b.count())) await page.locator('[data-burger]').click();
    await b.first().click();
  };
  const fill = async (form, name, phone = PHONE) => {
    await form.locator('[autocomplete=name]').fill(name);
    await form.locator('[autocomplete=tel]').fill(phone);
    await form.locator('input[type=checkbox]').check();
  };
  const send = async (page, form) => {
    const [r] = await Promise.all([page.waitForResponse((x) => x.url().includes('send.php')), form.locator('button[type=submit]').click()]);
    return { status: r.status(), body: await r.json().catch(() => null) };
  };
  const visible = (page, sel) => page.locator(sel).waitFor({ state: 'visible', timeout: 3000 }).then(() => true, () => false);
  const until = (page, ms = 400) => page.waitForTimeout(ms);

  try {
    // ================= A: CTA первого экрана и пути =================
    if (!ONLY || ONLY === 'A') {
      const a1 = new Map(), a2 = new Map(), a3 = new Map();
      const tel = 'tel:' + setting('phone_mobile');
      const WANT = ['/arenda-spetstekhniki/', '/shcheben-pesok-grunt/', '#contacts'];
      for (const [w, h] of SIZES) {
        const page = await open(w, h);
        for (const u of PAGES) {
          const at = `/${u}@${w}`;
          const r = await page.goto(BASE + u);
          if (r.status() !== 200) { add(a3, `HTTP ${r.status()}`, at); continue; }
          if (u === '') {
            const m = await page.evaluate(() => {
              const t = (a) => (a.textContent + ' ' + (a.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim();
              const links = [...document.querySelector('[data-herowrap]').querySelectorAll('a')].filter((a) => a.getClientRects().length);
              const cta = links.find((a) => /оставить заявку/i.test(t(a)));
              const to = cta && new URL(cta.href);
              const el = to && to.pathname === location.pathname && to.hash && document.getElementById(to.hash.slice(1));
              const call = links.find((a) => /позвонить/i.test(t(a)) && a.getAttribute('href').startsWith('tel:'));
              const p = document.querySelector('[data-paths]');
              const pl = p ? [...p.querySelectorAll('a')] : [];
              return { cta: !!(el && el.id === 'contacts' && el.querySelector('form')), call: call && call.getAttribute('href'),
                links: links.map((a) => `«${t(a).slice(0, 30)}»→${a.getAttribute('href')}`).join(', '),
                paths: p && pl.map((a) => { const x = new URL(a.href); return x.pathname === location.pathname && x.hash ? x.hash : x.pathname + x.hash; }),
                pathsHidden: pl.filter((a) => !a.getClientRects().length).length, chips: !!(p && (p.matches('[data-lp-chips]') || p.querySelector('[data-lp-chips]'))) };
            });
            if (!m.cta) add(a1, `«Оставить заявку» в hero не ведёт к форме #contacts (ссылки hero: ${m.links})`, w);
            if (m.call !== tel) add(a1, `в hero нет «Позвонить» → ${tel}${m.call ? ` (есть ${m.call})` : ''} (ссылки hero: ${m.links})`, w);
            if (!m.paths) add(a2, 'на / нет блока [data-paths]', w);
            else {
              if (JSON.stringify(m.paths) !== JSON.stringify(WANT)) add(a2, `пути ${JSON.stringify(m.paths)}, нужно ${JSON.stringify(WANT)}`, w);
              if (m.pathsHidden) add(a2, `невидимых путей: ${m.pathsHidden}`, w);
              if (m.chips) add(a2, '[data-paths] с [data-lp-chips] (fold.js: 0 чипов на /)', w);
            }
          }
          // A3: каждый CTA в шапке, main и панели → форма, tel: (с тем номером, что на нём написан) или wa.me;
          // ссылки на другие страницы и текстовые якоря — навигация, не CTA; кнопки кликаем и ждём появления формы
          const bad = await page.evaluate(() => {
            const vis = (e) => e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
            const txt = (e) => (e.textContent + ' ' + (e.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim();
            const digits = (s) => s.replace(/\D/g, '');
            const btnLike = (e) => {
              const c = getComputedStyle(e), bg = c.backgroundColor.match(/[\d.]+/g) || [];
              return (bg.length === 4 ? +bg[3] > 0 : bg.length === 3)
                || ['Top', 'Right', 'Bottom', 'Left'].every((s) => parseFloat(c[`border${s}Width`]) >= 1 && c[`border${s}Style`] !== 'none');
            };
            const panel = document.querySelector('nav[aria-label="Связаться"]');
            const els = [];
            for (const s of document.querySelectorAll('header, main')) s.querySelectorAll('a[href], button').forEach((e) => { if (vis(e)) els.push(e); });
            if (panel) panel.querySelectorAll('a[href], button').forEach((e) => els.push(e));
            const forms = () => new Set([...document.forms].filter(vis));
            const out = [];
            for (const e of els) {
              if (e.closest('form') || e.closest('[data-paths]') || e.matches('[data-burger], [aria-expanded], [aria-controls]')) continue;
              const name = txt(e).slice(0, 40);
              if (e.tagName === 'A') {
                const h = e.getAttribute('href');
                if (h.startsWith('tel:')) {
                  for (const m of txt(e).match(/[+\d][\d\s()\-]{4,}\d/g) || []) if (digits(m).length >= 6 && !digits(h).endsWith(digits(m).slice(-10))) out.push(`«${m}» внутри ссылки ${h}`);
                  continue;
                }
                if (/^https:\/\/wa\.me\//.test(h)) continue;
                const to = new URL(e.href);
                const same = to.origin === location.origin && to.pathname === location.pathname && to.hash;
                if (!same || !btnLike(e)) { if (panel && panel.contains(e)) out.push(`панель: «${name}»→${h}`); continue; }
                const el = document.getElementById(decodeURIComponent(to.hash.slice(1)));
                if (!(el && (el.matches('form') || el.querySelector('form')))) out.push(`«${name}»→${h} (не форма, не tel:, не wa.me)`);
                continue;
              }
              const before = forms();
              e.click();
              const opened = [...forms()].some((f) => !before.has(f));
              document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
              if (window.closeModal) window.closeModal();
              if (!opened) out.push(`кнопка «${name}» не открыла форму`);
            }
            return out;
          });
          bad.forEach((b) => add(a3, b, at));
        }
        await page.close();
      }
      mark('A1', !a1.size, group(a1) || 'hero /: «Оставить заявку» → #contacts с формой, «Позвонить» → ' + tel + ' на 360/390/768/1440');
      mark('A2', !a2.size, group(a2) || `[data-paths] → ${WANT.join(', ')} на 360/390/768/1440`);
      mark('A3', !a3.size, group(a3) || 'все CTA шапки, main и панели на 3 страницах × 4 ширины → форма, tel: или wa.me');

      // A4 (T07): на ≤640 верхняя полоса aside[aria-label="Контакты"] — одна строка 44±1 px без переполнения, в ней tel: с номером
      // цифрами (Oswald) в верхних 120 px, тап-цель ≥44; e-mail и WhatsApp из полосы — в мобильном меню
      await run('A4', async () => {
        const bad = new Map();
        for (const w of [320, 360, 390, 430, 640]) {
          const page = await open(w, 800);
          for (const u of PAGES) {
            const at = `/${u}@${w}`;
            await page.goto(BASE + u);
            const m = await page.evaluate(() => {
              const bar = document.querySelector('aside[aria-label="Контакты"]');
              if (!bar) return null;
              const d = (s) => s.replace(/\D/g, '');
              const a = [...bar.querySelectorAll('a[href^="tel:"]')].find((x) => x.getClientRects().length && d(x.textContent).length >= 10);
              const r = a && a.getBoundingClientRect(), row = bar.firstElementChild;
              return { h: bar.getBoundingClientRect().height, ovf: row.scrollWidth - row.clientWidth,
                tel: a && { txt: a.textContent.trim(), same: d(a.getAttribute('href')).endsWith(d(a.textContent).slice(-10)),
                  bottom: r.bottom + scrollY, w: r.width, h: r.height, ff: getComputedStyle(a).fontFamily } };
            });
            if (!m) { add(bad, 'нет aside[aria-label="Контакты"]', at); continue; }
            if (Math.abs(m.h - 44) > 1) add(bad, `высота полосы ${m.h}`, at);
            if (m.ovf > 0) add(bad, `переполнение полосы ${m.ovf} px`, at);
            if (!m.tel) { add(bad, 'в полосе нет видимой tel: с номером цифрами', at); continue; }
            if (!m.tel.same) add(bad, `«${m.tel.txt}» не совпадает с href`, at);
            if (m.tel.bottom > 120) add(bad, `номер ниже 120 px (низ ${m.tel.bottom})`, at);
            if (m.tel.w < 44 || m.tel.h < 44) add(bad, `тап-цель ${m.tel.w}×${m.tel.h}`, at);
            if (!/Oswald/.test(m.tel.ff)) add(bad, `шрифт ${m.tel.ff}`, at);
          }
          await page.close();
        }
        const page = await open(390, 844);
        await page.goto(BASE);
        await page.locator('[data-burger]').click();
        const menu = await page.evaluate(() => [...document.querySelectorAll('#mobileMenu a')].filter((a) => a.getClientRects().length).map((a) => a.getAttribute('href')));
        const wa = setting('whatsapp'), mail = 'mailto:' + setting('email');
        if (!menu.includes(mail)) add(bad, `в меню нет ${mail}`, '/@390');
        if (wa && !menu.some((h) => h.startsWith(wa))) add(bad, `в меню нет ${wa}`, '/@390');
        await page.close();
        return [!bad.size, group(bad) || '320/360/390/430/640 × 3 страницы: полоса 44±1, tel: Oswald в верхних 120 px, ≥44; e-mail и WhatsApp в меню на 390'];
      });
    }

    // ================= B: отправка заявок, «Спасибо», галочка =================
    if (!ONLY || ONLY === 'B') {
      const promise = setting('reply_promise') ?? 'в течение рабочего дня'; // фолбэк шаблона, если ключа нет
      const thx = {};
      const thanks = (page, sel) => page.evaluate(([sel, promise]) => {
        const S = document.querySelector(sel), a = document.activeElement;
        const has = (q) => [...S.querySelectorAll(q)].some((x) => x.getClientRects().length);
        return { focus: S.contains(a) && a.matches('h1,h2,h3,h4,h5,h6,[role=heading]'), on: a ? a.tagName + (a.id ? '#' + a.id : '') : 'null',
          promise: S.innerText.includes(promise), text: S.innerText.replace(/\s+/g, ' ').trim().slice(0, 80), tel: has('a[href^="tel:"]'), wa: has('a[href*="wa.me"]') };
      }, [sel, promise]);
      // окно: открыть → заполнить → отправить на стенд → «Спасибо» + строка в leads
      const viaModal = async (label, opener, want, thxKey, none = 'нечем открыть окно') => run(label, async () => {
        const page = await open();
        try {
          await page.goto(BASE);
          const what = await opener(page);
          if (what === null) return [false, none];
          const name = `PW ${label} ${STAMP}`;
          await fill(modal(page), name);
          const r = await send(page, modal(page));
          const ok = await visible(page, '#modalSuccess');
          if (ok && thxKey) thx[thxKey] = await thanks(page, '#modalSuccess');
          const row = lead(name);
          const [good, need] = want(row, what);
          return [r.body && r.body.ok === true && ok && good, `ответ ${r.status} ${JSON.stringify(r.body)}, «Спасибо» ${ok ? 'показано' : 'нет'}, leads ${JSON.stringify(row)}${good ? '' : ', нужно ' + need}${page.dialogs.length ? ', dialog: ' + page.dialogs.join(' | ') : ''}`];
        } finally { await page.close(); }
      });
      const title = async (page, sec) => (await card(page, sec).locator('h3').textContent()).trim();
      const SVC = 'Аренда спецтехники';

      await viaModal('B1', async (page) => { await openCall(page); return ''; },
        (row) => [!!row && row[0] === 'call', 'source=call'], 'modal');
      await viaModal('B2', async (page) => { const t = await title(page, 'fleet'); await card(page, 'fleet').locator('button').first().click(); return t; },
        (row, t) => [!!row && row[0] === 'calc' && row[1] === SVC && row[3].startsWith(`Техника: ${t}`), `source=calc, service=«${SVC}», comment «Техника: ${t}…»`]);
      await viaModal('B2p', async (page) => {
        const b = page.locator('#fleet dl button');
        if (!(await b.count())) return null;
        const t = (await b.first().textContent()).replace(/\s+/g, ' ').trim();
        await b.first().click();
        return t;
      }, (row, t) => { const tech = row && row[3].split('\n')[0].replace(/^Техника: /, ''); return [!!row && row[0] === 'calc' && row[1] === SVC && row[3].startsWith('Техника: ') && !!tech && t.includes(tech), `source=calc, service=«${SVC}», comment «Техника: <строка прайса «${t}»>…»`]; },
      null, 'в прайсе нет кнопок (#fleet dl button): строки прайса — <dt> без действия, окно аренды из строки не открыть');
      await viaModal('B3', async (page) => { const t = await title(page, 'materials'); await card(page, 'materials').locator('button').first().click(); return t; },
        (row, t) => [!!row && row[0] === 'material' && row[2] === t, `source=material, material=«${t}»`]);

      await run('B4', async () => {
        const page = await open();
        try {
          await page.goto(BASE);
          const f = page.locator('#contacts form'), name = `PW B4 ${STAMP}`;
          await fill(f, name);
          const r = await send(page, f);
          const ok = await visible(page, '#formSuccess');
          if (ok) thx.form = await thanks(page, '#formSuccess');
          const row = lead(name);
          return [r.body && r.body.ok === true && ok && !!row && row[0] === 'calc' && row[1] !== '', `ответ ${r.status} ${JSON.stringify(r.body)}, «Спасибо» ${ok ? 'показано' : 'нет'}, leads ${JSON.stringify(row)}`];
        } finally { await page.close(); }
      });

      await run('B5', async () => {
        const bad = [];
        for (const [k, sel] of [['modal', '#modalSuccess'], ['form', '#formSuccess']]) {
          const t = thx[k];
          if (!t) { bad.push(`${sel}: не дошли до «Спасибо» (B1/B4)`); continue; }
          const p = [];
          if (!t.focus) p.push(`фокус на ${t.on}, а не на заголовке`);
          if (!t.promise) p.push(`нет обещания «${promise}» (текст: «${t.text}»)`);
          if (!t.tel) p.push('нет tel:');
          if (!t.wa) p.push('нет wa.me');
          if (p.length) bad.push(`${sel}: ${p.join(', ')}`);
        }
        return [!bad.length, bad.join('; ') || `оба «Спасибо»: заголовок в фокусе, «${promise}», tel: и wa.me`];
      });

      await run('B6', async () => {
        const page = await open();
        try {
          await page.goto(BASE);
          const t = await title(page, 'fleet');
          await card(page, 'fleet').locator('button').first().click();
          const f = modal(page);
          const chip = f.getByRole('radio', { name: 'Завтра' });
          if (!(await chip.count())) return [false, 'в окне аренды нет чипа-радио «Завтра» (чипов «Сегодня / Завтра / Позже» нет)'];
          await chip.check();
          const ta = f.locator('textarea').filter({ visible: true });
          if ((await ta.count()) !== 1) return [false, `видимых текстовых полей в окне аренды: ${await ta.count()}, нужно одно`];
          const text = 'Объект в Орле, нужно две смены, подъезд с асфальта, разгрузка у ворот. '.repeat(13).slice(0, 879) + '.';
          await ta.fill(text);
          const got = (await ta.inputValue()).length;
          const name = `PW B6 ${STAMP}`;
          await fill(f, name);
          const r = await send(page, f);
          const row = lead(name), want = `Техника: ${t}\nКогда: завтра\n${text}`;
          return [r.body && r.body.ok === true && !!row && row[3] === want,
            `поле приняло ${got} зн., ответ ${r.status} ${JSON.stringify(r.body)}, comment ${!row ? 'нет строки' : row[3] === want ? 'совпал' : `«${row[3].slice(0, 50)}…» ≠ «${want.slice(0, 50)}…»`}`];
        } finally { await page.close(); }
      });

      await run('B7', async () => {
        const page = await open();
        try {
          await page.route('**/send.php', (r) => r.fulfill({ contentType: 'application/json', body: '{"ok":true,"n":1}' }));
          await page.goto(BASE);
          const st = [];
          const box = async (where, form) => st.push([where, await form.locator('input[type=checkbox]').evaluate((b) => [b.checked, b.required])]);
          const esc = () => page.keyboard.press('Escape');
          await box('нижняя форма', page.locator('#contacts form'));
          await openCall(page); await box('звонок', modal(page)); await esc();
          await card(page, 'fleet').locator('button').first().click(); await box('аренда', modal(page)); await esc();
          await card(page, 'materials').locator('button').first().click(); await box('материал', modal(page)); await esc();
          await openCall(page); await fill(modal(page), 'PW B7'); await send(page, modal(page)); await visible(page, '#modalSuccess'); await esc();
          await openCall(page); await box('звонок после отправки', modal(page)); await esc();
          await page.evaluate(() => scrollTo({ top: (document.documentElement.scrollHeight - innerHeight) / 2, behavior: 'instant' })); await until(page);
          await page.locator('nav[aria-label="Связаться"]').getByRole('button', { name: /заявка/i }).click(); await box('заявка из панели', modal(page)); await esc();
          const bad = st.filter(([, [c, r]]) => c || !r).map(([w, [c, r]]) => `${w}: ${c ? 'предотмечена' : ''}${!r ? ' не required' : ''}`);
          return [!bad.length, bad.join('; ') || st.map(([w]) => w).join(', ') + ' — не отмечена, required'];
        } finally { await page.close(); }
      });
    }

    // ================= C: ошибки отправки без alert() =================
    if (!ONLY || ONLY === 'C') {
      const errors = (page) => page.evaluate(() => [...document.querySelectorAll('#contacts [role=alert]')].filter((x) => x.getClientRects().length && x.innerText.trim())
        .map((x) => `${x.getAttribute('data-code')}:«${x.innerText.replace(/\s+/g, ' ').trim().slice(0, 50)}»`));
      const fault = (label, code, handler, keep) => run(label, async () => {
        const page = await open();
        try {
          await page.route('**/send.php', handler);
          await page.goto(BASE);
          const f = page.locator('#contacts form');
          await fill(f, 'PW C');
          await f.locator('textarea').fill('Проверка ошибки связи');
          await f.locator('button[type=submit]').click();
          await page.locator('#contacts [role=alert]').first().waitFor({ state: 'visible', timeout: 2000 }).catch(() => {});
          await until(page, 300);
          const blocks = await errors(page);
          const kept = keep ? [await f.locator('[autocomplete=name]').inputValue(), await f.locator('[autocomplete=tel]').inputValue(), await f.locator('textarea').inputValue()] : null;
          const keptOk = !keep || JSON.stringify(kept) === JSON.stringify(['PW C', PHONE, 'Проверка ошибки связи']);
          const ok = blocks.some((b) => b.startsWith(code + ':')) && !page.dialogs.length && keptOk;
          return [ok, `[role=alert]: ${blocks.length ? blocks.join(' ') : 'нет'}; dialog: ${page.dialogs.length ? page.dialogs.join(' | ') : 'нет'}${keep ? `; поля ${keptOk ? 'на месте' : JSON.stringify(kept)}` : ''}`];
        } finally { await page.close(); }
      });
      await fault('C1', 'server', (r) => r.fulfill({ status: 500, contentType: 'text/html', body: '<!DOCTYPE html><title>500</title><h1>Internal Server Error</h1>' }));
      await fault('C2', 'rate', (r) => r.fulfill({ status: 429, contentType: 'application/json', body: '{"ok":false,"error":"rate"}' }));
      await fault('C3', 'offline', (r) => r.abort('internetdisconnected'), true);

      await run('C4', async () => {
        const page = await open();
        try {
          let n = 0;
          await page.route('**/send.php', (r) => { n++; r.fulfill({ status: 400, contentType: 'application/json', body: '{"ok":false,"error":"invalid"}' }); });
          await page.goto(BASE);
          const f = page.locator('#contacts form');
          await fill(f, 'PW C4', '123');
          await f.locator('button[type=submit]').click();
          await until(page, 800);
          const focus = await page.evaluate(() => { const a = document.activeElement; return a ? a.tagName + (a.getAttribute('autocomplete') ? `[${a.getAttribute('autocomplete')}]` : '') : 'null'; });
          const hint = await f.evaluate((x) => x.innerText.includes('10 цифр'));
          return [n === 0 && focus === 'INPUT[tel]' && hint, `запросов к send.php: ${n}, фокус: ${focus}, подсказка «…10 цифр» у формы: ${hint ? 'есть' : 'нет'}${page.dialogs.length ? ', dialog: ' + page.dialogs.join(' | ') : ''}`];
        } finally { await page.close(); }
      });
    }

    // ================= D: цели Метрики (metrika_id=12345, «Принять», стаб tag.js) =================
    if (!ONLY || ONLY === 'D') {
      const mid0 = setting('metrika_id');
      setSetting('metrika_id', '12345');
      try {
        const openM = async () => {
          const page = await open(1440, 900), recs = [];
          await page.exposeBinding('__ymRec', (_, a) => { recs.push(a); });
          // tel:/wa.me не уводят со страницы; обработчики сайта всё равно получают клик
          await page.addInitScript(() => addEventListener('click', (e) => { const a = e.target.closest && e.target.closest('a[href^="tel:"], a[href*="wa.me"]'); if (a) e.preventDefault(); }, true));
          await page.route(/mc\.yandex\./, (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: TAG }));
          await page.goto(BASE);
          await Promise.all([page.waitForRequest(/mc\.yandex\.ru\/metrika\/tag\.js/), page.click('#cookie-accept')]);
          await until(page, 300);
          return { page, recs, goals: (from) => recs.slice(from).filter((a) => a[0] === 12345 && a[1] === 'reachGoal').map((a) => [a[2], a[3]]) };
        };
        const err500 = (r) => r.fulfill({ status: 500, contentType: 'text/html', body: '<h1>Internal Server Error</h1>' });

        await run('D1', async () => {
          const { page, recs, goals } = await openM();
          try {
            const close = () => page.keyboard.press('Escape');
            const viaModal = async (opener, who) => { await opener(); await fill(modal(page), `PW D1 ${who} ${STAMP}`); await send(page, modal(page)); await visible(page, '#modalSuccess'); await close(); };
            const steps = [
              ['lead_call', () => viaModal(() => openCall(page), 'call')],
              ['lead_rent', () => viaModal(() => card(page, 'fleet').locator('button').first().click(), 'rent')],
              ['lead_material', () => viaModal(() => card(page, 'materials').locator('button').first().click(), 'mat')],
              ['lead_calc', async () => { const f = page.locator('#contacts form'); await fill(f, `PW D1 calc ${STAMP}`); await send(page, f); await visible(page, '#formSuccess'); }],
              ['click_tel', () => page.locator('#contacts a[href^="tel:"]').first().click(), (p) => p && p.where === 'contacts'],
              ['click_whatsapp', () => page.locator('#contacts a[href*="wa.me"]').first().click(), (p) => p && p.where === 'contacts'],
              ['lead_error', async () => {
                await page.reload(); await until(page, 300);
                await page.route('**/send.php', err500);
                const f = page.locator('#contacts form');
                await fill(f, 'PW D1 err');
                await f.locator('button[type=submit]').click();
                await until(page, 800);
                await page.unroute('**/send.php');
              }, (p) => p && p.code === 'server'],
            ];
            const bad = [];
            for (const [goal, act, param] of steps) {
              const from = recs.length;
              try { await act(); await until(page, 300); } catch (e) { bad.push(`${goal}: действие упало (${String(e.message).split('\n')[0]})`); continue; }
              const got = goals(from), mine = got.filter((g) => g[0] === goal);
              const other = got.filter((g) => g[0] !== goal && g[0].startsWith('lead_')).map((g) => g[0]);
              if (!mine.length) bad.push(`${goal}: нет`);
              else if (param && !mine.some((g) => param(g[1]))) bad.push(`${goal}: параметры ${JSON.stringify(mine.map((g) => g[1]))}`);
              if (other.length) bad.push(`${goal}: лишние ${other.join(',')}`);
            }
            const all = goals(0);
            return [!bad.length, `ym-вызовов ${recs.length}, reachGoal ${all.length}${bad.length ? '; ' + bad.join('; ') : ' — 7 целей на своих действиях'}${page.dialogs.length ? '; dialog: ' + page.dialogs.length : ''}`];
          } finally { await page.close(); }
        });

        await run('D2', async () => {
          const { page, goals } = await openM();
          try {
            if (!(await page.evaluate(() => typeof window.goal === 'function'))) return [false, 'после «Принять» нет window.goal — отсутствие lead_* без n ничего не доказывает'];
            await page.route('**/send.php', (r) => r.fulfill({ contentType: 'application/json', body: '{"ok":true}' }));
            const f = page.locator('#contacts form');
            await fill(f, 'PW D2');
            await send(page, f);
            const ok = await visible(page, '#formSuccess');
            await until(page, 300);
            const g = goals(0).filter((x) => x[0].startsWith('lead_'));
            return [ok && !g.length, `ответ {"ok":true} без n: «Спасибо» ${ok ? 'показано' : 'нет'}, lead_*: ${JSON.stringify(g)}`];
          } finally { await page.close(); }
        });
      } finally { setSetting('metrika_id', mid0); }
    }

    // ================= E: тег Метрики только после согласия =================
    if (!ONLY || ONLY === 'E') {
      const mid0 = setting('metrika_id');
      setSetting('metrika_id', '12345');
      try {
        await run('E1', async () => {
          const bad = [];
          for (const u of PAGES) {
            const page = await open(1440, 900), hits = [];
            try {
              await page.route(/mc\.yandex\./, (r) => { hits.push(r.request().url()); r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }); });
              await page.goto(BASE + u);
              await until(page, 500);
              const tag = () => page.evaluate(() => !!document.querySelector('script[src*="mc.yandex.ru/metrika/tag.js"]'));
              const pre = { tag: await tag(), ym: await page.evaluate(() => typeof window.ym), bar: await page.locator('#cookie-bar').isVisible() };
              if (!pre.bar) bad.push(`/${u}: cookie-бар не виден`);
              if (pre.tag || hits.length || pre.ym !== 'undefined') bad.push(`/${u}: до согласия tag.js=${pre.tag}, запросов ${hits.length}, ym=${pre.ym}`);
              await Promise.all([page.waitForRequest(/mc\.yandex\.ru\/metrika\/tag\.js/, { timeout: 3000 }), page.click('#cookie-accept')]).catch(() => {});
              if (!(await tag())) bad.push(`/${u}: после «Принять» тега нет`);
            } finally { await page.close(); }
          }
          return [!bad.length, bad.join('; ') || '3 страницы: до согласия нет tag.js и 0 запросов mc.yandex, после «Принять» — есть'];
        });
      } finally { setSetting('metrika_id', mid0); }
    }

    // ================= F: липкая панель «Позвонить + WhatsApp» =================
    if (!ONLY || ONLY === 'F') {
      const state = (page) => page.evaluate(() => {
        const n = document.querySelector('nav[aria-label="Связаться"]');
        if (!n) return null;
        const c = getComputedStyle(n), r = n.getBoundingClientRect();
        const shown = !n.hidden && n.getClientRects().length > 0 && c.visibility !== 'hidden' && +c.opacity > 0 && r.bottom > 0 && r.top < innerHeight;
        const links = [...n.querySelectorAll('a, button')].filter((a) => a.getClientRects().length).map((a) => {
          const b = a.getBoundingClientRect();
          return { tag: a.tagName, role: a.getAttribute('role') || '', t: a.textContent.trim(), href: a.getAttribute('href') || '', w: b.width, h: b.height, ovf: a.scrollWidth - a.clientWidth };
        });
        return { pos: c.position, shown, r: { top: r.top, bottom: r.bottom, left: r.left, right: r.right }, cw: n.clientWidth, sw: n.scrollWidth, links };
      });
      // прокрутка сразу за hero (hero целиком выше экрана; #contacts ниже)
      const mid = async (page) => {
        await page.evaluate(() => scrollTo({ top: document.querySelector('[data-herowrap]').getBoundingClientRect().bottom + scrollY + 10, behavior: 'instant' }));
        await until(page);
      };
      const none = 'нет nav[aria-label="Связаться"]';

      await run('F1', async () => {
        const bad = new Map();
        for (const [w, h] of [[320, 640], ...MOBILE]) { // 320×640: CTA hero ниже первого экрана, прыжок через hero (R1)
          const page = await open(w, h);
          for (const u of PAGES) {
            await page.goto(BASE + u);
            await mid(page);
            const p = await state(page), at = `/${u}@${w}`;
            if (!p) { add(bad, none, at); continue; }
            if (p.pos !== 'fixed') add(bad, `position:${p.pos}`, at);
            if (!p.shown) add(bad, 'не видна после hero', at);
            if (!p.links.some((l) => l.href.startsWith('tel:'))) add(bad, 'нет tel:', at);
            if (!p.links.some((l) => /^https:\/\/wa\.me\/\d+\?text=./.test(l.href))) add(bad, 'нет wa.me/…?text=', at);
          }
          await page.close();
        }
        return [!bad.size, group(bad) || '320/360/390/768 × 3 страницы: fixed, видна после hero, tel: и wa.me?text='];
      });

      await run('F2', async () => {
        const bad = new Map();
        for (const [w, h] of MOBILE) {
          const page = await open(w, h);
          for (const u of PAGES) {
            const at = `/${u}@${w}x${h}`;
            await page.goto(BASE + u);
            await until(page);
            const p0 = await state(page);
            if (!p0) { add(bad, none, at); continue; }
            const cta = await page.evaluate(() => {
              const a = [...document.querySelector('[data-herowrap]').querySelectorAll('a')].find((x) => /оставить заявку/i.test(x.textContent));
              const r = a && a.getBoundingClientRect();
              return r && { top: r.top, bottom: r.bottom, left: r.left, right: r.right, in: r.top < innerHeight && r.bottom > 0 };
            });
            if (cta && cta.in && p0.shown) add(bad, 'видна, пока виден CTA hero', at);
            if (cta && p0.shown && p0.r.top < cta.bottom && p0.r.bottom > cta.top && p0.r.left < cta.right && p0.r.right > cta.left) add(bad, 'пересекает CTA hero', at);
            await mid(page);
            if (!(await state(page)).shown) { add(bad, 'не видна и после hero — скрытие не проверить', at); continue; }
            await page.evaluate(() => document.querySelector('#contacts form').scrollIntoView({ block: 'center', behavior: 'instant' }));
            await until(page);
            if ((await state(page)).shown) add(bad, 'видна при видимой #contacts', at);
            await mid(page);
            await openCall(page);
            await until(page);
            if ((await state(page)).shown) add(bad, 'видна при открытом окне', at);
            await page.keyboard.press('Escape');
          }
          await page.close();
        }
        return [!bad.size, group(bad) || 'скрыта при CTA hero, #contacts и окне; CTA hero не перекрыт'];
      });

      await run('F3', async () => {
        const bad = new Map();
        for (const [w, h] of [[1024, 768], [1440, 900]]) {
          const page = await open(w, h);
          for (const u of PAGES) {
            await page.goto(BASE + u);
            await mid(page);
            const p = await state(page);
            if (p && p.shown) add(bad, 'панель видна', `/${u}@${w}`);
          }
          await page.close();
        }
        return [!bad.size, group(bad) || '1024/1440 × 3 страницы: панели не видно'];
      });

      const wa0 = setting('whatsapp');
      setSetting('whatsapp', '');
      try {
        await run('F4', async () => {
          const bad = new Map();
          const page = await open(390, 844);
          for (const u of PAGES) {
            await page.goto(BASE + u);
            await mid(page);
            const p = await state(page), at = `/${u}`;
            if (!p) { add(bad, none, at); continue; }
            // T39: при пустом whatsapp — две кнопки «Позвонить» (tel:) и «Заявка» (окно расчёта; без JS — #contacts) на всю ширину панели
            const [t, z] = p.links;
            if (!(p.links.length === 2 && t.href.startsWith('tel:') && z.role === 'button' && z.href.endsWith('#contacts') && /заявка/i.test(z.t) && Math.abs(t.w + z.w - p.cw) <= 1 && Math.min(t.w, z.w) >= 44))
              add(bad, `кнопки ${JSON.stringify(p.links.map((l) => [l.tag, l.t, l.href.slice(0, 12), Math.round(l.w)]))} при ширине панели ${p.cw}`, at);
          }
          await page.close();
          return [!bad.size, group(bad) || 'whatsapp пуст: «Позвонить» (tel:) + «Заявка» на всю ширину на 3 страницах'];
        });
      } finally { setSetting('whatsapp', wa0); }

      // F5 (T39): заявка в один тап с любой точки. 320/360/390/768, прокрутка 50%: в панели три кнопки (tel:, wa.me, «Заявка») ≥44
      // без переполнения; тап «Заявка» → окно расчёта, фокус в имени, галочка не отмечена, Tab не выходит из окна;
      // на / отправка → leads source=calc, service = услуга из поля окна, цель lead_calc; на посадочных услуга выбрана по странице
      await run('F5', async () => {
        const bad = new Map(), seen = [];
        const PICK = { '': null, 'arenda-spetstekhniki/': 'Аренда спецтехники', 'shcheben-pesok-grunt/': 'Поставка материалов' };
        for (const [w, h] of [[320, 640], [360, 740], [390, 844], [768, 1024]]) {
          const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, reducedMotion: 'reduce' });
          const page = await ctx.newPage();
          page.setDefaultTimeout(5000);
          for (const u of PAGES) {
            const at = `/${u}@${w}`;
            await page.goto(BASE + u);
            await page.evaluate(() => { window.__g = []; window.goal = (g) => window.__g.push(g); }); // metrika_id пуст — цели ловит стаб
            await page.evaluate(() => scrollTo({ top: (document.documentElement.scrollHeight - innerHeight) / 2, behavior: 'instant' }));
            await until(page);
            const p = await state(page);
            if (!p) { add(bad, none, at); continue; }
            if (!p.shown) add(bad, 'панель не видна на 50%', at);
            const kinds = p.links.map((l) => l.href.startsWith('tel:') ? 'tel' : /wa\.me/.test(l.href) ? 'wa' : l.role === 'button' && l.href.endsWith('#contacts') && /^заявка$/i.test(l.t) ? 'zayavka' : `?${l.t}`);
            if (kinds.join() !== 'tel,wa,zayavka') add(bad, `кнопки панели ${kinds.join(', ')}, нужно tel, wa, Заявка`, at);
            for (const l of p.links) {
              if (l.w < 44 || l.h < 44) add(bad, `«${l.t}» ${Math.round(l.w)}×${Math.round(l.h)}`, at);
              if (l.ovf > 0) add(bad, `«${l.t}»: текст шире кнопки на ${l.ovf}`, at);
            }
            if (p.sw > p.cw) add(bad, `панель: переполнение ${p.sw}>${p.cw}`, at);
            const z = page.locator('nav[aria-label="Связаться"]').getByRole('button', { name: /заявка/i });
            if (!(await z.count())) { add(bad, 'в панели нет кнопки «Заявка»', at); continue; }
            await z.tap();
            await until(page, 200);
            const s = await page.evaluate(() => {
              const d = [...document.querySelectorAll('[role=dialog]')].find((x) => x.getClientRects().length), f = document.querySelector('#modalForm form'), a = document.activeElement;
              const sel = f && [...f.querySelectorAll('select')].find((x) => x.getClientRects().length);
              return { d: !!d, title: d ? document.getElementById(d.getAttribute('aria-labelledby')).textContent.trim() : '', name: !!(a && a.matches('#modalForm [autocomplete=name]')),
                on: a ? a.tagName + (a.id ? '#' + a.id : '') : 'null', box: f && f.querySelector('input[type=checkbox]').checked, svc: sel ? sel.value : null,
                bar: !!document.querySelector('nav[aria-label="Связаться"]:not([data-off])') && getComputedStyle(document.querySelector('nav[aria-label="Связаться"]')).visibility !== 'hidden' };
            });
            if (!s.d) { add(bad, 'тап «Заявка» не открыл окно', at); continue; }
            seen.push(`${at} «${s.title}» услуга «${s.svc}»`);
            if (!s.name) add(bad, `фокус на ${s.on}, а не в поле имени`, at);
            if (s.box) add(bad, 'галочка согласия предотмечена', at);
            if (s.bar) add(bad, 'панель видна при открытом окне', at);
            if (s.svc === null) add(bad, 'в окне нет видимого поля «Услуга» (select)', at);
            else if (PICK[u] && s.svc !== PICK[u]) add(bad, `услуга «${s.svc}», нужно «${PICK[u]}»`, at);
            if (w === 390) {
              const out = [];
              for (let i = 1; i <= 12; i++) { await page.keyboard.press('Tab'); if (!(await page.evaluate(() => document.querySelector('[role=dialog]').contains(document.activeElement)))) out.push(i); }
              if (out.length) add(bad, `Tab выводит фокус из окна (шаги ${out.join(',')})`, at);
            }
            if (u !== '') continue;
            const name = `PW F5 ${w} ${STAMP}`, note = w === 390 ? 'Двор 300 м², Орёл, к июню' : '';
            if (note) { await modal(page).getByRole('radio', { name: 'Завтра' }).check(); await modal(page).locator('textarea').fill(note); } // сборка comment: «Когда: …» → текст
            await fill(modal(page), name);
            const r = await send(page, modal(page));
            const ok = await visible(page, '#modalSuccess');
            const row = lead(name), g = await page.evaluate(() => window.__g.filter((x) => x.startsWith('lead_')));
            if (!(r.body && r.body.ok === true && ok)) add(bad, `отправка: ответ ${r.status} ${JSON.stringify(r.body)}, «Спасибо» ${ok ? 'есть' : 'нет'}`, at);
            const want = note ? `Когда: завтра\n${note}` : '';
            if (!row || row[0] !== 'calc' || row[1] !== s.svc || row[3] !== want) add(bad, `leads ${JSON.stringify(row)}, нужно source=calc, service=«${s.svc}», comment ${JSON.stringify(want)}`, at);
            if (g.join() !== 'lead_calc') add(bad, `цели ${JSON.stringify(g)}, нужно lead_calc`, at);
          }
          await ctx.close();
        }
        // без JS: «Заявка» — ссылка на #contacts своей страницы (<base href="/"> не уводит с посадочной), тап на 390 → нижняя форма в кадре
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, javaScriptEnabled: false, reducedMotion: 'reduce' });
        const page = await ctx.newPage();
        page.setDefaultTimeout(5000);
        const nj = [];
        for (const u of PAGES) {
          await page.goto(BASE + u);
          await page.evaluate(() => scrollTo({ top: (document.documentElement.scrollHeight - innerHeight) / 2, behavior: 'instant' }));
          await page.locator('nav[aria-label="Связаться"]').getByRole('button', { name: /заявка/i }).tap();
          await until(page, 1500);
          const m = await page.evaluate(() => { const r = document.querySelector('#contacts form').getBoundingClientRect(); return { at: location.pathname + location.hash, top: Math.round(r.top), vh: innerHeight }; });
          if (m.at !== `/${u}#contacts` || m.top < 0 || m.top >= m.vh) add(bad, `без JS: тап «Заявка» → ${m.at}, форма top ${m.top} при ${m.vh}`, `/${u}@390`);
          nj.push(`${m.at} (форма top ${m.top})`);
        }
        await ctx.close();
        seen.push(`без JS → ${nj.join(', ')}`);
        return [!bad.size, group(bad) || `${seen[seen.length - 1]}; 50% прокрутки → «Заявка» в панели → окно (${seen.length - 1}): ${seen.slice(0, 2).join('; ')} …; отправка с / на 4 ширинах → calc + lead_calc`];
      });
    }

    // ================= G: поля и окно — доступность (T10, T11, T12) =================
    if (!ONLY || ONLY === 'G') {
      // режимы окна → чем открыть (в странице); нижняя форма — #contacts form
      const MODES = [['звонок', 'call'], ['аренда', '#fleet [data-lift] button'], ['материал', '#materials [data-lift] button']];
      const FIELDS = 'input:not([type=checkbox]):not([type=radio]):not([name=website]), select, textarea';
      const openIn = (page, how) => page.evaluate((how) => {
        if (window.closeModal) closeModal();
        if (how === 'call') { openModal(); return true; }
        if (how === 'calc') { openCalc(); return true; } // T39: окно расчёта без позиции (кнопка «Заявка» панели)
        const b = document.querySelector(how);
        if (!b) return false;
        b.click();
        return true;
      }, how);
      const opener = (page, how) => how === 'call' ? page.getByRole('button', { name: /заказать звонок/i }).filter({ visible: true }).first() : page.locator(how).first();
      // контраст WCAG цвета a к цвету b ('rgb(…)'); кольцо фокуса — outline ≥2px и/или сплошная box-shadow со spread ≥2px
      const RING = () => {
        const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number);
        const lum = ([r, g, b]) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
        window.__cr = (a, b) => { const x = lum(rgb(a)), y = lum(rgb(b)); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
        window.__bg = (el) => { for (; el; el = el.parentElement) { const c = rgb(getComputedStyle(el).backgroundColor); if (c.length < 4 || c[3] > 0) return `rgb(${c.slice(0, 3).join(',')})`; } return 'rgb(255,255,255)'; };
        window.__ring = (el) => {
          const c = getComputedStyle(el), out = [];
          if (c.outlineStyle !== 'none' && parseFloat(c.outlineWidth) >= 2) out.push(c.outlineColor);
          for (const s of c.boxShadow === 'none' ? [] : c.boxShadow.split(/,(?![^(]*\))/)) {
            const col = (s.match(/rgba?\([^)]*\)/) || [''])[0], n = s.replace(col, '').trim().split(/\s+/).map(parseFloat);
            if (!/inset/.test(s) && n[0] === 0 && n[1] === 0 && n[2] === 0 && n[3] >= 2) out.push(col);
          }
          return out;
        };
      };

      // G1 (T10): поля ≥16px, над каждым — видимая <label> ≥13px; чекбокс согласия ≥22×22, не отмечен, строка согласия ≥44
      await run('G1', async () => {
        const bad = new Map();
        for (const w of [320, 390, 1440]) {
          const page = await open(w, 800);
          for (const u of PAGES) {
            await page.goto(BASE + u);
            for (const [k, how] of [['нижняя', null], ...MODES, ['заявка', 'calc']]) {
              if (how && !(await openIn(page, how))) continue;
              const at = `/${u}@${w}`;
              const r = await page.evaluate(([sel, FIELDS]) => {
                const f = document.querySelector(sel), out = [];
                const vis = (e) => e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
                for (const el of f.querySelectorAll(FIELDS)) {
                  if (!vis(el)) continue;
                  const n = el.getAttribute('autocomplete') || el.tagName.toLowerCase(), fs = parseFloat(getComputedStyle(el).fontSize);
                  if (fs < 16) out.push(`${n} ${fs}px`);
                  const lb = [...el.labels].find(vis);
                  if (!lb || !lb.innerText.trim()) { out.push(`${n} без видимой <label>`); continue; }
                  const lfs = parseFloat(getComputedStyle(lb).fontSize);
                  if (lfs < 13) out.push(`подпись ${n} ${lfs}px`);
                  if (lb.getBoundingClientRect().bottom > el.getBoundingClientRect().top + 1) out.push(`подпись ${n} не над полем`);
                }
                const b = f.querySelector('input[type=checkbox]'), br = b.getBoundingClientRect(), row = b.closest('label').getBoundingClientRect();
                if (br.width < 22 || br.height < 22) out.push(`чекбокс ${Math.round(br.width)}×${Math.round(br.height)}`);
                if (row.height < 44) out.push(`строка согласия ${Math.round(row.height)}px`);
                if (b.checked) out.push('согласие предотмечено');
                return out;
              }, [how ? '#modalForm form' : '#contacts form', FIELDS]);
              r.forEach((x) => add(bad, `${k}: ${x}`, at));
            }
          }
          await page.close();
        }
        return [!bad.size, group(bad) || '320/390/1440 × 3 страницы × (нижняя + 4 режима окна): поля ≥16px, подписи ≥13px над полями, чекбокс ≥22, строка согласия ≥44, не отмечен'];
      });

      // G2 (T10): iPhone 390×844 и Android 360×800 (isMobile, hasTouch, DPR 3) — тап в каждое поле → фокус в нём, visualViewport.scale = 1.
      // ponytail: Chromium-эмуляция не воспроизводит iOS-зум полей <16px; сам критерий кегля — в G1, здесь — тап доходит и масштаб не меняется
      await run('G2', async () => {
        const bad = new Map(); let n = 0;
        for (const [dn, dev, vp] of [['iphone', devices['iPhone 13'], { width: 390, height: 844 }], ['android', devices['Pixel 5'], { width: 360, height: 800 }]]) {
          const ctx = await browser.newContext({ ...dev, viewport: vp, deviceScaleFactor: 3, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
          const page = await ctx.newPage();
          page.setDefaultTimeout(5000);
          for (const [k, how] of [['нижняя', null], ...MODES, ['заявка', 'calc']]) {
            await page.goto(BASE);
            if (how === 'call' || how === 'calc') await openIn(page, how);
            else if (how) await opener(page, how).tap();
            const fields = page.locator(`${how ? '#modalForm form' : '#contacts form'} :is(${FIELDS})`).filter({ visible: true });
            for (let i = 0, c = await fields.count(); i < c; i++) {
              const f = fields.nth(i);
              await f.tap();
              await until(page, 300);
              const m = await f.evaluate((el) => ({ s: visualViewport.scale, me: document.activeElement === el, n: el.getAttribute('autocomplete') || el.tagName.toLowerCase() }));
              n++;
              if (m.s !== 1) add(bad, `${k}: ${m.n} → scale ${m.s}`, dn);
              if (!m.me) add(bad, `${k}: тап не дал фокус ${m.n}`, dn);
            }
          }
          await ctx.close();
        }
        return [!bad.size && n > 0, group(bad) || `iPhone/Android: ${n} тапов по полям (нижняя + 4 режима окна) — фокус в поле, scale = 1`];
      });

      // G3 (T10): фокус с клавиатуры на полях и галочке — кольцо (outline/box-shadow ≥2px), контраст ≥3:1 к фону поля
      // (#f4f4f2 в окне, #161616 внизу; у галочки — к фону вокруг неё)
      await run('G3', async () => {
        const bad = new Map(), seen = [];
        const page = await open(1440, 900);
        await page.goto(BASE);
        await page.evaluate(RING);
        for (const [k, how] of [['нижняя', null], ['аренда', MODES[1][1]]]) {
          if (how) await openIn(page, how);
          const sel = how ? '#modalForm form' : '#contacts form';
          await page.locator(`${sel} [autocomplete=name]`).focus();
          for (let i = 0; i < 14; i++) {
            const m = await page.evaluate(([sel, FIELDS]) => {
              const a = document.activeElement, f = document.querySelector(sel);
              if (!f.contains(a)) return null;
              if (!a.matches(FIELDS + ', input[type=checkbox]')) return { skip: true };
              const bg = a.type === 'checkbox' ? __bg(a.parentElement) : __bg(a), ring = __ring(a);
              return { n: a.getAttribute('autocomplete') || a.type || a.tagName.toLowerCase(), bg, ring: ring.map((c) => [c, +__cr(c, bg).toFixed(2)]) };
            }, [sel, FIELDS]);
            if (!m) break;
            if (!m.skip) {
              const best = Math.max(0, ...m.ring.map((x) => x[1]));
              seen.push(`${k}:${m.n} ${best}`);
              if (best < 3) add(bad, `${k}: ${m.n} — кольцо ${m.ring.length ? JSON.stringify(m.ring) : 'нет'} на ${m.bg}`, '/@1440');
            }
            await page.keyboard.press('Tab');
          }
        }
        await page.close();
        return [!bad.size && seen.length >= 7, group(bad) || `контраст кольца (лучший слой): ${seen.join(', ')}`];
      });

      // T12: окно — диалог. Состояние: видимый [role=dialog], aria-modal, aria-labelledby → текст, фокус внутри (в первом поле), фон inert
      const dlg = (page) => page.evaluate(() => {
        const d = [...document.querySelectorAll('[role=dialog]')].find((x) => x.getClientRects().length), a = document.activeElement;
        const lb = d && document.getElementById(d.getAttribute('aria-labelledby') || '');
        return { d: !!d, modal: d ? d.getAttribute('aria-modal') : null, label: lb ? lb.textContent.trim() : null, inside: !!(d && a && d.contains(a)),
          first: !!(a && a.matches('[autocomplete=name]')), on: a ? a.tagName + (a.getAttribute('autocomplete') ? `[${a.getAttribute('autocomplete')}]` : '') + (a.getAttribute('aria-label') ? `«${a.getAttribute('aria-label')}»` : '') : 'null',
          live: ['header', 'main', 'footer', 'nav[aria-label="Связаться"]'].filter((s) => { const e = document.querySelector(s); return e && !e.closest('[inert]'); }),
          dInert: !!(d && d.closest('[inert]')), open: getComputedStyle(document.getElementById('modalOverlay')).display !== 'none' };
      });
      const clickOpen = async (page, k, how) => { if (how === 'call') await openCall(page); else await page.locator(how).first().click(); await until(page, 150); };

      // G4 (T12): открытие кликом (3 режима, 390 и 1440) → диалог объявлен, фокус в первом поле, фон inert
      await run('G4', async () => {
        const bad = new Map();
        for (const w of [390, 1440]) {
          const page = await open(w, 900);
          for (const [k, how] of MODES) {
            await page.goto(BASE);
            await clickOpen(page, k, how);
            const s = await dlg(page), at = `${k}@${w}`;
            if (!s.d) { add(bad, 'нет видимого [role=dialog]', at); continue; }
            if (s.modal !== 'true') add(bad, `aria-modal=${s.modal}`, at);
            if (!s.label) add(bad, 'aria-labelledby не ведёт к тексту', at);
            if (!s.inside || !s.first) add(bad, `фокус на ${s.on}, а не в первом поле окна`, at);
            if (s.live.length) add(bad, `фон не inert: ${s.live.join(', ')}`, at);
            if (s.dInert) add(bad, 'окно само внутри [inert]', at);
          }
          await page.close();
        }
        return [!bad.size, group(bad) || '3 режима × 390/1440: role=dialog, aria-modal, aria-labelledby, фокус в имени, фон inert'];
      });

      // G5 (T12): 12×Tab и 12×Shift+Tab не выводят фокус из окна (звонок, аренда; 390 и 1440)
      await run('G5', async () => {
        const bad = new Map();
        for (const w of [390, 1440]) {
          const page = await open(w, 900);
          for (const [k, how] of MODES.slice(0, 2)) {
            await page.goto(BASE);
            await clickOpen(page, k, how);
            for (const key of ['Tab', 'Shift+Tab']) {
              const out = [];
              for (let i = 1; i <= 12; i++) { await page.keyboard.press(key); const s = await dlg(page); if (!s.inside) out.push(`${i}:${s.on}`); }
              if (out.length) add(bad, `${key} вне окна: ${out.slice(0, 4).join(' ')}${out.length > 4 ? ` …(${out.length})` : ''}`, `${k}@${w}`);
            }
          }
          await page.close();
        }
        return [!bad.size, group(bad) || 'звонок и аренда × 390/1440: 12×Tab и 12×Shift+Tab — фокус в окне'];
      });

      // G6 (T12): Esc и «×» закрывают окно и возвращают фокус на открывашку; «×» ≥44×44 с aria-label «Закрыть»; фон снова не inert
      await run('G6', async () => {
        const bad = new Map();
        for (const [w, k, how] of [[1440, 'звонок', 'call'], [390, 'аренда', MODES[1][1]]]) {
          const page = await open(w, 900), at = `${k}@${w}`;
          await page.goto(BASE);
          const o = opener(page, how);
          for (const close of ['Escape', '×']) {
            await o.evaluate((e) => { document.querySelectorAll('[data-pw-op]').forEach((x) => x.removeAttribute('data-pw-op')); e.setAttribute('data-pw-op', ''); });
            await o.click();
            await until(page, 150);
            if (close === 'Escape') await page.keyboard.press('Escape');
            else {
              const x = page.locator('[role=dialog] button[aria-label="Закрыть"]');
              if (!(await x.count())) { add(bad, 'нет [role=dialog] button[aria-label="Закрыть"]', at); await page.keyboard.press('Escape'); continue; }
              const b = await x.boundingBox();
              if (!b || b.width < 44 || b.height < 44) add(bad, `«×» ${b ? `${Math.round(b.width)}×${Math.round(b.height)}` : 'не видна'}`, at);
              await x.click();
            }
            await until(page, 150);
            const s = await dlg(page), back = await page.evaluate(() => !!(document.activeElement && document.activeElement.matches('[data-pw-op]')));
            if (s.open) add(bad, `${close}: окно не закрылось`, at);
            if (!back) add(bad, `${close}: фокус на ${s.on}, а не на открывашке`, at);
            if (s.live.length < 4) add(bad, `${close}: фон остался inert`, at);
          }
          await page.close();
        }
        return [!bad.size, group(bad) || 'Esc и «×» (≥44×44, «Закрыть») закрывают, фокус на открывашке, inert снят — звонок@1440, аренда@390'];
      });

      // G7 (T11): чипы «Сегодня / Завтра / Позже» — радиогруппа с подписью, тап-цель ≥44, стрелка → следующий, видимое кольцо фокуса;
      // на 360×640 оверлей прокручивается до кнопки отправки (аренда — самое высокое окно)
      await run('G7', async () => {
        const bad = [];
        let page = await open(390, 844);
        try {
          await page.goto(BASE);
          await page.evaluate(RING);
          await clickOpen(page, 'аренда', MODES[1][1]);
          const f = modal(page), radios = f.getByRole('radio');
          const names = await radios.evaluateAll((rs) => rs.map((r) => r.labels[0] ? r.labels[0].innerText.trim() : ''));
          if (JSON.stringify(names) !== JSON.stringify(['Сегодня', 'Завтра', 'Позже'])) return [false, `радио в окне аренды: ${JSON.stringify(names)}`];
          const g = await radios.first().evaluate((r) => { const fs = r.closest('fieldset'), lg = fs && fs.querySelector('legend');
            return { group: !!(lg && lg.innerText.trim()), legend: lg && lg.innerText.trim(), same: new Set([...fs.querySelectorAll('input[type=radio]')].map((x) => x.name)).size === 1,
              boxes: [...fs.querySelectorAll('label')].map((l) => { const b = l.getBoundingClientRect(); return [Math.round(b.width), Math.round(b.height)]; }) }; });
          if (!g.group || !g.same) bad.push(`группа: legend «${g.legend}», одно name — ${g.same}`);
          if (g.boxes.some(([w, h]) => w < 44 || h < 44)) bad.push(`чипы ${JSON.stringify(g.boxes)}`);
          await f.locator('[autocomplete=tel]').focus();
          await page.keyboard.press('Tab');
          const r1 = await page.evaluate(() => document.activeElement.type + ':' + (document.activeElement.labels[0] || {}).innerText);
          await page.keyboard.press('ArrowRight');
          const r2 = await page.evaluate(() => { const a = document.activeElement, l = a.closest('label'), bg = __bg(l.parentElement);
            const ring = [l, ...l.children].filter((x) => x !== a).flatMap((x) => __ring(x)).map((c) => +__cr(c, bg).toFixed(2));
            return { on: a.type + ':' + (a.labels[0] || {}).innerText, checked: a.checked, ring }; });
          if (r1 !== 'radio:Сегодня') bad.push(`Tab после телефона → ${r1}`);
          if (r2.on !== 'radio:Завтра' || !r2.checked) bad.push(`ArrowRight → ${r2.on} checked=${r2.checked}`);
          if (!(Math.max(0, ...r2.ring) >= 3)) bad.push(`кольцо фокуса чипа ${JSON.stringify(r2.ring)}`);
          await page.close();
          page = await open(360, 640);
          await page.goto(BASE);
          await clickOpen(page, 'аренда', MODES[1][1]);
          const rch = await page.evaluate(() => { const o = document.querySelector('#modalOverlay > div'); o.scrollTop = o.scrollHeight;
            const b = document.querySelector('#modalForm button[type=submit]').getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), vh: innerHeight }; });
          if (rch.top < 0 || rch.bottom > rch.vh) bad.push(`360×640: кнопка отправки ${rch.top}..${rch.bottom} при ${rch.vh}`);
          return [!bad.length, bad.join('; ') || `легенда «${g.legend}», чипы ${JSON.stringify(g.boxes)}, Tab → ${r1}, → ${r2.on}, кольцо ${JSON.stringify(r2.ring)}; 360×640 кнопка ${rch.top}..${rch.bottom}`];
        } finally { await page.close(); }
      });
    }
  } finally {
    await browser.close();
  }
  console.log(`ИТОГ${ONLY ? ` (ONLY=${ONLY})` : ''}: pass ${passed}, FAIL ${failed.length}${failed.length ? ' — ' + failed.join(' ') : ''}`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
