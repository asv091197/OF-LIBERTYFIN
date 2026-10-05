<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Servicio\AsignarComision;
use LibertyFin\Servicio\RegistrarPago;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

/**
 * Ventas.
 *
 * Compárese con ventas_lista.php del sistema anterior: 2,662 líneas con
 * 12 consultas y 320 bloques HTML mezclados. Aquí el controlador decide,
 * el repositorio consulta y la plantilla pinta.
 */
final class VentasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        // Por defecto, el mes en curso. Nunca "todo": eso fue lo que hacía
        // que el histórico mostrara meses que nadie quería ver.
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $filtros = [
            'estado' => Peticion::opcion('estado', ['completada','pendiente','cancelada']),
            'buscar' => Peticion::texto('q'),
        ];

        $pagina  = max(1, Peticion::entero('p', 1));
        $porPag  = Peticion::POR_PAGINA;
        $desfase = ($pagina - 1) * $porPag;

        // Al buscar se mira todo el historial, no solo el periodo del filtro.
        $todo = Fechas::buscandoTodo($filtros['buscar']);
        list($d1, $h1) = Fechas::rango($filtros['buscar'], $desde, $hasta);

        $resumen = $repo->resumen($d1, $h1, $filtros);
        $ventas  = $repo->listado($d1, $h1, $filtros, $porPag, $desfase);
        $total   = $repo->cuantas($d1, $h1, $filtros);
        $saldos  = $repo->saldosAbiertos(5);
        $meses   = $repo->cobradoPorMes(6);

        Plantilla::pagina('ventas/index', [
            'titulo'   => 'Ventas',
            'icono'    => 'venta',
            'subtitulo'=> ($todo ? 'Búsqueda en todo el historial' : Fechas::rotulo($desde, $hasta))
                          . ' · ' . number_format($total) . ' ventas',
            'todo'     => $todo,
            'soloPeriodo' => Peticion::texto('periodo') === '1',
            'resumen'  => $resumen,
            'ventas'   => $ventas,
            'saldos'   => $saldos,
            'meses'    => $meses,
            'desde'    => $desde,
            'hasta'    => $hasta,
            'filtros'  => $filtros,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / $porPag)),
            'total'    => $total,
        ]);
    }


    /** Detalle de una venta: pagos, gastos, IVA y comisiones en una pantalla. */
    public function ver($id)
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }

        Plantilla::pagina('ventas/detalle', [
            'titulo'     => 'Venta ' . $venta['codigo_venta'],
            'icono'      => 'venta',
            'subtitulo'  => ($venta['cliente'] ?: 'Público general') . ' · '
                          . date('d/m/Y H:i', strtotime($venta['fecha'])),
            'venta'      => $venta,
            'lineas'     => $repo->lineas($id),
            'metodos'    => (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(),
            'pagos'      => $repo->pagos($id),
            'gastos'     => $repo->gastos($id),
            'comisiones' => $repo->comisiones($id),
            'catalogo'   => (new ComisionRepo($db))->catalogo(),
            'sugeridos'  => (new ComisionRepo($db))->porcentajesUsados(),
            'nueva'      => Peticion::entero('nueva') === 1,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Registra un abono. */
    public function pagar($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $r = (new RegistrarPago($db))->abonar($id, [
                'monto'      => $_POST['monto'] ?? 0,
                'metodo'      => in_array($_POST['metodo'] ?? '',
                                    (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(), true)
                                    ? $_POST['metodo'] : 'efectivo',
                'referencia' => trim($_POST['referencia'] ?? ''),
                'fecha'      => Peticion::fecha('fecha', '') ?: ($_POST['fecha'] ?? ''),
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
            ]);
            Auditoria::anota('pago.registrar', 'venta ' . $id,
                null, ($_POST['metodo'] ?? '') . ' $' . ($_POST['monto'] ?? 0));
            $this->volver($id, $r['tipo'] === 'liquidacion'
                ? 'Abono registrado. La venta queda liquidada.'
                : 'Abono registrado. Queda un saldo de ' . \LibertyFin\Dominio\Dinero::pesos($r['saldo']) . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pagar: ' . $e->getMessage());
            $this->volver($id, 'No se pudo registrar el abono.', 'error');
        }
    }

    /** Cancela un pago. Solo admin: mueve dinero ya registrado. */
    public function cancelarPago($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede cancelar un pago.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            (new RegistrarPago($db))->cancelar(
                (int)($_POST['pago'] ?? 0), $_POST['motivo'] ?? '', $_SESSION['usuario_id'] ?? null);
            // Cancelar un pago mueve dinero hacia atrás y recalcula
            // comisiones: de todo lo que hace el sistema, es lo que más
            // falta hace poder reconstruir después.
            Auditoria::anota('pago.cancelar',
                'venta ' . $id . ' · pago ' . (int)($_POST['pago'] ?? 0),
                'activo', 'cancelado · ' . trim($_POST['motivo'] ?? ''));
            $this->volver($id, 'Pago cancelado. Las comisiones ya se recalcularon.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelarPago: ' . $e->getMessage());
            $this->volver($id, 'No se pudo cancelar el pago.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($id, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /ventas/' . (int)$id); exit;
    }

    /** Asigna una comisión a la venta. */
    public function asignarComision($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede asignar comisiones.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $r = (new AsignarComision($db))->asignar(
                $id, (int)($_POST['colaborador'] ?? 0), $_POST['pct'] ?? 0);
            Auditoria::anota('comision.asignar',
                'venta ' . $id . ' · ' . $r['colaborador'],
                null, $_POST['pct'] . '% sobre ' . $r['base']);
            $this->volver($id, $r['colaborador'] . ': '
                . \LibertyFin\Dominio\Dinero::pesos($r['asignada'])
                . ' sobre una base de ' . \LibertyFin\Dominio\Dinero::pesos($r['base']) . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] asignarComision: ' . $e->getMessage());
            $this->volver($id, 'No se pudo asignar la comisión.', 'error');
        }
    }

    /** Quita una comisión. */
    public function quitarComision($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver($id, 'Solo un administrador puede quitar comisiones.', 'error');
        }
        if (!$this->tokenValido()) $this->volver($id, 'No se pudo verificar el formulario.', 'error');

        try {
            $c = (new AsignarComision($db))->quitar((int)($_POST['comision'] ?? 0));
            Auditoria::anota('comision.quitar',
                'venta ' . $id . ' · comisión ' . (int)($_POST['comision'] ?? 0),
                'activa', 'cancelada');
            $this->volver($id, 'Comisión de ' . $c['colaborador_nombre'] . ' retirada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($id, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] quitarComision: ' . $e->getMessage());
            $this->volver($id, 'No se pudo quitar la comisión.', 'error');
        }
    }

    /** El ticket, para imprimir. Sin barra lateral ni menús. */
    public function ticket($id)
    {
        $db    = Conexion::de($_SESSION['empresa_db']);
        $repo  = new VentaRepo($db);
        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            Plantilla::pagina('errores/404', ['titulo'=>'No encontrada','icono'=>'alerta','subtitulo'=>'']);
            return;
        }
        $empresa = null;
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            $empresa = (new \LibertyFin\Datos\EmpresaRepo($principal))->uno($_SESSION['empresa_id'] ?? 0);
        } catch (\Throwable $e) { /* el ticket se imprime igual sin el membrete */ }

        // Sin layout: es una hoja suelta que se manda a la impresora.
        Plantilla::parcial('ventas/ticket', [
            'venta'   => $venta,
            'lineas'  => $repo->lineas($id),
            'empresa' => $empresa,
        ]);
    }
}
