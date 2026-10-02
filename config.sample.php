<?php
// Configuración de Vitalog (/salud + /backend).
//
// INSTALACIÓN: copia este archivo a  data/config.php  y edita los valores.
// data/ está bloqueado por .htaccess, así que config.php nunca se sirve por web,
// pero se puede editar en cualquier momento desde el File Manager de cPanel.

// Contraseña para VER la historia clínica (/salud) de la primera persona.
// Es la que se comparte con un médico. (Las demás personas tienen su propia
// contraseña, que se crea en el backend.) ¡Cámbiala antes de publicar!
define('SALUD_PASSWORD', 'cambiame-viz');

// Contraseña del panel de carga (/backend). No compartir. ¡Cámbiala!
define('BACKEND_PASSWORD', 'cambiame-backend');

// Nombre de la primera persona (se usa solo al crear la base; luego se edita
// en el backend, tarjeta Perfil).
// define('SALUD_PATIENT_NAME', 'Tu nombre');

// Las claves de IA (Anthropic y Gemini) NO hace falta ponerlas aquí: se pegan en el
// backend, tarjeta «Claves de IA», y se guardan en la base. Si pegas una allí, tiene
// prioridad sobre estas constantes, que quedan como respaldo.

// API key de Anthropic (console.anthropic.com) para la extracción de PDFs.
// Sin esto el backend funciona igual, pero solo con carga manual.
define('ANTHROPIC_API_KEY', '');

// Gemini (Google AI Studio / Vertex) para el resumen y las recomendaciones de /salud.
// Usar una key de cuenta CON facturación (los términos del tier gratuito permiten a
// Google usar lo que se envía). Sin key, el resto de la app funciona igual.
// define('GEMINI_API_KEY', '');
// define('GEMINI_MODEL', 'gemini-2.5-flash');   // cambiar si Google lo reemplaza

// Con true, si la base está vacía se cargan datos de ejemplo para ver el diseño.
// El backend tiene un botón para eliminarlos cuando empieces a cargar datos reales.
define('SEED_DUMMY', true);

// Optional: store the backend password as a hash instead of plain text. Generate it with
//   php -r "echo password_hash('your-long-password', PASSWORD_DEFAULT), PHP_EOL;"
// and paste it here; when set, BACKEND_PASSWORD is ignored.
// define('BACKEND_PASSWORD_HASH', '$2y$10$...');

// HTTPS: by default any http:// visit is redirected to https:// and HSTS is sent (except on localhost).
// Set false only if your server has no certificate (for example a test on your local network).
// define('SALUD_FORCE_HTTPS', false);

// If you get locked out (too many failed logins: 8 per IP, 10 for the backend password or 60 overall, per 15 min):
// wait 15 minutes, or delete  data/login_attempts.json  in cPanel's File Manager and sign in again.

// Only for a public demo site (NOT for your real install): when true, everybody is signed in
// without a password, nothing is saved, no files are uploaded and no AI is called.
// define('SALUD_DEMO_MODE', true);
