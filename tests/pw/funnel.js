// Playwright: воронка заявки (studio M2, strategy §2 Р2.2–Р2.7, §8). Тест написан до правок: метки задач, которые ещё
// не закрыты, красные. Метка → задача: A1, A2 → T06; A3 → T13 (и T06); B1–B4, B7 — регрессия; B2p → T13; B5 → T08;
// B6 → T11; C1–C4 → T09; D1, D2, E1 → T14; F1–F4 → T05.
// Контракт разметки: окно — #modalForm form / #modalSuccess, нижняя форма — #contacts form / #formSuccess,
// блок ошибки — #contacts [role=alert][data-code=invalid|rate|server|offline], панель — nav[aria-label="Связаться"],
// три пути — [data-paths], цели — window.goal + ym(ID,'reachGoal',цель,параметры), чип — radio «Завтра», строка прайса — #fleet dl button.
// Стенд: RDS_DB=<свежая db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<та же db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/funnel.js   (ONLY=<буква> — одна секция)
// Пишет в БД стенда (заявки, metrika_id, whatsapp) — только тестовая БД; metrika_id и whatsapp в конце возвращаются.
const { chromium } = require('playwright-core');
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
        const links = [...n.querySelectorAll('a')].filter((a) => a.getClientRects().length).map((a) => ({ href: a.getAttribute('href'), w: a.getBoundingClientRect().width }));
        return { pos: c.position, shown, r: { top: r.top, bottom: r.bottom, left: r.left, right: r.right }, cw: n.clientWidth, links };
      });
      // прокрутка сразу за hero (hero целиком выше экрана; #contacts ниже)
      const mid = async (page) => {
        await page.evaluate(() => scrollTo({ top: document.querySelector('[data-herowrap]').getBoundingClientRect().bottom + scrollY + 10, behavior: 'instant' }));
        await until(page);
      };
      const none = 'нет nav[aria-label="Связаться"]';

      await run('F1', async () => {
        const bad = new Map();
        for (const [w, h] of MOBILE) {
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
        return [!bad.size, group(bad) || '360/390/768 × 3 страницы: fixed, видна после hero, tel: и wa.me?text='];
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
            if (!(p.links.length === 1 && p.links[0].href.startsWith('tel:') && p.links[0].w >= p.cw - 1)) add(bad, `кнопки ${JSON.stringify(p.links)} при ширине панели ${p.cw}`, at);
          }
          await page.close();
          return [!bad.size, group(bad) || 'whatsapp пуст: одна tel:-кнопка во всю ширину на 3 страницах'];
        });
      } finally { setSetting('whatsapp', wa0); }
    }
  } finally {
    await browser.close();
  }
  console.log(`ИТОГ${ONLY ? ` (ONLY=${ONLY})` : ''}: pass ${passed}, FAIL ${failed.length}${failed.length ? ' — ' + failed.join(' ') : ''}`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
