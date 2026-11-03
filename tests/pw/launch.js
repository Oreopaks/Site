// Playwright: фиксы перед запуском (план 2026-11-03, F1–F12). F1 «Сохранить» без правок → «Изменений нет»; F2 тосты editor без отсылки к «Опубликовать»;
// F3 «Восстановить» из корзины → на прежнее место; F4 «Заявка удалена.»; F5 заявки карточками на 390; F6 «Сайт ↗» и ссылка на опубликованную посадочную;
// F7 подсказка у «Режим работы»; F8 maxlength=1000 у комментария нижней формы; F9 favicon в админке; F10 заявки без горизонтальной прокрутки страницы на 390 и 768;
// F11 кнопки ≥44×44 на 390; F12 плашка с раскрытым «Что изменено» ≤40% экрана на 390; F13 ссылки подвала сайта ≥44px на телефоне и тач-планшете;
// F14 «Позвонить» и бургер шапки сайта ≥44×44.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/launch.js
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const PASS = 'pw launch password 1', N = Date.now() % 100000;
const user = (login, role) => execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), login, role], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });
const EDITOR = 'pwled' + N, OWNER = 'pwlown' + N;
user(EDITOR, 'editor');
user(OWNER, 'owner');
// две заявки прямо в БД (форма сайта шлёт письмо); длинные имя и комментарий — как в жизни
const sql = (q, ...a) => execFileSync('php', ['-r', '(new PDO("sqlite:".$argv[1]))->prepare($argv[2])->execute(array_slice($argv, 3));', DB, q, ...a]);
for (const i of [1, 2]) sql('INSERT INTO leads (source, name, phone, service, comment, consent, consent_rev) VALUES (?, ?, ?, ?, ?, 1, ?)',
  'calc', 'Иван Петрович Сидоров ' + i, '+7 (980) 769-11-11', 'Аренда спецтехники', 'Нужен каток на 3 смены, адрес: Орёл, ул. Московская 100, сроки — до конца недели', '2026-10-27');

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const login = async (name, w = 1280, h = 800) => {
    const page = await (await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' })).newPage();
    page.on('dialog', (d) => d.accept());
    await page.goto(BASE + 'admin/');
    await page.fill('[name=login]', name);
    await page.fill('[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('form.login button')]);
    return page;
  };
  const click = (page, loc) => Promise.all([page.waitForNavigation(), loc.click()]);
  const text = async (loc) => (await loc.count()) ? loc.first().innerText() : ''; // нет элемента — '' сразу, без 30-секундного ожидания
  const ok = (page) => text(page.locator('p.ok'));
  // строка списка контента (форма «Добавить» — без id)
  const row = (page, i) => page.locator('form.fields').filter({ has: page.locator('input[name=id]:not([value=""])') }).nth(i);
  const titles = (page) => page.locator('form.fields input[name=title]').evaluateAll((els) => els.map((el) => el.value).filter(Boolean));
  const siteSvc = async (page) => { await page.goto(BASE); return page.locator('[data-svc] h3').evaluateAll((els) => els.map((el) => el.textContent.trim())); };
  const publish = (page) => click(page, page.locator('.draft > form[action*="a=publish"] button'));
  const NOPUB = ', когда опубликует владелец или менеджер.';

  const ed = await login(EDITOR);
  const own = await login(OWNER);

  // F1: «Сохранить» без правок — не «Сохранено в черновик», черновик не появляется
  await ed.goto(BASE + 'admin/?p=content&t=services');
  await click(ed, row(ed, 0).locator('button'));
  let m = await ok(ed);
  check(`F1 «Сохранить» без правок → «${m}», плашки черновика нет`, m === 'Изменений нет — сохранять нечего.' && await ed.locator('.draft').count() === 0);

  // F2: у editor нет «Опубликовать» — тосты сохранения и удаления не отсылают к ней
  await ed.goto(BASE + 'admin/?p=content&t=marquee');
  await row(ed, 0).locator('[name=title]').fill('Pw редактор ' + N);
  await click(ed, row(ed, 0).locator('button'));
  m = await ok(ed);
  check(`F2 editor сохранил → «${m}»`, m === 'Сохранено в черновик. На сайте появится' + NOPUB);
  await click(ed, ed.locator('form[action*="a=delete"] button').last());
  m = await ok(ed);
  check(`F2 editor удалил → «${m}»`, m === 'Удалено из черновика. С сайта исчезнет' + NOPUB);

  // F3: owner удаляет 4-ю услугу и публикует; editor восстанавливает из корзины; после публикации порядок как был
  await own.goto(BASE + 'admin/?p=content&t=services');
  const before = await titles(own);
  const site0 = await siteSvc(own);
  await own.goto(BASE + 'admin/?p=content&t=services');
  await click(own, own.locator('form[action*="a=delete"] button').nth(3));
  await publish(own); // с правками editor — confirm принимается
  check(`F3 удалена и опубликована «${before[3]}»`, !(await siteSvc(own)).includes(before[3]) && site0.includes(before[3]), site0.join(' | '));
  await ed.goto(BASE + 'admin/?p=trash');
  await click(ed, ed.locator('.row', { hasText: before[3] }).locator('button', { hasText: 'Восстановить' }));
  m = await ok(ed);
  check(`F2 editor восстановил из корзины → «${m}»`, m === 'Возвращено в черновик. На сайте появится' + NOPUB);
  await own.goto(BASE + 'admin/?p=content&t=services');
  const after = await titles(own);
  check(`F3 «Восстановить» → в черновике на прежнем месте: ${after.join(' | ')}`, JSON.stringify(after) === JSON.stringify(before), before.join(' | '));
  await publish(own);
  const site1 = await siteSvc(own);
  check(`F3 после публикации порядок на / как был: ${site1.join(' | ')}`, JSON.stringify(site1) === JSON.stringify(site0), site0.join(' | '));

  // F2 (обратная сторона): у owner тост по-прежнему про «Опубликовать»; 4 правки — черновик для F12
  for (let i = 0; i < 4; i++) {
    await own.goto(BASE + 'admin/?p=content&t=marquee');
    await row(own, i).locator('[name=title]').fill(`Pw владелец ${i} ${N}`);
    await click(own, row(own, i).locator('button'));
  }
  m = await ok(own);
  check(`F2 owner сохранил → «${m}»`, m === 'Сохранено в черновик. На сайте появится после «Опубликовать».');

  // F6: ссылка на сайт в меню; опубликованная посадочная в «Посадочные» и «Анкета» — ссылкой
  const site = own.locator('.top nav a[href="/"]');
  m = await text(site);
  check(`F6 в меню «${m}» → / в новой вкладке`, m === 'Сайт ↗' && await site.getAttribute('target') === '_blank' && (await site.getAttribute('rel')).split(' ').includes('noopener'));
  for (const g of ['pages', 'facts']) {
    await own.goto(BASE + 'admin/?p=settings&g=' + g);
    const a = own.locator('p.ok a[href="/arenda-spetstekhniki/"]');
    check(`F6 settings g=${g}: «/arenda-spetstekhniki/ опубликована» — ссылка`, await a.count() === 1 && await text(a) === '/arenda-spetstekhniki/ опубликована'
      && await a.getAttribute('target') === '_blank');
  }

  // F7: подсказка под «Режим работы» — те же часы в жёлтой полосе
  await own.goto(BASE + 'admin/?p=settings&g=contacts');
  const hint = own.locator('label:has(> input[name=hours_text]) + p.hint');
  m = await text(hint);
  check(`F7 подсказка у «Режим работы»: «${m}»`, m.startsWith('Часы также показаны в жёлтой полосе под первым экраном') && await hint.locator('a[href="/admin/?p=content&t=stat_bar"]').count() === 1);

  // F9: favicon в админке (иначе браузер просит /favicon.ico → 404 в консоли)
  const icon = (await own.locator('link[rel=icon]').count()) ? await own.locator('link[rel=icon]').getAttribute('href') : null;
  const st = icon ? (await own.request.get(new URL(icon, BASE).href)).status() : 0;
  check(`F9 админка: <link rel=icon href=${icon}> → HTTP ${st}`, icon === '/favicon.svg' && st === 200);

  // F8: комментарий нижней формы — не длиннее лимита send.php
  await own.goto(BASE);
  const ml = await own.locator('form textarea[aria-label="Комментарий"]').getAttribute('maxlength');
  check(`F8 нижняя форма: textarea maxlength=${ml}`, ml === '1000');

  // F5, F10, F11, F12: заявки на 390 и 768 с раскрытым «Что изменено»
  for (const w of [390, 768]) {
    const p = await login(OWNER, w, 800);
    await p.goto(BASE + 'admin/?p=leads');
    await p.locator('.changes summary').click();
    const r = await p.evaluate(() => {
      const t = document.querySelector('main table'), d = document.querySelector('.draft'), small = [], out = [];
      for (const el of document.querySelectorAll('button')) {
        const b = el.getBoundingClientRect();
        if (el.checkVisibility() && (b.width < 44 || b.height < 44)) small.push(`«${el.textContent.trim()}» ${b.width.toFixed(1)}×${b.height.toFixed(1)}`);
      }
      for (const el of t.querySelectorAll('select, button')) { const b = el.getBoundingClientRect(); if (b.left < 0 || b.right > innerWidth) out.push(`«${el.textContent.trim().slice(0, 10)}» right=${b.right.toFixed(0)}`); }
      return { sw: document.documentElement.scrollWidth, iw: innerWidth, ih: innerHeight, tsw: t.scrollWidth, tcw: t.clientWidth, thead: t.tHead.checkVisibility(),
        labels: [...t.tBodies[0].rows[0].cells].map((c) => getComputedStyle(c, '::before').content), bar: d.getBoundingClientRect().height, pos: getComputedStyle(d).position, small, out };
    });
    check(`F10 @${w} заявки: scrollWidth=${r.sw} innerWidth=${r.iw}`, r.sw <= r.iw);
    if (w === 390) {
      check(`F5 @390 заявки карточками: шапка скрыта=${!r.thead}, подписи ${r.labels.join(' ')}`, !r.thead && r.labels.length === 8 && r.labels.every((l) => /^".+"$/.test(l)));
      check(`F5 @390 статус, OK, «Удалить» в пределах экрана, таблица без прокрутки (${r.tsw} ≤ ${r.tcw})`, !r.out.length && r.tsw <= r.tcw, r.out.join('; '));
      check(`F11 @390 все кнопки ≥44×44`, !r.small.length, r.small.join('; '));
      check(`F12 @390 плашка с раскрытым «Что изменено»: ${r.bar.toFixed(0)} из ${r.ih} (${(100 * r.bar / r.ih).toFixed(0)}%)`, r.pos === 'fixed' && r.bar <= r.ih * 0.4 + 0.5);
      const sel = p.locator('.changes button');
      await sel.scrollIntoViewIfNeeded();
      const b = await sel.boundingBox();
      check(`F12 @390 «Опубликовать выбранное» доступна прокруткой плашки: низ=${(b.y + b.height).toFixed(0)} из ${r.ih}`, b.y >= 0 && b.y + b.height <= r.ih);
    } else {
      // ≥761 — настольная вёрстка: плашка в потоке страницы (не перекрывает), кнопки — без требования 44px
      console.log(`@768 справочно: плашка ${r.bar.toFixed(0)} из ${r.ih}, position=${r.pos}; кнопки <44: ${r.small.join('; ') || 'нет'}`);
      check(`F12 @768 плашка в потоке страницы, экран не перекрывает: position=${r.pos}`, r.pos === 'static');
    }
    await p.context().close();
  }

  // F4: удаление заявки — «Заявка удалена.»; смена статуса — по-прежнему «Сохранено.»
  await own.goto(BASE + 'admin/?p=leads');
  await own.locator('main table select[name=status]').first().selectOption('in_work');
  await click(own, own.locator('main table form[action*="a=status"] button').first());
  m = await ok(own);
  check(`F4 смена статуса → «${m}»`, m === 'Сохранено.');
  await click(own, own.locator('main table form[action*="a=delete"] button').first());
  m = await ok(own);
  check(`F4 удаление заявки → «${m}», осталась 1`, m === 'Заявка удалена.' && await own.locator('main table tbody tr').count() === 1);

  // F13: ссылки подвала (главная и посадочная) ≥44px по высоте на телефоне и тач-планшете; на ПК с мышью — прежние 19px
  for (const [w, touch, big] of [[390, false, true], [1024, true, true], [1440, false, false]])
    for (const url of ['', 'arenda-spetstekhniki/']) {
      const p = await (await browser.newContext({ viewport: { width: w, height: 800 }, hasTouch: touch, reducedMotion: 'reduce' })).newPage();
      await p.goto(BASE + url);
      const hs = await p.$$eval('footer a', (els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
      check(`F13 @${w}${touch ? ' тач' : ''} /${url} подвал: высоты ссылок ${hs.join(' ')}`, hs.length >= 9 && hs.every((h) => big ? h >= 44 : h < 44));
      await p.context().close();
    }

  // F14: «Позвонить» и бургер в шапке ≥44×44 везде, где видны
  for (const w of [360, 390, 768, 1024]) {
    const p = await (await browser.newContext({ viewport: { width: w, height: 800 }, reducedMotion: 'reduce' })).newPage();
    await p.goto(BASE);
    const r = await p.$$eval('[data-hcall], [data-burger]', (els) => els.filter((e) => e.getClientRects().length).map((e) => { const b = e.getBoundingClientRect(); return [Math.round(b.width), Math.round(b.height)]; }));
    check(`F14 @${w} кнопки шапки ${r.map((x) => x.join('×')).join(' ')}`, r.length === 2 && r.every(([bw, bh]) => bw >= 44 && bh >= 44));
    await p.context().close();
  }

  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
