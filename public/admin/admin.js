// JS админки (inline запрещён CSP). Формы с data-confirm спрашивают подтверждение (удаление и т.п. — M4/M5).
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
