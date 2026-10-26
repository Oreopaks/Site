// JS админки (inline запрещён CSP). Формы с data-confirm спрашивают подтверждение (удаление и т.п. — M4/M5).
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Счётчик «осталось N» под полями с data-max (лимит = maxlength = лимит сервера). maxlength снимаем: браузер молча
// режет вставку на полуслове — вместо этого текст остаётся целиком, а счётчик и красная рамка говорят, сколько сократить
function count(el) {
  var max = +el.getAttribute('data-max'), n = el.value.length, over = n > max, p = el.nextElementSibling;
  if (!p || !p.classList.contains('count')) {
    p = document.createElement('p');
    p.className = 'count';
    p.setAttribute('aria-live', 'polite');
    el.after(p);
  }
  p.textContent = over ? 'Слишком длинно: ' + n + ' из ' + max + ' — сократите на ' + (n - max) : 'осталось ' + (max - n);
  p.classList.toggle('err', over);
  if (over) el.classList.add('bad');
  else if (el.long) el.classList.remove('bad'); // рамку от ошибки сервера не снимаем
  el.long = over;
}
document.querySelectorAll('[data-max]').forEach(function (el) { el.removeAttribute('maxlength'); count(el); });

// Меню: «В шапке осталось N символов» — та же формула, что nav_left() на сервере (длины названий + 4 на пункт)
function navLeft() {
  var p = document.getElementById('nav-left');
  if (!p) return;
  var left = +p.getAttribute('data-room');
  document.querySelectorAll('[data-nav]').forEach(function (i) {
    if (i.getAttribute('data-nav') === 'row' || i.value !== '') left -= i.value.length + 4;
  });
  p.textContent = left < 0 ? 'Меню не помещается в шапку — сократите названия (лишних символов: ' + -left + ')' : 'В шапке осталось ' + left + ' символов';
  p.classList.toggle('err', left < 0);
}
document.addEventListener('input', function (e) {
  if (e.target.hasAttribute('data-max')) count(e.target);
  if (e.target.hasAttribute('data-nav')) navLeft();
});

// Живая карточка (техника, материалы): [data-pv] в .pv строки повторяют поля её формы — как в home.php
function card(row) {
  var pv = row && row.querySelector('.pv');
  if (!pv) return;
  var v = function (n) { var i = row.querySelector('[name="' + n + '"]'); return i ? i.value : ''; };
  var deal = v('price_kind') === 'contract';
  pv.querySelectorAll('[data-pv]').forEach(function (el) {
    var f = el.getAttribute('data-pv');
    if (f === 'img') {
      if (/^assets\//.test(v('img'))) el.src = '/' + v('img');
      el.style.objectPosition = v('img_pos') || '50% 50%'; // CSSOM, не атрибут style — CSP пропускает
    } else if (f === 'contract') el.hidden = !deal;
    else if (f === 'nc') el.hidden = deal;
    else if (f === 'price') el.textContent = (v('price_kind') === 'from' ? 'от ' : '') + String(+v('price') || 0).replace(/\B(?=(\d{3})+$)/g, ' ') + ' ₽';
    else {
      el.textContent = v(f);
      if (f === 'badge') el.hidden = !v(f);
    }
  });
}
document.querySelectorAll('.row').forEach(card);
document.addEventListener('input', function (e) { card(e.target.closest('.row')); });

// Фото: файл → POST a=upload → сервер отдаёт путь к .webp → он встаёт в поле «Путь к картинке» и в карточку
document.addEventListener('change', function (e) {
  var inp = e.target, url = inp.getAttribute('data-upload'), file = inp.files && inp.files[0];
  if (!url || !file) return;
  var st = inp.parentNode.querySelector('.up-st');
  var say = function (msg, bad) { st.textContent = msg; st.classList.toggle('err', !!bad); };
  if (file.size > 10 * 1024 * 1024) { say('Файл больше 10 МБ', true); inp.value = ''; return; }
  var fd = new FormData();
  fd.append('_csrf', inp.form.elements._csrf.value);
  fd.append('photo', file);
  say('Загружается…');
  fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.img) throw String(j.error || '');
      var img = inp.form.elements.img;
      img.value = j.img;
      img.dispatchEvent(new Event('input', { bubbles: true }));
      say('Загружено — нажмите «Сохранить»');
    })
    .catch(function (err) { say(typeof err === 'string' && err ? err : 'Файл больше 10 МБ или сессия истекла', true); });
  inp.value = '';
});
