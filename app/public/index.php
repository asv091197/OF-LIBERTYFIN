<?php
/**
 * Punto de entrada único.
 *
 * Todo pasa por aquí. Nada más en public/ es ejecutable, así que no
 * existe la posibilidad de abrir un .env, un log o un include suelto
 * por URL: no viven en la raíz del sitio.
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Router;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

$cfg = is_readable($raiz . '/config/config.php')
     ? require $raiz . '/config/config.php'
     : ['bd' => ['host'=>'localhost','usuario'=>'','clave'=>'','principal'=>''], 'depurar' => true];

if (!empty($cfg['depurar'])) { ini_set('display_errors','1'); error_reporting(E_ALL); }

// ── Zona horaria ──
// PHP y MySQL tienen que coincidir. Si no, date() arma el folio con la
// hora de México y NOW() guarda UTC: seis horas de diferencia que, en
// una venta de las 18:00 de fin de mes, la mandan al mes siguiente.
// Pasó de verdad: INITME Solutions, 31 de agosto a las 18:09, quedó
// registrada el 1 de septiembre.
date_default_timezone_set($cfg['zona'] ?? 'America/Mexico_City');
Conexion::configurar($cfg['bd'] + ['zona_sql' => $cfg['zona_sql'] ?? '-06:00']);
Plantilla::base($raiz . '/src/Vista/plantillas');
\LibertyFin\Servicio\Integraciones::cargar($raiz);
\LibertyFin\Servicio\Archivos::destino($raiz . '/public/assets/subidas');
$GLOBALS['lf_bd_principal'] = $cfg['bd']['principal'] ?? '';

// ── Cookie de sesión ──
// httponly: el JavaScript no puede leerla, así que un XSS no se lleva la
// sesión. samesite Lax: no viaja desde otro sitio, que es la defensa base
// contra envíos cruzados. secure solo si hay HTTPS, o en local no entra.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// ── Rutas ──
$r = new Router();

// Públicas
$r->get('/login',  ['LibertyFin\Controlador\LoginControlador', 'mostrar']);
$r->post('/login', ['LibertyFin\Controlador\LoginControlador', 'entrar']);
$r->get('/salir',  ['LibertyFin\Controlador\LoginControlador', 'salir']);
$r->get('/ayuda-acceso', ['LibertyFin\Controlador\LoginControlador', 'ayudaAcceso']);
// El registro solo existe si hay credenciales de cPanel: sin ellas no se
// puede crear la base de una empresa nueva, y un formulario que no lleva
// a ningún lado es peor que no tenerlo.
if (\LibertyFin\Servicio\Integraciones::activa('cpanel')) {
    $r->get('/registro',  ['LibertyFin\Controlador\RegistroControlador', 'formulario']);
    $r->post('/registro', ['LibertyFin\Controlador\RegistroControlador', 'enviar']);
}

// ── Los avisos de Paga de Todo ──
// Son públicas porque el proveedor pega desde sus servidores: no trae
// cookie, ni sesión, ni usuario. Lo que las protege es el secreto que va
// en la URL (?k=...), guardado en config/integraciones.php.
//
// Las direcciones que hay que dar de alta en el panel del Sandbox son
// estas mismas con el secreto pegado. Ver LEEME.md.
if (\LibertyFin\Servicio\Integraciones::activa('spei')) {
    $pdt = 'LibertyFin\\Controlador\\PagosEntrantesControlador';
    $r->get ('/pagadetodo/consulta-referencia', [$pdt, 'consultaReferencia']);
    $r->post('/pagadetodo/pago-referencia',     [$pdt, 'pagoReferencia']);
    $r->get ('/pagadetodo/consulta-clabe',      [$pdt, 'consultaClabe']);
    $r->post('/pagadetodo/pago-clabe',          [$pdt, 'pagoClabe']);
    $r->post('/pagadetodo/pago-liga',           [$pdt, 'pagoLiga']);
    // La cancelación llega por DELETE o por POST: el proveedor decide.
    $r->post  ('/pagadetodo/cancela-pago', [$pdt, 'cancelaPago']);
    $r->delete('/pagadetodo/cancela-pago', [$pdt, 'cancelaPago']);
}

// Privadas
// La raíz lleva a un panel u otro según el nivel del rol. Un rol de
// plataforma en el panel de empresa vería las ventas de quien le prestó
// la sesión, y las leería como del cliente que llamó.
if (\LibertyFin\Dominio\Permisos::esPlataforma($_SESSION['usuario_rol'] ?? '')) {
    $r->get('/',    ['LibertyFin\Controlador\SoporteControlador', 'panel']);
} else {
    $r->get('/',    ['LibertyFin\Controlador\PanelControlador',  'index']);
}
$r->get('/ventas',  ['LibertyFin\Controlador\VentasControlador', 'index']);
$r->get('/ventas/{id}',               ['LibertyFin\Controlador\VentasControlador', 'ver']);
$r->get('/ventas/{id}/ticket',        ['LibertyFin\Controlador\VentasControlador', 'ticket']);
$r->post('/ventas/{id}/pagar',        ['LibertyFin\Controlador\VentasControlador', 'pagar']);
$r->post('/ventas/{id}/cancelar-pago',['LibertyFin\Controlador\VentasControlador', 'cancelarPago']);
$r->post('/ventas/{id}/comision',        ['LibertyFin\Controlador\VentasControlador', 'asignarComision']);
$r->post('/ventas/{id}/quitar-comision', ['LibertyFin\Controlador\VentasControlador', 'quitarComision']);
$r->get('/caja',          ['LibertyFin\Controlador\CajaControlador', 'index']);
$r->get('/caja/clientes', ['LibertyFin\Controlador\CajaControlador', 'clientes']);
// Las ventas a las que se les puede agregar algo, para no abrir otro
// folio cuando el cliente se acuerda de un servicio mas.
$r->get('/caja/ventas-abiertas', ['LibertyFin\Controlador\CajaControlador', 'ventasAbiertas']);
$r->get('/qr',              ['LibertyFin\Controlador\CajaControlador', 'qr']);
$r->get('/caja/estado/{id}', ['LibertyFin\Controlador\CajaControlador', 'estado']);
$r->post('/caja/cobrar',  ['LibertyFin\Controlador\CajaControlador', 'cobrar']);
$r->get('/comisiones',            ['LibertyFin\Controlador\ComisionesControlador', 'index']);
$r->get('/comisiones/colaborador/{id}', ['LibertyFin\Controlador\ComisionesControlador', 'colaborador']);
$r->post('/comisiones/reasignar', ['LibertyFin\Controlador\ComisionesControlador', 'reasignar']);
$r->get('/clientes',  ['LibertyFin\Controlador\ClientesControlador',  'index']);
$r->get('/cobranza',  ['LibertyFin\Controlador\CobranzaControlador',  'index']);
$r->get('/gastos',          ['LibertyFin\Controlador\GastosControlador', 'index']);
$r->post('/gastos/guardar', ['LibertyFin\Controlador\GastosControlador', 'guardar']);
$r->post('/gastos/borrar',  ['LibertyFin\Controlador\GastosControlador', 'borrar']);
$r->post('/gastos/proveedor',          ['LibertyFin\Controlador\GastosControlador', 'guardarProveedor']);
$r->post('/gastos/proveedor/alternar', ['LibertyFin\Controlador\GastosControlador', 'alternarProveedor']);
$r->get('/servicios', ['LibertyFin\Controlador\ServiciosControlador', 'index']);
$r->post('/clientes/guardar',   ['LibertyFin\Controlador\ClientesControlador',  'guardar']);
$r->post('/servicios/guardar',  ['LibertyFin\Controlador\ServiciosControlador', 'guardar']);
$r->post('/servicios/alternar', ['LibertyFin\Controlador\ServiciosControlador', 'alternar']);
$r->get('/reportes',     ['LibertyFin\Controlador\ReportesControlador', 'index']);
// Ligas de pago: solo con credenciales de SPEI.
if (\LibertyFin\Servicio\Integraciones::activa('spei')) {
    $r->get('/ligas',          ['LibertyFin\Controlador\LigasControlador', 'index']);
    $r->post('/ligas/generar', ['LibertyFin\Controlador\LigasControlador', 'generar']);
    $r->post('/ligas/revisar', ['LibertyFin\Controlador\LigasControlador', 'revisar']);
    $r->get('/ligas/{id}/documento', ['LibertyFin\Controlador\LigasControlador', 'documento']);
    $r->post('/ligas/confirmar', ['LibertyFin\Controlador\LigasControlador', 'confirmar']);
$r->post('/ligas/aprobar', ['LibertyFin\Controlador\LigasControlador', 'aprobar']);
}

// Facturación: igual que recargas, solo existe con credenciales.
if (\LibertyFin\Servicio\Integraciones::activa('facturapi')) {
    $r->get('/facturacion',              ['LibertyFin\Controlador\FacturacionControlador', 'index']);
    $r->get('/facturacion/{id}/timbrar', ['LibertyFin\Controlador\FacturacionControlador', 'timbrar']);
}

// Recargas: la ruta solo existe si hay credenciales. Sin ellas, 404.
if (\LibertyFin\Servicio\Integraciones::activa('emida')) {
    $r->get('/recargas',          ['LibertyFin\Controlador\RecargasControlador', 'index']);
    $r->post('/recargas/consultar',['LibertyFin\Controlador\RecargasControlador', 'consultar']);
    $r->post('/recargas/probar',  ['LibertyFin\Controlador\RecargasControlador', 'probar']);
    $r->post('/recargas/catalogo',['LibertyFin\Controlador\RecargasControlador', 'sincronizar']);
    $r->post('/recargas/pegar',   ['LibertyFin\Controlador\RecargasControlador', 'pegarCatalogo']);
    $r->post('/recargas/vender',  ['LibertyFin\Controlador\RecargasControlador', 'vender']);
}
$r->get('/reportes/excel',    ['LibertyFin\Controlador\ReportesControlador', 'excel']);
$r->get('/reportes/imprimir', ['LibertyFin\Controlador\ReportesControlador', 'imprimir']);
$r->get('/ayuda',                 ['LibertyFin\Controlador\AyudaControlador', 'index']);
$r->post('/ayuda/crear',          ['LibertyFin\Controlador\AyudaControlador', 'crear']);
$r->post('/ayuda/{id}/responder', ['LibertyFin\Controlador\AyudaControlador', 'responder']);
$r->get('/plataforma',            ['LibertyFin\Controlador\PlataformaControlador', 'index']);
$r->post('/plataforma/usuario',   ['LibertyFin\Controlador\PlataformaControlador', 'guardarUsuario']);
$r->post('/plataforma/alternar',  ['LibertyFin\Controlador\PlataformaControlador', 'alternarUsuario']);
$r->post('/plataforma/clave',     ['LibertyFin\Controlador\PlataformaControlador', 'claveUsuario']);
$r->post('/plataforma/empresa',   ['LibertyFin\Controlador\PlataformaControlador', 'suspenderEmpresa']);
$r->post('/plataforma/global',    ['LibertyFin\Controlador\PlataformaControlador', 'alternarGlobal']);
$r->get('/informes',              ['LibertyFin\Controlador\InformesControlador', 'index']);
$r->get('/conocimiento',          ['LibertyFin\Controlador\ConocimientoControlador', 'index']);
$r->post('/conocimiento/guardar', ['LibertyFin\Controlador\ConocimientoControlador', 'guardar']);
$r->post('/conocimiento/alternar',['LibertyFin\Controlador\ConocimientoControlador', 'alternar']);
$r->get('/auditoria',             ['LibertyFin\Controlador\AuditoriaControlador', 'index']);
$r->get('/tickets',               ['LibertyFin\Controlador\TicketsControlador', 'index']);
$r->post('/tickets/crear',        ['LibertyFin\Controlador\TicketsControlador', 'crear']);
$r->get('/tickets/{id}',          ['LibertyFin\Controlador\TicketsControlador', 'ver']);
$r->post('/tickets/{id}/responder',['LibertyFin\Controlador\TicketsControlador', 'responder']);
$r->post('/tickets/{id}/cambiar', ['LibertyFin\Controlador\TicketsControlador', 'cambiar']);
$r->get('/soporte',               ['LibertyFin\Controlador\SoporteControlador', 'index']);
$r->get('/soporte/{id}',          ['LibertyFin\Controlador\SoporteControlador', 'ficha']);
$r->post('/soporte/restablecer',  ['LibertyFin\Controlador\SoporteControlador', 'restablecer']);
$r->post('/soporte/alternar',     ['LibertyFin\Controlador\SoporteControlador', 'alternar']);
$r->post('/soporte/ajuste',       ['LibertyFin\Controlador\SoporteControlador', 'alternarAjuste']);
$r->get('/mantenimiento',           ['LibertyFin\Controlador\MantenimientoControlador', 'index']);
$r->post('/mantenimiento/secciones',['LibertyFin\Controlador\MantenimientoControlador', 'secciones']);
$r->post('/mantenimiento/migrar',   ['LibertyFin\Controlador\MantenimientoControlador', 'migrar']);
$r->post('/mantenimiento/revisar',  ['LibertyFin\Controlador\MantenimientoControlador', 'revisar']);
$r->post('/mantenimiento/pago',     ['LibertyFin\Controlador\MantenimientoControlador', 'revisarPago']);
$r->post('/mantenimiento/correo',   ['LibertyFin\Controlador\MantenimientoControlador', 'probarCorreo']);
$r->post('/mantenimiento/empresa/aprobar',  ['LibertyFin\Controlador\MantenimientoControlador', 'aprobarEmpresa']);
$r->post('/mantenimiento/empresa/rechazar', ['LibertyFin\Controlador\MantenimientoControlador', 'rechazarEmpresa']);
$r->get('/ajustes',          ['LibertyFin\Controlador\AjustesControlador', 'index']);
$r->post('/ajustes/guardar', ['LibertyFin\Controlador\AjustesControlador', 'guardar']);
$r->post('/ajustes/alternar',['LibertyFin\Controlador\AjustesControlador', 'alternar']);
$r->post('/ajustes/aprobacion',['LibertyFin\Controlador\AjustesControlador', 'aprobacion']);
$r->get('/usuarios',             ['LibertyFin\Controlador\UsuariosControlador', 'index']);
$r->post('/usuarios/guardar',    ['LibertyFin\Controlador\UsuariosControlador', 'guardar']);
$r->post('/usuarios/restablecer',['LibertyFin\Controlador\UsuariosControlador', 'restablecer']);
$r->post('/usuarios/alternar',   ['LibertyFin\Controlador\UsuariosControlador', 'alternar']);
$r->get('/cuenta',               ['LibertyFin\Controlador\UsuariosControlador', 'miCuenta']);
$r->post('/cuenta/clave',        ['LibertyFin\Controlador\UsuariosControlador', 'cambiarClave']);
$r->post('/cuenta/foto',         ['LibertyFin\Controlador\UsuariosControlador', 'guardarFoto']);
$r->post('/cuenta/fiscales',     ['LibertyFin\Controlador\UsuariosControlador', 'guardarFiscales']);
$r->post('/cuenta/comercio',     ['LibertyFin\Controlador\UsuariosControlador', 'guardarComercio']);
$r->post('/cuenta/documento',    ['LibertyFin\Controlador\UsuariosControlador', 'subirDocumento']);
$r->post('/cuenta/plan',         ['LibertyFin\Controlador\UsuariosControlador', 'solicitarPlan']);
$r->post('/cuenta/plan/comprobante', ['LibertyFin\Controlador\UsuariosControlador', 'comprobantePlan']);
$r->post('/guia/vista',          ['LibertyFin\Controlador\UsuariosControlador', 'guiaVista']);
$r->get('/guia',                 ['LibertyFin\Controlador\UsuariosControlador', 'verGuia']);
$r->get('/corte',        ['LibertyFin\Controlador\CorteControlador', 'index']);
$r->post('/corte/abrir', ['LibertyFin\Controlador\CorteControlador', 'abrir']);
$r->post('/corte/cerrar',['LibertyFin\Controlador\CorteControlador', 'cerrar']);

// ── Qué permiso pide cada ruta ──
// El router ya no solo dice si la ruta existe: dice quién puede entrar.
// Esconder el enlace del menú es cortesía; esto es la puerta.
$permisos = [
  // La raíz no lleva permiso: cada rol va a su propio panel.
  '/ventas'                 => 'ver.ventas',
  '/ventas/{id}'            => 'ver.ventas',
  '/ventas/{id}/ticket'     => 'ver.ventas',
  '/ventas/{id}/pagar'      => 'abonar',
  '/ventas/{id}/cancelar-pago' => 'cancelar.pago',
  '/ventas/{id}/comision'   => 'asignar.comision',
  '/ventas/{id}/quitar-comision' => 'quitar.comision',
  '/caja'                   => 'cobrar',
  '/caja/clientes'          => 'cobrar',
  '/caja/ventas-abiertas'   => 'cobrar',
  '/caja/cobrar'            => 'cobrar',
  '/caja/estado/{id}'       => 'cobrar',
  '/qr'                     => 'cobrar',
  '/cobranza'               => 'ver.cobranza',
  '/corte'                  => 'ver.corte',
  '/corte/abrir'            => 'abrir.caja',
  '/corte/cerrar'           => 'cerrar.caja',
  '/clientes'               => 'ver.clientes',
  '/clientes/guardar'       => 'editar.clientes',
  '/comisiones'             => 'ver.comisiones',
  '/comisiones/colaborador/{id}' => 'ver.comisiones',
  '/comisiones/reasignar'   => 'asignar.comision',
  '/gastos'                 => 'ver.gastos',
  '/gastos/guardar'         => 'editar.gastos',
  '/gastos/borrar'          => 'borrar.gastos',
  '/gastos/proveedor'       => 'editar.gastos',
  '/gastos/proveedor/alternar' => 'editar.gastos',
  '/servicios'              => 'ver.servicios',
  '/servicios/guardar'      => 'editar.servicios',
  '/servicios/alternar'     => 'editar.servicios',
  '/reportes'               => 'ver.reportes',
  '/reportes/excel'         => 'ver.reportes',
  '/reportes/imprimir'      => 'ver.reportes',
  '/ajustes'                => 'ver.ajustes',
  '/ajustes/guardar'        => 'editar.ajustes',
  '/ajustes/alternar'       => 'editar.ajustes',
  '/ajustes/aprobacion'     => 'editar.ajustes',
  '/usuarios'               => 'ver.usuarios',
  '/usuarios/guardar'       => 'editar.usuarios',
  '/usuarios/restablecer'   => 'editar.usuarios',
  '/usuarios/alternar'      => 'editar.usuarios',
  '/ligas'                  => 'ver.ligas',
  '/ligas/generar'          => 'cobrar',
  '/ligas/revisar'          => 'ver.ligas',
  '/ligas/{id}/documento'   => 'ver.ligas',
  '/ligas/aprobar'          => 'cobrar',
  '/ligas/confirmar'        => 'cobrar',
  '/facturacion'            => 'ver.facturacion',
  '/facturacion/{id}/timbrar' => 'timbrar',
  '/recargas'               => 'ver.recargas',
  '/recargas/consultar'     => 'ver.recargas',
  '/recargas/probar'        => 'ver.recargas',
  '/recargas/catalogo'      => 'ver.recargas',
  '/recargas/pegar'         => 'ver.recargas',
  '/recargas/vender'        => 'vender.recarga',
  '/ayuda'                  => 'abrir.ticket',
  '/ayuda/crear'            => 'abrir.ticket',
  '/ayuda/{id}/responder'   => 'abrir.ticket',
  '/plataforma'             => 'usuarios.plataforma',
  '/plataforma/usuario'     => 'usuarios.plataforma',
  '/plataforma/alternar'    => 'usuarios.plataforma',
  '/plataforma/clave'       => 'usuarios.plataforma',
  '/plataforma/empresa'     => 'suspender.empresa',
  '/plataforma/global'      => 'ajustes.globales',
  '/informes'               => 'ver.informes',
  '/conocimiento'           => 'ver.conocimiento',
  '/conocimiento/guardar'   => 'editar.conocimiento',
  '/conocimiento/alternar'  => 'editar.conocimiento',
  '/auditoria'              => 'ver.auditoria',
  '/tickets'                => 'ver.tickets',
  '/tickets/crear'          => 'ver.tickets',
  '/tickets/{id}'           => 'ver.tickets',
  '/tickets/{id}/responder' => 'ver.tickets',
  '/tickets/{id}/cambiar'   => 'ver.tickets',
  '/soporte'                => 'ver.empresas',
  '/soporte/{id}'           => 'ver.empresas',
  '/soporte/restablecer'    => 'clave.ajena',
  '/soporte/alternar'       => 'bloquear.cuenta',
  '/soporte/ajuste'         => 'ajustes.empresa',
  '/mantenimiento'          => 'ver.mantenimiento',
  '/mantenimiento/secciones'=> 'secciones',
  '/mantenimiento/migrar'   => 'secciones',
  '/mantenimiento/revisar'  => 'revisar.docs',
  '/mantenimiento/pago'     => 'revisar.docs',
  '/mantenimiento/correo'   => 'diagnostico',
  '/mantenimiento/empresa/aprobar'  => 'alta.empresas',
  '/mantenimiento/empresa/rechazar' => 'alta.empresas',
  // El perfil y la clave son de cada quien, sin permiso. Los datos
  // fiscales, el alta de comercio y los documentos comprometen a la
  // empresa entera: esos sí son de administrador.
  '/cuenta/fiscales'  => 'editar.empresa',
  '/cuenta/comercio'  => 'editar.empresa',
  '/cuenta/documento' => 'editar.empresa',
  '/cuenta/plan'      => 'editar.empresa',
  '/cuenta/plan/comprobante' => 'editar.empresa',
];

$publicas = ['/login', '/salir', '/registro', '/ayuda-acceso',
    // Los avisos del proveedor de pago. No pueden pedir sesión: quien
    // llama es un servidor de Paga de Todo, no una persona con cookie.
    // Su puerta es el secreto de la URL, que revisa el controlador.
    '/pagadetodo/consulta-referencia', '/pagadetodo/pago-referencia',
    '/pagadetodo/consulta-clabe',      '/pagadetodo/pago-clabe',
    '/pagadetodo/cancela-pago',        '/pagadetodo/pago-liga',
];
$ruta     = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// El portero: una sola línea decide quién pasa, en vez de repetir la
// comprobación al inicio de cada archivo como hacía el sistema anterior.
if (!in_array($ruta, $publicas, true) && !Autenticar::sesionValida()) {
    header('Location: /login'); exit;
}

$hallazgo = $r->despachar($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

// La bitácora escribe en la base de la empresa de la sesión. Se fija una
// vez aquí para que ningún controlador tenga que acordarse.
// SI EL CÓDIGO AVANZÓ MIENTRAS HABÍA SESIONES ABIERTAS.
//
// Las migraciones se aplican al entrar. Quien ya estaba dentro cuando
// se subió una actualización sigue con la base vieja, y una pantalla
// que usa una columna nueva revienta con "Unknown column" sin decir
// qué hacer.
//
// Esto lo nota comparando con lo que se guardó al entrar: cero
// consultas de más en el caso normal, y se arregla solo en cuanto el
// código cambia.
if (!empty($_SESSION['empresa_db'])
    && ($_SESSION['lf_esquema'] ?? 0) < \LibertyFin\Servicio\Migraciones::VERSION) {
    try {
        \LibertyFin\Servicio\Migraciones::aplicar(
            \LibertyFin\Datos\Conexion::de($_SESSION['empresa_db']));
        $_SESSION['lf_esquema'] = \LibertyFin\Servicio\Migraciones::VERSION;
    } catch (\Throwable $e) {
        error_log('[LibertyFin] migración en caliente: ' . $e->getMessage());
    }
}

// Un usuario de plataforma no tiene base de empresa: su bitácora se
// escribe en la base de la empresa sobre la que actúa, no aquí.
if (!empty($_SESSION['empresa_db'])) {
    try { \LibertyFin\Servicio\Auditoria::en(\LibertyFin\Datos\Conexion::de($_SESSION['empresa_db'])); }
    catch (\Throwable $e) { /* sin bitácora se opera igual */ }
}

// ── El permiso, antes de ejecutar nada ──
if ($hallazgo !== null && !in_array($ruta, $publicas, true)) {
    $patron = $hallazgo['patron'] ?? $ruta;
    $necesita = $permisos[$patron] ?? null;
    // Una sesión de plataforma en una ruta de empresa: se detiene aquí
    // con una explicación, en vez de dejar que reviente adentro con
    // "1046 No database selected".
    if ($necesita !== null && empty($_SESSION['empresa_db'])
        && \LibertyFin\Dominio\Permisos::necesitaEmpresa($necesita)) {
        http_response_code(403);
        Plantilla::pagina('errores/sin-empresa', [
            'titulo' => 'Esta pantalla es de una empresa', 'icono' => 'alerta', 'subtitulo' => '',
        ]);
        exit;
    }

    if ($necesita !== null && !\LibertyFin\Dominio\Permisos::puede($necesita)) {
        http_response_code(403);
        Plantilla::pagina('errores/403', [
            'titulo' => 'Sin permiso', 'icono' => 'alerta', 'subtitulo' => '',
            'permiso' => $necesita,
        ]);
        exit;
    }
}

if ($hallazgo === null) {
    http_response_code(404);
    Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
    exit;
}

try {
    list($clase, $metodo) = $hallazgo['destino'];
    call_user_func_array([new $clase(), $metodo], $hallazgo['args']);
} catch (Throwable $e) {
    error_log('[LibertyFin] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());

    // UN AVISO DE PAGO NUNCA SE CONTESTA CON 500.
    //
    // Paga de Todo lee un 500 como "el emisor no lo autorizó" y cancela
    // la operación. El cliente ya pagó en la tienda y ya tiene su
    // ticket: deshacerlo del lado del proveedor deja un cobro que la
    // tienda hizo y que nadie reconoce. Se contesta el código 50 que su
    // documentación pide para esto, con HTTP 200, y el error queda en
    // el log para revisarlo con calma.
    if (strncmp($ruta, '/pagadetodo/', 12) === 0) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['codigo' => 50, 'mensaje' => 'Error de sistema',
                          'code' => '99', 'message' => 'Error interno.']);
        exit;
    }

    http_response_code(500);
    if (!empty($cfg['depurar'])) {
        echo '<pre style="padding:20px;font:13px ui-monospace">'
           . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo 'Ocurrió un error. Ya quedó registrado.';
    }
}
