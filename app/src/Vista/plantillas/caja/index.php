<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!empty($ligaLista)): $l = $ligaLista; ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 46%,transparent);
         margin-bottom:18px">
  <header class="card-header">
    <div><span>Cómo puede pagar</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        <?= P::e($l['cliente_nombre'] ?: $l['descripcion']) ?> ·
        <b style="color:var(--lf-brand-2)"><?= D::pesos($l['monto']) ?></b></p></div>
    <div style="display:flex;gap:8px;flex-shrink:0">
      <a class="btn btn-secondary btn-sm" href="/ligas/<?= (int)$l['id'] ?>/documento"
         target="_blank"><?= W::icono('baja','14px') ?>Imprimir</a>
      <a class="btn btn-secondary btn-sm" href="/caja">Siguiente venta</a>
    </div>
  </header>
  <div class="card-body">
    <?php P::parcial('ligas/panel', ['l' => $l]); ?>
  </div>
</section>
<?php endif; ?>

<?php if ($cobrada = \LibertyFin\Http\Peticion::texto('ok', '')): ?>
<div class="alert alert-success" style="margin-bottom:14px">
  <?= W::icono('ok','18px') ?>
  <span>Venta <b><?= P::e($cobrada) ?></b> cobrada.
    <a href="/ventas?q=<?= urlencode($cobrada) ?>" style="font-weight:600">Verla</a></span>
</div>
<?php endif; ?>

<?php if (!$cajaAbierta && \LibertyFin\Dominio\Permisos::puede('abrir.caja')): ?>
<div class="alert alert-warning" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>No tienes caja abierta.</b> Las ventas se registran igual, pero no entran
    al corte del turno y al cerrar no van a cuadrar.
    <a href="/corte" style="font-weight:600">Abrir caja</a></span>
</div>
<?php endif; ?>

<div class="lf-pos">
  <section class="card">
    <header class="card-header" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
      <span>Productos</span>
      <div class="lf-pag-serv" id="pagServ" hidden>
        <button type="button" id="servAnt" aria-label="Anterior">&lsaquo;</button>
        <span><b id="servPag">1</b> de <b id="servTot">1</b></span>
        <button type="button" id="servSig" aria-label="Siguiente">&rsaquo;</button>
      </div>
      <form class="lf-search" method="get" style="max-width:240px" id="formBuscaCat">
        <?= W::icono('buscar','15px') ?>
        <input type="search" name="q" id="buscaCat" value="<?= P::e($buscar) ?>" placeholder="Nombre o código"
               autocomplete="off">
        <?php if ($area): ?><input type="hidden" name="area" value="<?= P::e($area) ?>"><?php endif; ?>
      </form>
    </header>

    <div class="lf-cat-fila">
      <?php /* Los enlaces siguen siendo enlaces (sin JavaScript funcionan), pero
               con JavaScript solo filtran la rejilla: no recargan y el ticket
               se queda como está. */ ?>
      <a class="lf-pill <?= $area === '' ? 'active' : '' ?>" data-area=""
         href="/caja<?= $buscar ? '?q='.urlencode($buscar) : '' ?>">Todos</a>
      <?php foreach ($areas as $a): ?>
        <a class="lf-pill <?= (string)$area === (string)$a['id'] ? 'active' : '' ?>"
           data-area="<?= (int)$a['id'] ?>"
           href="/caja?area=<?= (int)$a['id'] ?><?= $buscar ? '&q='.urlencode($buscar) : '' ?>">
          <?= P::e($a['nombre']) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="lf-grid-serv">
      <p id="catVacio" <?= $servicios ? 'hidden' : '' ?>
         style="grid-column:1/-1;text-align:center;color:var(--lf-tinta-4);padding:30px;font-size:13px">
        No hay productos que coincidan.</p>
      <?php foreach ($servicios as $s): ?>
        <button type="button" class="lf-serv<?= (float)$s['precio'] <= 0 ? ' sin-precio' : '' ?>"
                data-id="<?= (int)$s['id'] ?>"
                data-area="<?= (int)($s['categoria_id'] ?? 0) ?>"
                data-b="<?= P::e(mb_strtolower($s['nombre'] . ' ' . $s['codigo'])) ?>"
                data-nombre="<?= P::e($s['nombre']) ?>"
                title="<?= P::e($s['nombre']) ?>"
                data-precio="<?= (float)$s['precio'] ?>">
          <span class="e"<?= !empty($s['imagen'])
              ? ' style="background-image:url(\''.P::e($s['imagen']).'\');background-size:cover"' : '' ?>>
            <?= !empty($s['imagen']) ? '' : W::icono('serv','17px') ?></span>
          <b><?= P::e($s['nombre']) ?></b>
          <small><?= P::e($s['codigo']) ?></small>
          <span class="p"><?= (float)$s['precio'] > 0 ? D::pesos($s['precio']) : 'Precio libre' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <form class="card" method="post" action="/caja/cobrar" id="ticket" style="align-self:start">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="lineas" id="lineas" value="[]">
    <input type="hidden" name="cliente_id" id="cliente_id" value="">
    <?php /* Con valor, las lineas entran a ESA venta en vez de abrir
             una nueva. Ver Servicio\AmpliarVenta. */ ?>
    <input type="hidden" name="ampliar_venta" id="ampliarVenta" value="">

    <header class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
      <span id="tituloTicket">Ticket</span>
      <span class="badge bg-secondary" id="conteo">0 conceptos</span>
    </header>

    <?php /* ─────────────────────────────────────────────────────
         AGREGAR A UNA VENTA QUE YA EXISTE

         El cliente paga tres servicios, se le hace el folio, y a los
         dos minutos se acuerda de otros tres. Sin esto la unica salida
         era otra venta: dos folios y un historial que cuenta dos
         visitas donde hubo una.

         Va cerrado por omision. Abrirlo es la excepcion, no el camino
         de todos los dias, y un selector siempre abierto invita a
         agregarle cosas a la venta de otro por equivocacion.
         ───────────────────────────────────────────────────── */ ?>
    <details class="lf-ampliar" id="cajaAmpliar">
      <summary>
        <?= W::icono('venta','15px') ?>
        <span>Agregar a una venta que ya existe</span>
      </summary>
      <div class="cuerpo">
        <div class="lf-search">
          <?= W::icono('buscar','15px') ?>
          <input type="text" id="buscaVenta" autocomplete="off"
                 placeholder="Folio o nombre del cliente">
        </div>
        <p class="pista">Salen las de hoy y las que siguen debiendo.</p>
        <div id="listaVentas" class="lista"></div>
      </div>
    </details>

    <div class="lf-ampliando" id="avisoAmpliar" hidden>
      <div>
        <b>Se agrega a la venta <span id="folioAmpliar" class="lf-mono"></span></b>
        <small id="detalleAmpliar"></small>
      </div>
      <button type="button" class="lf-btn-ghost" id="quitarAmpliar"
              title="Hacer una venta nueva" aria-label="Hacer una venta nueva">&times;</button>
    </div>

    <div style="padding:0 20px 12px">
      <div class="lf-search">
        <?= W::icono('cliente','15px') ?>
        <?php /* `name="cliente_nombre"`: si el nombre NO se eligió de la lista,
                 el servidor lo busca por nombre exacto o crea el cliente.
                 Antes ese texto se perdía y la venta salía sin cliente. */ ?>
        <input type="text" id="buscaCliente" name="cliente_nombre" maxlength="150"
               placeholder="Cliente (opcional)" autocomplete="off">
      </div>
      <div id="sugerencias" class="lf-sugerencias" hidden></div>
      <p style="font-size:11px;color:var(--lf-tinta-4);margin:6px 2px 0;line-height:1.4">
        Elígelo de la lista o escribe su nombre: si no existe, se crea al cobrar.</p>
    </div>

    <?php /* Quién va a hacer el trabajo, no quién cobra. De aquí sale a quién
             se le asigna la comisión: sin esto toda venta queda "POR ASIGNAR". */ ?>
    <div style="padding:0 20px 12px">
      <label class="form-label" for="selEspecialista">Especialista asignado</label>
      <select class="form-select form-select-sm" name="especialista" id="selEspecialista">
        <option value="">Sin asignar</option>
        <?php foreach (($equipo ?? []) as $nombreArea => $gente): ?>
          <optgroup label="<?= P::e($nombreArea) ?>">
            <?php foreach ($gente as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= P::e($c['nombre']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <label class="form-label" for="descVenta" style="margin-top:12px">Descripción <small style="font-weight:400;color:var(--lf-tinta-4)">(opcional)</small></label>
      <textarea class="form-control lf-desc" name="descripcion" id="descVenta" rows="2"
                placeholder="Qué se vendió, condiciones, referencias…"></textarea>
    </div>

    <div id="lista"></div>
    <p id="vacio" style="padding:26px 20px;text-align:center;color:var(--lf-tinta-4);font-size:13px">
      Toca un producto para agregarlo.</p>

    <div class="lf-sumas">
      <div><span>Subtotal</span><span class="lf-mono" id="sSub">$0.00</span></div>
      <div style="align-items:center">
        <span style="display:flex;align-items:center;gap:7px">IVA
          <input class="form-control form-control-sm lf-mono" type="number" name="iva_pct" id="ivaPct"
                 value="0" min="0" max="100" step="0.01" style="width:68px;padding:4px 7px">%
        </span>
        <span class="lf-mono" id="sIva">$0.00</span>
      </div>
      <div><span>Gastos de operación</span>
        <span style="display:flex;align-items:center;gap:6px">−
          <input class="form-control form-control-sm lf-mono" type="number" name="gastos" id="gastos"
                 value="0" min="0" step="0.01" style="width:104px;padding:4px 7px">
        </span></div>
      <div class="tt"><span>Total</span><span id="sTot">$0.00</span></div>
    </div>

    <div class="lf-anticipo" id="cajaAnticipo">
      <label for="anticipo">Anticipo que se cobra hoy</label>
      <input class="lf-mono" type="number" name="anticipo" id="anticipo" value="0" min="0" step="0.01">
      <p id="msgSaldo">Deja el total para liquidar de una vez.</p>
    </div>

      <?php
      // Solo lo que esta empresa puede cobrar. Ofrecer tarjeta a quien
      // la tiene apagada hace que el cajero la elija y la venta falle al
      // guardar, cuando el cliente ya está esperando.
      /* LOS CUATRO, NI UNO MAS.
       Antes salian seis o siete porque se mezclaban los metodos del
       mostrador con los de linea. Con tantos botones el cajero tiene
       que leerlos cada vez en vez de dar al de siempre. */
    $opciones = [
        ['id'=>'efectivo', 'rotulo'=>'Efectivo', 'icono'=>'caja', 'linea'=>''],
    ];
    // Tarjeta y SPEI (transferencia) se ofrecen si hay proveedor para
    // generar la liga, O si el método está encendido: en ese caso se cobra
    // marcando "Ya me pagaron", que no genera ninguna liga. Efectivo en
    // tienda siempre necesita el proveedor.
    $mets = $metodos ?? [];
    if ($ligas || in_array('tarjeta', $mets, true)) {
        $opciones[] = ['id'=>'_tarjeta', 'rotulo'=>'Tarjeta', 'icono'=>'cobro', 'linea'=>'tarjeta'];
    }
    if ($ligas || in_array('transferencia', $mets, true)) {
        $opciones[] = ['id'=>'_spei', 'rotulo'=>'SPEI', 'icono'=>'venta', 'linea'=>'spei'];
    }
    if ($ligas) {
        $opciones[] = ['id'=>'_tienda', 'rotulo'=>'Efectivo (tienda)', 'icono'=>'bolsa', 'linea'=>'efectivo'];
    }
    ?>
    <div class="lf-metodos">
      <label class="form-label">¿Cómo paga?</label>
      <div class="ops">
        <?php foreach ($opciones as $n => $o): ?>
          <button type="button" class="m<?= $n === 0 ? ' on' : '' ?>"
                  data-metodo="<?= P::e($o['id']) ?>" data-linea="<?= P::e($o['linea']) ?>">
            <?= W::icono($o['icono'],'17px') ?>
            <span><?= P::e($o['rotulo']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="como_paga" id="comoPaga"
             value="<?= P::e($opciones[0]['id'] ?? 'efectivo') ?>">
    </div>

    <?php /* Solo con Tarjeta, SPEI o Efectivo (tienda), que normalmente
             generan una liga. Marcado: NO se genera y el cobro pasa como
             pagado, para cuando el cliente ya transfirió o pagó por otro
             lado. Sin proveedor configurado es la única opción, así que
             va marcado. */ ?>
    <div class="lf-sinliga" id="cajaSinLiga" hidden>
      <label>
        <input type="checkbox" name="sin_liga" id="sinLiga" value="1" <?= $ligas ? '' : 'checked' ?>>
        <span><b>Ya me pagaron</b> — no generar liga de pago
          <small>El cobro se registra como pagado en este momento.</small></span>
      </label>
      <div class="ref" id="cajaRef" hidden>
        <label for="refTransf">Referencia o clave de rastreo <small>(opcional)</small></label>
        <input class="form-control lf-mono" type="text" name="referencia" id="refTransf"
               maxlength="60" autocomplete="off" placeholder="Para cuadrar el depósito después">
      </div>
    </div>


    <button class="btn btn-primary" type="submit" id="btnCobrar" disabled
            style="width:calc(100% - 40px);margin:0 20px 20px;padding:14px">
      <?= W::icono('cobro','16px') ?><span id="btnTexto">Cobrar</span>
    </button>
  </form>
</div>

<script>
/* ══════════════════════════════════════════════════════
   AGREGAR A UNA VENTA QUE YA EXISTE
   Elegir una venta cambia el destino del ticket: en vez de
   abrir un folio nuevo, las lineas entran a esa.
   ══════════════════════════════════════════════════════ */
(function(){
  var det   = document.getElementById('cajaAmpliar');
  if (!det) return;
  var campo = document.getElementById('buscaVenta'),
      lista = document.getElementById('listaVentas'),
      campoId = document.getElementById('ampliarVenta'),
      aviso = document.getElementById('avisoAmpliar'),
      folio = document.getElementById('folioAmpliar'),
      detalle = document.getElementById('detalleAmpliar'),
      quitar = document.getElementById('quitarAmpliar'),
      titulo = document.getElementById('tituloTicket'),
      btn = document.getElementById('btnCobrar'),
      btnTexto = document.getElementById('btnTexto'),
      pagaCon = document.getElementById('cajaPagaCon'),
      anticipo = document.getElementById('cajaAnticipo'),
      metodos = document.querySelector('.lf-metodos'),
      elegida = null, pidiendo = null, reloj = null;

  function pintar(ventas){
    lista.innerHTML = '';
    if (!ventas.length) {
      lista.innerHTML = '<p class="nada">No hay ventas abiertas que coincidan.</p>';
      return;
    }
    ventas.forEach(function(v){
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'v' + (v.debe ? ' debe' : '');
      b.innerHTML =
        '<span class="f lf-mono">' + v.folio + '</span>' +
        '<span class="c">' + v.cliente + '</span>' +
        '<span class="d">' + v.fecha + (v.de_hoy ? '' : ' · otro día') +
        ' · ' + v.lineas + (v.lineas === 1 ? ' producto' : ' productos') + '</span>' +
        '<span class="s">' + (v.debe ? 'debe ' + v.saldo : 'liquidada') + '</span>';
      b.addEventListener('click', function(){ elegir(v); });
      lista.appendChild(b);
    });
  }

  function buscar(){
    if (pidiendo) pidiendo.abort();
    pidiendo = new AbortController();
    lista.innerHTML = '<p class="nada">Buscando…</p>';
    var cli = document.getElementById('cliente_id');
    var u = '/caja/ventas-abiertas?q=' + encodeURIComponent(campo.value.trim())
          + (cli && cli.value ? '&cliente=' + encodeURIComponent(cli.value) : '');
    fetch(u, { signal: pidiendo.signal, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ pintar(j.ventas || []); })
      .catch(function(e){
        if (e.name === 'AbortError') return;
        lista.innerHTML = '<p class="nada">No se pudo consultar.</p>';
      });
  }

  function elegir(v){
    elegida = v;
    campoId.value = v.id;
    folio.textContent = v.folio;
    detalle.textContent = v.cliente + ' · ' + v.fecha
      + (v.debe ? ' · debe ' + v.saldo : ' · liquidada')
      + (v.de_hoy ? '' : ' · OJO: es de otro día, el corte de ese día cambia');
    aviso.hidden = false;
    det.open = false;
    if (titulo) titulo.textContent = 'Productos que se agregan';
    /* AMPLIAR NO COBRA. Se agrega lo vendido y se abre saldo: el dinero
       entra despues por donde entra siempre, un abono. Dejar a la vista
       "anticipo" y "como paga" haria creer que el cliente esta pagando
       algo aqui, y no es asi. */
    if (pagaCon)  pagaCon.hidden  = true;
    if (anticipo) anticipo.hidden = true;
    if (metodos)  metodos.hidden  = true;
    var sinl = document.getElementById('cajaSinLiga'); if (sinl) sinl.hidden = true;
    refrescarBoton();
  }

  function soltar(){
    elegida = null;
    campoId.value = '';
    aviso.hidden = true;
    campo.value = '';
    lista.innerHTML = '';
    if (titulo) titulo.textContent = 'Ticket';
    if (anticipo) anticipo.hidden = false;
    if (metodos)  metodos.hidden  = false;
    refrescarBoton();
    /* El metodo de pago decide si "con cuanto te paga" vuelve: lo sabe
       el bloque de abajo, asi que se le avisa en vez de adivinarlo. */
    document.dispatchEvent(new CustomEvent('lf:ticket-cambio'));
  }

  function refrescarBoton(){
    if (!btnTexto) return;
    btnTexto.textContent = elegida ? 'Agregar a ' + elegida.folio : 'Cobrar';
    if (btn) btn.classList.toggle('lf-agregando', !!elegida);
  }

  det.addEventListener('toggle', function(){ if (det.open) buscar(); });
  campo.addEventListener('input', function(){
    clearTimeout(reloj); reloj = setTimeout(buscar, 220);
  });
  campo.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(reloj); buscar(); }
  });
  quitar.addEventListener('click', soltar);

  /* Si se elige otro cliente con una venta ya elegida, la venta manda:
     cambiar el cliente de una venta existente no es lo que se pidio. */
  var bc = document.getElementById('buscaCliente');
  if (bc) bc.addEventListener('input', function(){ if (elegida) soltar(); });

  window.lfVentaElegida = function(){ return elegida; };
})();
</script>

<script>
(function(){
  var lineas = [];
  var $ = function(id){ return document.getElementById(id); };
  var pesos = function(n){ return '$' + (Math.round(n*100)/100).toLocaleString('es-MX',
                     {minimumFractionDigits:2, maximumFractionDigits:2}); };

  function pintar(){
    var lista = $('lista'); lista.innerHTML = '';
    lineas.forEach(function(l, i){
      var d = document.createElement('div');
      d.className = 'lf-linea';
      // El precio es editable: muchos servicios se cotizan por caso y el
      // catálogo los tiene en cero a propósito.
      d.innerHTML = '<div style="flex:1;min-width:0"><b>' + l.nombre + '</b>'
        + '<small>cantidad ' + l.cantidad + '</small></div>'
        + '<input class="lf-precio lf-mono" type="number" step="0.01" min="0" '
        +   'data-i="' + i + '" value="' + l.precio.toFixed(2) + '" aria-label="Precio">'
        + '<button type="button" class="lf-quitar" data-i="' + i + '" aria-label="Quitar">&times;</button>';
      lista.appendChild(d);
    });
    $('vacio').hidden = lineas.length > 0;
    $('conteo').textContent = lineas.length + ' concepto' + (lineas.length===1?'':'s');
    calcular();   // calcular() ya serializa las líneas
  }

  function calcular(){
    /* SE VUELVE A SERIALIZAR AQUÍ, NO SOLO EN pintar().
       Editar un precio no repinta la lista —perdería el foco a media
       escritura— y por eso el campo oculto conservaba los precios
       originales. Con los servicios de precio libre, que van en cero,
       el servidor recibía un ticket de cero aunque en pantalla se viera
       bien: "El ticket suma cero" con el precio puesto. */
    $('lineas').value = JSON.stringify(lineas.map(function(l){
      return {id:l.id, cantidad:l.cantidad, precio:l.precio}; }));

    var cap = lineas.reduce(function(a,l){ return a + l.precio*l.cantidad; }, 0);
    var pct = parseFloat($('ivaPct').value) || 0;
    // IVA incluido: el total es lo capturado y el impuesto se extrae.
    var base = pct > 0 ? cap / (1 + pct/100) : cap;
    var iva  = cap - base;
    $('sSub').textContent = pesos(base);
    $('sIva').textContent = pesos(iva);
    $('sTot').textContent = pesos(cap);

    /* "Ya me pagaron": con un método de liga, marcado = NO se genera la
       liga y el cobro pasa como pagado. Entonces el anticipo es lo que
       entró, y por defecto es el total: así cobrar completo no obliga a
       escribir nada. Si el cajero lo cambia a mano, se respeta. */
    var act = document.querySelector('.lf-metodos .m.on');
    var esLinea = !!(act && act.dataset.linea);
    var sl = $('sinLiga');
    var omitir = esLinea && sl && sl.checked;
    if (omitir && !$('anticipo').dataset.manual) $('anticipo').value = cap.toFixed(2);

    var ant = parseFloat($('anticipo').value) || 0;
    if (ant > cap) { $('anticipo').value = cap.toFixed(2); ant = cap; }
    var saldo = cap - ant;
    $('msgSaldo').innerHTML = saldo <= 0.009
      ? 'Se liquida completa. La comisión se libera toda.'
      : 'Queda un saldo de <b class="lf-mono" style="color:var(--lf-amb)">' + pesos(saldo)
        + '</b>. La comisión se libera conforme el cliente pague.';
    var enLinea = esLinea && !omitir;
    $('btnTexto').textContent = enLinea
      ? 'Cobrar ' + pesos(cap)
      : (ant > 0 ? 'Cobrar ' + pesos(ant) : 'Registrar sin cobro');
    $('btnTexto').dataset.total = (ant > 0 ? ant : cap).toFixed(2);
    document.dispatchEvent(new Event('lf-recalcular'));
    $('btnCobrar').disabled = lineas.length === 0;
  }

  /* ══════════════════════════════════════════════════════
     CATÁLOGO: CATEGORÍA, BÚSQUEDA Y PÁGINAS, SIN RECARGAR
     Antes cada categoría era una página nueva y el ticket que se iba
     armando se perdía. Ahora todas las tarjetas ya están en la página y
     aquí solo se muestran u ocultan; el ticket ni se entera.
     Las 21 por página se conservan: con el catálogo completo a la vista
     el ticket quedaría perdido al fondo de la pantalla.
     ══════════════════════════════════════════════════════ */
  (function(){
    var POR_PAG = 21;
    var todas = Array.prototype.slice.call(document.querySelectorAll('.lf-serv'));
    var area = <?= json_encode((string)$area) ?>;
    var q = <?= json_encode(mb_strtolower(trim((string)$buscar))) ?>;
    var actual = 1, visibles = [];
    var caja = $('pagServ'), vacio = $('catVacio'), campoQ = $('buscaCat');

    function filtrar(){
      visibles = todas.filter(function(t){
        return (area === '' || t.dataset.area === area)
            && (q === '' || (t.dataset.b || '').indexOf(q) !== -1);
      });
      actual = 1;
      pinta();
    }
    function pinta(){
      var total = Math.max(1, Math.ceil(visibles.length / POR_PAG));
      if (actual > total) actual = total;
      todas.forEach(function(t){ t.style.display = 'none'; });
      visibles.slice((actual-1)*POR_PAG, actual*POR_PAG)
              .forEach(function(t){ t.style.display = ''; });
      if (vacio) vacio.hidden = visibles.length > 0;
      if (caja) caja.hidden = total <= 1;
      $('servPag').textContent = actual;
      $('servTot').textContent = total;
      $('servAnt').disabled = actual === 1;
      $('servSig').disabled = actual === total;
    }
    /* La dirección se mantiene al día para poder recargar o compartir,
       pero sin recargar ahora. */
    function direccion(){
      var p = [];
      if (area !== '') p.push('area=' + encodeURIComponent(area));
      if (q !== '') p.push('q=' + encodeURIComponent(campoQ ? campoQ.value.trim() : q));
      try { history.replaceState(history.state, '', '/caja' + (p.length ? '?' + p.join('&') : '')); }
      catch (e) {}
    }

    $('servAnt').addEventListener('click', function(){ if (actual>1){ actual--; pinta(); } });
    $('servSig').addEventListener('click', function(){
      if (actual < Math.ceil(visibles.length / POR_PAG)){ actual++; pinta(); } });

    /* Categorías. preventDefault ANTES de que el navegador de secciones
       del armazón vea el clic: si no, pediría la página entera. */
    var fila = document.querySelector('.lf-cat-fila');
    if (fila) fila.addEventListener('click', function(ev){
      var p = ev.target.closest('.lf-pill');
      if (!p || !fila.contains(p)) return;
      ev.preventDefault();
      area = p.dataset.area || '';
      fila.querySelectorAll('.lf-pill').forEach(function(x){ x.classList.toggle('active', x === p); });
      filtrar(); direccion();
    });

    /* Búsqueda: filtra al escribir; Enter ya no recarga. */
    if (campoQ) {
      campoQ.addEventListener('input', function(){
        q = campoQ.value.trim().toLowerCase();
        filtrar(); direccion();
      });
      var fq = $('formBuscaCat');
      if (fq) fq.addEventListener('submit', function(ev){ ev.preventDefault(); });
    }

    filtrar();
  })();

  /* Los botones de método. El texto del botón de cobrar cambia según
     el elegido: con el mismo texto, el cajero cree que ya cobró cuando
     el cliente se va a pagar a otro lado. */
  (function(){
    /* Qué se ve según el método y "Ya me pagaron":
         · método de liga SIN marcar → no hay anticipo (el cliente todavía no
           pagó; anotarlo dejaría la venta liquidada sin que entre un peso)
         · método de liga CON "Ya me pagaron" → sin liga, anticipo = total,
           y aparece la referencia para cuadrar el depósito
         · efectivo → como siempre */
    function aplicarSinLiga(){
      var act = document.querySelector('.lf-metodos .m.on');
      var linea = !!(act && act.dataset.linea);
      var wrap = $('cajaSinLiga'), sl = $('sinLiga'), rf = $('cajaRef'), caja = $('cajaAnticipo');
      if (wrap) wrap.hidden = !linea;
      var omitir = linea && sl && sl.checked;
      if (caja) caja.hidden = linea && !omitir;
      if (rf) { rf.hidden = !omitir; if (rf.hidden) $('refTransf').value = ''; }
      if (linea && !omitir) { $('anticipo').dataset.manual = ''; $('anticipo').value = '0'; }
      calcular();
    }
    /* Al soltar una venta ampliada se vuelve a decidir qué se ve. */
    document.addEventListener('lf:ticket-cambio', aplicarSinLiga);
    if ($('sinLiga')) $('sinLiga').addEventListener('change', function(){
      $('anticipo').dataset.manual = '';
      aplicarSinLiga();
    });
    /* Si el cajero escribe un anticipo a mano, deja de seguir al total. */
    $('anticipo').addEventListener('input', function(){ this.dataset.manual = '1'; });

    document.querySelectorAll('.lf-metodos .m').forEach(function(b){
      b.addEventListener('click', function(){
        document.querySelectorAll('.lf-metodos .m').forEach(function(x){
          x.classList.remove('on'); });
        b.classList.add('on');
        $('comoPaga').value = b.dataset.metodo;
        /* Con un pago en línea el anticipo no aplica: el cliente todavía
           no ha pagado nada. Dejarlo a la vista invita a escribir ahí el
           total y entonces la venta queda liquidada sin que haya entrado
           un peso. */
        $('anticipo').dataset.manual = '';
        $('anticipo').value = '0';
        aplicarSinLiga();
        /* "Paga con" solo tiene sentido en efectivo: en una
           transferencia nadie entrega cambio. */
        var pc = $('cajaPagaCon');
        if (pc) {
          pc.hidden = (b.dataset.metodo !== 'efectivo');
          if (pc.hidden) { $('pagaCon').value = ''; $('verCambio').textContent = ''; }
        }
        calcular();
      });
    });
  })();

  document.querySelectorAll('.lf-serv').forEach(function(b){
    b.addEventListener('click', function(){
      var id = +b.dataset.id;
      var y = lineas.filter(function(l){ return l.id === id; })[0];
      if (y) y.cantidad++;
      else lineas.push({id:id, nombre:b.dataset.nombre, precio:+b.dataset.precio, cantidad:1});
      pintar();
    });
  });

  $('lista').addEventListener('click', function(e){
    var b = e.target.closest('.lf-quitar');
    if (!b) return;
    lineas.splice(+b.dataset.i, 1);
    pintar();
  });

  // Cambiar el precio no repinta la lista: perdería el foco a media escritura.
  $('lista').addEventListener('input', function(e){
    var i = e.target.closest('.lf-precio');
    if (!i) return;
    lineas[+i.dataset.i].precio = Math.max(0, parseFloat(i.value) || 0);
    calcular();
  });

  ['ivaPct','anticipo','gastos'].forEach(function(id){
    $(id).addEventListener('input', calcular);
  });

  /* El cambio, mientras escribe. Es la cuenta que el cajero hace de
     cabeza cada venta y donde mas se equivoca con prisa. */
  (function(){
    var i = $('pagaCon'), out = $('verCambio');
    if (!i || !out) return;
    function ver(){
      var da = parseFloat(i.value) || 0;
      var ant = parseFloat($('anticipo').value) || 0;
      var cobra = ant > 0 ? ant : (parseFloat($('btnTexto').dataset.total) || 0);
      if (!da) { out.textContent = ''; out.className = 'cambio'; return; }
      if (da < cobra) {
        out.textContent = 'Faltan ' + pesos(cobra - da);
        out.className = 'cambio falta';
      } else {
        out.textContent = 'Cambio ' + pesos(da - cobra);
        out.className = 'cambio ok';
      }
    }
    i.addEventListener('input', ver);
    document.addEventListener('lf-recalcular', ver);
  })();

  document.querySelectorAll('input[name="metodo"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('input[name="metodo"]').forEach(function(o){
        o.parentNode.classList.toggle('active', o.checked); });
    });
  });

  // Cliente
  var t = null;
  $('buscaCliente').addEventListener('input', function(){
    var q = this.value.trim();
    $('cliente_id').value = '';
    clearTimeout(t);
    if (q.length < 2) { $('sugerencias').hidden = true; return; }
    t = setTimeout(function(){
      fetch('/caja/clientes?q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(cs){
          var s = $('sugerencias');
          s.innerHTML = '';
          cs.forEach(function(c){
            var d = document.createElement('button');
            d.type = 'button';
            d.innerHTML = '<b>' + c.nombre + '</b><small>' + (c.compras>0
              ? c.compras + ' compra' + (c.compras==1?'':'s') : 'Cliente nuevo') + '</small>';
            d.addEventListener('click', function(){
              $('cliente_id').value = c.id;
              $('buscaCliente').value = c.nombre;
              s.hidden = true;
            });
            s.appendChild(d);
          });
          s.hidden = cs.length === 0;
        });
    }, 220);
  });

  /* ══════════════════════════════════════════════════════
     EL TICKET SOBREVIVE AL CAMBIO DE CATEGORÍA
     Las categorías de arriba son enlaces: cambiar de una a otra carga
     la pantalla otra vez y el ticket —productos, precios, IVA, cliente,
     método— se borraba. Se guarda en la pestaña (sessionStorage) cada vez
     que algo cambia y se restaura al cargar. Se borra al cobrar con
     éxito, para que la siguiente venta empiece limpia.
     ══════════════════════════════════════════════════════ */
  var CLAVE_TICKET = 'lf-caja-ticket';
  var ticketListo = false;     // no se guarda nada antes de restaurar
  var ticketCerrado = false;   // ya se cobró: no se vuelve a guardar

  window.lfGuardarTicket = function(){
    if (!ticketListo || ticketCerrado) return;
    try {
      sessionStorage.setItem(CLAVE_TICKET, JSON.stringify({
        lineas: lineas,
        iva: $('ivaPct').value, gastos: $('gastos').value,
        anticipo: $('anticipo').value, manual: $('anticipo').dataset.manual || '',
        clienteId: $('cliente_id').value, cliente: $('buscaCliente').value,
        esp: $('selEspecialista') ? $('selEspecialista').value : '',
        desc: $('descVenta') ? $('descVenta').value : '',
        metodo: $('comoPaga').value,
        sinLiga: $('sinLiga') ? $('sinLiga').checked : null,
        ref: $('refTransf') ? $('refTransf').value : ''
      }));
    } catch (e) { /* sin sessionStorage se opera igual, solo que sin guardar */ }
  };

  /* Lo que se va a registrar, leído de la pantalla, para el resumen de
     confirmación. */
  window.lfResumenTicket = function(){
    var cap = lineas.reduce(function(a,l){ return a + l.precio*l.cantidad; }, 0);
    var ant = parseFloat($('anticipo').value) || 0;
    if (ant > cap) ant = cap;
    var act = document.querySelector('.lf-metodos .m.on');
    var esLinea = !!(act && act.dataset.linea);
    var sl = $('sinLiga');
    var se = $('selEspecialista');
    var v = window.lfVentaElegida && window.lfVentaElegida();
    return {
      lineas: lineas.map(function(l){ return {nombre:l.nombre, cantidad:l.cantidad, precio:l.precio}; }),
      total: cap, anticipo: ant, saldo: cap - ant,
      liga: esLinea && !(sl && sl.checked),
      metodo: act ? act.textContent.trim() : 'Efectivo',
      cliente: ($('buscaCliente').value || '').trim(),
      esp: (se && se.value) ? se.options[se.selectedIndex].text : '',
      ampliar: v ? v.folio : ''
    };
  };

  /* Venta cobrada: se olvida el ticket y no se vuelve a guardar. */
  window.lfTicketCobrado = function(){
    ticketCerrado = true;
    try { sessionStorage.removeItem(CLAVE_TICKET); } catch (e) {}
  };

  function restaurarTicket(){
    var g = null;
    try {
      /* Si se acaba de cobrar (?ok=…), el ticket de antes ya no vale. */
      if (/[?&]ok=/.test(location.search)) { sessionStorage.removeItem(CLAVE_TICKET); }
      else g = JSON.parse(sessionStorage.getItem(CLAVE_TICKET) || 'null');
    } catch (e) { g = null; }

    if (g && g.lineas && g.lineas.length) {
      lineas = g.lineas.map(function(l){
        return {id:l.id, nombre:l.nombre, precio:+l.precio, cantidad:+l.cantidad || 1};
      });
      $('ivaPct').value = g.iva;
      $('gastos').value = g.gastos;
      $('cliente_id').value = g.clienteId || '';
      $('buscaCliente').value = g.cliente || '';
      if ($('selEspecialista')) $('selEspecialista').value = g.esp || '';
      if ($('descVenta')) $('descVenta').value = g.desc || '';

      /* El método: se "toca" su botón para que todo lo que depende de él
         (anticipo, casilla de "Ya me pagaron", referencia) se acomode. */
      var bm = document.querySelector('.lf-metodos .m[data-metodo="' + (g.metodo || '') + '"]');
      if (bm) bm.click();
      if ($('sinLiga') && g.sinLiga !== null) $('sinLiga').checked = !!g.sinLiga;
      if ($('refTransf')) $('refTransf').value = g.ref || '';
      $('anticipo').value = g.anticipo;
      $('anticipo').dataset.manual = g.manual || '';
      if ($('sinLiga')) $('sinLiga').dispatchEvent(new Event('change'));
      /* `change` en la casilla reinicia el anticipo; se vuelve a poner. */
      $('anticipo').value = g.anticipo;
      $('anticipo').dataset.manual = g.manual || '';
    }
    ticketListo = true;
  }

  /* Cualquier cambio en el formulario guarda el ticket. */
  ['ticket'].forEach(function(id){
    var fm = $(id);
    if (!fm) return;
    fm.addEventListener('input',  function(){ window.lfGuardarTicket(); });
    fm.addEventListener('change', function(){ window.lfGuardarTicket(); });
    fm.addEventListener('click',  function(){ setTimeout(window.lfGuardarTicket, 0); });
  });
  /* Y siempre que se recalcula (agregar, quitar, cambiar un precio). */
  document.addEventListener('lf-recalcular', function(){ window.lfGuardarTicket(); });

  restaurarTicket();
  pintar();
})();
</script>

<?php /* ═══════════ CONFIRMAR ANTES DE REGISTRAR ═══════════ */ ?>
<div class="lf-modal" id="modalConfirma" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="cTitulo">
    <header>
      <div>
        <h2 id="cTitulo">Revisa antes de registrar</h2>
        <p>Una vez registrada, la venta no puede eliminarse. Cualquier cancelación requiere un proceso adicional.</p>
      </div>
      <button type="button" class="cerrar" id="cCerrar" aria-label="Cerrar">&times;</button>
    </header>
    <div class="cuerpo" id="cCuerpo"></div>
    <footer>
      <button type="button" class="btn btn-secondary" id="cVolver">Revisar</button>
      <button type="button" class="btn btn-primary" id="cOk">Confirmar y registrar</button>
    </footer>
  </div>
</div>

<?php /* ═══════════ EL MODAL DE COBRO ═══════════ */ ?>
<div class="lf-modal" id="modalCobro" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="mTitulo">
    <header>
      <div>
        <h2 id="mTitulo">Cobro</h2>
        <p id="mSub"></p>
      </div>
      <button type="button" class="cerrar" id="mCerrar" aria-label="Cerrar">&times;</button>
    </header>
    <div class="cuerpo" id="mCuerpo"></div>
    <footer id="mPie"></footer>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('modalCobro');
  if (!modal) return;
  var cuerpo = document.getElementById('mCuerpo'),
      titulo = document.getElementById('mTitulo'),
      sub    = document.getElementById('mSub'),
      pie    = document.getElementById('mPie'),
      /* El formulario del TICKET, por su id. Antes se tomaba el primer
         <form> de la página, que en Caja es el buscador del catálogo: el
         cobro nunca se enviaba por detrás, el modal no abría y la liga
         salía como un bloque arriba de la página. */
      form   = document.getElementById('ticket'),
      reloj  = null;

  function abrir(){ modal.hidden = false; document.body.classList.add('lf-modal-abierto'); }
  function cerrar(){
    modal.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
    if (reloj) { clearInterval(reloj); reloj = null; }
  }
  document.getElementById('mCerrar').addEventListener('click', cerrar);
  modal.addEventListener('click', function(e){ if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.hidden) cerrar();
  });

  function esc(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function money(n){ return '$' + (+n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }

  function copiable(valor, id, grande){
    return '<div class="dato' + (grande ? ' grande' : '') + '">' + esc(valor) + '</div>'
      + '<div class="copiar"><input readonly id="' + id + '" value="' + esc(valor) + '">'
      + '<button type="button" class="btn btn-secondary btn-sm" data-copia="' + id + '">Copiar</button></div>';
  }

  /* Pregunta cada 4 segundos si ya pagó. Así el cajero ve el aviso en el
     momento en que entra el dinero, sin recargar ni ir a otra pantalla. */
  function vigilar(id){
    var espera = document.getElementById('mEspera');
    if (reloj) clearInterval(reloj);
    reloj = setInterval(function(){
      fetch('/caja/estado/' + id, { headers: {'Accept':'application/json'}, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d || !d.pagado) return;
          clearInterval(reloj); reloj = null;
          if (espera) {
            espera.className = 'espera ok';
            espera.innerHTML = d.estado === 'por_aprobar'
              ? '<b>El cliente ya pagó.</b> Falta aprobarlo para que entre al corte.'
              : '<b>¡Pagado!</b> El abono ya quedó aplicado.';
          }
        })
        .catch(function(){ /* si falla la red se vuelve a intentar solo */ });
    }, 4000);
  }

  /* El boton de confirmar a mano.
     No siempre llega el aviso del proveedor: el pago en tienda tarda
     horas y a veces el cliente ensena el comprobante en el mostrador.
     Sin esto el cajero se queda esperando con el cliente enfrente. */
  function confirmar(l){
    return '<button type="button" class="btn btn-secondary" data-confirmar="'
         + l.id + '">Ya me pagó</button>';
  }

  function pintar(d){
    var l = d.liga || {}, modo = d.modo;

    if (modo === 'cobrado') {
      var v = d.venta;
      titulo.textContent = 'Cobrado';
      sub.textContent = 'Venta ' + v.codigo;

      /* El CAMBIO va primero y en grande. Es lo unico que el cajero
         necesita en este segundo: tiene la mano en el cajon y al
         cliente esperando. Lo cobrado ya lo sabia. */
      var html = '';
      if (v.cambio > 0) {
        html += '<p class="etiqueta">Cambio</p>'
             +  '<p class="ok-grande">' + money(v.cambio) + '</p>'
             +  '<p class="msg centro">Cobró ' + money(v.cobrado)
             +  ' de ' + money(v.paga_con) + '</p>';
      } else {
        html += '<p class="ok-grande">' + money(v.cobrado) + '</p>'
             +  '<p class="msg centro">Listo, la venta quedó registrada.</p>';
      }
      if (!v.en_corte) {
        html += '<div class="espera"><b>Sin caja abierta.</b> Esta venta no entra al '
             +  'corte del turno.</div>';
      }
      cuerpo.innerHTML = html;
      pie.innerHTML = '<a class="btn btn-secondary" href="/ventas/' + v.id
        + '/ticket?auto=1" target="_blank">Imprimir ticket</a>'
        + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      return;
    }

    sub.textContent = 'Venta ' + (d.venta ? d.venta.codigo : '') + ' · ' + money(l.monto);

    /* Cada servicio devuelve lo suyo, asi que no hace falta adivinar:
       el modo que se pidio es el que llego. */
    var aviso = '';

 if (modo === 'tarjeta') {
    titulo.textContent = 'Pago con tarjeta';
    cuerpo.innerHTML =
      '<p class="msg">Que escanee el código con su teléfono, o ábrele la página '
      + 'para que capture los datos de su tarjeta.</p>'
      // El SVG viene ya en `l.qr`. Meterlo tal cual evita el segundo
      // viaje a /caja/qr, que Mod_Security cortaba al ver una dirección
      // dentro de la cadena de consulta, y que dejaba el <img> roto.
      + '<div class="qr" id="mQr">' + (l.qr || '') + '</div>'
      + copiable(l.liga, 'mLiga')
      + '<div class="espera" id="mEspera"><span class="giro"></span>'
      + 'Esperando a que pague. Esto se actualiza solo.</div>' + aviso;
    pie.innerHTML = '<a class="btn btn-secondary" href="' + esc(l.liga)
      + '" target="_blank" rel="noopener">Abrir la página</a>' + confirmar(l)
      + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
    vigilar(l.id);
  } else if (modo === 'spei') {
      titulo.textContent = 'Transferencia SPEI';
      cuerpo.innerHTML =
        '<p class="msg">Que transfiera desde su banco a esta CLABE, por '
        + '<b>' + money(l.monto) + '</b> exactos.</p>'
        + copiable(l.clabe, 'mClabe', true)
        + '<p class="aviso">Una cantidad distinta no se asocia sola y hay que buscarla a mano.</p>'
        + '<div class="espera" id="mEspera"><span class="giro"></span>'
        + 'Esperando el depósito. En cuanto llegue, aparece aquí.</div>' + aviso;
      pie.innerHTML = confirmar(l) + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      vigilar(l.id);

    } else if (modo === 'efectivo') {
      titulo.textContent = 'Pago en tienda';
      cuerpo.innerHTML =
        '<p class="msg">Dale el comprobante. Puede pagar en OXXO y tiendas participantes '
        + 'con este código.</p>'
        + copiable(l.ref, 'mRef', true)
        + '<p class="aviso">El comprobante trae el código de barras, los pasos y la lista '
        + 'de tiendas. Imprímelo o mándaselo.</p>'
        + '<div class="espera" id="mEspera"><span class="giro"></span>'
        + 'El pago en tienda puede tardar unas horas en reflejarse.</div>' + aviso;
      pie.innerHTML = '<a class="btn btn-secondary" href="' + esc(l.doc)
        + '" target="_blank">Ver el comprobante</a>' + confirmar(l)
        + '<button type="button" class="btn btn-primary" data-seguir>Siguiente venta</button>';
      vigilar(l.id);
    }
  }

  /* El QR se pide al servidor, que ya sabe dibujarlo. Meter un generador
     en el navegador sería repetir trescientas líneas que ya existen. */
function cargarQr(l) {
  document.getElementById('mQr').innerHTML =
    '<img src="/caja/qr?liga=' + encodeURIComponent(l.id) + '"'
    + ' width="190" height="190" alt="Código QR">';
}

  document.addEventListener('click', function(e){
    var c = e.target.closest('[data-copia]');
    if (c) {
      var i = document.getElementById(c.dataset.copia);
      navigator.clipboard.writeText(i.value).then(function(){
        var t = c.textContent; c.textContent = 'Copiado';
        setTimeout(function(){ c.textContent = t; }, 1600);
      }).catch(function(){ i.select(); });
      return;
    }
    if (e.target.closest('[data-seguir]')) { location.href = '/caja'; return; }

    var cf = e.target.closest('[data-confirmar]');
    if (cf) {
      if (!window.confirm('¿El cliente ya pagó? El abono se aplica de inmediato y '
                        + 'queda anotado como confirmación manual.')) return;
      cf.disabled = true; cf.textContent = 'Aplicando…';
      var fd = new FormData();
      fd.append('token', <?= json_encode($token) ?>);
      fd.append('id', cf.dataset.confirmar);
      fetch('/ligas/confirmar', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (!d.ok) { cf.disabled = false; cf.textContent = 'Ya me pagó';
                       alert(d.error || 'No se pudo.'); return; }
          var esp = document.getElementById('mEspera');
          if (esp) { esp.className = 'espera ok';
                     esp.innerHTML = '<b>Pago aplicado.</b> Confirmado a mano.'; }
          cf.remove();
          if (reloj) { clearInterval(reloj); reloj = null; }
        })
        .catch(function(){ cf.disabled = false; cf.textContent = 'Ya me pagó';
                           alert('No se pudo conectar.'); });
    }
  });

  /* El envío ya no recarga: se manda, se recibe y se abre el modal.
     Esta función es el envío de verdad; solo se llega aquí DESPUÉS de la
     confirmación (ver más abajo). */
  function enviar(){
    var btn = document.getElementById('btnCobrar');
    if (btn) { btn.disabled = true; btn.classList.add('cargando'); }

    var datos = new FormData(form);
    datos.append('json', '1');
    var recibido = false;   // ¿ya contestó el servidor? Si sí, la venta YA existe.
    fetch(form.action, { method:'POST', body:datos,
                         headers:{'Accept':'application/json'}, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        recibido = true;
        if (btn) { btn.disabled = false; btn.classList.remove('cargando'); }
        if (!d.ok) { alert(d.error || 'No se pudo cobrar.'); return; }

        /* La venta ya existe: el ticket guardado en la pestaña ya no vale,
           o la siguiente venta arrancaría con los mismos productos. */
        if (window.lfTicketCobrado) window.lfTicketCobrado();

        /* SE AGREGO A UNA VENTA QUE YA EXISTIA.
           No hay nada que cobrar aqui —se abrio saldo— asi que no hay
           modal de cambio ni de liga: se va a la venta, que es donde
           esta lo que acaba de pasar y desde donde se cobra. */
        if (d.ampliada) {
          location.href = '/ventas/' + d.venta.id;
          return;
        }

        /* EFECTIVO SIN CAMBIO: NI SIQUIERA ABRE EL MODAL.
           Si pagó justo, no hay nada que decirle al cajero y un modal
           que solo pide cerrarse es un clic de más en la operación que
           más se repite en el día. Se avisa arriba y a la siguiente. */
        if (d.modo === 'cobrado' && !(d.venta.cambio > 0) && d.venta.en_corte) {
          location.href = '/caja?ok=' + encodeURIComponent(d.venta.codigo);
          return;
        }
        pintar(d);
        abrir();
      })
      .catch(function(){
        if (btn) { btn.disabled = false; btn.classList.remove('cargando'); }
        /* Si falló la CONEXIÓN, se manda como siempre para no dejar al
           cajero sin poder cobrar. Pero si el servidor ya contestó, la
           venta YA se registró y el error fue al mostrarla: reenviar el
           formulario crearía una segunda venta. */
        if (recibido) {
          alert('La venta se registró, pero hubo un problema al mostrar el cobro. '
              + 'Revísala en Ventas y no la cobres otra vez.');
          return;
        }
        form.submit();
      });
  }

  /* ══════════════════════════════════════════════════════
     CONFIRMAR ANTES DE REGISTRAR
     Un Enter de más, o un toque sin querer, registraba una venta a medias
     —sin cliente, sin especialista, con saldo— y deshacerla es trabajo
     aparte. Ahora:
       · Enter dentro de un campo ya NO envía el formulario;
       · enviar abre un resumen y avisa de lo que falta;
       · el foco cae en "Revisar", no en "Confirmar": un segundo Enter
         seguido tampoco la registra.
     ══════════════════════════════════════════════════════ */
  var conf = document.getElementById('modalConfirma');
  var confCuerpo = document.getElementById('cCuerpo');
  var pendiente = null;

  function cerrarConf(){
    conf.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
    pendiente = null;
  }

  function abrirConf(r){
    var av = [];
    if (!r.ampliar) {
      if (!r.cliente) av.push('No elegiste cliente: la venta saldrá como <b>Público general</b>.');
      if (!r.esp)     av.push('Sin <b>especialista</b>: la comisión quedará por asignar.');
      if (!r.liga && r.saldo > 0.009)
        av.push('Quedará un <b>saldo de ' + money(r.saldo) + '</b> por cobrar.');
      if (!r.liga && r.anticipo <= 0)
        av.push('<b>No se cobra nada hoy</b>: se registra solo la venta.');
    }
    var items = r.lineas.slice(0, 5).map(function(l){
      return '<li><span>' + (l.cantidad > 1 ? l.cantidad + ' × ' : '') + esc(l.nombre) + '</span>'
           + '<b class="lf-mono">' + money(l.precio * l.cantidad) + '</b></li>';
    }).join('');
    if (r.lineas.length > 5) items += '<li class="mas">y ' + (r.lineas.length - 5) + ' más…</li>';

    var filas = [];
    if (r.ampliar) {
      filas.push(['Se agrega a la venta', esc(r.ampliar)]);
    } else {
      filas.push(['Cliente', r.cliente ? esc(r.cliente) : '<i>Público general</i>']);
      filas.push(['Especialista', r.esp ? esc(r.esp) : '<i>Sin asignar</i>']);
      filas.push(['Paga con', esc(r.metodo)]);
      filas.push(r.liga ? ['Se genera una liga por', '<b class="lf-mono">' + money(r.total) + '</b>']
                        : ['Se cobra hoy', '<b class="lf-mono">' + money(r.anticipo) + '</b>']);
      if (!r.liga && r.saldo > 0.009) filas.push(['Saldo', '<b class="lf-mono">' + money(r.saldo) + '</b>']);
    }

    confCuerpo.innerHTML =
        (av.length ? '<div class="lf-conf-av">' + av.map(function(a){ return '<p>' + a + '</p>'; }).join('') + '</div>' : '')
      + '<ul class="lf-conf-items">' + items + '</ul>'
      + '<div class="lf-conf-total"><span>Total</span><b class="lf-mono">' + money(r.total) + '</b></div>'
      + '<dl class="lf-conf-datos">' + filas.map(function(f){
          return '<div><dt>' + f[0] + '</dt><dd>' + f[1] + '</dd></div>'; }).join('') + '</dl>';

    document.getElementById('cOk').textContent = r.liga ? 'Confirmar y generar liga' : 'Confirmar y registrar';
    conf.hidden = false;
    document.body.classList.add('lf-modal-abierto');
    /* El foco va a "Revisar" a propósito: ver nota arriba. */
    document.getElementById('cVolver').focus();
  }

  if (conf) {
    document.getElementById('cVolver').addEventListener('click', cerrarConf);
    document.getElementById('cCerrar').addEventListener('click', cerrarConf);
    conf.addEventListener('click', function(e){ if (e.target === conf) cerrarConf(); });
    document.getElementById('cOk').addEventListener('click', function(){
      cerrarConf();
      enviar();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && !conf.hidden) cerrarConf();
    });
  }

  if (form) {
    /* Enter dentro de un campo (precio, IVA, anticipo, referencia…) ya no
       manda el formulario. En un área de texto y en botones sí hace lo
       suyo: salto de línea, o el clic del botón enfocado. */
    form.addEventListener('keydown', function(e){
      if (e.key !== 'Enter') return;
      var t = e.target;
      if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON') return;
      e.preventDefault();
    });

    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      if (!conf || !window.lfResumenTicket) { enviar(); return; }   // sin resumen, como antes
      var r = window.lfResumenTicket();
      if (!r.lineas.length) return;
      abrirConf(r);
    });
  }
})();
</script>
