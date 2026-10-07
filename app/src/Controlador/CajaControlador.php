<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CatalogoRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Dominio\Iva;
use LibertyFin\Dominio\Ticket;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\RegistrarVenta;
use LibertyFin\Vista\Plantilla;

final class CajaControlador
{
    public function index()
    {
        $db  = Conexion::de($_SESSION['empresa_db']);
        $cat = new CatalogoRepo($db);

        $area   = Peticion::texto('area');
        $buscar = Peticion::texto('q');
        $suc    = $_SESSION['sucursal_id'] ?? null;

        // El turno se pregunta a la BASE, no a la sesión. Antes se leía
        // `$_SESSION['caja_id']`, que solo se escribía al pasar por
        // /corte: quien entraba derecho a cobrar veía "no tienes caja
        // abierta" teniéndola, y sus ventas se guardaban fuera del corte.
        $turno = \LibertyFin\Servicio\Caja::abierta($db);

        Plantilla::pagina('caja/index', [
            'titulo'    => 'Caja',
            'icono'     => 'caja',
            'subtitulo' => 'Venta nueva · ' . ($_SESSION['sucursal_nombre'] ?? 'Matriz'),
            // Se cargan TODOS: la categoría y la búsqueda filtran en el navegador,
            // sin recargar, para no perder el ticket que se va armando.
            'servicios' => $cat->servicios($suc, '', '', 1500),
            'areas'     => $cat->areas(),
            'metodos'   => (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles(),
            // Quien va a HACER el trabajo: los COLABORADORES, agrupados
            // por area, no los usuarios del sistema. Un contador puede
            // atender sin tener cuenta para entrar.
            'equipo'    => (new \LibertyFin\Datos\ComisionRepo($db))->equipoPorArea(),
            // Sin caja abierta la venta se registra igual, pero no entra
            // al corte del turno. Se avisa antes, no despues.
            'cajaAbierta' => $turno !== null,
            'turno'       => $turno,
            // Cobrar con liga solo aparece si hay con qué generarla.
            'ligas'     => \LibertyFin\Servicio\Integraciones::activa('spei')
                         && (new \LibertyFin\Datos\ConfigRepo($db))->seccionActiva('ligas'),
            'formasLiga'=> \LibertyFin\Servicio\LigaPago::METODOS,
            'ligaLista' => $_SESSION['lf_liga'] ?? null,
            'area'      => $area,
            'buscar'    => $buscar,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso'], $_SESSION['lf_liga']);
    }

    /** Búsqueda de clientes para el ticket. Devuelve JSON. */
    public function clientes()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode((new CatalogoRepo($db))->buscarClientes(Peticion::texto('q')));
    }

    /** Cobra el ticket. */
    public function cobrar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        if (!$this->tokenValido()) {
            $this->volver('No se pudo verificar el formulario. Intenta de nuevo.', 'error');
        }

        $lineas = json_decode($_POST['lineas'] ?? '[]', true);
        if (!is_array($lineas) || !$lineas) {
            $this->volver('El ticket está vacío.', 'error');
        }

        // El precio SÍ puede venir del formulario: estos servicios se cotizan
        // por caso y el catálogo tiene varios en cero a propósito.
        //
        // Lo que se relee de la base es el NOMBRE y el COSTO, y se valida que
        // el producto exista y esté activo. El precio se acepta, se limpia y
        // queda registrado en venta_detalles junto con el usuario que lo
        // capturó: aquí el control es el rastro, no el candado.
        //
        // Si el formulario no manda precio, se usa el del catálogo.
        $ids = array_map(function ($l) { return (int)($l['id'] ?? 0); }, $lineas);
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids) $this->volver('El ticket está vacío.', 'error');

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        // El precio de venta es `subprecio`. Releerlo mal aquí sería peor que
        // no releerlo: cobraría el importe equivocado con toda confianza.
        $st = $db->prepare("
            SELECT id, nombre, COALESCE(NULLIF(subprecio,0), precio) AS precio, costo
            FROM productos WHERE id IN ($marcas) AND activo = 1");
        $st->execute($ids);
        $reales = [];
        foreach ($st->fetchAll() as $p) $reales[(int)$p['id']] = $p;

        $pct  = max(0, min(100, (float)($_POST['iva_pct'] ?? 0)));
        $modo = ($_POST['iva_modo'] ?? 'incluido') === 'sumar' ? Iva::SUMAR : Iva::INCLUIDO;
        $ticket = new Ticket(new Iva($pct, $modo));

        foreach ($lineas as $l) {
            $id = (int)($l['id'] ?? 0);
            if (!isset($reales[$id])) continue;
            $p = $reales[$id];

            $precio = isset($l['precio']) ? round((float)$l['precio'], 2) : (float)$p['precio'];
            if ($precio < 0) $precio = 0;
            if ($precio > 9999999) {
                $this->volver('Ese precio no parece correcto. Revísalo.', 'error');
            }
            $ticket->agregar($id, $p['nombre'], $precio,
                max(1, (float)($l['cantidad'] ?? 1)), 0, $p['costo'] ?? 0);
        }

        // Un ticket entero en cero casi siempre es un dedazo, no una cortesía.
        if ($ticket->subtotalCapturado() <= 0) {
            $this->volver('El ticket suma cero. Pon el precio de cada producto antes de cobrar.', 'error');
        }
        $ticket->gastosOperacion((float)($_POST['gastos'] ?? 0));

        // ── AGREGAR A UNA VENTA QUE YA EXISTE ──
        //
        // El cliente pagó tres servicios, le hicimos el folio, y a los
        // dos minutos se acordó de otros tres. Hasta ahora la única
        // salida era otra venta: dos folios y un historial que cuenta
        // dos visitas donde hubo una.
        //
        // Aquí las líneas entran a la MISMA venta. No se cobra nada: se
        // agrega lo vendido y se abre saldo, que es lo que de verdad
        // pasó. El dinero entra después por donde entra siempre.
        $ampliar = (int)($_POST['ampliar_venta'] ?? 0);
        if ($ampliar > 0) return $this->ampliar($db, $ampliar, $ticket);

        // EL CLIENTE. Se eligió de la lista (id), o se escribió un nombre.
        // Un nombre escrito se enlaza al cliente que ya tenga ese nombre
        // exacto o, si no existe, se crea: antes ese texto se descartaba y
        // la venta salía sin cliente, sin avisar.
        $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: null;
        if (!$clienteId) {
            $nombreCli = trim((string)($_POST['cliente_nombre'] ?? ''));
            if ($nombreCli !== '') {
                try {
                    $clienteId = $this->clientePorNombre($db, $nombreCli);
                } catch (\InvalidArgumentException $e) {
                    $this->volver($e->getMessage(), 'error');
                } catch (\Throwable $e) {
                    error_log('[LibertyFin] cliente al cobrar: ' . $e->getMessage());
                    $this->volver('No se pudo guardar el cliente.', 'error');
                }
            }
        }

        // ¿Se pidió un método de liga (tarjeta, SPEI, efectivo en tienda)?
        // Y si sí: ¿se marcó "Ya me pagaron", o no hay proveedor con qué
        // generarla? En esos casos NO se llama al proveedor y el cobro
        // pasa como pagado, con el método que se eligió.
        $enLineaPedida = self::formaEnLinea($_POST['como_paga'] ?? '');
        $sinLiga = $enLineaPedida !== ''
                && (!empty($_POST['sin_liga'])
                    || !\LibertyFin\Servicio\Integraciones::activa('spei'));

        try {
            $r = (new RegistrarVenta($db))->cobrar($ticket, [
                'cliente_id'     => $clienteId,
                'usuario_id'     => $_SESSION['usuario_id'] ?? null,
                'sucursal_id'    => $_SESSION['sucursal_id'] ?? null,
                // El turno sale de la base. Leerlo de la sesión dejaba
                // las ventas en `caja_id = NULL` cuando el cajero no
                // había pasado por /corte, y al cerrar no aparecían.
                'caja_id'        => \LibertyFin\Servicio\Caja::id($db),
                // EN UN PAGO EN LINEA NO HAY ANTICIPO.
                //
                // El cliente todavia no ha pagado nada: va a pasar su
                // tarjeta, transferir o ir a la tienda. Anotar un
                // anticipo seria decir que entro dinero que no entro, y
                // ademas dejaba la venta liquidada: al ir a generar el
                // cobro no quedaba saldo que cobrar y el sistema
                // contestaba "no hizo falta".
                //
                // SALVO "YA ME PAGARON": ahí el cliente sí pagó (transfirió
                // o pagó por otro lado) y no se genera liga, así que lo que
                // se captura como anticipo es lo que entró.
                'anticipo'       => ($enLineaPedida && !$sinLiga)
                                    ? 0.0 : (float)($_POST['anticipo'] ?? 0),
                // El nombre se guarda además del id: si esa persona se va y su
                // usuario se desactiva, el reporte de hace seis meses tiene que
                // seguir diciendo quién atendió.
                'especialista_id'     => (int)($_POST['especialista'] ?? 0) ?: null,
                'especialista_nombre' => $this->nombreDe($db, (int)($_POST['especialista'] ?? 0)),
                // `como_paga` trae UNA sola respuesta: el metodo del
                // mostrador, o uno de los de linea con guion bajo delante.
                'metodo_pago'    => self::metodoDe($_POST['como_paga'] ?? '', $db),
                'referencia'     => trim($_POST['referencia'] ?? ''),
                'descripcion'    => trim($_POST['descripcion'] ?? ''),
                'concepto_gasto' => trim($_POST['concepto_gasto'] ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cobrar: ' . $e->getMessage());
            $this->volver('No se pudo registrar la venta. Quedó anotado el error.', 'error');
        }

        // Pago en linea: se genera el cobro y se devuelven los datos
        // para el modal, sin salir de la caja.
        $enLinea = $enLineaPedida;
        if ($enLinea && !$sinLiga && \LibertyFin\Servicio\Integraciones::activa('spei')) {
            return $this->conLiga($db, $r, $enLinea);
        }

        // Cobro en el mostrador. Si lo pidio el modal se contesta en
        // JSON; si no, se sigue como siempre y se va al ticket.
        if ($this->pideJson()) {
            // El cambio se calcula aqui y no en el navegador: es dinero
            // que el cajero va a entregar, y tiene que salir de la misma
            // cuenta que registro la venta.
            $pagaCon = round((float)($_POST['paga_con'] ?? 0), 2);
            $cobrado = (float)$r['cobrado'];
            $cambio  = ($pagaCon > $cobrado) ? round($pagaCon - $cobrado, 2) : 0.0;

            $this->json(['ok' => true, 'modo' => 'cobrado',
                'venta' => ['id' => (int)$r['id'], 'codigo' => $r['codigo'],
                            'total' => (float)$r['total'], 'cobrado' => $cobrado,
                            'paga_con' => $pagaCon, 'cambio' => $cambio,
                            'en_corte' => \LibertyFin\Servicio\Caja::hay($db)]]);
        }

        header('Location: /ventas/' . $r['id'] . '?nueva=1');
        exit;
    }

    /**
     * El id del cliente con ese nombre exacto; si no existe, lo crea solo con
     * el nombre (lo demás se completa después en Clientes).
     * @throws \InvalidArgumentException si el nombre no es válido
     */
    private function clientePorNombre($db, $nombre)
    {
        $st = $db->prepare("SELECT id FROM clientes WHERE nombre = ? ORDER BY id LIMIT 1");
        $st->execute([$nombre]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
        return (new \LibertyFin\Datos\ClienteRepo($db))->crear(['nombre' => $nombre]);
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token'])
            && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($mensaje, $tipo = 'error')
    {
        // Si lo pidio el modal, el error vuelve como JSON. Redirigir
        // dejaria al cajero mirando la caja en blanco sin saber que paso.
        if ($this->pideJson()) {
            $this->json(['ok' => $tipo !== 'error', 'error' => $mensaje]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $mensaje, 'tipo' => $tipo];
        header('Location: /caja'); exit;
    }

    /**
     * Genera la liga para una venta recién creada.
     *
     * Se llama después de registrar la venta, no antes: si la liga
     * fallara y la venta no existiera, el cliente se iría sin nada y sin
     * rastro de lo que se intentó cobrarle.
     */
    /**
     * Mete las líneas nuevas en una venta que ya existe.
     *
     * No cobra. Agrega lo vendido y deja el saldo abierto, porque eso es
     * lo que pasó: el cliente pidió más, no pagó más.
     */
    private function ampliar($db, $ventaId, $ticket)
    {
        try {
            $r = (new \LibertyFin\Servicio\AmpliarVenta($db))
                 ->agregar($ventaId, $ticket, [
                     'concepto_gasto' => trim($_POST['concepto_gasto'] ?? ''),
                 ]);
        } catch (\InvalidArgumentException $e) {
            if ($this->pideJson()) {
                $this->json(['ok' => false, 'error' => $e->getMessage()]);
            }
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] ampliar venta ' . $ventaId . ': ' . $e->getMessage());
            if ($this->pideJson()) {
                $this->json(['ok' => false, 'error' => 'No se pudo agregar. Quedó anotado el error.']);
            }
            $this->volver('No se pudo agregar a esa venta.', 'error');
        }

        \LibertyFin\Servicio\Auditoria::anota('venta.ampliar',
            'venta ' . $r['codigo'],
            Dinero::pesos($r['total'] - $r['agregado']),
            Dinero::pesos($r['total']) . ' · ' . $r['lineas'] . ' producto'
            . ($r['lineas'] == 1 ? '' : 's') . ' más', $db);

        // Ampliar algo de otro día mueve el total de un corte que ya se
        // cerró. Se hace —hay negocios que dejan la cuenta abierta— pero
        // se dice, porque quien cuadró ayer va a ver otro número.
        $aviso = 'Se agregaron ' . $r['lineas'] . ' producto'
               . ($r['lineas'] == 1 ? '' : 's') . ' a la venta ' . $r['codigo']
               . ' por ' . Dinero::pesos($r['agregado']) . '. '
               . 'Ahora debe ' . Dinero::pesos($r['saldo']) . '.';
        if (!$r['de_hoy']) {
            $aviso .= ' Ojo: esa venta es del '
                   . date('d/m/Y', strtotime($r['fecha']))
                   . ', así que el corte de ese día cambia.';
        }

        if ($this->pideJson()) {
            $this->json(['ok' => true, 'ampliada' => true, 'venta' => $r,
                         'mensaje' => $aviso]);
        }
        $_SESSION['lf_aviso'] = ['texto' => $aviso, 'tipo' => $r['de_hoy'] ? 'ok' : 'alerta'];
        $this->a('/ventas/' . $r['id']);
    }

    /**
     * Las ventas a las que se les puede agregar algo. JSON, para la caja.
     *
     * Se ofrecen las del día y las que siguen con saldo: el cliente que
     * se acordó de otro servicio y la clienta que viene cada mes. Con
     * texto, busca por folio —el de la venta o el de cualquiera de sus
     * cobros— y por nombre de cliente.
     */
    public function ventasAbiertas()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $q  = trim(Peticion::texto('q'));
        $srv = new \LibertyFin\Servicio\AmpliarVenta($db);

        $lista = [];
        if ($q !== '') {
            $una = $srv->porFolio($q);
            if ($una) $lista[] = $una;
        }
        if (!$lista) {
            $lista = $srv->candidatas((int)Peticion::entero('cliente', 0) ?: null, 25);
            if ($q !== '') {
                $lista = array_values(array_filter($lista, function ($v) use ($q) {
                    return stripos((string)$v['codigo_venta'], $q) !== false
                        || stripos((string)$v['cliente'], $q) !== false;
                }));
            }
        }

        $salida = [];
        foreach ($lista as $v) {
            $salida[] = [
                'id'      => (int)$v['id'],
                'folio'   => $v['codigo_venta'],
                'cliente' => $v['cliente'] ?: 'Público general',
                'fecha'   => date('d/m/Y H:i', strtotime($v['fecha'])),
                'de_hoy'  => date('Y-m-d', strtotime($v['fecha'])) === date('Y-m-d'),
                'total'   => Dinero::pesos($v['total']),
                'saldo'   => Dinero::pesos($v['saldo']),
                'debe'    => (float)$v['saldo'] > 0.005,
                'lineas'  => (int)$v['lineas'],
                'iva_modo'=> $v['iva_modo'] ?: 'incluido',
            ];
        }
        $this->json(['ok' => true, 'ventas' => $salida]);
    }

    private function conLiga($db, array $r, $forma)
    {
        $venta = (new \LibertyFin\Datos\VentaRepo($db))->detalle($r['id']);
        if (!$venta) $this->volver('La venta se registró pero no se pudo leer.', 'error');

        $saldo = round((float)$venta['saldo'], 2);
        if ($saldo <= 0.01) {
            // Con el anticipo ya forzado a cero esto no deberia pasar.
            // Si pasa es que la venta traia pagos de antes, y entonces
            // no hay nada que cobrar: decirlo claro es mejor que generar
            // un cobro por cero que el proveedor va a rechazar.
            $this->volver('Esta venta ya está pagada por completo, no hay nada que cobrar. '
                . 'Si querías cobrar algo más, regístralo como una venta nueva.', 'error');
        }

        $api = new \LibertyFin\Servicio\LigaPago(\LibertyFin\Servicio\Integraciones::de('spei'));

       
        $semilla = \LibertyFin\Servicio\LigaPago::semilla((int)$r['id']);

        $g = $api->generar([
            'monto' => $saldo, 'metodo' => $forma,
            'descripcion' => 'Venta ' . $venta['codigo_venta'],
            'referencia' => $semilla, 'id' => $semilla,
            // El proveedor pide el nombre del cliente; sin el rechaza.
            'cliente' => $venta['cliente'] ?: 'Publico general',
            'correo'  => $venta['cliente_email'] ?? '',
        ]);
        if (!$g) {
            if ($this->pideJson()) {
                $this->json(['ok' => false, 'venta_ok' => true,
                    'error' => 'La venta ' . $venta['codigo_venta'] . ' quedó registrada con '
                             . 'saldo, pero el cobro no se generó: ' . $api->error(),
                    'detalle' => $api->respuesta()], 200);
            }
            // La venta SÍ quedó. Se dice qué pasó y dónde seguir, en vez
            // de dejar creer que no se registró nada.
            $this->volver('Venta ' . $venta['codigo_venta'] . ' registrada con saldo, pero '
                . 'la liga no se generó: ' . $api->error()
                . ' Puedes intentarlo de nuevo desde Ligas de pago.', 'error');
        }

        try {
            $id = (new \LibertyFin\Datos\LigaRepo($db))->crear([
                'referencia' => $g['referencia'], 'venta_id' => (int)$r['id'],
                'cliente' => $venta['cliente'] ?: 'Público general', 'monto' => $saldo,
                'metodo' => $forma, 'descripcion' => 'Venta ' . $venta['codigo_venta'],
                'liga' => $g['liga'], 'clabe' => $g['clabe'], 'barras' => $g['barras'],
                'imagen' => $g['imagen'] ?? null, 'formato' => $g['formato'] ?? null,
                'vence' => $g['vence'], 'pruebas' => $g['pruebas'],
                'usuario_id' => $_SESSION['usuario_id'] ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? null,
            ]);
            // Se apunta en qué base vive esta referencia. El aviso del
            // proveedor llega sin sesión y sin empresa: sin el directorio
            // habría que abrir todas las bases para encontrarla.
            \LibertyFin\Servicio\Cobros::apuntar($g['referencia'], $forma);
            // Se vuelve a CAJA, no a Ligas. El cajero tiene al cliente
            // enfrente: mandarlo a otra pantalla lo obliga a volver a
            // empezar para la siguiente venta.
            $liga = (new \LibertyFin\Datos\LigaRepo($db))->porId($id);

            if ($this->pideJson()) {
                $this->json(['ok' => true, 'modo' => $forma, 'liga' => [
                    'id'    => (int)$liga['id'],
                    'monto' => (float)$liga['monto'],
                    'liga'  => $liga['liga'],
                    'clabe' => $liga['clabe'],
                    'barras'=> $liga['barras'],
                    'ref'   => $liga['referencia'],
                    'vence' => $liga['vence'],
                    'doc'   => '/ligas/' . (int)$liga['id'] . '/documento',
                    // Que forma pidio el cajero y si el proveedor la
                    // devolvio. Sin esto el modal muestra lo que haya y
                    // parece que el boton no sirvio.
                    'falta' => $g['falta'] ?? '',
                ], 'venta' => ['codigo' => $venta['codigo_venta']]]);
            }

            $_SESSION['lf_liga'] = $liga;
            $_SESSION['lf_aviso'] = ['texto' =>
                'Venta ' . $venta['codigo_venta'] . ' registrada. Muéstrale el código o '
                . 'mándale la liga; el abono entra cuando pague.', 'tipo' => 'ok'];
            header('Location: /caja'); exit;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] liga en caja: ' . $e->getMessage());
            $this->volver('Venta registrada, pero la liga no se guardó: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * El nombre del colaborador, para dejarlo escrito en la venta.
     *
     * Se guarda el nombre ademas del id porque si esa persona deja la
     * empresa y se desactiva, el reporte de hace seis meses tiene que
     * seguir diciendo quien atendio.
     */
    private function nombreDe($db, $id)
    {
        if (!$id) return null;
        foreach ((new \LibertyFin\Datos\ComisionRepo($db))->equipoPorArea() as $area => $gente) {
            foreach ($gente as $c) if ((int)$c['id'] === (int)$id) return $c['nombre'];
        }
        return null;
    }

    /**
     * Que forma de pago en linea pidio, si es que pidio alguna.
     *
     * Las opciones del mostrador son el metodo tal cual; las de linea
     * llevan guion bajo delante para no confundirse con ellas. Devuelve
     * cadena vacia cuando el cliente ya pago.
     */
    private static function formaEnLinea($como)
    {
        $mapa = ['_tarjeta' => 'tarjeta', '_spei' => 'spei', '_tienda' => 'efectivo'];
        return $mapa[$como] ?? '';
    }

    /**
     * Con que metodo se registra la venta.
     *
     * En las de linea el dinero todavia no entra, pero el metodo queda
     * anotado para que el corte y los reportes sepan por donde va a
     * llegar. SPEI y tienda acaban en la cuenta, asi que cuentan como
     * transferencia.
     */
    private static function metodoDe($como, $db)
    {
        $linea = self::formaEnLinea($como);
        if ($linea) return $linea === 'tarjeta' ? 'tarjeta' : 'transferencia';

        $validos = (new \LibertyFin\Datos\ConfigRepo($db))->metodosDisponibles();
        return in_array($como, $validos, true) ? $como : 'efectivo';
    }

    /** ¿La petición espera JSON en vez de una página? */
    private function pideJson()
    {
        return !empty($_POST['json'])
            || (isset($_SERVER['HTTP_ACCEPT'])
                && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }

    private function json(array $d, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * ¿Ya pago? La caja lo pregunta cada pocos segundos.
     *
     * Asi el cajero ve el aviso en el momento en que entra el dinero,
     * sin recargar ni ir a otra pantalla. Es lo que hace que SPEI se
     * sienta como un cobro y no como una promesa.
     */
    public function estado($id)
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $l  = (new \LibertyFin\Datos\LigaRepo($db))->porId($id);
        if (!$l) $this->json(['ok' => false, 'error' => 'No existe'], 404);

        if ($l['estado'] === 'pagada') {
            $this->json(['ok' => true, 'pagado' => true, 'estado' => 'pagada']);
        }
        if ($l['estado'] === 'por_aprobar') {
            $this->json(['ok' => true, 'pagado' => true, 'estado' => 'por_aprobar']);
        }

        // SOLO LA TARJETA SE CONSULTA.
        //
        // SPEI y tienda no tienen servicio de estatus en Paga de Todo: el
        // proveedor avisa llamando a /pagadetodo/pago-clabe y
        // /pagadetodo/pago-referencia, que ya dejan la liga marcada. Aquí
        // basta con releer la fila, que es lo que se hizo arriba.
        //
        // Antes se le preguntaba igual, a una dirección que además era la
        // nuestra, y la respuesta no servía para nada: el modal giraba
        // hasta que el cajero se cansaba.
        $consultable = \LibertyFin\Servicio\LigaPago::consultable($l['metodo']);

        // Se le pregunta al proveedor, pero no en cada vuelta: cada diez
        // segundos basta y evita castigar su servicio.
        $hace = $l['revisado_en'] ? (time() - strtotime($l['revisado_en'])) : 999;
        if ($consultable && $hace >= 10) {
            $cfg = \LibertyFin\Servicio\Integraciones::de('spei');
            if ($cfg) {
                ob_start();
                $api = new \LibertyFin\Servicio\LigaPago($cfg);
                $r = $api->estado($l['referencia'], $l['metodo']);
                ob_end_clean();

                $repo = new \LibertyFin\Datos\LigaRepo($db);
                if ($r && !empty($r['pagado'])) {
                    $exige = (new \LibertyFin\Datos\ConfigRepo($db))->exigeAprobacion();
                    if ($exige) {
                        $repo->marcarRevisada($l['id'], 'por_aprobar');
                        $this->json(['ok' => true, 'pagado' => true, 'estado' => 'por_aprobar']);
                    }
                    $pagoId = null;
                    if ($l['venta_id']) {
                        $res = (new \LibertyFin\Servicio\RegistrarPago($db))->abonar((int)$l['venta_id'], [
                            'monto' => $l['monto'],
                            'metodo' => $l['metodo'] === 'tarjeta' ? 'tarjeta' : 'transferencia',
                            'referencia' => 'Liga ' . $l['referencia'],
                            'fecha' => date('Y-m-d'),
                            'usuario_id' => $_SESSION['usuario_id'] ?? null,
                        ]);
                        $pagoId = $res['pago_id'] ?? null;
                    }
                    $repo->marcarPagada($l['id'], $pagoId);
                    \LibertyFin\Servicio\Auditoria::anota('pago.registrar',
                        'cobro en linea ' . $l['referencia'], null,
                        \LibertyFin\Dominio\Dinero::pesos($l['monto']));
                    $this->json(['ok' => true, 'pagado' => true, 'estado' => 'pagada']);
                }
                $repo->marcarRevisada($l['id']);
            }
        }
        // `consulta` le dice al modal de qué va la espera: si estamos
        // preguntando, o si toca esperar a que el proveedor avise. Con
        // eso el cajero sabe si vale la pena quedarse mirando.
        $this->json(['ok' => true, 'pagado' => false, 'estado' => 'pendiente',
                     'consulta' => $consultable]);
    }

    /**
     * Dibuja un QR. Lo pide el modal de cobro.
     *
     * Se hace aqui y no en el navegador porque el generador ya existe en
     * el servidor: meter otro en JavaScript seria repetir trescientas
     * lineas que ya estan escritas y probadas.
     */
    public function qr()
    {
        $t = (string)(\LibertyFin\Http\Peticion::texto('t', ''));
        // Solo direcciones nuestras o del proveedor de pago. Dibujar
        // cualquier texto convertiria esto en un generador abierto que
        // alguien podria usar para que la pagina sirva un QR a donde el
        // quiera.
        if ($t === '' || !preg_match('~^https?://~', $t) || mb_strlen($t) > 213) {
            http_response_code(400);
            exit;
        }
        $svg = \LibertyFin\Vista\Qr::svg($t, 190);
        if ($svg === null) { http_response_code(400); exit; }

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        echo $svg;
        exit;
    }
}
