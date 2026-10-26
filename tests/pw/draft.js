// Playwright: черновик (ADR M7). editor правит → на / старое, в превью новое, «Опубликовать» нет; owner публикует → / новое;
// owner снимает галочку с правки редактора → публикуется только своя; тост «Отменить» откатывает правку. На 390 и 1440; на 390 в админке — без переполнения, плашка прибита к низу, цели ≥44px.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/draft.js
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const PASS = 'pw draft password 1', N = Date.now() % 100000;
const user = (login, role) => execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), login, role], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });
const EDITOR = 'pwed' + N, OWNER = 'pwown' + N;
user(EDITOR, 'editor');
user(OWNER, 'owner');

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const login = async (w, name) => {
    const page = await (await browser.newContext({ viewport: { width: w, height: 844 }, reducedMotion: 'reduce' })).newPage();
    page.on('dialog', (d) => { page.lastDialog = d.message(); d.accept(); });
    await page.goto(BASE + 'admin/');
    await page.fill('[name=login]', name);
    await page.fill('[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('form.login button')]);
    return page;
  };
  const html = async (page, url) => { await page.goto(BASE + url); return page.content(); };
  const click = (page, loc) => Promise.all([page.waitForNavigation(), loc.click()]);
  // последний этап (строка с id; форма «Добавить» — без id)
  const lastStep = (page) => page.locator('form.fields').filter({ has: page.locator('input[name=id]:not([value=""])') }).last();

  for (const w of [390, 1440]) {
    const ed = await login(w, EDITOR);
    await ed.goto(BASE + 'admin/?p=content&t=step');
    const old = await lastStep(ed).locator('[name=title]').inputValue();
    const NEW = 'Сдача и гарантия ' + w;
    await lastStep(ed).locator('[name=title]').fill(NEW);
    await click(ed, lastStep(ed).locator('button'));
    check(`@${w} editor сохранил «${NEW}» → тост`, await ed.locator('.toast p.ok').isVisible());
    check(`@${w} editor: кнопок «Опубликовать»/«Отменить всё» нет, есть «ждут публикации»`,
      await ed.locator('form[action*="a=publish"], form[action*="a=discard"]').count() === 0 && await ed.locator('.draft', { hasText: 'ждут публикации' }).isVisible());

    if (w === 390) {
      const m = await ed.evaluate(() => {
        window.scrollTo(0, document.documentElement.scrollHeight / 2);
        const d = document.querySelector('.draft'), r = d.getBoundingClientRect(), small = [];
        for (const el of document.querySelectorAll('button, .top a, .tabs a, .draft a, label.chk, input:not([type=hidden]):not([type=checkbox]), select, textarea'))
          if (el.checkVisibility() && el.getBoundingClientRect().height < 44) small.push(el.tagName + ' «' + (el.textContent || el.name).trim().slice(0, 20) + '» ' + el.getBoundingClientRect().height);
        return [document.documentElement.scrollWidth, document.documentElement.clientWidth, getComputedStyle(d).position, r.top, r.bottom, innerHeight, small];
      });
      check(`@390 админка: scrollWidth=${m[0]} clientWidth=${m[1]}`, m[0] <= m[1]);
      check(`@390 плашка при прокрутке внизу экрана: position=${m[2]} top=${m[3].toFixed(0)} bottom=${m[4].toFixed(0)} innerHeight=${m[5]}`,
        m[2] === 'fixed' && m[3] < m[5] && Math.abs(m[4] - m[5]) < 1);
      check(`@390 кнопки, ссылки, поля ≥44px`, !m[6].length, m[6].join('; '));

      // вставка 562 символов в «Текст» услуги (лимит 200): не обрезается, счётчик «Слишком длинно», рамка
      await ed.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
      await ed.goto(BASE + 'admin/?p=content&t=services');
      const ta = ed.locator('form.fields textarea[name=text]').first();
      await ta.fill('');
      await ed.evaluate((s) => navigator.clipboard.writeText(s), 'Ж'.repeat(562));
      await ta.press('Control+V');
      const v = await ta.evaluate((el) => [el.value.length, el.nextElementSibling.textContent, el.classList.contains('bad')]);
      check(`@390 вставка 562 в «Текст» услуги → в поле ${v[0]}, «${v[1]}», рамка=${v[2]}`,
        v[0] === 562 && v[1] === 'Слишком длинно: 562 из 200 — сократите на 362' && v[2] && await ed.locator('.count.err', { hasText: 'Слишком длинно' }).first().isVisible());
    } else {
      check(`@${w} плашка видна`, await ed.locator('.draft').isVisible());
    }

    const pub = await html(ed, '');
    check(`@${w} на / прежний этап «${old}», нового нет`, pub.includes(old) && !pub.includes(NEW));
    check(`@${w} в превью новый этап`, (await html(ed, 'admin/?p=preview')).includes(NEW));

    const own = await login(w, OWNER);
    // «Что изменено»: owner правит бегущую строку, снимает галочку с правки редактора → на / только правка владельца
    await own.goto(BASE + 'admin/?p=content&t=marquee');
    const MQ = 'Владелец ' + w, mq = own.locator('form.fields').first();
    await mq.locator('[name=title]').fill(MQ);
    await click(own, mq.locator('button'));
    await own.locator('.changes summary').click();
    const edRow = own.locator('.changes li', { hasText: '· ' + EDITOR });
    check(`@${w} owner: «${await own.locator('.changes summary').innerText()}», правка редактора с его логином`,
      (await own.locator('.changes summary').innerText()) === 'Что изменено (2)' && await edRow.count() === 1 && await edRow.locator('a').innerText() === 'Этапы работы: ' + NEW);
    if (w === 390) {
      const m = await own.evaluate(() => {
        const d = document.querySelector('.draft').getBoundingClientRect(), small = [];
        for (const el of document.querySelectorAll('.changes summary, .changes .pk, .changes a, .changes button'))
          if (el.getBoundingClientRect().height < 44 || el.getBoundingClientRect().width < 44) small.push(el.tagName + ' ' + el.getBoundingClientRect().height);
        return [d.top, innerHeight, small];
      });
      check(`@390 открытый список не закрывает экран: top плашки=${m[0].toFixed(0)} из ${m[1]}`, m[0] > m[1] * 0.2);
      check(`@390 цели списка ≥44px`, !m[2].length, m[2].join('; '));
    }
    await edRow.locator('input[type=checkbox]').uncheck();
    await click(own, own.locator('.changes button'));
    check(`@${w} «Опубликовать выбранное» → плашка «${await own.locator('.draft b').first().innerText()}»`, (await own.locator('.draft b').first().innerText()) === '1 изменение');
    const sel = await html(own, '');
    check(`@${w} на / «${MQ}», этапа редактора «${NEW}» нет`, sel.includes(MQ) && !sel.includes(NEW));

    await own.goto(BASE + 'admin/?p=content&t=step');
    own.lastDialog = '';
    await click(own, own.locator('.draft > form[action*="a=publish"] button'));
    check(`@${w} «Опубликовать» с чужой правкой спросило: «${own.lastDialog.split('\n')[0]}»`, own.lastDialog.includes('чужие правки') && own.lastDialog.includes(NEW));
    check(`@${w} owner опубликовал → тост`, (await own.locator('.toast p.ok').innerText()).startsWith('Опубликовано'));
    const tb = await own.locator('.toast p.ok').evaluate((el) => [el.getBoundingClientRect().bottom, innerHeight]);
    check(`@${w} тост «Опубликовано» на первом экране: низ=${tb[0].toFixed(0)} из ${tb[1]}`, tb[0] <= tb[1]);
    check(`@${w} на / новый этап`, (await html(own, '')).includes(NEW));

    // тост «Отменить»: правка → «Отменить» → поле и превью как до правки
    await own.goto(BASE + 'admin/?p=content&t=step');
    await lastStep(own).locator('[name=title]').fill('Отмена ' + w);
    await click(own, lastStep(own).locator('button'));
    await click(own, own.locator('.toast button', { hasText: 'Отменить' }));
    check(`@${w} «Отменить» → «${await own.locator('.toast p.ok').innerText()}», в поле «${await lastStep(own).locator('[name=title]').inputValue()}»`,
      await lastStep(own).locator('[name=title]').inputValue() === NEW);
    check(`@${w} после «Отменить» в превью правки нет`, !(await html(own, 'admin/?p=preview')).includes('Отмена ' + w));
    await own.context().close();
    await ed.context().close();
  }

  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
