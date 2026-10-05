<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<script>
// Se ejecuta antes de que el navegador pinte nada: si esperara al final
// del documento, la página aparecería en claro y saltaría a oscuro. Ese
// parpadeo blanco es lo que hace que un modo oscuro se sienta barato.
(function(){
  try {
    var t = localStorage.getItem('lf-tema');
    if (t === 'dark' || t === 'light') {
      document.documentElement.setAttribute('data-theme', t);
      return;
    }
  } catch (e) {}
  // Sin preferencia guardada: claro. NUNCA se hereda del sistema sin que
  // el usuario lo pida.
  document.documentElement.setAttribute('data-theme', 'light');
})();
</script>


<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= P::e($titulo ?? 'LibertyFin') ?> · LibertyFin</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='DM Sans,system-ui,sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<meta name="theme-color" content="#27ae60">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<?php
// LA VERSIÓN SALE DE LA FECHA DEL ARCHIVO, no de un número a mano.
// Con `?v=2` fijo, cada cambio de estilos exigía acordarse de subirlo, y
// cuando a alguien se le olvidaba —siempre— el navegador seguía
// enseñando la hoja vieja. Tanto, que las ventanas flotantes se
// escribieron dentro de su vista solo para esquivar este problema.
$hoja = __DIR__ . '/../../../public/assets/css/libertyfin.css';
?>
<link rel="stylesheet" href="/assets/css/libertyfin.css?v=<?= is_file($hoja) ? filemtime($hoja) : '3' ?>">
<?php
// El color de la empresa se inyecta como variable. Todo lo demás
// —hovers, fondos tenues, anillos de foco— se calcula con color-mix,
// así que basta con este dato para que el sistema entero cambie.
$marca = $_SESSION['lf_marca_color'] ?? '';
if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)$marca)): ?>
<style>:root{--lf-brand:<?= $marca ?>}</style>
<?php endif; ?>
</head>
<body>
<div class="lf-app">
  <?php P::parcial('parciales/sidebar', ['activo' => $icono ?? '']); ?>
  <div class="lf-main">
    <?php P::parcial('parciales/topbar', ['titulo' => $titulo ?? '', 'icono' => $icono ?? 'panel', 'subtitulo' => $subtitulo ?? '']); ?>
    <div class="lf-cont">
      <?php
      // Aviso de suscripción: va dentro de .lf-cont para que se repinte
      // con cada navegación sin recarga, y no se muestra en la propia
      // pestaña Plan, donde ya está todo lo que dice.
      $aboSus = \LibertyFin\Servicio\Suscripcion::aviso();
      $enPlan = strpos($_SERVER['REQUEST_URI'] ?? '', '/cuenta') === 0
             && strpos($_SERVER['REQUEST_URI'] ?? '', 't=plan') !== false;
      if ($aboSus && !$enPlan): $vencida = $aboSus['dias'] < 0; ?>
        <a class="alert alert-<?= $vencida ? 'danger' : 'warning' ?> lf-aviso-plan"
           href="/cuenta?t=plan">
          <?= W::icono('alerta','18px') ?>
          <span><b><?= $vencida
              ? 'Tu suscripción venció el ' . P::e($aboSus['fecha']) . '.'
              : ($aboSus['dias'] === 0 ? 'Tu suscripción vence hoy.'
                 : 'Tu suscripción vence en ' . $aboSus['dias'] . ' día' . ($aboSus['dias'] === 1 ? '' : 's')
                   . ' (' . P::e($aboSus['fecha']) . ').') ?></b>
            <?= $vencida ? 'Renueva para no perder el acceso.' : 'Renueva a tiempo para no perder el acceso.' ?>
            <u>Renovar ahora</u></span>
        </a>
      <?php endif; ?>
      <?= $contenido ?></div>
  </div>
  <?php if (!empty($_SESSION['lf_mostrar_guia'])): unset($_SESSION['lf_mostrar_guia']);
        P::parcial('parciales/guia'); endif; ?>
  </div>
</div>

<?php /* ═══════════════════════════════════════════════════════
     ESTE JAVASCRIPT VA AL FINAL DEL CUERPO, NO EN LA CABEZA.

     Estaba arriba, en el mismo bloque que elige el tema, y eso
     rompía dos cosas de golpe porque allí el documento todavía no
     existe:

       · `document.querySelector('.lf-cont')` devolvía null, y el módulo
         de navegación se iba por su propia salida de emergencia en la
         primera línea. Nunca llegó a correr: por eso cambiar de sección
         seguía recargando la página entera.

       · `observe(document.body)` reventaba con "parameter 1 is not of
         type 'Node'". Y una excepción ahí mata el RESTO del bloque, así
         que `window.lfVentana` no llegaba a definirse y el botón de
         ventana libre caía en su respaldo: abrir la página.

     El detector de tema sí se queda arriba, y tiene que quedarse: si
     esperara hasta aquí, la página aparecería en claro y saltaría a
     oscuro. Ese parpadeo es lo que hace que un modo oscuro se sienta
     barato.
     ═══════════════════════════════════════════════════════ */ ?>
<script>
/* ══════════════════════════════════════════════════════
   PESTAÑAS SIN RECARGAR
   Sirve para cualquier sección: un contenedor con
   data-tabs, enlaces con data-tab y bloques con
   data-panel. Los enlaces siguen siendo enlaces, así que
   funcionan igual sin JavaScript y se pueden abrir en
   otra pestaña del navegador.
   ══════════════════════════════════════════════════════ */
(function () {
  function activar(caja, clave, empujar) {
    var hubo = false;
    caja.querySelectorAll('[data-panel]').forEach(function (p) {
      var es = p.dataset.panel === clave;
      p.hidden = !es;
      if (es) hubo = true;
    });
    if (!hubo) return false;

    caja.querySelectorAll('[data-tab]').forEach(function (t) {
      var es = t.dataset.tab === clave;
      t.classList.toggle('active', es);
      if (t.hasAttribute('aria-selected')) t.setAttribute('aria-selected', es ? 'true' : 'false');
    });

    /* La dirección se actualiza sin recargar: así recargar a mano,
       compartir el enlace o usar el botón de atrás siguen llevando a la
       misma pestaña. Sin esto, cambiar de pestaña y recargar devolvía a
       la primera sin explicación. */
    if (empujar) {
      var activo = caja.querySelector('[data-tab="' + CSS.escape(clave) + '"]');
      if (activo && activo.getAttribute('href')) {
        history.pushState({ lfTabs: caja.dataset.tabs, lfClave: clave },
                          '', activo.getAttribute('href'));
      }
    }
    return true;
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-tab]');
    if (!t) return;
    /* Ctrl, Cmd o botón de en medio: se deja pasar, porque el usuario
       está pidiendo abrirlo en otra pestaña del navegador. */
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;

    var caja = t.closest('[data-tabs]');
    if (!caja) return;
    if (activar(caja, t.dataset.tab, true)) ev.preventDefault();
  });

  window.addEventListener('popstate', function (ev) {
    var e = ev.state;
    if (e && e.lfTabs) {
      var caja = document.querySelector('[data-tabs="' + CSS.escape(e.lfTabs) + '"]');
      if (caja) { activar(caja, e.lfClave, false); return; }
    }
    /* Sin estado propio —por ejemplo al volver desde otra página— se
       lee de la dirección. */
    document.querySelectorAll('[data-tabs]').forEach(function (caja) {
      var par = new URLSearchParams(location.search);
      var v = par.get('tipo') || par.get('t') || par.get('pestana');
      if (v) activar(caja, v, false);
    });
  });
})();

/* ══════════════════════════════════════════════════════
   CAMBIAR DE PESTAÑA SIN RECARGAR LA PÁGINA
   Vale para cualquier sección: pestañas, filtros y
   paginación. Trae solo el contenido y lo cambia en su
   sitio, en vez de volver a pedir la página entera con su
   menú, su barra y sus estilos.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.history || !window.fetch) return;

  /* Se busca CADA VEZ, no una sola al arrancar.
     Guardarlo en una variable al cargar ataba el módulo entero al orden
     del documento: si por lo que fuera no estaba todavía, la primera
     línea se iba por la salida de emergencia y la navegación sin
     recarga quedaba muerta sin que nada lo dijera. Buscarlo al usarlo
     cuesta nada y no se puede romper así. */
  function caja() { return document.querySelector('.lf-cont'); }

  var enCurso = null;

  /* Lo que NUNCA se trae por partes.
     Un enlace puede descargar un archivo, imprimir un ticket, salir de
     la sesión o disparar una acción. Cambiar media pantalla en esos
     casos deja la aplicación mintiendo sobre dónde está. */
  /* Se compara por TRAMO COMPLETO, no por prefijo de texto.
     Con `indexOf(prefijo) === 0` la sección `/tickets` quedaba
     bloqueada por la regla de `/ticket` —el ticket de una venta— y era
     la única que seguía recargando la página entera. */
  var FUERA = /^\/(salir|login|registro|ticket|imprimir|descargar|exportar|pdf|qr)(\/|$)/;

  function esNuestro(a) {
    if (!a || !a.getAttribute('href')) return false;
    if (a.target || a.hasAttribute('download')) return false;
    if (a.hasAttribute('data-completo')) return false;
    var u;
    try { u = new URL(a.href, location.href); } catch (e) { return false; }
    if (u.origin !== location.origin) return false;
    if (u.pathname === location.pathname && u.hash && u.search === location.search) return false;
    if (FUERA.test(u.pathname)) return false;
    /* Pestañas, filtros y paginación —lo de siempre— y además las
       SECCIONES del menú y los enlaces que se marquen a mano.
       Cambiar de sección volvía a pedir la página entera: su menú, su
       barra, sus fuentes y su hoja de estilos, para cambiar solo el
       centro. */
    return a.matches('.lf-pill, .lf-pag a, [data-tab], .lf-nav a, .lf-dedo a, [data-parcial]');
  }

  function ejecutarScripts(donde) {
    /* Un <script> insertado con innerHTML no corre. Se vuelve a crear
       para que sí: sin esto, los botones de copiar, los selectores y los
       confirmar del contenido nuevo quedan muertos. */
    donde.querySelectorAll('script').forEach(function (viejo) {
      var nuevo = document.createElement('script');
      for (var i = 0; i < viejo.attributes.length; i++) {
        nuevo.setAttribute(viejo.attributes[i].name, viejo.attributes[i].value);
      }
      nuevo.textContent = viejo.textContent;
      viejo.parentNode.replaceChild(nuevo, viejo);
    });
  }

  /* La primera parte de la ruta: /ventas/137 y /ventas son la misma
     sección, /clientes es otra. */
  function seccionDe(u) {
    try { return '/' + (new URL(u, location.href)).pathname.split('/')[1]; }
    catch (e) { return u; }
  }

  function ir(url, empujar) {
    var cont = caja();
    /* Sin dónde ponerlo, se navega como siempre. Mejor una recarga que
       una pantalla que no responde al clic. */
    if (!cont) { location.href = url; return; }
    var antes = seccionDe(location.pathname);
    if (enCurso) enCurso.abort();
    enCurso = new AbortController();
    cont.classList.add('cargando');

    fetch(url, { signal: enCurso.signal, headers: { 'X-LF-Parcial': '1' },
                 credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nuevo = doc.querySelector('.lf-cont');
        if (!nuevo) { location.href = url; return; }

        cont.innerHTML = nuevo.innerHTML;

        /* La barra superior y el menú viajan con la página pedida. Sin
           esto, al cambiar de sección el centro era el nuevo pero el
           título seguía diciendo el anterior y la sección encendida en
           el menú era la de antes: la aplicación mentía sobre dónde
           estabas parado. */
        var barraVieja = document.querySelector('.lf-top'),
            barraNueva = doc.querySelector('.lf-top');
        if (barraVieja && barraNueva) {
          barraVieja.innerHTML = barraNueva.innerHTML;
          ejecutarScripts(barraVieja);
        }
        var navNueva = doc.querySelector('.lf-nav');
        if (navNueva) {
          var activa = navNueva.querySelector('a.on');
          var ruta = activa ? activa.getAttribute('href') : null;
          document.querySelectorAll('.lf-nav a, .lf-dedo a').forEach(function (a) {
            a.classList.toggle('on', ruta !== null && a.getAttribute('href') === ruta);
          });
        }

        ejecutarScripts(cont);
        if (doc.title) document.title = doc.title;
        if (empujar) history.pushState({ lfNav: 1 }, '', url);
        cont.classList.remove('cargando');
        /* Quien escucha —un lector de pantalla, por ejemplo— no se
           entera de que cambió medio documento si nadie lo dice. */
        document.dispatchEvent(new CustomEvent('lf:cargado', { detail: { url: url } }));

        /* Cambiar de sección deja arriba. Antes solo se subía si había
           una tarjeta por encima del borde, pensando en los filtros;
           al saltar de Ventas a Clientes eso dejaba a medio documento,
           leyendo el final de una lista que ya no era la suya. */
        if (seccionDe(url) !== antes) {
          window.scrollTo({ top: 0, behavior: 'instant' in document.documentElement.style
                            ? 'instant' : 'auto' });
        }
        /* Se sube al principio del bloque, no de la página: con el
           filtro arriba, quedarse donde estaba hace creer que no pasó
           nada. */
        var caja = cont.querySelector('.card');
        if (caja && caja.getBoundingClientRect().top < 0) {
          caja.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        cont.classList.remove('cargando');
        location.href = url;   /* si algo falla, se recarga como siempre */
      });
  }

  document.addEventListener('click', function (ev) {
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
    /* Si el módulo de pestañas ya lo atendió, no se vuelve a pedir la
       página: se repintaba el contenido y el scroll saltaba a la primera
       tarjeta ("De dónde a dónde") en vez de quedarse en el reporte. */
    if (ev.defaultPrevented) return;
    var a = ev.target.closest('a');
    if (!esNuestro(a)) return;
    ev.preventDefault();
    ir(a.href, true);
  });

  window.addEventListener('popstate', function (ev) {
    if (ev.state && ev.state.lfNav) ir(location.href, false);
  });
})();

/* ══════════════════════════════════════════════════════
   SELECTOR DE ARCHIVO
   El control nativo mide lo que mida el nombre del archivo
   y no acepta puntos suspensivos. Con `comprobante de pago
   enero 2026 sucursal centro.pdf` se salía de su tarjeta.
   El input sigue siendo el mismo —se envía igual, funciona
   igual sin JavaScript—: lo que se dibuja encima es lo que
   sí obedece al ancho.
   ══════════════════════════════════════════════════════ */
(function () {
  function nombrar(inp) {
    var caja = inp.closest('.lf-file');
    if (!caja) return;
    var et = caja.querySelector('.n');
    if (!et) return;
    var f = inp.files;
    if (!f || !f.length) {
      et.textContent = et.dataset.vacio || 'Ningún archivo elegido';
      et.classList.remove('hay');
      return;
    }
    et.textContent = f.length === 1 ? f[0].name : f.length + ' archivos';
    et.classList.add('hay');
    /* El nombre completo, por si no cabe. */
    et.title = f.length === 1 ? f[0].name : '';
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('.lf-file input[type=file]')) nombrar(e.target);
  });
  /* El botón es un <label for>, así que abre el diálogo solo. Esto es
     para cuando no se puede usar `for` —dentro de otra etiqueta—. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.lf-file .bt');
    if (!b || b.tagName === 'LABEL') return;
    var inp = b.closest('.lf-file').querySelector('input[type=file]');
    if (inp) inp.click();
  });
})();

/* ══════════════════════════════════════════════════════
   AJUSTES QUE SE GUARDAN SIN RECARGAR
   Elegir "los pagos se aplican solos" recargaba la página
   entera: se perdía el scroll, la pestaña abierta y el
   sitio donde estabas leyendo, para cambiar una palabra.

   Cualquier formulario con `data-guardar` se envía por
   detrás. Sin JavaScript sigue siendo un formulario normal
   que recarga, que es como debe degradar.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.fetch || !window.FormData) return;

  function aviso(form, texto, tipo) {
    var caja = form.querySelector('[data-aviso]');
    if (!caja) {
      caja = document.createElement('p');
      caja.setAttribute('data-aviso', '');
      form.appendChild(caja);
    }
    caja.className = 'lf-guardado ' + (tipo === 'error' ? 'mal' : 'bien');
    caja.textContent = texto;
    caja.hidden = false;
    clearTimeout(caja._t);
    /* El aviso de error se queda: si algo no se guardó, enterarse
       cuatro segundos no basta. El de éxito sí se va solo. */
    if (tipo !== 'error') {
      caja._t = setTimeout(function () { caja.hidden = true; }, 4000);
    }
  }

  function enviar(form) {
    var btn = form.querySelector('[type=submit]');
    if (form._enviando) return;
    form._enviando = true;
    if (btn) { btn.disabled = true; btn.dataset.antes = btn.textContent; btn.textContent = 'Guardando…'; }

    fetch(form.action, {
      method: (form.method || 'post').toUpperCase(),
      body: new FormData(form),
      headers: { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); })
      .then(function (res) {
        var j = null;
        try { j = JSON.parse(res.t); } catch (e) {}
        if (j && typeof j.ok !== 'undefined') {
          aviso(form, j.mensaje || j.error || (j.ok ? 'Guardado.' : 'No se pudo guardar.'),
                j.ok ? 'ok' : 'error');
          if (j.ok && j.recargar) location.reload();
          return;
        }
        /* El servidor contestó una página, no JSON: la vista todavía no
           sabe responder por detrás. Se recarga, que es lo que habría
           pasado de todos modos, en vez de dejar al usuario sin saber
           si se guardó. */
        location.reload();
      })
      .catch(function () {
        aviso(form, 'No se pudo guardar: revisa tu conexión.', 'error');
      })
      .then(function () {
        form._enviando = false;
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.antes || 'Guardar'; }
      });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-guardar]');
    if (!f) return;
    e.preventDefault();
    enviar(f);
  });

  /* Elegir una opción guarda sola. Un formulario de una sola decisión
     no necesita confirmarse: el botón pasa a ser un trámite. */
  document.addEventListener('change', function (e) {
    var f = e.target.closest('form[data-guardar][data-al-elegir]');
    if (!f || !e.target.matches('input[type=radio],input[type=checkbox],select')) return;
    /* La tarjeta elegida se marca al momento, sin esperar al servidor:
       el cambio tiene que sentirse en el dedo. */
    f.querySelectorAll('.m').forEach(function (m) {
      var r = m.querySelector('input');
      m.classList.toggle('on', !!(r && r.checked));
    });
    enviar(f);
  });
})();

/* ══════════════════════════════════════════════════════
   VER SIN PERDER EL SITIO
   Abrir una venta desde la lista te sacaba de la lista:
   leías tres datos y volvías con el botón de atrás, al
   principio de la tabla y sin el filtro que tenías puesto.

   Cualquier enlace con `data-modal` se abre en un panel
   encima. Dentro del panel se puede seguir navegando —a la
   ficha del cliente, a otra venta— y al cerrar sigues donde
   estabas, con tu filtro y tu scroll intactos.

   Sigue siendo un enlace: ctrl+clic, "abrir en otra
   pestaña" y entrar sin JavaScript funcionan igual.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.fetch) return;

  var caja = null, cuerpo = null, titulo = null, enlace = null,
      pila = [], devolver = null, pidiendo = null;

  function armar() {
    if (caja) return;
    caja = document.createElement('div');
    caja.className = 'lf-vistazo';
    caja.hidden = true;
    caja.innerHTML =
      '<div class="velo" data-cerrar></div>' +
      '<div class="hoja" role="dialog" aria-modal="true" aria-label="Detalle">' +
        '<header>' +
          '<button type="button" class="atras" hidden aria-label="Volver">&#8249;</button>' +
          '<b class="tit">Cargando…</b>' +
          '<button type="button" class="libre" title="Abrir como ventana que se mueve">' +
            'Ventana libre</button>' +
          '<a class="abrir" href="#" target="_blank" rel="noopener">Abrir completo</a>' +
          '<button type="button" class="cerrar" data-cerrar aria-label="Cerrar">&times;</button>' +
        '</header>' +
        '<div class="cuerpo"><div class="cargando">Cargando…</div></div>' +
      '</div>';
    document.body.appendChild(caja);
    cuerpo = caja.querySelector('.cuerpo');
    titulo = caja.querySelector('.tit');
    enlace = caja.querySelector('.abrir');

    caja.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-cerrar')) cerrar();
    });
    caja.querySelector('.atras').addEventListener('click', function () {
      if (pila.length > 1) { pila.pop(); traer(pila[pila.length - 1], false); }
    });
    /* SACARLO A UNA VENTANA QUE SE MUEVE.
       El panel tapa la pantalla: sirve para mirar una cosa, no para
       trabajar con dos a la vez. Pasa a la ventana la direccion que se
       esta viendo —no la primera— y se queda donde ibas. */
    caja.querySelector('.libre').addEventListener('click', function () {
      var url = pila.length ? pila[pila.length - 1] : enlace.getAttribute('href');
      var t = titulo.textContent;
      cerrar();
      if (window.lfVentana) window.lfVentana(url, t);
      else location.href = url;
    });
    /* Dentro del panel, los enlaces siguen dentro del panel. Saltar a
       página completa desde aquí tiraría el contexto que el panel
       existe para conservar. */
    cuerpo.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      var a = e.target.closest('a');
      if (!a || a.target || a.hasAttribute('download')) return;
      var u;
      try { u = new URL(a.href, location.href); } catch (err) { return; }
      if (u.origin !== location.origin) return;
      if (/\/(salir|login|ticket|imprimir|descargar|exportar|pdf|qr)/.test(u.pathname)) return;
      e.preventDefault();
      traer(u.pathname + u.search, true);
    });
  }

  function traer(url, apilar) {
    armar();
    if (apilar) pila.push(url);
    caja.querySelector('.atras').hidden = pila.length <= 1;
    enlace.href = url;
    cuerpo.innerHTML = '<div class="cargando">Cargando…</div>';

    if (pidiendo) pidiendo.abort();
    pidiendo = new AbortController();
    fetch(url, { signal: pidiendo.signal, credentials: 'same-origin',
                 headers: { 'X-LF-Parcial': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'),
            dentro = doc.querySelector('.lf-cont');
        if (!dentro) { location.href = url; return; }
        cuerpo.innerHTML = dentro.innerHTML;
        /* Un <script> puesto con innerHTML no corre. Sin esto, los
           botones de copiar y los confirmar del contenido traído
           quedan muertos. */
        cuerpo.querySelectorAll('script').forEach(function (v) {
          var n = document.createElement('script');
          for (var i = 0; i < v.attributes.length; i++) {
            n.setAttribute(v.attributes[i].name, v.attributes[i].value);
          }
          n.textContent = v.textContent;
          v.parentNode.replaceChild(n, v);
        });
        var t = doc.querySelector('.lf-top-tit b, .lf-top-tit h1, h1');
        titulo.textContent = t ? t.textContent.trim() : (doc.title || 'Detalle');
        cuerpo.scrollTop = 0;
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        cuerpo.innerHTML = '<div class="cargando">No se pudo cargar. ' +
          '<a href="' + url + '">Abrir completo</a></div>';
      });
  }

  function abrir(url, origen) {
    armar();
    pila = [];
    devolver = origen || null;
    document.body.classList.add('lf-vistazo-abierto');
    caja.hidden = false;
    traer(url, true);
  }

  function cerrar() {
    if (!caja || caja.hidden) return;
    caja.hidden = true;
    document.body.classList.remove('lf-vistazo-abierto');
    pila = [];
    if (pidiendo) { pidiendo.abort(); pidiendo = null; }
    /* El foco vuelve a donde estaba. Quien navega con teclado se
       quedaba al principio del documento al cerrar. */
    if (devolver && document.contains(devolver)) devolver.focus();
    devolver = null;
  }

  document.addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var a = e.target.closest('[data-modal]');
    if (!a || !a.getAttribute('href')) return;
    e.preventDefault();
    abrir(a.getAttribute('href'), a);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrar();
  });

  window.lfVistazo = abrir;
  window.lfVistazoCerrar = cerrar;
})();

/* ══════════════════════════════════════════════════════
   VENTANA LIBRE
   El panel tapa la pantalla: sirve para mirar una cosa,
   no para trabajar con dos a la vez. La ventana libre se
   mueve, se agranda, se encoge y deja ver lo de abajo, así
   que puedes tener la venta abierta mientras sigues en la
   lista —o dos ventas lado a lado—.

   La maquinaria venía de Comisiones, donde ya existía pero
   solo para los colaboradores. Aquí sirve en cualquier
   sección, y DENTRO de la ventana se puede seguir
   navegando: de una venta a su cliente, del cliente a otra
   sección. Eso es lo que antes no se podía.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.fetch || window.lfVentana) return;

  var abiertas = [], Z = 200, MINW = 320, MINH = 180;
  var ICO = {
    atras: '<svg viewBox="0 0 16 16"><path d="M10 3.5 5.5 8l4.5 4.5"/></svg>',
    mini:  '<svg viewBox="0 0 16 16"><path d="M3.5 8h9"/></svg>',
    max:   '<svg viewBox="0 0 16 16"><rect x="3.5" y="3.5" width="9" height="9" rx="1.5"/></svg>',
    x:     '<svg viewBox="0 0 16 16"><path d="M4 4l8 8M12 4l-8 8"/></svg>'
  };

  function lim(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function alFrente(w) {
    abiertas.forEach(function (o) { o.classList.remove('activa'); });
    w.classList.add('activa');
    w.style.zIndex = ++Z > 290 ? (Z = 200) : Z;
    var i = abiertas.indexOf(w);
    if (i >= 0) { abiertas.splice(i, 1); abiertas.push(w); }
  }

  /* Dónde se abre y de qué tamaño. Se recuerda entre visitas: quien
     acomodó la ventana donde le sirve no tiene que volver a hacerlo
     cada vez. En cascada cuando hay varias, para que no se tapen. */
  function sitio(w) {
    var g = null;
    try { g = JSON.parse(localStorage.getItem('lf-ventana') || 'null'); } catch (e) {}
    var an = g && g.w ? g.w : Math.min(760, innerWidth - 32),
        al = g && g.h ? g.h : Math.min(540, innerHeight - 120);
    an = lim(an, MINW, innerWidth - 16);
    al = lim(al, MINH, innerHeight - 16);
    var n = (abiertas.length % 7) * 26;
    w.style.width  = an + 'px';
    w.style.height = al + 'px';
    w.style.left = lim((g && g.x != null ? g.x : (innerWidth - an) / 2) + n,
                       4, Math.max(4, innerWidth - an - 4)) + 'px';
    w.style.top  = lim((g && g.y != null ? g.y : 76) + n,
                       4, Math.max(4, innerHeight - 90)) + 'px';
  }

  function guardarSitio(w) {
    if (!w.classList.contains('mini') && !w.classList.contains('grande')) {
      try {
        localStorage.setItem('lf-ventana', JSON.stringify({
          x: parseInt(w.style.left, 10), y: parseInt(w.style.top, 10),
          w: parseInt(w.style.width, 10), h: parseInt(w.style.height, 10)
        }));
      } catch (e) { /* modo privado: se pierde la posición, nada más */ }
    }
    guardarAbiertas();
  }

  /* ── QUE LA VENTANA SOBREVIVA AL CAMBIO DE SECCIÓN ──
     Las ventanas viven en <body>, fuera de `.lf-cont`, así que cambiar
     de sección sin recargar no las toca. Pero basta UNA recarga
     completa —entrar por la barra de direcciones, enviar un formulario,
     volver con el botón de atrás— para que desaparezcan, y con ellas lo
     que el usuario estaba consultando al lado.

     Se apunta lo que hay abierto y se vuelve a abrir al cargar. Va en
     `sessionStorage` y no en `localStorage` a propósito: es de ESTA
     pestaña. Compartirlo haría que abrir una segunda pestaña arrastrara
     las ventanas de la primera, que es justo lo que nadie pidió. */
  var LLAVE = 'lf-ventanas-abiertas';

  function guardarAbiertas() {
    try {
      var datos = abiertas.map(function (w) {
        return {
          url: w.dataset.url || '',
          tit: (w.querySelector('.lf-win-tit b') || {}).textContent || '',
          /* Comisiones identifica sus ventanas con esto. Se guarda para
             que al volver reconozca la suya y no abra una segunda. */
          llave: w.dataset.llave || '',
          x: parseInt(w.style.left, 10) || 0, y: parseInt(w.style.top, 10) || 0,
          w: parseInt(w.style.width, 10) || 0, h: parseInt(w.style.height, 10) || 0,
          mini: w.classList.contains('mini'),
          grande: w.classList.contains('grande')
        };
      }).filter(function (d) { return d.url; });
      sessionStorage.setItem(LLAVE, JSON.stringify(datos));
    } catch (e) { /* sin sessionStorage se pierde al recargar, nada más */ }
  }

  function restaurarAbiertas() {
    var datos;
    try { datos = JSON.parse(sessionStorage.getItem(LLAVE) || '[]'); }
    catch (e) { return; }
    if (!datos || !datos.length) return;
    datos.forEach(function (d) {
      var ya = false;
      abiertas.forEach(function (o) { if (o.dataset.url === d.url) ya = true; });
      if (ya) return;
      var w = abrir(d.url, d.tit || 'Ventana');
      if (!w) return;
      if (d.llave) w.dataset.llave = d.llave;
      /* Se le devuelve EXACTAMENTE el sitio y el tamaño que tenía, no
         el de por omisión: una ventana que vuelve a aparecer en otro
         lado obliga a recolocarla cada vez. */
      if (d.w) w.style.width  = lim(d.w, MINW, innerWidth - 16) + 'px';
      if (d.h) w.style.height = lim(d.h, MINH, innerHeight - 16) + 'px';
      if (d.x || d.y) {
        w.style.left = lim(d.x, 2, Math.max(2, innerWidth - 90)) + 'px';
        w.style.top  = lim(d.y, 2, Math.max(2, innerHeight - 40)) + 'px';
      }
      if (d.mini) w.classList.add('mini');
      if (d.grande) w.classList.add('grande');
    });
  }

  /* ── Mover y cambiar de tamaño ─────────────────────── */
  function arrastrar(w) {
    var bar = w.querySelector('.lf-win-bar');
    bar.addEventListener('pointerdown', function (e) {
      if (e.target.closest('button')) return;         /* los botones no mueven */
      if (w.classList.contains('grande')) return;
      alFrente(w);
      var r = w.getBoundingClientRect(),
          dx = e.clientX - r.left, dy = e.clientY - r.top;
      w.classList.add('moviendo');
      bar.setPointerCapture(e.pointerId);
      function mover(ev) {
        /* No se deja arrastrar fuera de la pantalla: una ventana que se
           va al otro lado del borde ya no se puede recuperar. */
        w.style.left = lim(ev.clientX - dx, -r.width + 90, innerWidth - 90) + 'px';
        w.style.top  = lim(ev.clientY - dy, 2, innerHeight - 40) + 'px';
      }
      function soltar() {
        bar.removeEventListener('pointermove', mover);
        bar.removeEventListener('pointerup', soltar);
        bar.removeEventListener('pointercancel', soltar);
        w.classList.remove('moviendo');
        guardarSitio(w);
      }
      bar.addEventListener('pointermove', mover);
      bar.addEventListener('pointerup', soltar);
      bar.addEventListener('pointercancel', soltar);
      e.preventDefault();
    });
  }

  function redimensionar(w) {
    w.querySelectorAll('.lf-win-grip').forEach(function (g) {
      g.addEventListener('pointerdown', function (e) {
        alFrente(w);
        var r = w.getBoundingClientRect(),
            oeste = g.dataset.grip === 'sw',
            x0 = e.clientX, y0 = e.clientY,
            a0 = r.width, h0 = r.height, l0 = r.left;
        w.classList.add('redim');
        g.setPointerCapture(e.pointerId);
        function mover(ev) {
          var da = oeste ? (x0 - ev.clientX) : (ev.clientX - x0);
          var an = lim(a0 + da, MINW, innerWidth - 8);
          w.style.width  = an + 'px';
          w.style.height = lim(h0 + (ev.clientY - y0), MINH, innerHeight - 8) + 'px';
          if (oeste) w.style.left = lim(l0 - (an - a0), 2, innerWidth - MINW) + 'px';
        }
        function soltar() {
          g.removeEventListener('pointermove', mover);
          g.removeEventListener('pointerup', soltar);
          g.removeEventListener('pointercancel', soltar);
          w.classList.remove('redim');
          guardarSitio(w);
        }
        g.addEventListener('pointermove', mover);
        g.addEventListener('pointerup', soltar);
        g.addEventListener('pointercancel', soltar);
        e.preventDefault();
      });
    });
  }

  /* ── Traer contenido ───────────────────────────────── */
  function cargar(w, url, apilar) {
    var cuerpo = w.querySelector('.lf-win-cuerpo'),
        tit = w.querySelector('.lf-win-tit b');

    if (apilar && cuerpo.innerHTML.indexOf('Cargando') === -1) {
      /* Se guarda lo que había tal cual. No hace falta saber cómo se
         cargó —puede venir de otra sección con su propia forma de
         pedirlo— y así volver atrás funciona igual en todos los casos. */
      w._pila.push({ html: cuerpo.innerHTML, tit: tit.textContent,
                     scroll: cuerpo.scrollTop, url: w.dataset.url || '' });
    }
    w.dataset.url = url;
    /* Una ventana adoptada de otro código puede no traer estos botones:
       no son suyos hasta que se navega por primera vez. */
    var at = w.querySelector('.atras'); if (at) at.hidden = !w._pila.length;
    var ab = w.querySelector('.abrir'); if (ab) ab.href = url;
    /* Al navegar cambia lo que la ventana está enseñando: si se
       recarga después, tiene que volver donde iba, no al principio. */
    guardarAbiertas();

    if (w._ac) w._ac.abort();
    w._ac = new AbortController();
    w.classList.add('cargando');
    fetch(url, { signal: w._ac.signal, credentials: 'same-origin',
                 headers: { 'X-LF-Parcial': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'),
            dentro = doc.querySelector('.lf-cont');
        /* Una página completa trae su `.lf-cont`; un trozo pedido a un
           endpoint parcial viene suelto y se usa como está. */
        cuerpo.innerHTML = dentro ? dentro.innerHTML : html;
        cuerpo.querySelectorAll('script').forEach(function (v) {
          var n = document.createElement('script');
          for (var i = 0; i < v.attributes.length; i++) {
            n.setAttribute(v.attributes[i].name, v.attributes[i].value);
          }
          n.textContent = v.textContent;
          v.parentNode.replaceChild(n, v);
        });
        var t = doc.querySelector('.lf-top-tit b, .lf-top-tit h1, h1');
        if (t) tit.textContent = t.textContent.trim();
        else if (doc.title) tit.textContent = doc.title.split('·')[0].trim();
        cuerpo.scrollTop = 0;
        w.classList.remove('cargando');
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        w.classList.remove('cargando');
        cuerpo.innerHTML = '<p class="lf-win-msj">No se pudo cargar. ' +
          '<a href="' + url + '">Abrir en la página</a></p>';
      });
  }

  function atras(w) {
    var v = w._pila.pop();
    if (!v) return;
    var cuerpo = w.querySelector('.lf-win-cuerpo');
    cuerpo.innerHTML = v.html;
    w.querySelector('.lf-win-tit b').textContent = v.tit;
    cuerpo.scrollTop = v.scroll || 0;
    if (v.url) w.dataset.url = v.url;
    var at2 = w.querySelector('.atras'); if (at2) at2.hidden = !w._pila.length;
    guardarAbiertas();
    /* De vuelta en el contenido propio de la ventana, su buscador
       vuelve a servir. */
    if (!w._pila.length) {
      var b = w.querySelector('.lf-win-busca');
      if (b) b.hidden = false;
    }
  }

  function cerrar(w) {
    var i = abiertas.indexOf(w);
    if (i >= 0) abiertas.splice(i, 1);
    if (w._ac) w._ac.abort();
    w.remove();
    guardarAbiertas();
  }

  /* ── Abrir ─────────────────────────────────────────── */
  function abrir(url, titulo) {
    /* La misma dirección no se abre dos veces: se trae al frente. */
    for (var i = 0; i < abiertas.length; i++) {
      if (abiertas[i].dataset.url === url) {
        abiertas[i].classList.remove('mini');
        alFrente(abiertas[i]);
        return abiertas[i];
      }
    }
    var w = document.createElement('div');
    w.className = 'lf-win lf-win-libre';
    w.setAttribute('role', 'dialog');
    w.setAttribute('aria-label', titulo || 'Ventana');
    w._pila = [];
    w.innerHTML =
      '<div class="lf-win-bar">' +
        '<button type="button" class="atras" hidden title="Volver" aria-label="Volver">' + ICO.atras + '</button>' +
        '<div class="lf-win-tit"><b>' + (titulo || 'Cargando…') + '</b></div>' +
        '<a class="abrir" href="' + url + '" title="Abrir en la página completa">Abrir</a>' +
        '<div class="lf-win-btns">' +
          '<button type="button" class="mini" title="Minimizar" aria-label="Minimizar">' + ICO.mini + '</button>' +
          '<button type="button" class="maxi" title="Agrandar" aria-label="Agrandar">' + ICO.max + '</button>' +
          '<button type="button" class="cerrar" title="Cerrar" aria-label="Cerrar">' + ICO.x + '</button>' +
        '</div>' +
      '</div>' +
      '<div class="lf-win-cuerpo"><p class="lf-win-msj">Cargando…</p></div>' +
      '<span class="lf-win-grip sw" data-grip="sw"></span>' +
      '<span class="lf-win-grip se" data-grip="se"></span>';
    sitio(w);
    document.body.appendChild(w);
    abiertas.push(w);
    alFrente(w);
    arrastrar(w);
    redimensionar(w);

    w.addEventListener('pointerdown', function () { alFrente(w); });
    w.querySelector('.atras').addEventListener('click', function () { atras(w); });
    w.querySelector('.cerrar').addEventListener('click', function () { cerrar(w); });
    w.querySelector('.mini').addEventListener('click', function () {
      w.classList.toggle('mini');
      w.classList.remove('grande');
      guardarAbiertas();
    });
    w.querySelector('.maxi').addEventListener('click', function () {
      w.classList.remove('mini');
      if (w.classList.contains('grande')) {
        w.classList.remove('grande');
        sitio(w);
      } else {
        /* Antes de agrandar se apunta dónde estaba, para devolverla ahí
           y no al sitio por omisión. */
        guardarSitio(w);
        w.classList.add('grande');
      }
      guardarAbiertas();
    });

    cargar(w, url, false);
    guardarAbiertas();
    return w;
  }

  /* ── Navegar DENTRO de la ventana ──────────────────── */
  /* Esto es lo que faltaba: en Comisiones se podía abrir la ventana de
     un colaborador pero cualquier enlace de dentro sacaba de ahí y
     recargaba la página entera. Ahora el enlace se queda en la ventana,
     con su botón de volver. Vale para las de Comisiones y para las
     nuevas, porque escucha en el cuerpo de cualquiera. */
  var FUERA = /\/(salir|login|registro|ticket|imprimir|descargar|exportar|pdf|qr)(\/|$|\?)/;
  document.addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var cuerpo = e.target.closest('.lf-win-cuerpo');
    if (!cuerpo) return;
    var a = e.target.closest('a');
    if (!a || !a.getAttribute('href') || a.target || a.hasAttribute('download')) return;
    var href = a.getAttribute('href');
    if (href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    var u;
    try { u = new URL(a.href, location.href); } catch (err) { return; }
    if (u.origin !== location.origin || FUERA.test(u.pathname)) return;

    var w = cuerpo.closest('.lf-win');
    if (!w) return;
    if (!w._pila) w._pila = [];
    /* Una ventana de Comisiones no trae estos botones: se le ponen la
       primera vez que se navega, para que pueda volver. */
    if (!w.querySelector('.atras')) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'atras'; b.title = 'Volver';
      b.setAttribute('aria-label', 'Volver');
      b.innerHTML = ICO.atras;
      b.addEventListener('click', function () { atras(w); });
      w.querySelector('.lf-win-bar').insertBefore(b, w.querySelector('.lf-win-bar').firstChild);
    }
    var busca = w.querySelector('.lf-win-busca');
    if (busca) busca.hidden = true;
    e.preventDefault();
    alFrente(w);
    cargar(w, u.pathname + u.search, true);
  });

  /* ── ADOPTAR LAS VENTANAS QUE ABRE OTRO ──
     Comisiones crea las suyas con su propio código y no pasan por
     `abrir()`, así que el módulo no las conocía: ni entraban en el
     orden de apilado ni sobrevivían a una recarga. Se vigila <body> y
     se adoptan en cuanto aparecen. Vigilar es mejor que pedirle a
     Comisiones que avise: así funciona también con cualquier ventana
     que se añada mañana sin saber que esto existe. */
  function vigilarVentanas() {
    /* `observe` sobre null lanza "parameter 1 is not of type 'Node'", y
       una excepción aquí mata el resto del bloque: `window.lfVentana`
       no se definía y el botón de ventana libre acababa abriendo la
       página. Ahora el cuerpo ya existe —esto corre al final— pero la
       comprobación se queda: es una línea y evita que mover el script
       lo rompa otra vez. */
    if (!window.MutationObserver || !document.body) return;
    new MutationObserver(function (cambios) {
      var hubo = false;
      cambios.forEach(function (c) {
        [].forEach.call(c.addedNodes, function (n) {
          if (n.nodeType !== 1 || !n.classList || !n.classList.contains('lf-win')) return;
          if (abiertas.indexOf(n) >= 0) return;
          if (!n._pila) n._pila = [];
          abiertas.push(n);
          hubo = true;
        });
        [].forEach.call(c.removedNodes, function (n) {
          if (n.nodeType !== 1 || !n.classList || !n.classList.contains('lf-win')) return;
          var i = abiertas.indexOf(n);
          if (i >= 0) { abiertas.splice(i, 1); hubo = true; }
        });
      });
      if (hubo) setTimeout(guardarAbiertas, 60);   /* tras su primera carga */
    }).observe(document.body, { childList: true });
  }
  if (document.body) vigilarVentanas();
  else document.addEventListener('DOMContentLoaded', vigilarVentanas);

  /* Solo al CARGAR la página, no al cambiar de sección sin recargar:
     ahí las ventanas siguen puestas y volver a abrirlas las duplicaría. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restaurarAbiertas);
  } else {
    restaurarAbiertas();
  }

  window.lfVentana = abrir;
  window.lfVentanasAbiertas = function () { return abiertas.slice(); };
})();
</script>
</body>
</html>
