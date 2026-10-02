// Confirmaciones y botones "ocupado" del backend, sin atributos onsubmit (la CSP no permite scripts en línea).
//   data-confirm="texto"  → pide confirmación antes de enviar el formulario
//   data-busy="texto"     → deshabilita el primer botón y le pone ese texto mientras se envía
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (!f || !f.getAttribute) return;
  var c = f.getAttribute('data-confirm');
  if (c && !window.confirm(c)) { ev.preventDefault(); return; }
  var busy = f.getAttribute('data-busy');
  if (busy) {
    var b = f.querySelector('button');
    if (b) { b.disabled = true; b.textContent = busy; }
  }
});
