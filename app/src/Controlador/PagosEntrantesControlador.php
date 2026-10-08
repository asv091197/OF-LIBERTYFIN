<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\LigaRepo;
use LibertyFin\Datos\RutaLigaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\RegistrarPago;

/**
 * Los servicios que PAGA DE TODO nos llama a nosotros.
 *
 * ESTA ES LA MITAD QUE FALTABA
 *
 * La integración tiene dos direcciones y solo estaba escrita una:
 *
 *   nosotros → ellos    generar la liga, la CLABE o la referencia
 *   ellos → nosotros    "este cliente está pagando, ¿lo autorizo?"
 *                       "ya pagó, aquí está el folio"
 *                       "hubo un problema, cancélalo"
 *
 * Sin esta mitad, de los cuatro métodos de cobro solo la tarjeta podía
 * llegar a confirmarse —porque esa sí tiene servicio de consulta—. SPEI
 * y efectivo en tienda se quedaban girando en "esperando el depósito"
 * para siempre, aunque el cliente ya hubiera pagado.
 *
 * El código viejo lo tenía al revés: consultaba `/Service/ConsultaReferencia`
 * como si fuera del proveedor. Esa dirección es ESTA, la nuestra.
 *
 * LAS SEIS PUERTAS
 *
 *   GET  consulta-referencia?r=   ¿esta referencia es válida y cuánto debe?
 *   POST pago-referencia          pagaron en la tienda, autorízalo
 *   GET  consulta-clabe?r=        ¿esta CLABE es válida y cuánto debe?
 *   POST pago-clabe               llegó el SPEI, autorízalo
 *   POST cancela-pago             deshaz el último pago
 *   POST pago-liga                aviso de la liga de tarjeta
 *
 * CÓMO SE PROTEGEN
 *
 * El proveedor no manda credenciales: pega a la URL que le diste de
 * alta. Por eso la URL lleva un secreto dentro, que vive en
 * config/integraciones.php y no en el repositorio. Sin él, cualquiera
 * que adivine una referencia podría marcar ventas como pagadas
 * escribiendo una dirección en el navegador.
 *
 * REGLAS QUE NO SE NEGOCIAN
 *
 *   1. Responder SIEMPRE HTTP 200 con el JSON que la documentación pide.
 *      Un 500 hace que el proveedor dé el pago por fallido y lo cancele
 *      con el cliente ya pagado y el ticket en la mano.
 *   2. Idempotencia. El proveedor reintenta. El mismo número de
 *      transacción no puede abonar dos veces.
 *   3. Los montos viajan en CENTAVOS, como entero.
 */
final class PagosEntrantesControlador
{
    /** Los códigos de la documentación. Son los que el proveedor entiende. */
    const OK            = 0;    // operación exitosa
    const SIN_ADEUDO    = 13;   // ya está pagada
    const SIN_VIGENCIA  = 14;   // se le pasó la fecha
    const MAL_FORMATO   = 15;   // la referencia no tiene forma de referencia
    const MONTO_INVALIDO= 30;   // el importe no corresponde
    const NO_RECONOCIDA = 40;   // no sabemos qué es esto
    const ERROR_SISTEMA = 50;   // reventó algo de nuestro lado
    const FUERA_PERIODO = 60;   // la cancelación llegó tarde

    // ════════════════════════════════════════════════════════════
    //  CONSULTA · ¿es válida y cuánto debe?
    // ════════════════════════════════════════════════════════════

    /** Pago en tienda: el cajero del OXXO escaneó el código. */
    public function consultaReferencia() { $this->consultar('referencia'); }

    /** SPEI: el banco va a depositar a la CLABE. */
    public function consultaClabe() { $this->consultar('clabe'); }

    private function consultar($campo)
    {
        $this->abrirPuerta();

        $valor = trim((string)($_GET['r'] ?? $_GET['referencia'] ?? $_GET['clabe'] ?? ''));
        $limpio = preg_replace('/\D/', '', $valor);
        if ($limpio === '' || strlen($limpio) > 30) {
            $this->consultaJson(self::MAL_FORMATO,
                'Referencia con error de formato', 0, $valor, '', $campo);
        }

        $hallado = $this->ubicar($limpio);
        if (!$hallado) {
            $this->consultaJson(self::NO_RECONOCIDA,
                'Adquiriente inválido', 0, $limpio, '', $campo);
        }
        list($db, $l) = $hallado;

        if ($l['estado'] === 'pagada') {
            $this->consultaJson(self::SIN_ADEUDO,
                'Referencia sin adeudo', 0, $limpio, '', $campo);
        }
        if (!empty($l['vence']) && strtotime($l['vence']) < strtotime('today')) {
            $this->consultaJson(self::SIN_VIGENCIA,
                'Referencia fuera de vigencia', 0, $limpio, '', $campo);
        }

        // LO QUE SE COBRA ES EL SALDO DE LA VENTA, no el monto que se
        // guardó al generar el cobro. Entre una cosa y otra el cliente
        // pudo haber abonado en el mostrador, y cobrarle otra vez el
        // total es la forma más rápida de tener que devolver dinero.
        $saldo = $this->saldoDe($db, $l);
        if ($saldo <= 0.009) {
            $this->consultaJson(self::SIN_ADEUDO,
                'Referencia sin adeudo', 0, $limpio, '', $campo);
        }

        $this->consultaJson(self::OK, 'Operación exitosa',
            (int)round($saldo * 100), $limpio, (string)$this->siguienteTransaccion($l), $campo);
    }

    /**
     * La respuesta de consulta, con los seis campos que pide la doc.
     *
     * `parcial` va siempre en true: el emisor acepta abonos. Mandarlo en
     * false obliga al cliente a pagar el total exacto o nada.
     */
    private function consultaJson($codigo, $mensaje, $centavos, $valor, $transaccion, $campo)
    {
        $this->json([
            'codigo'      => (int)$codigo,
            'mensaje'     => (string)$mensaje,
            'monto'       => (string)(int)$centavos,
            $campo        => (string)$valor,
            'transaccion' => (string)$transaccion,
            'parcial'     => true,
        ]);
    }

    // ════════════════════════════════════════════════════════════
    //  PAGO · ya cobraron, autorízalo
    // ════════════════════════════════════════════════════════════

    public function pagoReferencia() { $this->autorizar('referencia'); }
    public function pagoClabe()      { $this->autorizar('clabe'); }

    private function autorizar($campo)
    {
        $this->abrirPuerta();
        $d = $this->cuerpo();

        $valor = trim((string)($d['referencia'] ?? $d['clabe'] ?? ''));
        $limpio = preg_replace('/\D/', '', $valor);
        $transaccion = trim((string)($d['transaccion'] ?? ''));
        // Llega en centavos. Convertirlo mal aquí abona cien veces de más.
        $monto = round(((float)($d['monto'] ?? 0)) / 100, 2);

        if ($limpio === '') {
            $this->pagoJson(self::MAL_FORMATO, '', 'Referencia con error de formato', $transaccion);
        }

        $hallado = $this->ubicar($limpio);
        if (!$hallado) {
            $this->pagoJson(self::NO_RECONOCIDA, '', 'Adquiriente inválido', $transaccion);
        }
        list($db, $l) = $hallado;
        $repo = new LigaRepo($db);

        // IDEMPOTENCIA. El proveedor reintenta cuando no le contestamos a
        // tiempo, y si no se revisara esto la venta quedaría abonada dos
        // veces con un solo pago del cliente.
        if ($ya = $repo->yaEntro($l['referencia'], $transaccion)) {
            $this->pagoJson(self::OK, (string)$ya['autorizacion'],
                'Operación exitosa', $transaccion,
                $ya['pagado_en'] ? date('Y-m-d', strtotime($ya['pagado_en'])) : '');
        }
        if ($l['estado'] === 'pagada') {
            $this->pagoJson(self::SIN_ADEUDO, '', 'Referencia sin adeudo', $transaccion);
        }
        if (!empty($l['vence']) && strtotime($l['vence']) < strtotime('today')) {
            $this->pagoJson(self::SIN_VIGENCIA, '', 'Referencia fuera de vigencia', $transaccion);
        }

        $saldo = $this->saldoDe($db, $l);
        if ($saldo <= 0.009) {
            $this->pagoJson(self::SIN_ADEUDO, '', 'Referencia sin adeudo', $transaccion);
        }
        // SPEI deposita lo que el cliente quiera. Si mandó de más, se
        // rechaza: aceptarlo dejaría la venta sobrepagada y RegistrarPago
        // tiraría de todos modos.
        if ($monto > $saldo + 0.009) {
            $this->pagoJson(self::MONTO_INVALIDO, '', 'Monto inválido', $transaccion);
        }
        if ($monto <= 0) {
            $this->pagoJson(self::MONTO_INVALIDO, '', 'Monto inválido', $transaccion);
        }

        $autorizacion = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);

        try {
            $repo->anotarAviso($l['id'], $transaccion, $autorizacion, $monto);

            // Si el negocio pidió revisar los pagos, aquí se detiene. Para
            // el proveedor el cobro SÍ quedó autorizado —si no, le
            // devolvería el dinero al cliente—; lo que espera es el
            // abono, que entra cuando alguien lo apruebe.
            if ((new ConfigRepo($db))->exigeAprobacion()) {
                $repo->marcarRevisada($l['id'], 'por_aprobar');
                $this->pagoJson(self::OK, $autorizacion, 'Operación exitosa', $transaccion);
            }

            $pagoId = null;
            if (!empty($l['venta_id'])) {
                $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                    'monto'      => $monto,
                    'metodo'     => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                    'referencia' => $this->rotulo($l) . ' ' . $l['referencia'],
                    'fecha'      => date('Y-m-d'),
                    'usuario_id' => null,   // lo registró el proveedor, no una persona
                ]);
                $pagoId = $res['pago_id'] ?? null;
            }
            $repo->marcarPagada($l['id'], $pagoId);

            Auditoria::anota('pago.registrar',
                $this->rotulo($l) . ' ' . $l['referencia'], 'esperando',
                Dinero::pesos($monto) . ' · autorización ' . $autorizacion, $db);

            $this->pagoJson(self::OK, $autorizacion, 'Operación exitosa', $transaccion);
        } catch (\InvalidArgumentException $e) {
            // El saldo ya no da. No es una falla del sistema: es que esa
            // venta cambió. Se contesta lo que la doc pide para el caso.
            error_log('[LibertyFin] pago entrante ' . $limpio . ': ' . $e->getMessage());
            $this->pagoJson(self::SIN_ADEUDO, '', $e->getMessage(), $transaccion);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] pago entrante ' . $limpio . ': ' . $e->getMessage());
            $this->pagoJson(self::ERROR_SISTEMA, '', 'Error de sistema', $transaccion);
        }
    }

    /**
     * La respuesta de autorización, con los ocho campos de la doc.
     *
     * Los tres de SMS y ticket se mandan vacíos a propósito: el proveedor
     * los trae "en desarrollo" y poner texto ahí no imprime nada.
     */
    private function pagoJson($codigo, $autorizacion, $mensaje, $transaccion, $fecha = '')
    {
        $this->json([
            'codigo'           => (int)$codigo,
            'autorizacion'     => (string)$autorizacion,
            'mensaje'          => (string)$mensaje,
            'transaccion'      => (string)$transaccion,
            'fecha'            => $fecha !== '' ? $fecha : date('Y-m-d'),
            'notificacion_sms' => '',
            'mensaje_sms'      => '',
            'mensaje_ticket'   => '',
        ]);
    }

    // ════════════════════════════════════════════════════════════
    //  CANCELACIÓN · deshaz el último pago
    // ════════════════════════════════════════════════════════════

    /**
     * El proveedor no pudo cerrar la operación y pide deshacerla.
     *
     * Pasa cuando el punto de venta falla al registrar su transacción
     * después de que ya autorizamos. Solo se puede el MISMO día, y solo
     * deshace UN pago: el cliente puede volver a intentar.
     */
    public function cancelaPago()
    {
        $this->abrirPuerta();
        $d = $this->cuerpo();

        $valor  = preg_replace('/\D/', '',
            (string)($d['referencia'] ?? $d['clabe'] ?? ''));
        $transaccion = trim((string)($d['transaccion'] ?? ''));

        $hallado = $this->ubicar($valor);
        if (!$hallado) $this->json(['codigo' => self::NO_RECONOCIDA,
                                    'mensaje' => 'Operación no encontrada']);
        list($db, $l) = $hallado;

        // YA CANCELADO TAMBIÉN RESPONDE 0. Lo pide la documentación, y
        // tiene sentido: el proveedor reintenta y lo que quiere saber es
        // si el pago está deshecho, no si fue él quien lo deshizo.
        if ($l['estado'] !== 'pagada') {
            $this->json(['codigo' => self::OK, 'mensaje' => 'Cancelación exitosa']);
        }
        if (!empty($l['pagado_en'])
            && date('Y-m-d', strtotime($l['pagado_en'])) !== date('Y-m-d')) {
            $this->json(['codigo' => self::FUERA_PERIODO,
                         'mensaje' => 'Cancelación fuera de periodo']);
        }
        if ($transaccion !== '' && (string)$l['transaccion'] !== $transaccion) {
            $this->json(['codigo' => self::NO_RECONOCIDA,
                         'mensaje' => 'Operación no encontrada']);
        }

        try {
            $repo = new LigaRepo($db);
            // El abono se cancela de forma lógica, no se borra: el rastro
            // de que entró y se deshizo es justo lo que hace falta
            // cuando alguien pregunte por qué el corte no cuadra.
            if (!empty($l['pago_id'])) {
                (new RegistrarPago($db))->cancelar((int)$l['pago_id'],
                    'Cancelado por el proveedor de pago', null);
            }
            $repo->marcarCancelada($l['id']);
            Auditoria::anota('pago.cancelar',
                $this->rotulo($l) . ' ' . $l['referencia'],
                Dinero::pesos($l['pagado_monto'] ?? $l['monto']),
                'cancelado por el proveedor', $db);
            $this->json(['codigo' => self::OK, 'mensaje' => 'Cancelación exitosa']);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cancelar entrante: ' . $e->getMessage());
            $this->json(['codigo' => self::ERROR_SISTEMA, 'mensaje' => 'Error de sistema']);
        }
    }

    // ════════════════════════════════════════════════════════════
    //  LIGA DE TARJETA · el aviso del cobro
    // ════════════════════════════════════════════════════════════

    /**
     * El aviso de la liga de tarjeta.
     *
     * Trae otra forma: no pregunta nada, informa. El cuerpo viene con
     * `reference`, `response` (approved/denied/error) y los datos de la
     * tarjeta, y espera {"code":"00"} de vuelta.
     *
     * No es indispensable —la tarjeta sí se puede consultar— pero sin
     * esto el abono tarda hasta que alguien abre la pantalla, y el
     * cliente ya se fue creyendo que pagó.
     */
    public function pagoLiga()
    {
        $this->abrirPuerta();
        $d = $this->cuerpo();

        $ref = preg_replace('/\D/', '', (string)($d['reference'] ?? ''));
        $respuesta = strtolower(trim((string)($d['response'] ?? '')));
        if ($ref === '') $this->json(['code' => '21', 'message' => 'Referencia obligatoria.']);

        $hallado = $this->ubicar($ref);
        if (!$hallado) $this->json(['code' => '99', 'message' => 'Referencia no encontrada.']);
        list($db, $l) = $hallado;

        // Solo `approved` mueve dinero. `denied` y `error` se anotan para
        // que en la pantalla se vea que el intento existió y falló, en
        // vez de dejar el cobro como si nadie lo hubiera tocado.
        if ($respuesta !== 'approved') {
            (new LigaRepo($db))->marcarRevisada($l['id']);
            $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
        }
        if ($l['estado'] === 'pagada') {
            $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
        }

        // Aquí el monto viene en PESOS y con comas ("1,156.00"), no en
        // centavos como en los otros avisos. Es del proveedor, no un
        // descuido nuestro.
        $monto = (float)str_replace([',', '$', ' '], '', (string)($d['amount'] ?? 0));
        if ($monto <= 0) $monto = (float)$l['monto'];
        $saldo = $this->saldoDe($db, $l);
        if ($saldo > 0 && $monto > $saldo) $monto = $saldo;

        try {
            $repo = new LigaRepo($db);
            $repo->anotarAviso($l['id'], (string)($d['foliocpagos'] ?? ''),
                (string)($d['auth'] ?? ''), $monto);

            if ((new ConfigRepo($db))->exigeAprobacion()) {
                $repo->marcarRevisada($l['id'], 'por_aprobar');
                $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
            }

            $pagoId = null;
            if (!empty($l['venta_id']) && $monto > 0) {
                $res = (new RegistrarPago($db))->abonar((int)$l['venta_id'], [
                    'monto'      => $monto,
                    'metodo'     => 'tarjeta',
                    'referencia' => 'Liga ' . $l['referencia'],
                    'fecha'      => date('Y-m-d'),
                    'usuario_id' => null,
                ]);
                $pagoId = $res['pago_id'] ?? null;
            }
            $repo->marcarPagada($l['id'], $pagoId);
            Auditoria::anota('pago.registrar', 'liga cobrada · ' . $l['referencia'],
                'esperando', Dinero::pesos($monto), $db);
        } catch (\Throwable $e) {
            // Se contesta 00 de todos modos: el cobro con el banco ya se
            // hizo y pedirle al proveedor que reintente no arregla un
            // problema que es nuestro. Queda en el log para revisarlo.
            error_log('[LibertyFin] aviso de liga ' . $ref . ': ' . $e->getMessage());
        }
        $this->json(['code' => '00', 'message' => 'Recibido correctamente.']);
    }

    // ════════════════════════════════════════════════════════════
    //  LO COMPARTIDO
    // ════════════════════════════════════════════════════════════

    /**
     * El secreto de la URL.
     *
     * Es lo único que separa estos endpoints de cualquiera con un
     * navegador. Se compara con hash_equals para que no se pueda adivinar
     * midiendo cuánto tarda en fallar.
     */
    private function abrirPuerta()
    {
        $cfg = Integraciones::de('spei') ?: [];
        $esperado = trim((string)($cfg['secreto_webhook'] ?? ''));

        if ($esperado === '') {
            // Sin secreto configurado NO se abre. Dejarlo pasar sería
            // publicar un botón de "marcar como pagada" en internet.
            error_log('[LibertyFin] webhook sin `secreto_webhook` en config/integraciones.php');
            http_response_code(503);
            $this->json(['codigo' => self::ERROR_SISTEMA,
                         'mensaje' => 'Servicio no configurado']);
        }

        $dado = (string)($_GET['k'] ?? $_SERVER['HTTP_X_LF_SECRETO'] ?? '');
        if (!hash_equals($esperado, $dado)) {
            http_response_code(403);
            $this->json(['codigo' => self::NO_RECONOCIDA, 'mensaje' => 'No autorizado']);
        }
    }

    /**
     * En qué base vive este cobro.
     *
     * Primero el directorio de la base principal, que es una búsqueda
     * directa. Si no está —cobros de antes de que existiera— se recorren
     * las bases activas. Eso es caro, por eso es el último recurso.
     *
     * @return array|null  [PDO, fila del cobro]
     */
private function ubicar($referencia)
{
    $referencia = preg_replace('/\D/', '', (string)$referencia);
    if ($referencia === '') return null;

    $principal = null;
    try {
        $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
    } catch (\Throwable $e) {
        error_log('[LibertyFin] sin base principal para ubicar cobros');
    }

    if ($principal) {
        // 1) Ruta conocida → empresa_db (camino rápido)
        $ruta = (new RutaLigaRepo($principal))->buscar($referencia);
        if ($ruta && !empty($ruta['empresa_db'])) {
            try {
                $db = Conexion::de($ruta['empresa_db']);
                $l  = (new LigaRepo($db))->porCualquiera($referencia);
                if ($l) return [$db, $l];
            } catch (\Throwable $e) {
                error_log('[LibertyFin] abrir ' . $ruta['empresa_db'] . ': ' . $e->getMessage());
            }
        }

        // 2) NUEVO: la liga puede vivir en la PROPIA base principal
        //    (empresas sin base dedicada, cobros antiguos, mono-inquilino).
        try {
            $l = (new LigaRepo($principal))->porCualquiera($referencia);
            if ($l) return [$principal, $l];
        } catch (\Throwable $e) {
            error_log('[LibertyFin] liga en principal ' . $referencia . ': ' . $e->getMessage());
        }
    }

    if (!$principal) return null;

    // 3) Último recurso: recorrer bases de empresas (caro, pero exhaustivo)
    foreach ((new RutaLigaRepo($principal))->basesDeEmpresas() as $base) {
        try {
            $db = Conexion::de($base);
            $l  = (new LigaRepo($db))->porCualquiera($referencia);
            if ($l) {
                (new RutaLigaRepo($principal))->apuntar(
                    $l['referencia'], $base, null, $l['metodo']);
                return [$db, $l];
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    return null;
}

    /**
     * Lo que de verdad se debe.
     *
     * Sale de la VENTA, no del monto con el que se generó el cobro: en
     * el rato que el cliente tardó en ir a la tienda pudo haber abonado
     * en el mostrador, y cobrarle el total otra vez obliga a devolverle.
     */
    private function saldoDe(\PDO $db, array $l)
    {
        if (empty($l['venta_id'])) return Dinero::centavos($l['monto']);
        try {
            $v = (new \LibertyFin\Datos\VentaRepo($db))->detalle((int)$l['venta_id']);
            if (!$v) return Dinero::centavos($l['monto']);
            if (($v['estado'] ?? '') === 'cancelada') return 0.0;
            return Dinero::centavos($v['saldo']);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] saldo de venta ' . $l['venta_id'] . ': ' . $e->getMessage());
            return Dinero::centavos($l['monto']);
        }
    }

    /** Un consecutivo para la transacción que el proveedor pide en la consulta. */
    private function siguienteTransaccion(array $l)
    {
        return (int)$l['id'] * 100 + (int)date('s');
    }

    private function rotulo(array $l)
    {
        return $l['metodo'] === 'spei' ? 'SPEI'
             : ($l['metodo'] === 'efectivo' ? 'Pago en tienda' : 'Liga');
    }

    /**
     * El cuerpo de la petición.
     *
     * Acepta JSON y formulario: la documentación dice JSON, pero el
     * Sandbox manda formulario en algunas pruebas y rechazarlo deja al
     * emulador sin poder probar nada.
     */
    private function cuerpo()
    {
        $crudo = file_get_contents('php://input');
        $d = json_decode((string)$crudo, true);
        if (!is_array($d)) $d = $_POST;
        // Todo lo que entra queda anotado. Cuando un pago no cuadra, esto
        // es lo único que dice qué llegó de verdad.
        error_log('[LibertyFin] aviso de pago: ' . mb_substr((string)$crudo, 0, 600));
        return is_array($d) ? $d : [];
    }

    private function json(array $d)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
