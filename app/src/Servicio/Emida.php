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

    /** ¿Se habla con Emida por un intermediario? */
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

        $http = ['timeout' => $seg, 'user_agent' => 'LibertyFin/1.0'];
        if ($usr !== '') {
            $http['header'] = "Authorization: Basic " . base64_encode($usr . ':' . $pwd);
        }

        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                      'allow_self_signed' => false],
            'http' => $http,
        ]);

        $opciones = [
            'trace'              => true,
            'exceptions'         => true,
            'cache_wsdl'         => !empty($this->cfg['sandbox']) ? WSDL_CACHE_NONE : WSDL_CACHE_DISK,
            'connection_timeout' => $seg,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'encoding'           => 'UTF-8',
            'stream_context'     => $ctx,
        ];
        if ($usr !== '') { $opciones['login'] = $usr; $opciones['password'] = $pwd; }

        try {
            return new \SoapClient($wsdl, $opciones);
        } catch (\SoapFault $e) {
            throw new \RuntimeException(self::explicar($wsdl, $e->getMessage()));
        }
    }

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

    public function probar()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        $r = [];

        $agrega = function (&$r, $que, $ok, $detalle, $bloquea = true) {
            $r[] = ['que' => $que, 'ok' => $ok, 'detalle' => $detalle, 'bloquea' => $bloquea];
        };

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

        if ($this->porProxy()) {
            $p = $this->proxy($this->cfg['proxy_saldo'] ?? 'get_balance.php', [
                'username' => $this->cfg['usuario'] ?? '',
                'password' => $this->cfg['clave'] ?? '',
            ]);
            $agrega($r, 'El intermediario responde', $p['ok'],
                $p['ok'] ? mb_substr(is_string($p['crudo']) ? $p['crudo'] : '', 0, 160)
                         : $p['error']);
        }

        $d = $this->bajarWsdl();
        $agrega($r, 'Descarga del WSDL', $d['ok'],
            $d['ok'] ? ($d['bytes'] . ' bytes de XML') : $d['error']);
        if (!$d['ok']) return $r;

        $ep = $this->endpoint();
        $mismoHost = $ep && parse_url($ep, PHP_URL_HOST) === parse_url($wsdl, PHP_URL_HOST);
        $agrega($r, 'A dónde manda las llamadas', (bool)$ep,
            $ep ? ($ep . ($mismoHost ? '' : '  ← host distinto al del WSDL'))
                : 'el WSDL no declara <soap:address>');

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

        $ops = $this->operacionesDelXml();
        if ($ops['ok']) {
            $n = $ops['operaciones'];
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

    private function base()
    {
        return [
            'UserId'         => $this->cfg['usuario'] ?? '',
            'Password'       => $this->cfg['clave'] ?? '',
            'MerchantId'     => $this->cfg['merchant_id'] ?? '',
            'ClerkPassword'  => $this->cfg['clerk_password'] ?? ($this->cfg['clave'] ?? ''),
        ];
    }

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

    public function endpoint()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return null;
        if (preg_match('/<(?:\w+:)?address\s+location=["\']([^"\']+)["\']/i', $d['xml'], $m)) {
            return $m[1];
        }
        return null;
    }

    public function operacionesDelXml()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return ['ok' => false, 'error' => $d['error']];
        preg_match_all('/<(?:\w+:)?operation\s+name=["\']([^"\']+)["\']/i', $d['xml'], $m);
        $nombres = array_values(array_unique($m[1] ?? []));
        return ['ok' => true, 'operaciones' => $nombres];
    }

    public function operaciones()
    {
        try {
            $fns = $this->cliente()->__getFunctions();
            $r = [];
            foreach ((array)$fns as $f) {
                if (preg_match('/\s(\w+)\(/', (string)$f, $m)) $r[$m[1]] = (string)$f;
            }
            return ['ok' => true, 'operaciones' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function tipos()
    {
        try { return ['ok' => true, 'tipos' => (array)$this->cliente()->__getTypes()]; }
        catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

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

    private function nombresDisponibles()
    {
        $o = $this->operacionesDelXml();
        return $o['ok'] ? implode(', ', $o['operaciones']) : ('no se pudieron leer: ' . $o['error']);
    }

    /**
     * El catálogo de productos del proveedor.
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
     * Quita la envoltura HTML del intermediario.
     *
     * EL INTERMEDIARIO DEVUELVE UNA PÁGINA DE DEBUG, NO XML
     *
     * En vez del sobre SOAP a secas, devuelve:
     *
     *   <h3>HTTP Code: 200</h3><h3>Respuesta del servidor:</h3>
     *   <pre>&lt;?xml version=&quot;1.0&quot; ...&gt;...&lt;/soapenv:Envelope&gt;
     *   </pre>
     *
     * SE HACE CON strpos, NO CON preg_match.
     *
     * El cuerpo pesa alrededor de 1 MB, y `.*?` no codicioso sobre ese
     * tamaño revienta el pcre.backtrack_limit de PHP (1 000 000 pasos
     * por defecto). Cuando eso pasa, preg_match NO devuelve 0: devuelve
     * false, y el código cree que no hay <pre> cuando sí lo hay. Por eso
     * el primer intento cayó al caso 2, arrastró el </pre> final al XML
     * y el parser falló con "Extra content at the end of the document".
     *
     * strpos y substr no tienen límite de backtracking: recorren el
     * string una vez y ya.
     */
    private static function desenvolver($cuerpo)
    {
        if (!is_string($cuerpo) || trim($cuerpo) === '') return '';

        // Caso 0 · ya es XML limpio.
        $ini = ltrim($cuerpo);
        if (strncmp($ini, '<?xml', 5) === 0
            || stripos($ini, '<soapenv:Envelope') === 0
            || stripos($ini, '<Envelope') === 0) {
            return $cuerpo;
        }

        // ¿Hay <pre>?
        $p = stripos($cuerpo, '<pre');
        if ($p === false) {
            // Caso 3 · escapado sin <pre>.
            if (strpos($cuerpo, '&lt;') !== false) {
                return html_entity_decode($cuerpo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $cuerpo;
        }

        // Saltar la etiqueta de apertura <pre ...> (o <pre>).
        $ini = strpos($cuerpo, '>', $p);
        if ($ini === false) return $cuerpo;
        $ini++;

        // Buscar el </pre>. Si no está, tomar hasta el final. El script
        // del intermediario se corta antes de cerrarlo.
        $fin = stripos($cuerpo, '</pre>', $ini);
        $trozo = $fin === false
            ? substr($cuerpo, $ini)
            : substr($cuerpo, $ini, $fin - $ini);

        return html_entity_decode($trozo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Convierte la respuesta del intermediario a una lista de productos.
     *
     * DOS ENVOLTORIOS, NO UNO
     *
     *  1. La página HTML del intermediario (<h3>…<pre>…). Se quita con
     *     desenvolver().
     *  2. El sobre SOAP del proveedor, donde el contenido real va como
     *     CADENA XML escapada dentro de <return>:
     *
     *        <return>&lt;ProductFlowInfoServiceResponse&gt;…
     *                &lt;Products&gt;&lt;Product&gt;…&lt;/Product&gt;…
     *
     *     Para SimpleXML ese <return> es un STRING, no un árbol. Hay que
     *     desescaparlo y parsearlo otra vez o los productos se quedan
     *     dentro de la cadena y se pierden.
     */
    private static function deXml($xml)
    {
        $xml = self::desenvolver($xml);
        if ($xml === '') return [];

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($sx === false) return [];

        // Sacar el <return> del sobre, sin pelearse con los namespaces
        // (local-name() ignora el prefijo, que cambia entre versiones).
        $ret = $sx->xpath('//*[local-name()="return"]');
        if ($ret && isset($ret[0])) {
            $prev = libxml_use_internal_errors(true);
            $interno = simplexml_load_string((string)$ret[0]);
            libxml_use_internal_errors($prev);

            if ($interno !== false) {
                // El proveedor manda su propio ResponseCode aquí dentro.
                // Se deja en el log para no perderlo.
                $rc = $interno->xpath('//*[local-name()="ResponseCode"]');
                $cod = $rc ? trim((string)$rc[0]) : '';
                if ($cod !== '' && $cod !== '00') {
                    $rm = $interno->xpath('//*[local-name()="ResponseMessage"]');
                    error_log('[LibertyFin] catálogo Emida: ResponseCode ' . $cod
                        . ' — ' . ($rm ? mb_substr((string)$rm[0], 0, 200) : 'sin mensaje'));
                    return [];
                }

                // Los productos viven en Products/Product.
                $prod = $interno->xpath('//*[local-name()="Products"]/*[local-name()="Product"]');
                if (!$prod) $prod = $interno->xpath('//*[local-name()="Product"]');
                if ($prod && count($prod) > 0) {
                    return json_decode(json_encode($prod), true);
                }
            }
        }

        // Sin <return>, se intenta como XML normal por si cambia el
        // formato. No estorba y evita romper si pasa.
        $cuerpo = $sx;
        $env = $sx->children('http://schemas.xmlsoap.org/soap/envelope/');
        if (isset($env->Body)) $cuerpo = $env->Body;
        return self::aLista(json_decode(json_encode($cuerpo), true));
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

    /**
     * Pone el catálogo en una forma estable.
     *
     * El proveedor devuelve nombres de campo distintos según la operación
     * y la versión. Se aceptan todos los que se han visto —incluidos los
     * que manda Emida hoy: ProductCategory, ProductUFee, FlowType,
     * AmountMin, AmountMax— y se traducen a uno solo, para que el resto
     * del sistema no tenga que saber cuál vino.
     */
    private static function normalizar($datos)
    {
        $lista = [];
        if (is_object($datos)) $datos = (array)$datos;
        if (is_array($datos)) {
            foreach (['Products','Product','productos','items','data'] as $k) {
                if (isset($datos[$k])) { $datos = $datos[$k]; break; }
            }
            if (is_object($datos)) $datos = (array)$datos;

            foreach ((array)$datos as $p) {
                $p = (array)$p;
                $id = self::campo($p, ['ProductId','productId','id','ProductID']);
                if ($id === null || $id === '') continue;

                $min   = self::campo($p, ['AmountMin','MinAmount','minAmount','monto_min','MinimumAmount']);
                $max   = self::campo($p, ['AmountMax','MaxAmount','maxAmount','monto_max','MaximumAmount']);
                $monto = self::campo($p, ['Amount','amount','monto','Price','FaceValue']);
                $flow  = strtoupper((string)self::campo($p, ['FlowType','flowType']));

                // FlowType decide el tipo sin ambigüedad:
                //   A = Venta Directa  → monto fijo, se vende tal cual
                //   B = Consulta/Pago  → monto variable, primero se consulta
                // Si no viene, se deduce: min/max > 0 o sin monto = variable.
                if ($flow === 'B')      $tipo = 'consulta';
                elseif ($flow === 'A')  $tipo = 'directa';
                else $tipo = (self::num($min) > 0 || self::num($max) > 0 || self::num($monto) <= 0)
                             ? 'consulta' : 'directa';

                $lista[] = [
                    'producto_id' => (string)$id,
                    'nombre'      => (string)self::campo($p, ['ProductName','productName','nombre','Description','Name']),
                    'categoria'   => (string)self::campo($p, ['ProductCategory','CategoryName','category','categoria','Category']),
                    'carrier'     => (string)self::campo($p, ['CarrierName','carrier','proveedor','Carrier']),
                    'comision'    => self::num(self::campo($p, ['ProductUFee','Fee','fee','comision','UserFee'])),
                    'monto'       => self::num($monto),
                    'monto_min'   => self::num($min),
                    'monto_max'   => self::num($max),
                    'tipo'        => $tipo,
                ];
            }
        }
        return $lista;
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