<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\AutenticacionRepo;
use LibertyFin\Servicio\Migraciones;
use PDO;

/**
 * Ingreso al sistema.
 *
 * Decisiones, y por qué:
 *
 * · El mensaje de error es el MISMO si el usuario no existe o si la
 *   contraseña está mal. Distinguirlos le regala a quien prueba una lista
 *   de usuarios válidos.
 *
 * · Se espera un tiempo mínimo aunque el usuario no exista. Sin eso, la
 *   diferencia de milisegundos entre "no existe" y "existe pero clave
 *   mala" delata cuáles son reales.
 *
 * · La sesión se regenera al entrar. Si no, quien logre fijar un id de
 *   sesión antes del ingreso se queda dentro después.
 *
 * · Cinco intentos y quince minutos de espera, por sesión.
 */
final class Autenticar
{
    const INTENTOS_MAX = 5;
    const ESPERA       = 900;   // 15 minutos
    const VIDA_SESION  = 28800; // 8 horas

    private $repo;
    public function __construct(PDO $principal) { $this->repo = new AutenticacionRepo($principal); }

    /** Segundos que faltan de castigo, o 0 si puede intentar. */
    public static function bloqueoRestante()
    {
        if (empty($_SESSION['lf_intentos']) || $_SESSION['lf_intentos'] < self::INTENTOS_MAX) return 0;
        $pasado = time() - ($_SESSION['lf_ultimo_intento'] ?? 0);
        if ($pasado >= self::ESPERA) {
            $_SESSION['lf_intentos'] = 0;
            return 0;
        }
        return self::ESPERA - $pasado;
    }

    /** @throws \RuntimeException con un mensaje apto para mostrar */
    public function entrar($identificador, $clave)
    {
        $espera = self::bloqueoRestante();
        if ($espera > 0) {
            throw new \RuntimeException(
                'Demasiados intentos. Vuelve a probar en ' . ceil($espera / 60) . ' minutos.');
        }

        $inicio = microtime(true);

        // Primero la tabla de plataforma. Soporte, validación y
        // superadministración no pertenecen a ninguna empresa, así que no
        // se buscan entre sus usuarios.
        $plataforma = $this->repo->usuarioPlataforma($identificador);
        if ($plataforma) {
            $ok = password_verify((string)$clave, (string)$plataforma['password']);
            $gastado = microtime(true) - $inicio;
            if ($gastado < 0.35) usleep((int)((0.35 - $gastado) * 1000000));
            if (!$ok) {
                $_SESSION['lf_intentos'] = ($_SESSION['lf_intentos'] ?? 0) + 1;
                $_SESSION['lf_ultimo_intento'] = time();
                throw new \RuntimeException('Usuario o contraseña incorrectos.');
            }
            if (empty($plataforma['activo'])) {
                throw new \RuntimeException('Esta cuenta está desactivada.');
            }
            return $this->abrirPlataforma($plataforma);
        }

        $hallazgo = $this->repo->buscar($identificador);
        $ok = $hallazgo && password_verify((string)$clave, (string)$hallazgo['usuario']['password']);

        // Mismo tiempo para todos los casos.
        $gastado = microtime(true) - $inicio;
        if ($gastado < 0.35) usleep((int)((0.35 - $gastado) * 1000000));

        if (!$ok) {
            $_SESSION['lf_intentos'] = ($_SESSION['lf_intentos'] ?? 0) + 1;
            $_SESSION['lf_ultimo_intento'] = time();
            $faltan = self::INTENTOS_MAX - $_SESSION['lf_intentos'];
            throw new \RuntimeException('Usuario o contraseña incorrectos.'
                . ($faltan > 0 && $faltan <= 2 ? ' Quedan ' . $faltan . ' intentos.' : ''));
        }

        $empresa = $hallazgo['empresa'];
        $usuario = $hallazgo['usuario'];

        if (!$empresa['activo']) {
            throw new \RuntimeException('La cuenta de ' . $empresa['nombre_empresa'] . ' está inactiva.');
        }
        // Una suscripción vencida YA NO impide entrar: antes la persona
        // se quedaba afuera sin forma de pagar, justo cuando más lo
        // necesitaba. Entra, pero el portero (public/index.php) la deja
        // solo en Mi cuenta → Plan, o en un aviso si no puede pagar.

        $sucursal = $this->repo->sucursal($empresa['nombre_base_datos'], $usuario['sucursal_id']);

        // Id nuevo: lo anterior de esta sesión deja de servir.
        session_regenerate_id(true);
        $_SESSION = [
            'logged_in'        => true,
            'login_time'       => time(),
            'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? '',
            'empresa_id'       => (int)$empresa['id'],
            'empresa_db'       => $empresa['nombre_base_datos'],
            'empresa_nombre'   => $empresa['nombre_empresa'],
            'empresa_plan'     => $empresa['plan'] ?? 'prueba',
            'usuario_id'       => (int)$usuario['id'],
            'usuario_nombre'   => $usuario['nombre'] ?: $usuario['username'],
            'usuario_rol'      => $usuario['rol'],
            'sucursal_id'      => $usuario['sucursal_id'] ? (int)$usuario['sucursal_id'] : null,
            'sucursal_nombre'  => $sucursal['nombre'] ?? 'Matriz',
            'usuario_email'    => $usuario['email'] ?? '',
        ];

        // La personalización se lee una vez al entrar y vive en la sesión:
        // el armazón la pinta en cada página y consultarla cada vez sería
        // una consulta más por petición para un dato que casi nunca cambia.

        // Los interruptores globales se leen UNA vez al entrar y viven en
        // la sesión: los consulta cada página y cada carga de menú, y
        // preguntarle a la base principal cada vez sería una consulta más
        // por petición para un dato que cambia dos veces al año.
        try {
            $_SESSION['lf_global'] = (new \LibertyFin\Datos\AjustesPlataformaRepo(
                \LibertyFin\Datos\Conexion::de($GLOBALS['lf_bd_principal'] ?? '')))->paraSesion();
        } catch (\Throwable $e) { $_SESSION['lf_global'] = []; }

        try {
            $db = \LibertyFin\Datos\Conexion::de($empresa['nombre_base_datos']);

            // La base se pone al día sola al entrar.
            //
            // LibertyFin crea una base por empresa, y el esquema vive como
            // CREATE TABLE dentro de registroEmpresa.php. Sin esto, una
            // empresa creada hoy nace sin las columnas que agregó la última
            // versión, y falla con un error que ninguna otra empresa tiene.
            //
            // En una base al día cuesta una consulta.
            if (!Migraciones::alDia($db)) Migraciones::aplicar($db);
            // Se recuerda con qué versión de CÓDIGO se entró. Si después
            // se sube una actualización, quien ya tenía sesión abierta
            // seguiría usando columnas que su base no tiene. Guardarlo
            // permite notarlo sin una consulta por petición.
            $_SESSION['lf_esquema'] = Migraciones::VERSION;

            $cfg = new \LibertyFin\Datos\ConfigRepo($db);
            $color = (string)$cfg->valorDe('marca.color', '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $_SESSION['lf_marca_color'] = $color;
            $logo = (string)$cfg->valorDe('marca.logo', '');
            if ($logo) $_SESSION['lf_marca_logo'] = $logo;
            $ur = new \LibertyFin\Datos\UsuarioRepo($db);
            $foto = $ur->foto($usuario['id']);
            if ($foto) $_SESSION['lf_foto'] = $foto;
            // La guía se muestra una sola vez, en el primer ingreso.
            if (!$ur->vioGuia($usuario['id'])) $_SESSION['lf_mostrar_guia'] = true;
        } catch (\Throwable $e) { /* sin personalización se ve el tema base */ }

        return $_SESSION;
    }

    /**
     * ¿La sesión sigue siendo válida?
     * El navegador tiene que ser el mismo. La IP NO se exige: en México
     * cambia sola al saltar de wifi a datos, y echar a la gente por eso
     * genera más llamadas a soporte que ataques evitados. Se anota y ya.
     */
    public static function sesionValida()
    {
        if (empty($_SESSION['logged_in'])) return false;
        // Un usuario de empresa sin base es una sesión rota; uno de
        // plataforma no tiene base y es lo normal.
        if (empty($_SESSION['plataforma']) && empty($_SESSION['empresa_db'])) return false;
        if (time() - ($_SESSION['login_time'] ?? 0) > self::VIDA_SESION) return false;
        if (($_SESSION['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) return false;
        if (($_SESSION['ip_address'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            error_log('[LibertyFin] la IP cambió para el usuario ' . ($_SESSION['usuario_id'] ?? '?'));
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        return true;
    }

    public static function salir()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Abre la sesión de un usuario de plataforma.
     *
     * NO lleva `empresa_db`: no pertenece a ninguna. Todo lo que este
     * rol usa —tickets, empresas, conocimiento— vive en la base
     * principal, y para mirar dentro de una empresa entra por su ficha,
     * que abre esa base explícitamente y dice de quién son los datos.
     */
    private function abrirPlataforma(array $u)
    {
        session_regenerate_id(true);
        unset($_SESSION['lf_intentos'], $_SESSION['lf_ultimo_intento']);

        $_SESSION['logged_in']      = true;
        $_SESSION['plataforma']     = true;
        $_SESSION['usuario_id']     = (int)$u['id'];
        $_SESSION['usuario_nombre'] = $u['nombre'];
        $_SESSION['usuario_rol']    = $u['rol'];
        $_SESSION['usuario_email']  = $u['email'] ?? '';
        $_SESSION['empresa_nombre'] = 'LibertyFin';
        $_SESSION['sucursal_nombre']= 'Plataforma';
        $_SESSION['login_time']     = time();
        self::recordar();
        $_SESSION['user_agent']     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // Sin empresa: es la marca de que estas claves NO deben existir.
        unset($_SESSION['empresa_db'], $_SESSION['empresa_id'], $_SESSION['sucursal_id']);


        // Los interruptores globales se leen UNA vez al entrar y viven en
        // la sesión: los consulta cada página y cada carga de menú, y
        // preguntarle a la base principal cada vez sería una consulta más
        // por petición para un dato que cambia dos veces al año.
        try {
            $_SESSION['lf_global'] = (new \LibertyFin\Datos\AjustesPlataformaRepo(
                \LibertyFin\Datos\Conexion::de($GLOBALS['lf_bd_principal'] ?? '')))->paraSesion();
        } catch (\Throwable $e) { $_SESSION['lf_global'] = []; }

        $this->repo->marcarAccesoPlataforma((int)$u['id']);
        return true;
    }

    /**
     * "Recordar sesión en este dispositivo".
     *
     * Alarga la cookie de sesión a 30 días en vez de que muera al cerrar
     * el navegador. NO guarda la contraseña ni crea un token aparte: lo
     * que dura más es la sesión, y sigue cayéndose sola si cambia el
     * navegador o pasa el tiempo de inactividad.
     *
     * Es menos potente que un "recordarme" con token persistente, y a
     * propósito: ese token es una segunda llave que hay que guardar,
     * rotar y poder revocar, y en un sistema donde se cobra dinero no
     * vale la pena a cambio de no volver a escribir la contraseña.
     */
    private static function recordar()
    {
        $quiere = !empty($_POST['recordar']);
        $dias = 30;

        if ($quiere) {
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $dias * 86400,
                'path'     => $p['path'] ?: '/',
                'domain'   => $p['domain'] ?? '',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // Solo para dejar la casilla marcada la próxima vez. No
            // contiene nada que sirva para entrar.
            setcookie('lf_recordar', '1', [
                'expires' => time() + $dias * 86400, 'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax',
            ]);
            $_SESSION['lf_recordado'] = true;
        } else {
            setcookie('lf_recordar', '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }
}
