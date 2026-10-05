<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\EmidaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Emida;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Recargas y pago de servicios.
 *
 * EL ORDEN IMPORTA Y NO ES EL OBVIO
 *
 *   1. Se elige un PRODUCTO del catálogo, no una compañía y un monto.
 *      Cada combinación es un identificador distinto para Emida, y
 *      mandar otra cosa da el código 51.
 *   2. Se envía al proveedor.
 *   3. Y solo si sale, se registra el cobro.
 *
 * Cobrar primero parece más natural —el cliente ya te dio el dinero—
 * pero deja el caso donde la recarga falla y hay que devolver efectivo
 * de una caja que ya cuadró. Al revés, lo peor que pasa es no vender.
 */
final class RecargasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new EmidaRepo($db);
        $cfg  = Integraciones::de('emida');
        $api  = new Emida($cfg);

        $buscar = trim(Peticion::texto('q', ''));
        $cat    = trim(Peticion::texto('cat', ''));
        $carr   = trim(Peticion::texto('carrier', ''));
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 24;
        $total  = $repo->cuantos($buscar, $cat, $carr);

        Plantilla::pagina('recargas/index', [
            'titulo'      => 'Recargas y servicios',
            'icono'       => 'bolsa',
            'subtitulo'   => !empty($cfg['sandbox']) ? 'Modo de pruebas' : 'En producción',
            'sandbox'     => !empty($cfg['sandbox']),
            'cifrado'     => $api->cifrado(),
            'aceptado'    => !empty($cfg['acepto_sin_cifrar']),
            'productos'   => $repo->productos($buscar, $cat, $carr, $porPag, ($pagina-1)*$porPag),
            'categorias'  => $repo->categorias(),
            'carriers'    => $repo->carriers($cat),
            'actualizado' => $repo->cuandoSeActualizo(),
            'total'       => $total,
            'pagina'      => $pagina,
            'paginas'     => max(1, (int)ceil($total / $porPag)),
            'q' => $buscar, 'cat' => $cat, 'carrier' => $carr,
            'hoy'         => $repo->cifrasDelDia(),
            'transacciones' => $repo->delDia(),
            'saldo'       => $_SESSION['lf_saldo_emida'] ?? null,
            'prueba'      => $_SESSION['lf_prueba_emida'] ?? null,
            'elegido'     => Peticion::texto('producto', '')
                             ? $repo->porId(Peticion::texto('producto', '')) : null,
            'pegar'       => !empty($_SESSION['lf_pegar']),
            'aviso'       => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_saldo_emida'],
              $_SESSION['lf_prueba_emida'], $_SESSION['lf_pegar']);
    }

    

    /**
     * Baja el catálogo del proveedor y lo guarda.
     *
     * Son más de mil productos: pedirlos en cada carga de la pantalla la
     * haría tardar segundos y no vendería nada si el proveedor no
     * contesta. Se baja a mano, cuando alguien lo pide.
     */
    public function sincronizar()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);

        ob_start();
        $r = (new Emida(Integraciones::de('emida')))->catalogo();
        ob_end_clean();

        if (!$r['ok']) {
            // Si el intermediario no tiene el script, se dice qué hacer
            // mientras tanto en vez de dejarlo en "no se pudo".
            $_SESSION['lf_pegar'] = !empty($r['sin_script']);
            $this->volver('No se pudo bajar el catálogo: ' . $r['error'], 'error');
        }
        if (!$r['productos']) {
            $this->volver('El proveedor respondió, pero sin productos. '
                . 'Puede que la cuenta no tenga ninguno asignado todavía.', 'error');
        }
        try {
            $n = (new EmidaRepo($db))->guardarCatalogo($r['productos']);
            Auditoria::anota('ajustes.cambiar', 'catálogo de Emida', null, $n . ' productos');
            $this->volver($n . ' productos actualizados.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] catálogo emida: ' . $e->getMessage());
            $this->volver('No se pudo guardar el catálogo: ' . $e->getMessage(), 'error');
        }
    }

    public function consultar()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        ob_start();
        $r = (new Emida(Integraciones::de('emida')))->saldo();
        ob_end_clean();
        if (!$r['ok']) $this->volver($r['error'], 'error');
        $_SESSION['lf_saldo_emida'] = $r['saldo'];
        $this->volver('Saldo consultado.', 'ok');
    }

    public function probar()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        ob_start();
        $r = (new Emida(Integraciones::de('emida')))->probar();
        ob_end_clean();
        $_SESSION['lf_prueba_emida'] = $r;
        $this->volver('Prueba terminada.', 'ok');
    }

    /** Vende un producto del catálogo. */
    public function vender()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new EmidaRepo($db);

        // El producto sale del CATÁLOGO, no del formulario: así nadie
        // puede vender un identificador que no existe o uno que el
        // proveedor dejó de ofrecer.
        $p = $repo->porId($_POST['producto'] ?? '');
        if (!$p) $this->volver('Ese producto ya no está en el catálogo. Actualízalo.', 'error');

        $cuenta = preg_replace('/[^0-9]/', '', (string)($_POST['cuenta'] ?? ''));
        if (strlen($cuenta) < 6) {
            $this->volver('Escribe el número o la referencia completa.', 'error');
        }

        // El monto: fijo si el producto lo trae, capturado si es variable.
        if ((float)$p['monto'] > 0) {
            $monto = (float)$p['monto'];
        } else {
            $monto = round((float)($_POST['monto'] ?? 0), 2);
            $min = (float)$p['monto_min'];
            $max = (float)$p['monto_max'];
            if ($min > 0 && $monto < $min) {
                $this->volver('El mínimo de este servicio es ' . Dinero::pesos($min) . '.', 'error');
            }
            if ($max > 0 && $monto > $max) {
                $this->volver('El máximo de este servicio es ' . Dinero::pesos($max) . '.', 'error');
            }
            if ($monto <= 0) $this->volver('Escribe el monto a pagar.', 'error');
        }

        // Identificador propio. Si la red se cae y se reintenta con el
        // mismo, Emida devuelve 294 en vez de cobrar dos veces.
        $salesId = date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 4);

        $repo->registrar([
            'sales_id' => $salesId, 'producto_id' => $p['producto_id'],
            'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
            'comision' => $p['comision'], 'estado' => 'pendiente',
            'usuario_id' => $_SESSION['usuario_id'] ?? null,
            'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
        ]);

        ob_start();
        $api = new Emida(Integraciones::de('emida'));
        $r = $api->recargar($cuenta, $p['producto_id'], $monto, $salesId);
        ob_end_clean();

        if (!empty($r['incierta'])) {
            // El peor caso: no se sabe si salió. NUNCA se reintenta solo.
            $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
                'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
                'comision' => $p['comision'], 'estado' => 'incierta',
                'mensaje' => $r['error']]);
            error_log('[LibertyFin] recarga incierta ' . $salesId . ' ' . $cuenta);
            $this->volver($r['error'], 'error');
        }

        if (!$r['ok']) {
            $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
                'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
                'comision' => $p['comision'], 'estado' => 'fallida',
                'codigo' => $r['codigo'] ?? null, 'h2h' => $r['h2h'] ?? null,
                'mensaje' => $r['error']]);
            $this->volver($r['error'], 'error');
        }

        $repo->registrar(['sales_id' => $salesId, 'producto_id' => $p['producto_id'],
            'producto_nombre' => $p['nombre'], 'cuenta' => $cuenta, 'monto' => $monto,
            'comision' => $p['comision'], 'estado' => 'exitosa',
            'folio' => $r['folio'] ?? null, 'mensaje' => $r['duplicada'] ? 'duplicada' : '']);

        Auditoria::anota('pago.registrar', 'recarga ' . $p['nombre'] . ' · ' . $cuenta,
            null, Dinero::pesos($monto));

        $this->volver(
            ($r['duplicada']
                ? 'Esa misma operación ya se había hecho hace unos minutos, no se cobró dos veces. '
                : $p['nombre'] . ' aplicado a ' . $cuenta . ' por ' . Dinero::pesos($monto) . '. ')
            . (!empty($r['folio']) ? 'Folio ' . $r['folio'] . '.' : ''), 'ok');
    }

    private function token()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /recargas'); exit;
    }

    /**
     * Carga el catálogo pegado desde el portal del proveedor.
     *
     * No reemplaza a bajarlo por API: es lo que permite trabajar mientras
     * el intermediario no tenga el script. Un sistema que no se puede
     * usar hasta que un tercero suba un archivo no sirve de nada.
     */
    public function pegarCatalogo()
    {
        if (!$this->token()) $this->volver('No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);

        $productos = EmidaRepo::leerPegado($_POST['pegado'] ?? '');
        if (!$productos) {
            $_SESSION['lf_pegar'] = true;
            $this->volver('No se entendió ningún producto. Copia la tabla completa '
                . 'desde el portal de Emida, con todo y encabezados.', 'error');
        }
        try {
            $n = (new EmidaRepo($db))->guardarCatalogo($productos);
            Auditoria::anota('ajustes.cambiar', 'catálogo de Emida (pegado)',
                null, $n . ' productos');
            $this->volver($n . ' productos cargados desde el pegado.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pegar catálogo: ' . $e->getMessage());
            $this->volver('No se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }
}
