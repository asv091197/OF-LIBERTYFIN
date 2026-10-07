<?php
namespace LibertyFin\Servicio;

/**
 * Cobros en línea de Paga de Todo: tarjeta, SPEI y efectivo en tiendas.
 *
 * CÓMO FUNCIONA ESTO DE VERDAD
 *
 * Un cobro en línea NO es un pago. Es una instrucción que el cliente
 * todavía tiene que cumplir: entrar y pasar su tarjeta, hacer la
 * transferencia, o ir al OXXO con el código.
 *
 * Por eso la venta se registra con SALDO, no como pagada. El abono se
 * aplica cuando el proveedor confirma, ni un minuto antes. Darla por
 * cobrada al generar la referencia haría que el corte mintiera todos los
 * días: diría que entraron $4,000 que nadie ha pagado.
 *
 * EL PROVEEDOR ES PAGA DE TODO, NO PAGA LA ESCUELA
 *
 * Son dos plataformas del mismo dueño (Cobroscontarjeta.com) y la
 * documentación viene mezclada, pero NO son intercambiables:
 *
 *                     Paga la Escuela          Paga de Todo
 *   host              pagalaescuela.mx         pagadetodo.mx
 *   identificador     SchoolID                 BusinessID
 *
 * Apuntar a una con las credenciales de la otra devuelve "El ID de la
 * escuela es obligatorio" aunque todo lo demás esté bien.
 *
 * CADA FORMA DE PAGO ES UN SERVICIO DISTINTO
 *
 * No se elige con un código: se elige con la dirección a la que se pega.
 *
 *   GenerarLigaIndi        liga de tarjeta, pago en línea
 *   GenerarClabeIndi       la CLABE para recibir SPEI
 *   GenerarReferenciaIndi  la referencia para pagar en tienda
 *
 * `PaymentTypes` no selecciona el método. `41` y `401` son LA MISMA
 * cosa —"Contado"—: 401 en Sandbox y 41 en producción. Solo lo recibe la
 * liga de tarjeta; los otros dos servicios ni lo miran.
 *
 * QUIÉN AVISA QUE YA PAGARON
 *
 * Solo la liga de tarjeta se puede consultar (ConsultarEstatusLigaIndi).
 * SPEI y tienda NO tienen servicio de consulta: el proveedor avisa
 * llamando a los endpoints que publica PagosEntrantesControlador. Pedirle
 * el estatus de una CLABE es preguntarle algo que no sabe contestar.
 */
final class LigaPago
{
    /**
     * Las formas que se le ofrecen al cliente.
     *
     *   clave => [rótulo, servicio, explicación]
     *
     * `todos` es un alias viejo que quedó en registros ya guardados: se
     * deja para que una liga antigua siga abriendo, apuntando a tarjeta.
     */
    const METODOS = [
        'tarjeta'  => ['Tarjeta',            'liga',       'Débito o crédito, en línea'],
        'spei'     => ['Transferencia SPEI', 'clabe',      'A una CLABE, se detecta solo'],
        'efectivo' => ['Efectivo en tienda', 'referencia', 'OXXO y tiendas participantes'],
        'todos'    => ['Tarjeta',            'liga',       'Débito o crédito, en línea'],
    ];

    /**
     * El contrato de cada servicio, sacado de la documentación.
     *
     *   ruta · campos del cuerpo · campos de la respuesta
     *
     * NO SON INTERCAMBIABLES, Y LAS DIFERENCIAS IMPORTAN:
     *
     *   · SPEI NO LLEVA MONTO. Devuelve una CLABE que acepta lo que el
     *     cliente deposite; el monto se cuadra después contra la venta.
     *     Mandarle `Amount` lo hace rechazar.
     *
     *   · SPEI pide `Account` en vez de `Reference`. Es el identificador
     *     del cliente del lado del proveedor, no el del pago.
     *
     *   · Solo la liga lleva `PaymentTypes` e `Id`.
     */
    const SERVICIOS = [
        'liga' => [
            '/Service/GenerarLigaIndi',
            ['BusinessID','PaymentTypes','Id','Description','Amount','Reference','ExpirationDate'],
            ['url','Url'],
        ],
        'clabe' => [
            '/Service/GenerarClabeIndi',
            ['BusinessID','Description','Account','CustomerEmail','CustomerName','ExpirationDate'],
            ['Clabe','clabe'],
        ],
        'referencia' => [
            '/Service/GenerarReferenciaIndi',
            ['BusinessID','Description','Amount','Reference','CustomerEmail','CustomerName','ExpirationDate'],
            ['Reference','reference'],
        ],
    ];

    /**
     * Dónde se pregunta si ya pagaron.
     *
     * SOLO LA LIGA. Antes aquí estaban las tres, con `clabe` y
     * `referencia` apuntando a `/Service/ConsultaReferencia`, que es un
     * endpoint NUESTRO —el que el proveedor llama a nosotros—, no suyo.
     * Consultarlo era pegarle a nuestro propio servidor y leer basura.
     */
    const CONSULTA = [
        'liga' => '/Service/ConsultarEstatusLigaIndi',
    ];

    /** La base de Paga de Todo, si no se configuró otra. */
    const HOST = 'https://pagadetodo.mx/Pagadetodo';

    private $cfg;
    private $ultimoError = '';
    private $ultimaRespuesta = '';

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    public function error() { return $this->ultimoError; }

    /** Lo último que contestó el proveedor, para diagnosticar. */
    public function respuesta() { return $this->ultimaRespuesta; }

    public function listo()
    {
        foreach (['usuario','clave','integracion_id'] as $c) {
            if (trim((string)($this->cfg[$c] ?? '')) === '') return false;
        }
        return true;
    }

    public function enPruebas() { return !empty($this->cfg['sandbox']); }

    /** Qué servicio atiende una forma de pago. */
    public static function servicioDe($metodo)
    {
        return self::METODOS[$metodo][1] ?? 'liga';
    }

    /**
     * Deja el método con su nombre canónico.
     *
     * `todos` es un alias de `tarjeta` que quedó en registros viejos y
     * en el formulario de Ligas. Guardarlo así en la base hacía que
     * hubiera dos valores para la MISMA forma de pago —`tarjeta` desde
     * Caja y `todos` desde Ligas— y luego las consultas por método se
     * volvían incómodas: había que acordarse de incluir los dos.
     */
    public static function normalizar($metodo)
    {
        $m = trim((string)$metodo);
        if ($m === 'todos') return 'tarjeta';
        return isset(self::METODOS[$m]) ? $m : 'tarjeta';
    }

    /**
     * ¿Se le puede preguntar al proveedor por esta forma?
     *
     * La caja lo usa para decidir si vale la pena seguir consultando o
     * si toca esperar el aviso del proveedor. Sin esto el modal giraba
     * para siempre en SPEI y en tienda.
     */
    public static function consultable($metodo)
    {
        return isset(self::CONSULTA[self::servicioDe($metodo)]);
    }

    /**
     * La semilla con la que se arma la referencia de un cobro.
     *
     * ──────────────────────────────────────────────────────────
     * POR QUÉ ESTO VIVE AQUÍ Y NO EN CADA CONTROLADOR
     *
     * Caja y Ligas armaban su propia semilla, cada una a su manera, y
     * la de Caja estaba mal.
     *
     * El proveedor pide que `Id` sea Numérico(10). `generar()` lo
     * recorta con `substr(..., -10)` para que no falle cuando la
     * semilla viene más larga. Ligas mandaba 9 dígitos: sobreviven
     * íntegros al recorte. Caja mandaba 18 —`'9'` + venta de 7 +
     * `date('ymdHi')` de 10— y los últimos diez eran SOLO la fecha:
     *
     *   9 0001234 202610071614
     *              └────┬───┘
     *              esto es lo único que llegaba como Id
     *
     * Dos ventas en el mismo minuto compartían `Id`. Y la `Reference`,
     * recortada a 15 de esos mismos 18, arrastraba la cola del id de
     * venta. El proveedor contestaba el código 15 —"El formato del ID
     * es incorrecto"—, que el mapa traducía como "este comercio no
     * está vinculado a la integración" y mandaba a revisar el
     * `BusinessID`, que estaba bien.
     *
     * Aquí hay una sola receta para los dos lados: 9 dígitos, para que
     * el recorte a 10 sea inocuo.
     * ──────────────────────────────────────────────────────────
     *
     * Si hay venta, la semilla la lleva dentro: reintentar devuelve la
     * MISMA liga en vez de crear otra, que es justo lo que se quiere.
     * Sin venta, lleva la marca de tiempo y algo de azar para no
     * chocar con la de otro cobro del mismo segundo.
     */
    public static function semilla($ventaId = 0)
    {
        $ventaId = (int)$ventaId;
        if ($ventaId > 0) {
            // El 9 va delante para que la referencia empiece con un
            // dígito distinto al de las que se generan por tiempo:
            // facilita reconocerlas en el panel del proveedor.
            return substr(
                '9' . str_pad((string)$ventaId, 6, '0', STR_PAD_LEFT) . date('ymdHi'),
                0, 9
            );
        }
        return substr(date('ymdHis') . random_int(100000, 999999), 0, 9);
    }

    /**
     * El código de Contado.
     *
     *   401  Sandbox
     *    41  producción
     *
     * Solo lo usa la liga de tarjeta.
     */
    private function contado()
    {
        $propio = trim((string)($this->cfg['payment_types'] ?? ''));
        if ($propio !== '') return $propio;
        return $this->enPruebas() ? '401' : '41';
    }

    /**
     * Cuántos dígitos lleva la referencia.
     *
     * Quince en Paga de Todo. Con trece el proveedor contesta el código
     * 22, "El formato de la referencia es incorrecto": es el mismo golpe
     * que ya se había cobrado en la integración anterior.
     */
    private function largoReferencia()
    {
        $n = (int)($this->cfg['digitos_referencia'] ?? 0);
        if ($n >= 10 && $n <= 20) return $n;
        return 15;
    }

    /** El identificador del comercio. BusinessID en Paga de Todo. */
    private function negocio()
    {
        $b = trim((string)($this->cfg['negocio_id'] ?? ''));
        // `escuela_id` queda como respaldo para quien venía de Paga la
        // Escuela y todavía no mueve su configuración.
        return $b !== '' ? $b : trim((string)($this->cfg['escuela_id'] ?? ''));
    }

    /**
     * El IntegrationID de ESTE servicio.
     *
     * En Paga de Todo cada forma de pago se contrata por separado y
     * cada una puede traer su propio IntegrationID, aunque compartan
     * BusinessID. Mandar el de SPEI al servicio de tarjeta devuelve el
     * código 26 —"este comercio no está vinculado a la integración"—,
     * que suena a problema del comercio y es en realidad de la
     * integración.
     *
     * `integracion_id` sigue siendo el valor por omisión, para no
     * romper a quien tiene un solo producto contratado.
     */
    private function integracion($servicio)
    {
        $propio = trim((string)($this->cfg['integracion_id_' . $servicio] ?? ''));
        return $propio !== '' ? $propio : (string)($this->cfg['integracion_id'] ?? '');
    }

    /**
     * La dirección de un servicio: la configurada, o host + ruta.
     *
     * UNA URL PUESTA A MANO SE REVISA ANTES DE USARLA.
     *
     * Estos campos existen para un caso raro —que el proveedor te dé una
     * dirección distinta a la normal— y se pagan caro cuando se llenan
     * mal, porque pegarle al servicio equivocado no da un error claro:
     * da un rechazo genérico, o peor, hace algo que nadie pidió.
     *
     * Dos cosas que se han visto de verdad en una configuración:
     *
     *   url_referencia → .../GenerarLigaDomiciliacionIndi
     *       El cobro en tienda pegándole al servicio de tarjeta
     *       tokenizada. Nunca iba a devolver un código de barras.
     *
     *   url_estado → .../PagarDomiciliacionIndi
     *       Preguntar "¿ya pagó?" al servicio que COBRA una tarjeta
     *       guardada. Eso no consulta: cobra.
     *
     * Así que si la dirección no tiene un host con forma de host, o si
     * apunta a un servicio que no es el que toca, se ignora y se usa la
     * buena. Queda en el log y en Ajustes → Integraciones, porque
     * ignorar algo en silencio es su propia clase de problema.
     */
    private function url($servicio, $ruta)
    {
        $propia = trim((string)($this->cfg['url_' . $servicio] ?? ''));
        if ($propia !== '') {
            $queja = self::revisarUrl($propia, $ruta);
            if ($queja === '') return $propia;
            error_log('[LibertyFin] `url_' . $servicio . '` se ignora: ' . $queja
                . ' · se usa ' . self::HOST . $ruta);
        }
        $base = rtrim(trim((string)($this->cfg['host'] ?? $this->cfg['url'] ?? '')), '/');
        if ($base !== '' && self::revisarUrl($base . $ruta, $ruta) !== '') {
            error_log('[LibertyFin] `host` se ignora: ' . self::revisarUrl($base . $ruta, $ruta));
            $base = '';
        }
        if ($base === '') $base = self::HOST;
        return $base . $ruta;
    }

    /**
     * Qué tiene de malo una dirección. Cadena vacía = está bien.
     *
     * Se compara contra el nombre del servicio que toca, sin la ruta
     * completa: así una dirección con otro host o otra carpeta sigue
     * valiendo —que para eso está el campo— pero una que apunta a otro
     * servicio, no.
     */
    public static function revisarUrl($url, $ruta)
    {
        $partes = parse_url(trim((string)$url));
        if (!$partes || empty($partes['host'])) return 'no se entiende como dirección';

        $host = $partes['host'];
        // "https://.mx/..." pasa el filtro de arriba con host ".mx", y
        // "paadetodo.mx" también: por eso además se exige que el nombre
        // antes del punto exista y tenga cuerpo.
        if (strpos($host, '.') === false)  return 'al host le falta el dominio: ' . $host;
        $etiquetas = explode('.', trim($host, '.'));
        if (count($etiquetas) < 2)         return 'host incompleto: ' . $host;
        foreach ($etiquetas as $e) {
            if ($e === '') return 'host con un punto de más o un pedazo vacío: ' . $host;
        }
        if (strlen($etiquetas[count($etiquetas) - 2]) < 2) {
            return 'host incompleto: ' . $host;
        }
        if (($partes['scheme'] ?? '') === '') return 'le falta https://';

        // El nombre del servicio, sin "Indi" ni la ruta: GenerarReferencia,
        // GenerarClabe, GenerarLiga, ConsultarEstatusLiga.
        $espera = preg_replace('~^.*/|Indi$~', '', $ruta);
        $camino = $partes['path'] ?? '';
        if ($espera !== '' && stripos($camino, $espera) === false) {
            return 'apunta a ' . trim(basename($camino)) . ' y debería apuntar a '
                 . $espera . 'Indi';
        }
        // GenerarLiga es prefijo de GenerarLigaDomiciliacion, así que la
        // comparación de arriba lo deja pasar. Aquí se corta.
        if (stripos($espera, 'Domiciliacion') === false
            && stripos($camino, 'Domiciliacion') !== false) {
            return 'apunta al servicio de domiciliación (tarjeta guardada), '
                 . 'no a ' . $espera . 'Indi';
        }
        return '';
    }

    /**
     * El usuario y la clave SON UNA PAREJA. Nunca se mezclan.
     *
     * Antes cada uno decidía por su cuenta si usar el de Sandbox:
     *
     *     usuario = usuario_prueba ?: usuario
     *     clave   = clave_prueba   ?: clave
     *
     * Con `usuario_prueba` vacío y `clave_prueba` llena —que es como
     * queda una configuración a medio llenar— salía el usuario de
     * producción con la contraseña de Sandbox. El proveedor contesta
     * "El usuario y/o contraseña son inválidos" y uno jura que las
     * credenciales están mal cuando lo que está mal es la mezcla.
     *
     * @return array [usuario, clave]
     */
    private function credenciales()
    {
        if ($this->enPruebas() && trim((string)($this->cfg['usuario_prueba'] ?? '')) !== '') {
            return [(string)$this->cfg['usuario_prueba'], (string)($this->cfg['clave_prueba'] ?? '')];
        }
        return [(string)($this->cfg['usuario'] ?? ''), (string)($this->cfg['clave'] ?? '')];
    }

    private function usuario() { list($u, ) = $this->credenciales(); return $u; }
    private function clave()   { list( , $c) = $this->credenciales(); return $c; }

    /**
     * Genera el cobro.
     *
     * @param array $d  monto, descripcion, referencia, metodo, cliente, correo, dias
     * @return array|null
     */
    public function generar(array $d)
    {
        $this->ultimoError = '';
        if (!$this->listo()) { $this->ultimoError = 'Paga de Todo sin configurar'; return null; }
        if ($this->negocio() === '') {
            $this->ultimoError = 'Falta `negocio_id` (BusinessID) en config/integraciones.php';
            return null;
        }

        $monto = round((float)($d['monto'] ?? 0), 2);
        if ($monto <= 0) { $this->ultimoError = 'El monto tiene que ser mayor a cero'; return null; }

        $metodo   = self::normalizar($d['metodo'] ?? 'tarjeta');
        $servicio = self::servicioDe($metodo);
        list($ruta, $campos, $devuelve) = self::SERVICIOS[$servicio];

        // El proveedor rechaza fuera de rango con el código 18. Se avisa
        // aquí para no gastar una llamada y, sobre todo, para que el
        // cajero lea algo que entienda.
        $min = (float)($this->cfg['monto_min'] ?? 50);
        $max = (float)($this->cfg['monto_max'] ?? 15000);
        if ($servicio !== 'clabe' && ($monto < $min || $monto > $max)) {
            $this->ultimoError = 'El importe debe estar entre '
                . \LibertyFin\Dominio\Dinero::pesos($min) . ' y '
                . \LibertyFin\Dominio\Dinero::pesos($max) . '. Este cobro es de '
                . \LibertyFin\Dominio\Dinero::pesos($monto) . '.';
            return null;
        }

        $url        = $this->url($servicio, $ruta);
        $pruebas    = $this->enPruebas();
        $referencia = self::referencia($d['referencia'] ?? '', $this->largoReferencia());
        $dias       = max(1, (int)($d['dias'] ?? 0) ?: (int)($this->cfg['dias_vigencia'] ?? 3));
        $cliente    = mb_substr(trim((string)($d['cliente'] ?? '')), 0, 50) ?: 'Publico general';
        $correo     = filter_var($d['correo'] ?? '', FILTER_VALIDATE_EMAIL) ? $d['correo'] : '';

        // Credenciales: las pide siempre, las tres.
        $cuerpo = [
            'User'          => $this->usuario(),
            'Password'      => $this->clave(),
            // ⟵ AJUSTE: el IntegrationID va por servicio, no el mismo
            // para los tres. Ver `integracion()`.
            'IntegrationID' => $this->integracion($servicio),
        ];

        $posibles = [
            'BusinessID'     => $this->negocio(),
            'PaymentTypes'   => $this->contado(),
            // `Id` es Numérico(10) en la documentación. Mandarle los 15
            // de la referencia devuelve el código 15, "El formato del ID
            // es incorrecto": se toman los últimos diez.
            //
            // Con la semilla de 9 dígitos (ver `semilla()`) el recorte
            // es inocuo: los 9 pasan íntegros.
            'Id'             => substr((string)($d['id'] ?? $referencia), -10),
            // El proveedor corta a 40 y si se pasa, rechaza.
            'Description'    => mb_substr((string)($d['descripcion'] ?? 'Pago'), 0, 40),
            // En CENTAVOS y como cadena: mandar "150.00" en vez de
            // "15000" genera un cobro por peso y medio.
            'Amount'         => (string)(int)round($monto * 100),
            'Reference'      => $referencia,
            // SPEI identifica al cliente con `Account`. Va el mismo valor
            // que la referencia: dos identificadores para una sola
            // operación harían imposible conciliar.
            'Account'        => $referencia,
            'CustomerName'   => $cliente,
            'CustomerEmail'  => $correo,
            'ExpirationDate' => date('Y-m-d', strtotime('+' . $dias . ' days')),
        ];
        // Y después, SOLO los campos que ese servicio espera. Mandarle de
        // más es lo que lo hace rechazar la petición.
        foreach ($campos as $c) {
            if (isset($posibles[$c])) $cuerpo[$c] = $posibles[$c];
        }

        $j = $this->pegar($url, $cuerpo);
        if ($j === null) return null;

        // Cada servicio devuelve lo suyo, con el nombre que dice su
        // documentación. Buscar en todos daría falsos positivos: la liga
        // también trae un `reference` que NO es un código de barras, y
        // mostrarlo como tal manda al cliente al OXXO con un número que
        // ahí no sirve.
        $valor  = self::campo($j, $devuelve);
        $liga   = $servicio === 'liga'       ? $valor : null;
        $clabe  = $servicio === 'clabe'      ? $valor : null;
        $barras = $servicio === 'referencia' ? $valor : null;

        // La referencia trae además la imagen del código de barras y el
        // formato de pago, cuando el convenio los genera.
        $imagen  = $servicio === 'referencia' ? self::campo($j, ['BarCode','barCode']) : null;
        $formato = $servicio === 'referencia' ? self::campo($j, ['PayFormat','payFormat']) : null;

        if (!$liga && !$clabe && !$barras) {
            $this->ultimoError = self::motivo($j);
            error_log('[LibertyFin] cobro rechazado · ' . $url
                . ' · enviado: ' . json_encode(self::sinSecretos($cuerpo))
                . ' · recibido: ' . $this->ultimaRespuesta);
            return null;
        }

        return [
            'liga'       => $liga,
            'clabe'      => $clabe,
            'barras'     => $barras,
            'imagen'     => $imagen,
            'formato'    => $formato,
            'falta'      => '',
            // La referencia con la que el cobro queda registrado de
            // NUESTRO lado. Es la que viaja en los avisos del proveedor.
            'referencia' => $referencia,
            'remota'     => (string)(self::campo($j, ['Reference','reference']) ?: ''),
            'account'    => $servicio === 'clabe' ? $referencia : null,
            'folio'      => self::campo($j, ['Folio','folio']),
            'vence'      => $cuerpo['ExpirationDate'],
            'metodo'     => $metodo,
            'servicio'   => $servicio,
            'pruebas'    => $pruebas,
            'crudo'      => $j,
        ];
    }

    /**
     * Pregunta si ya pagaron. Solo sirve para la liga de tarjeta.
     *
     * Las otras dos formas no se consultan: el proveedor avisa llamando
     * a nuestros endpoints. Devolver null con el motivo escrito es mejor
     * que inventar un "no pagado" que haga girar la pantalla sin fin.
     *
     * @param string $referencia
     * @param string $metodo  tarjeta | spei | efectivo
     */
    public function estado($referencia, $metodo = 'tarjeta')
    {
        $this->ultimoError = '';
        $servicio = self::servicioDe($metodo);

        if (!isset(self::CONSULTA[$servicio])) {
            $this->ultimoError = $servicio === 'clabe'
                ? 'El SPEI no se consulta: el banco avisa cuando llega el depósito.'
                : 'El pago en tienda no se consulta: la tienda avisa cuando cobra.';
            return null;
        }
        if (!$this->listo()) { $this->ultimoError = 'Paga de Todo sin configurar'; return null; }

        $j = $this->pegar($this->url('estado', self::CONSULTA[$servicio]), [
            'User'          => $this->usuario(),
            'Password'      => $this->clave(),
            // ⟵ AJUSTE: la consulta también va contra la integración de
            // tarjeta, no la genérica.
            'IntegrationID' => $this->integracion($servicio),
            'BusinessID'    => $this->negocio(),
            'Reference'     => (string)$referencia,
        ]);
        if ($j === null) return null;

        // LA RESPUESTA VIENE ANIDADA, y antes se buscaba un campo
        // `Status` que no existe en ningún lado. Por eso la consulta
        // jamás detectaba un pago: siempre contestaba "desconocido".
        //
        //   { "code": "00", "message": "...",
        //     "paymentResponse": { "response": "approved", ... } }
        //
        // `response` trae approved, denied, error o pending.
        $anidada = isset($j['paymentResponse']) && is_array($j['paymentResponse']);
        $p = $anidada ? $j['paymentResponse'] : $j;

        $codigo = (string)(self::campo($j, ['code','Code']) ?: '');
        if (!$anidada && $codigo !== '' && $codigo !== '00') {
            // Un código distinto de 00 sin cuerpo de pago es un rechazo
            // de la consulta, no un "todavía no paga".
            $this->ultimoError = self::motivo($j);
            return null;
        }

        $estado = strtolower(trim((string)(
            self::campo($p, ['response','Response','status','Status','estado']) ?: '')));
        $pagado = in_array($estado, ['approved','aprobado','paid','pagado','success','completed'], true);

        return [
            'pagado'       => $pagado,
            'estado'       => $estado ?: 'pendiente',
            'monto'        => self::numero($p['amount'] ?? null),
            'autorizacion' => (string)($p['auth'] ?? ''),
            'folio'        => (string)($p['foliocpagos'] ?? ''),
            'fecha'        => (string)($p['date'] ?? ''),
            'crudo'        => $j,
        ];
    }

    /**
     * Una llamada al proveedor. Devuelve el JSON o null con el motivo.
     *
     * Centraliza el cURL porque antes estaba copiado en dos métodos con
     * diferencias que nadie había pedido: uno mandaba `Accept` y el otro
     * no, y los tiempos de espera eran distintos.
     */
    private function pegar($url, array $cuerpo)
    {
        $this->ultimaRespuesta = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8',
                                       'Accept: application/json'],
            CURLOPT_TIMEOUT        => max(10, (int)($this->cfg['timeout'] ?? 30)),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            // El certificado SÍ se verifica. Por aquí viajan montos y
            // referencias de pago.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp   = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->ultimoError = 'No se pudo contactar al proveedor: ' . $err;
            return null;
        }
        $this->ultimaRespuesta = mb_substr((string)$resp, 0, 800);

        $j = json_decode($resp, true);
        if (!is_array($j)) {
            $this->ultimoError = 'El proveedor respondió algo que no se entiende (HTTP '
                               . $codigo . '): ' . mb_substr(strip_tags((string)$resp), 0, 160);
            return null;
        }
        // Las claves llegan con espacios de vez en cuando ("url ").
        $limpio = [];
        foreach ($j as $k => $v) $limpio[trim((string)$k)] = $v;
        return $limpio;
    }

    /** Por qué rechazó, en palabras. */
    private static function motivo(array $j)
    {
        $codigo = (string)(self::campo($j, ['Error','error','code','Code']) ?: '');
        $msg    = (string)(self::campo($j, ['Message','message','mensaje','ErrorMessage']) ?: '');
        $texto  = self::CODIGOS[$codigo] ?? '';
        if ($texto === '' && $msg !== '') $texto = $msg;
        if ($texto === '') {
            return 'El proveedor no devolvió ninguna forma de pagar.';
        }
        return 'El proveedor rechazó el cobro: ' . $texto
             . ($codigo !== '' ? ' (código ' . $codigo . ')' : '');
    }

    /**
     * Los códigos de la documentación, traducidos.
     *
     * Sin esto el cajero leía "22" y no había forma de saber que la
     * referencia iba con el largo equivocado.
     *
     * ──────────────────────────────────────────────────────────
     * AJUSTE: EL 15 Y EL 26 SIGNIFICAN COSAS DISTINTAS
     *
     * El mapa venía de Paga la Escuela, donde el 15 es "comercio no
     * vinculado". En Paga de Todo el 15 es OTRA COSA:
     *
     *   15  El formato del Id es incorrecto (es Numérico(10))
     *   26  Este comercio no está vinculado a la integración
     *
     * Con el texto viejo, un `Id` mal recortado —que es justo lo que
     * pasaba desde Caja— mandaba al cajero a revisar el `BusinessID`,
     * que estaba bien, y a perder la tarde en el lugar equivocado.
     * ──────────────────────────────────────────────────────────
     */
    const CODIGOS = [
        '00'  => 'los datos enviados vienen vacíos',
        '1'   => 'el usuario o la contraseña son inválidos',
        '2'   => 'faltan el usuario y la contraseña',
        '3'   => 'el ID de la integración no existe',
        '4'   => 'el ID de la integración trae mal el formato',
        '5'   => 'falta el ID de la integración',
        '6'   => 'el ID del comercio no existe',
        '7'   => 'el ID del comercio trae mal el formato',
        '8'   => 'falta el ID del comercio',
        '9'   => 'falta la descripción',
        '10'  => 'falta el Account',
        '11'  => 'el Account trae mal el formato',
        '12'  => 'el Account ya se usó: tiene que ser único',
        '14'  => 'el formato de la fecha de vencimiento es incorrecto',
        '15'  => 'el ID del cobro trae mal el formato (a lo más 10 dígitos)',
        '17'  => 'falta la descripción',
        '18'  => 'el importe debe ser mínimo $50.00 y máximo $15,000.00',
        '19'  => 'el formato del importe es incorrecto',
        '20'  => 'falta el importe',
        '21'  => 'falta la referencia',
        '22'  => 'el formato de la referencia es incorrecto (lleva 15 dígitos)',
        '23'  => 'esa referencia ya se usó: tiene que ser única',
        '24'  => 'la fecha de vencimiento tiene que ser de hoy en adelante',
        '25'  => 'el formato de la fecha de vencimiento es incorrecto',
        '26'  => 'este comercio no está vinculado a la integración',
        '400' => 'la CLABE no se pudo generar: habla con el proveedor',
        '401' => 'la cuenta no tiene acceso: habla con el proveedor',
        '404' => 'la cuenta no tiene permiso para generar esta forma de pago',
    ];

    /**
     * La referencia: exactamente los dígitos que pida el convenio.
     *
     * Si se repite, el proveedor devuelve el cobro ANTERIOR en vez de
     * crear uno nuevo. Eso es lo que se quiere al reintentar una venta
     * que no se completó, y un desastre si dos ventas distintas la
     * comparten: la segunda cobraría el monto de la primera.
     */
    public static function referencia($semilla = '', $largo = 15)
    {
        $largo = max(10, min(20, (int)$largo));
        $s = preg_replace('/\D/', '', (string)$semilla);
        if (strlen($s) >= $largo) return substr($s, -$largo);
        return str_pad($s, $largo, (string)random_int(0, 9), STR_PAD_LEFT);
    }

    /** "1,156.00" -> 1156.00 */
    private static function numero($v)
    {
        if ($v === null || $v === '') return null;
        return (float)str_replace([',', '$', ' '], '', (string)$v);
    }

    /** Para el log: todo menos la contraseña. */
    private static function sinSecretos(array $c)
    {
        if (isset($c['Password'])) $c['Password'] = '•••';
        return $c;
    }

    private static function campo(array $j, array $nombres)
    {
        foreach ($nombres as $n) {
            if (isset($j[$n]) && $j[$n] !== '' && $j[$n] !== null) return $j[$n];
            // A veces viene envuelto en Data / Result
            foreach (['Data','data','Result','result','Response'] as $w) {
                if (isset($j[$w]) && is_array($j[$w])
                    && isset($j[$w][$n]) && $j[$w][$n] !== '') return $j[$w][$n];
            }
        }
        return null;
    }
}