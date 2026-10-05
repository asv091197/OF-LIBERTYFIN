<?php
namespace LibertyFin\Servicio;

/**
 * Recargas telefónicas · Emida.
 *
 * Es SOAP sobre un WSDL, no REST. Los datos y los códigos de respuesta
 * salen del archivo de configuración del sistema anterior, que sí estaba
 * probado contra la cuenta real.
 *
 * DOS COSAS QUE NO SE COPIAN DEL SISTEMA ANTERIOR:
 *
 *  · El WSDL apunta a `http://104.248.179.142` SIN cifrar. Por ahí viajan
 *    el usuario, la clave y el número del cliente en claro. Aquí se avisa
 *    en pantalla y se deja pasar solo porque es el único endpoint que hay;
 *    en cuanto Emida dé uno con HTTPS, se cambia en la configuración.
 *
 *  · `verify_peer => false`. Eso convierte HTTPS en decoración: cualquiera
 *    en medio puede presentar su propio certificado. Aquí se verifica, y
 *    si el certificado falla la recarga no sale.
 */
final class Emida
{
    /**
     * Qué operación sirve para qué, en orden de preferencia.
     *
     * POR QUÉ NO SE ADIVINA POR EL NOMBRE
     *
     * Este WSDL tiene unas 150 operaciones. Buscar por palabras eligió
     * `CardBalance` para el saldo —que es de tarjetas de regalo— y
     * `LookUpBillPaymentMxByInvocieNo` para recargar, que ni siquiera
     * vende. Con un catálogo así, cualquier heurística acierta por
     * casualidad.
     *
     * Estos nombres salen de los proxies del sistema anterior, que sí
     * estaban probados contra la cuenta real: `pinDistSale.php`,
     * `get_balance.php`, `lookup_transaction.php`.
     *
     * Se puede sobrescribir cada uno en config/integraciones.php si el
     * proveedor cambia el contrato.
     */
    const OPERACIONES = [
        'saldo'     => ['GetMerchantBalance', 'GetTerminalBalance', 'GetAccountBalance'],
        'validar'   => ['CheckTrxById', 'LookUpTransactionByInvocieNo', 'CheckTBID'],
        'vender'    => ['PinDistSale', 'PinDistSale_01', 'CardSale'],
        'productos' => ['GetProductList', 'GetProductListExt', 'GetProductListDetailed'],
        'carriers'  => ['GetCarrierList'],
        'prueba'    => ['CommTest'],
    ];

    /** Los códigos que el proveedor documenta. */
    const ERRORES = [
        '16'   => 'El número no existe o no admite recargas',
        '51'   => 'Monto no válido para esa compañía',
        '12'   => 'Terminal o comercio no válidos',
        '294'  => 'Ya se hizo esa misma recarga hace menos de 5 minutos',
        '504'  => 'El proveedor no respondió a tiempo',
        '2518' => 'Error de comunicación con la compañía telefónica',
    ];

    private $cfg;

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    /**
     * ¿Se habla con Emida por un intermediario?
     *
     * POR QUÉ ESTO EXISTE
     *
     * El endpoint real es ws.terecargamos.com:8448, y desde el hosting
     * da "Connection refused". No es un puerto cerrado de salida: es que
     * Emida solo acepta conexiones desde direcciones que tiene en lista,
     * y el servidor de LibertyFin no está en ella.
     *
     * Por eso el sistema anterior nunca habló con Emida: hablaba con
     * 104.248.179.142, un servidor propio cuya IP sí está autorizada, que
     * reenvía las peticiones. Ahí viven get_balance.php, pinDistSale.php
     * y lookup_transaction.php.
     *
     * Dos caminos, y los dos válidos:
     *   · Pedirle a Emida que autorice la IP del hosting → modo directo.
     *   · Seguir pasando por el intermediario → modo proxy.
     */
    public function porProxy()
    {
        return trim((string)($this->cfg['proxy'] ?? '')) !== '';
    }

    /**
     * Llama a un script del intermediario.
     * Devuelve lo que responda, ya decodificado si es JSON.
     */
    private function proxy($script, array $params)
    {
        $base = rtrim((string)($this->cfg['proxy'] ?? ''), '/');
        $url  = $base . '/' . ltrim($script, '/');
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(5, (int)($this->cfg['timeout'] ?? 30)),
            CURLOPT_FOLLOWLOCATION => true,
            // El certificado SÍ se verifica, al revés que los proxies del
            // sistema anterior. Si el intermediario va por http no hay
            // nada que verificar, y eso ya se avisa en pantalla.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LibertyFin/1.0',
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false) {
            return ['ok' => false, 'error' => 'No se pudo llamar al intermediario: ' . $error];
        }
        if ($codigo >= 400) {
            return ['ok' => false, 'error' => 'El intermediario respondió ' . $codigo];
        }
        $j = json_decode($cuerpo, true);
        return ['ok' => true, 'datos' => $j === null ? $cuerpo : $j, 'crudo' => $cuerpo];
    }

    /** ¿El endpoint va cifrado? Se muestra en pantalla. */
    public function cifrado()
    {
        return strncasecmp((string)($this->cfg['wsdl'] ?? ''), 'https://', 8) === 0;
    }

    private function cliente()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') {
            throw new \RuntimeException('Falta la dirección del WSDL en config/integraciones.php');
        }
        if (!class_exists('SoapClient')) {
            throw new \RuntimeException(
                'Este servidor no tiene la extensión SOAP de PHP. Actívala para usar recargas.');
        }
        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(
                'Este servidor tiene allow_url_fopen apagado y SOAP lo necesita para leer el WSDL.');
        }

        $seg  = max(5, (int)($this->cfg['timeout'] ?? 30));
        $usr  = (string)($this->cfg['usuario'] ?? '');
        $pwd  = (string)($this->cfg['clave'] ?? '');

        // EL WSDL VA PROTEGIDO CON AUTENTICACIÓN BÁSICA.
        //
        // Sin esta cabecera, PHP descarga una página de error en vez del
        // XML y SoapClient falla con "failed to load external entity",
        // que no dice nada de lo que de verdad pasó. El sistema anterior
        // sí la mandaba; yo la había omitido.
        $http = ['timeout' => $seg, 'user_agent' => 'LibertyFin/1.0'];
        if ($usr !== '') {
            $http['header'] = "Authorization: Basic " . base64_encode($usr . ':' . $pwd);
        }

        $ctx = stream_context_create([
            // Para una dirección IP no hay certificado válido posible: se
            // emiten para nombres de dominio. Si el endpoint es https a una
            // IP, verificar siempre falla, y APAGAR la verificación no lo
            // arregla: lo esconde. Por eso esto se queda en true y el
            // aviso dice que hay que pedir un dominio.
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                      'allow_self_signed' => false],
            'http' => $http,
        ]);

        $opciones = [
            'trace'              => true,
            'exceptions'         => true,
            // Sin caché mientras se está probando: un WSDL mal descargado
            // queda guardado y sigue fallando aunque ya se arregle.
            'cache_wsdl'         => !empty($this->cfg['sandbox']) ? WSDL_CACHE_NONE : WSDL_CACHE_DISK,
            'connection_timeout' => $seg,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'encoding'           => 'UTF-8',
            'stream_context'     => $ctx,
        ];
        // Y también como credenciales del propio cliente, para las
        // llamadas que van después de leer el WSDL.
        if ($usr !== '') { $opciones['login'] = $usr; $opciones['password'] = $pwd; }

        try {
            return new \SoapClient($wsdl, $opciones);
        } catch (\SoapFault $e) {
            throw new \RuntimeException(self::explicar($wsdl, $e->getMessage()));
        }
    }

    /**
     * Traduce el error de SOAP a algo accionable.
     *
     * "failed to load external entity" significa que PHP no pudo bajar el
     * XML, y las causas son pocas y conocidas. Decirlas ahorra una tarde.
     */
    private static function explicar($wsdl, $mensaje)
    {
        $esHttps = strncasecmp($wsdl, 'https://', 8) === 0;
        $esIp    = (bool)preg_match('~^https?://\d{1,3}(\.\d{1,3}){3}~', $wsdl);

        if (stripos($mensaje, 'external entity') !== false
            || stripos($mensaje, "Couldn't load from") !== false) {
            if ($esHttps && $esIp) {
                return 'No se pudo leer el WSDL en ' . $wsdl . '. Está apuntando a una '
                     . 'dirección IP por HTTPS, y eso casi nunca funciona: los certificados '
                     . 'se emiten para nombres de dominio, no para IPs. Cámbialo a http:// '
                     . 'y pídele a Emida un dominio con certificado.';
            }
            return 'No se pudo leer el WSDL en ' . $wsdl . '. Puede ser que el servidor de '
                 . 'Emida no responda desde aquí, que las credenciales no sirvan para '
                 . 'descargarlo, o que el hosting bloquee la salida a esa dirección. '
                 . 'Comprueba desde el servidor con: curl -u USUARIO:CLAVE ' . $wsdl;
        }
        return 'No se pudo conectar con Emida: ' . $mensaje;
    }

    /**
     * Prueba la conexión y dice qué falla, paso por paso.
     * Sirve para no adivinar cuando una recarga no sale.
     */
    public function probar()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        $r = [];

        // Un renglón informativo NO detiene la prueba. Antes "va cifrada"
        // contaba como fallo, así que con HTTP —que es lo normal aquí—
        // la revisión se cortaba justo antes de lo único que importa:
        // si el WSDL se lee y a dónde apunta.
        $agrega = function (&$r, $que, $ok, $detalle, $bloquea = true) {
            $r[] = ['que' => $que, 'ok' => $ok, 'detalle' => $detalle, 'bloquea' => $bloquea];
        };

        // La extensión SOAP hace falta para LLAMAR, no para leer el WSDL.
        // Si no está, el resto del diagnóstico sigue siendo útil: dice a
        // dónde apunta y qué ofrece, que es lo que hay que averiguar.
        $agrega($r, 'Extensión SOAP de PHP', class_exists('SoapClient'),
            class_exists('SoapClient')
                ? 'disponible'
                : 'falta: sin ella se puede diagnosticar pero no recargar', false);
        $agrega($r, 'allow_url_fopen', (bool)ini_get('allow_url_fopen'),
            ini_get('allow_url_fopen') ? 'encendido' : 'apagado: SOAP no puede leer el WSDL');
        $agrega($r, 'Dirección del WSDL', $wsdl !== '', $wsdl ?: 'sin configurar');
        $agrega($r, 'Credenciales', trim((string)($this->cfg['usuario'] ?? '')) !== ''
                                 && trim((string)($this->cfg['merchant_id'] ?? '')) !== '',
            'usuario y comercio');
        $agrega($r, 'Va cifrada', $this->cifrado(),
            $this->cifrado() ? 'HTTPS' : 'HTTP: las credenciales viajan en claro', false);
        $agrega($r, 'Cómo habla con Emida', true,
            $this->porProxy()
                ? 'por intermediario: ' . $this->cfg['proxy']
                : 'directo al endpoint del WSDL', false);

        foreach ($r as $x) if ($x['bloquea'] && !$x['ok']) return $r;

        // Si va por intermediario, lo que importa es que ESE responda.
        if ($this->porProxy()) {
            $p = $this->proxy($this->cfg['proxy_saldo'] ?? 'get_balance.php', [
                'username' => $this->cfg['usuario'] ?? '',
                'password' => $this->cfg['clave'] ?? '',
            ]);
            $agrega($r, 'El intermediario responde', $p['ok'],
                $p['ok'] ? mb_substr(is_string($p['crudo']) ? $p['crudo'] : '', 0, 160)
                         : $p['error']);
        }

        // 1 · ¿Se baja el XML?
        $d = $this->bajarWsdl();
        $agrega($r, 'Descarga del WSDL', $d['ok'],
            $d['ok'] ? ($d['bytes'] . ' bytes de XML') : $d['error']);
        if (!$d['ok']) return $r;

        // 2 · ¿A dónde manda las llamadas? Esto es lo que suele fallar:
        // el WSDL se lee y el endpoint que declara es otro.
        $ep = $this->endpoint();
        $mismoHost = $ep && parse_url($ep, PHP_URL_HOST) === parse_url($wsdl, PHP_URL_HOST);
        $agrega($r, 'A dónde manda las llamadas', (bool)$ep,
            $ep ? ($ep . ($mismoHost ? '' : '  ← host distinto al del WSDL'))
                : 'el WSDL no declara <soap:address>');

        // 3 · ¿Responde ese endpoint?
        if ($ep && !$this->porProxy()) {
            $abre = @fsockopen(
                (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 'ssl://' : '') . parse_url($ep, PHP_URL_HOST),
                parse_url($ep, PHP_URL_PORT) ?: (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 443 : 80),
                $errno, $errstr, 6);
            if ($abre) { fclose($abre); $agrega($r, 'El endpoint responde', true, 'contesta'); }
            else {
                $agrega($r, 'El endpoint responde', false,
                    ($errstr ?: 'no contesta') . '. Emida solo acepta conexiones desde '
                    . 'direcciones autorizadas, y la de este servidor no lo está. '
                    . 'O pides que la autoricen, o configuras `proxy` para pasar por '
                    . 'un servidor que sí lo esté.');
            }
        }

        // 4 · Qué operaciones ofrece, y cuál sirve para qué.
        $ops = $this->operacionesDelXml();
        if ($ops['ok']) {
            $n = $ops['operaciones'];
            // 150 nombres en pantalla no se leen. Se dice cuántos y se
            // muestran los que de verdad se van a usar.
            $agrega($r, 'Operaciones que ofrece', count($n) > 0,
                count($n) . ' en total');
            foreach (['saldo' => 'saldo', 'validar' => 'validar número',
                      'vender' => 'recargar', 'productos' => 'catálogo'] as $k => $rotulo) {
                $op = $this->operacionPara($k);
                $agrega($r, 'Operación de ' . $rotulo, (bool)$op,
                    $op ?: 'ninguna de ' . implode(', ', self::OPERACIONES[$k] ?? []) . ' está en el WSDL',
                    false);
            }
        } else {
            $agrega($r, 'Operaciones que ofrece', false, $ops['error']);
        }
        return $r;
    }

    /** Los campos que toda petición lleva. */
    private function base()
    {
        return [
            'UserId'         => $this->cfg['usuario'] ?? '',
            'Password'       => $this->cfg['clave'] ?? '',
            'MerchantId'     => $this->cfg['merchant_id'] ?? '',
            'ClerkPassword'  => $this->cfg['clerk_password'] ?? ($this->cfg['clave'] ?? ''),
        ];
    }

    /**
     * Descarga el WSDL como texto, con su autenticación.
     *
     * Se hace aparte de SoapClient a propósito: SoapClient falla con un
     * mensaje único ("Could not connect to host") sin importar si el
     * problema fue la descarga, el XML o el endpoint. Bajándolo a mano
     * se puede decir exactamente cuál de los tres.
     */
    public function bajarWsdl()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') return ['ok' => false, 'error' => 'Sin dirección de WSDL'];

        $http = ['timeout' => max(5, (int)($this->cfg['timeout'] ?? 30)),
                 'user_agent' => 'LibertyFin/1.0', 'ignore_errors' => true];
        $usr = (string)($this->cfg['usuario'] ?? '');
        if ($usr !== '') {
            $http['header'] = 'Authorization: Basic '
                . base64_encode($usr . ':' . (string)($this->cfg['clave'] ?? ''));
        }
        $ctx = stream_context_create(['http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $xml = @file_get_contents($wsdl, false, $ctx);
        if ($xml === false) {
            $e = error_get_last();
            return ['ok' => false, 'error' => 'No se pudo descargar: '
                . ($e['message'] ?? 'sin detalle')];
        }
        if (stripos($xml, '<definitions') === false && stripos($xml, ':definitions') === false) {
            return ['ok' => false,
                    'error' => 'Lo que devolvió no es un WSDL. Primeros caracteres: '
                             . mb_substr(strip_tags($xml), 0, 120)];
        }
        return ['ok' => true, 'xml' => $xml, 'bytes' => strlen($xml)];
    }

    /**
     * A qué dirección se mandan las llamadas.
     *
     * NO es la misma que la del WSDL. El XML declara su propio endpoint
     * en <soap:address>, y si ahí dice https o una IP distinta, las
     * llamadas van a otro lado aunque el WSDL se haya leído bien. Ese es
     * justo el caso de "se descargó el WSDL pero no conecta".
     */
    public function endpoint()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return null;
        if (preg_match('/<(?:\w+:)?address\s+location=["\']([^"\']+)["\']/i', $d['xml'], $m)) {
            return $m[1];
        }
        return null;
    }

    /** Las operaciones, leídas del XML. No necesita conectar a nada. */
    public function operacionesDelXml()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return ['ok' => false, 'error' => $d['error']];
        preg_match_all('/<(?:\w+:)?operation\s+name=["\']([^"\']+)["\']/i', $d['xml'], $m);
        $nombres = array_values(array_unique($m[1] ?? []));
        return ['ok' => true, 'operaciones' => $nombres];
    }

    /**
     * Qué operaciones ofrece de verdad este WSDL.
     *
     * Los nombres que yo usaba —GetBalance, LookupTransaction,
     * SubmitTransaction— salieron de la documentación general de Emida,
     * no de ESTE servicio. El WSDL es la única fuente que no se
     * equivoca: se le pregunta y se acabó la adivinanza.
     */
    public function operaciones()
    {
        try {
            $fns = $this->cliente()->__getFunctions();
            $r = [];
            foreach ((array)$fns as $f) {
                // Vienen como "TipoRespuesta Nombre(TipoPeticion $p)"
                if (preg_match('/\s(\w+)\(/', (string)$f, $m)) $r[$m[1]] = (string)$f;
            }
            return ['ok' => true, 'operaciones' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Los tipos que espera cada operación, para saber qué campos mandar. */
    public function tipos()
    {
        try { return ['ok' => true, 'tipos' => (array)$this->cliente()->__getTypes()]; }
        catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    /**
     * Busca la operación que sirve para algo, por lo que su nombre
     * contiene. Así funciona aunque el proveedor la llame distinto:
     * GetBalance, ObtenerSaldo, BalanceInquiry…
     */
    /**
     * La operación para un propósito.
     * Primero lo que diga la configuración, luego la lista de preferencia,
     * y solo se devuelve si el WSDL de verdad la ofrece.
     */
    public function operacionPara($proposito)
    {
        $propia = trim((string)($this->cfg['op_' . $proposito] ?? ''));
        $ops = $this->operacionesDelXml();
        $hay = $ops['ok'] ? $ops['operaciones'] : [];

        if ($propia !== '') {
            return in_array($propia, $hay, true) || !$hay ? $propia : null;
        }
        foreach (self::OPERACIONES[$proposito] ?? [] as $n) {
            if (!$hay || in_array($n, $hay, true)) return $n;
        }
        return null;
    }

    /** Los nombres disponibles, para decirlos en un mensaje de error. */
    private function nombresDisponibles()
    {
        $o = $this->operacionesDelXml();
        return $o['ok'] ? implode(', ', $o['operaciones']) : ('no se pudieron leer: ' . $o['error']);
    }

    /**
     * El catálogo de productos del proveedor.
     *
     * POR QUÉ ESTO ERA LO QUE FALTABA
     *
     * Yo tenía un desplegable con "Telcel, Movistar, AT&T" y un monto
     * libre. Emida no funciona así: cada combinación de compañía y monto
     * es un PRODUCTO con su propio identificador. "Recarga Telcel $200"
     * es el 5077200, y mandar otra cosa da el código 51.
     *
     * Y hay mucho más que recargas: pago de agua, gas, gobierno,
     * tarjetas de regalo, internet. Unos son de monto fijo (Venta
     * Directa) y otros de monto variable que primero se consulta
     * (Consulta/Pago). Sin el catálogo no se puede vender ninguno.
     */
    public function catalogo()
    {
        if ($this->porProxy()) {
                 $script = $this->cfg['proxy_catalogo'] ?? 'get_products.php';
                 $r = $this->proxy($script, [
                 'terminal' => $this->cfg['terminal'] ?? '',
                 'clerk'    => $this->cfg['clerk']    ?? '',
               ]);
               
                if ($r['ok']) {
                $datos = is_array($r['datos']) ? $r['datos'] : self::deXml($r['crudo']);
                return ['ok' => true, 'via' => 'intermediario',
                'productos' => self::normalizar($datos)];
}
            // Un 404 aquí no es un fallo de red: es que ese script NO
            // existe en el intermediario. El sistema anterior solo subió
            // los de saldo, venta y consulta; el del catálogo nunca hizo
            // falta porque los productos se capturaban a mano.
            if (strpos($r['error'], '404') !== false) {
                return ['ok' => false, 'sin_script' => true, 'error' =>
                    'El intermediario no tiene el script del catálogo (' . $script . '). '
                  . 'Ahí solo están get_balance.php, pinDistSale.php y lookup_transaction.php. '
                  . 'Mientras lo suben, puedes pegar el catálogo a mano.'];
            }
            return $r;
        }
        try {
            $op = $this->operacionPara('productos');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de catálogo. Ofrece: '
                    . $this->nombresDisponibles()];
            }
            $r = $this->cliente()->__soapCall($op, [$this->base()]);
            return ['ok' => true, 'operacion' => $op, 'productos' => self::normalizar($r)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pone el catálogo en una forma estable.
     *
     * El proveedor devuelve nombres de campo distintos según la
     * operación y la versión. Se aceptan todos los que se han visto y se
     * traducen a uno solo, para que el resto del sistema no tenga que
     * saber cuál vino.
     */
    private static function normalizar($datos)
    {
        $lista = [];
        if (is_object($datos)) $datos = (array)$datos;
        if (is_array($datos)) {
            // A veces viene envuelto: {Products: {Product: [...]}}
            foreach (['Products','Product','productos','items','data'] as $k) {
                if (isset($datos[$k])) { $datos = $datos[$k]; break; }
            }
            if (is_object($datos)) $datos = (array)$datos;
            foreach ((array)$datos as $p) {
                $p = is_object($p) ? (array)$p : (array)$p;
                $id = self::campo($p, ['ProductId','productId','id','ProductID']);
                if ($id === null || $id === '') continue;
                $min = self::campo($p, ['MinAmount','minAmount','monto_min','MinimumAmount']);
                $max = self::campo($p, ['MaxAmount','maxAmount','monto_max','MaximumAmount']);
                $monto = self::campo($p, ['Amount','amount','monto','Price','FaceValue']);
                $lista[] = [
                    'producto_id' => (string)$id,
                    'nombre'      => (string)self::campo($p, ['ProductName','productName','nombre','Description','Name']),
                    'categoria'   => (string)self::campo($p, ['CategoryName','category','categoria','Category']),
                    'carrier'     => (string)self::campo($p, ['CarrierName','carrier','proveedor','Carrier']),
                    'comision'    => self::num(self::campo($p, ['Fee','fee','comision','UserFee'])),
                    'monto'       => self::num($monto),
                    'monto_min'   => self::num($min),
                    'monto_max'   => self::num($max),
                    // Monto fijo = se vende directo. Variable = primero
                    // se consulta cuánto debe el cliente.
                    'tipo'        => (self::num($min) > 0 || self::num($max) > 0 || self::num($monto) <= 0)
                                     ? 'consulta' : 'directa',
                ];
            }
        }
        return $lista;
    }

    /**
 * Convierte el XML que devuelve el intermediario a un array plano.
 *
 * El proxy responde el XML crudo  —el proveedor no habla JSON—,
 * así que aquí se recorre y se deja en la misma forma que ya entiende
 * normalizar(). Se ignoran las envolturas del sobre SOAP y se busca
 * la lista de productos donde esté, porque el nombre exacto de la
 * etiqueta cambia según el comando.
 */
private static function deXml($xml)
{
    if (!is_string($xml) || trim($xml) === '') return [];

    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($sx === false) return [];

    // El sobre SOAP mete todo dentro de Body, con su propio namespace.
    $cuerpo = $sx;
    $env = $sx->children('http://schemas.xmlsoap.org/soap/envelope/');
    if (isset($env->Body)) $cuerpo = $env->Body;

    $plano = json_decode(json_encode($cuerpo), true);
    return self::aLista($plano);
}

/**
 * Escarba un array anidado hasta encontrar una lista de productos.
 * Devuelve la primera lista de arrays asociativos que aparezca.
 */
private static function aLista($nodo, $profundidad = 0)
{
    if ($profundidad > 6 || !is_array($nodo)) return [];

    $esLista = true;
    foreach ($nodo as $k => $v) {
        if (is_int($k) && is_array($v)) continue;
        $esLista = false;
        break;
    }
    if ($esLista && $nodo) return array_values($nodo);

    foreach ($nodo as $v) {
        if (!is_array($v)) continue;
        $r = self::aLista($v, $profundidad + 1);
        if ($r) return $r;
    }
    return [];
}

    private static function campo(array $p, array $nombres)
    {
        foreach ($nombres as $n) {
            if (array_key_exists($n, $p) && $p[$n] !== null && $p[$n] !== '') return $p[$n];
        }
        return null;
    }

    private static function num($v)
    {
        if ($v === null || $v === '') return 0.0;
        return (float)preg_replace('/[^0-9.\-]/', '', (string)$v);
    }

    /** El saldo disponible con el proveedor. */
    public function saldo()
    {
        if ($this->porProxy()) {
            $r = $this->proxy($this->cfg['proxy_saldo'] ?? 'get_balance.php', [
                'username' => $this->cfg['usuario'] ?? '',
                'password' => $this->cfg['clave'] ?? '',
            ]);
            if (!$r['ok']) return $r;
            $d = $r['datos'];
            return ['ok' => true, 'via' => 'intermediario',
                    'saldo' => is_array($d)
                        ? ($d['balance'] ?? $d['Balance'] ?? $d['saldo'] ?? null)
                        : $d,
                    'crudo' => $d];
        }
        try {
            $op = $this->operacionPara('saldo');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de saldo. Las que ofrece son: '
                    . $this->nombresDisponibles()
                    . '. Dime cuál corresponde y la conecto.'];
            }
            $r = $this->cliente()->__soapCall($op, [$this->base()]);
            return ['ok' => true, 'saldo' => $r->Balance ?? $r->balance ?? $r->Amount ?? null,
                    'operacion' => $op, 'crudo' => $r];
        } catch (\SoapFault $e) {
            return ['ok' => false, 'error' => self::explicar(
                (string)($this->cfg['wsdl'] ?? ''), $e->getMessage())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Valida un número antes de cobrarle al cliente.
     *
     * Se consulta ANTES de aceptar el dinero. Si se cobrara primero y el
     * número resultara inválido, habría que devolver efectivo de una caja
     * que ya cuadró.
     */
    public function validar($numero, $productoId)
    {
        try {
            $op = $this->operacionPara('validar');
            if (!$op) {
                // Sin validación previa se puede seguir: es una protección,
                // no un requisito. Pero se avisa, porque cambia el riesgo.
                return ['ok' => true, 'sin_validar' => true];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
            ])]);
            return ['ok' => true, 'operacion' => $op, 'crudo' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ejecuta la recarga.
     *
     * `SalesId` es un identificador propio que el proveedor usa para
     * detectar duplicados: si la red se cae después de enviar y se
     * reintenta con el mismo, Emida devuelve 294 en vez de recargar dos
     * veces. Por eso lo recibe y no lo genera él.
     */
    public function recargar($numero, $productoId, $monto, $salesId)
    {
        if (!$this->cifrado()) {
            error_log('[LibertyFin] Emida sobre HTTP sin cifrar: ' . ($this->cfg['wsdl'] ?? ''));
        }

        if ($this->porProxy()) {
            $r = $this->proxy($this->cfg['proxy_venta'] ?? 'pinDistSale.php', [
                'username'  => $this->cfg['usuario'] ?? '',
                'password'  => $this->cfg['clave'] ?? '',
                'accountId' => $numero,
                'productId' => $productoId,
                'amount'    => number_format((float)$monto, 2, '.', ''),
                'salesId'   => $salesId,
            ]);
            if (!$r['ok']) return $r;
            $d = is_array($r['datos']) ? $r['datos'] : [];
            $resp = (string)($d['responseCode'] ?? $d['ResponseCode'] ?? '');
            $h2h  = (string)($d['h2hResultCode'] ?? $d['H2HResultCode'] ?? '');
            if ($resp === '' && $h2h === '') {
                return ['ok' => false, 'incierta' => true,
                        'error' => 'El intermediario respondió algo que no se entiende. '
                                 . 'La recarga PUDO haber salido: consulta el saldo antes de reintentar.',
                        'crudo' => $r['datos']];
            }
            if (self::exitosa($resp, $h2h)) {
                return ['ok' => true, 'via' => 'intermediario', 'duplicada' => $h2h === '294',
                        'folio' => $d['carrierControlNo'] ?? $d['transactionId'] ?? null,
                        'crudo' => $d];
            }
            return ['ok' => false, 'error' => self::mensaje($resp, $h2h),
                    'codigo' => $resp, 'h2h' => $h2h];
        }

        try {
            $op = $this->operacionPara('vender');
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de recarga. Las que ofrece son: '
                    . $this->nombresDisponibles()
                    . '. Dime cuál es la de vender y la conecto.'];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
                'Amount'    => number_format((float)$monto, 2, '.', ''),
                'SalesId'   => $salesId,
            ])]);

            $resp = (string)($r->ResponseCode ?? '');
            $h2h  = (string)($r->H2HResultCode ?? '');

            if (self::exitosa($resp, $h2h)) {
                return ['ok' => true, 'duplicada' => $h2h === '294',
                        'folio' => $r->CarrierControlNo ?? $r->TransactionId ?? null,
                        'crudo' => $r];
            }
            return ['ok' => false, 'error' => self::mensaje($resp, $h2h),
                    'codigo' => $resp, 'h2h' => $h2h];

        } catch (\SoapFault $e) {
            // Un timeout NO es un fallo: la recarga pudo haber salido. Se
            // avisa de otra forma para que nadie la reintente a ciegas.
            $esTimeout = stripos($e->getMessage(), 'timed out') !== false
                      || stripos($e->getMessage(), 'timeout') !== false;
            return ['ok' => false, 'incierta' => $esTimeout,
                    'error' => $esTimeout
                        ? 'El proveedor no respondió a tiempo. La recarga PUDO haber salido: '
                        . 'consulta el saldo antes de reintentar.'
                        : 'El proveedor respondió con un error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Según el proveedor: ResponseCode "00" y H2H "0".
     * El 294 también cuenta como éxito: es una recarga que ya se hizo,
     * no una que falló.
     */
    public static function exitosa($responseCode, $h2h)
    {
        return $responseCode === '00' && ($h2h === '0' || $h2h === '294');
    }

    public static function mensaje($responseCode, $h2h)
    {
        if (isset(self::ERRORES[$h2h]))          return self::ERRORES[$h2h];
        if (isset(self::ERRORES[$responseCode])) return self::ERRORES[$responseCode];
        return 'El proveedor rechazó la recarga (código ' . $responseCode . '/' . $h2h . ')';
    }
}
