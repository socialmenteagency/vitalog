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
