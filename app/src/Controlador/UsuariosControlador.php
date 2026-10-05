<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\EmpresaRepo;
use LibertyFin\Datos\PlanRepo;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Vista\Plantilla;

final class UsuariosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new UsuarioRepo($db);
        $this->soloAdmin();

        $editar = Peticion::entero('editar');
        Plantilla::pagina('usuarios/index', [
            'titulo'     => 'Usuarios',
            'icono'      => 'cliente',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            'usuarios'   => $repo->todos_(),
            'sucursales' => $repo->sucursales(),
            'editando'   => $editar ? $repo->uno_($editar) : null,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();

        $repo = new UsuarioRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) {
                $antes = $repo->porId($id);
                $repo->actualizar($id, $_POST);
                $m = 'Usuario actualizado.';
                // El cambio de ROL va aparte: es el único de esta pantalla
                // que cambia lo que esa persona puede hacer.
                if ($antes && ($antes['rol'] ?? '') !== ($_POST['rol'] ?? '')) {
                    Auditoria::anota('usuario.rol', $antes['nombre'],
                        $antes['rol'], $_POST['rol'] ?? '');
                } else {
                    Auditoria::anota('usuario.editar', $antes['nombre'] ?? ('usuario ' . $id));
                }
            } else {
                $repo->crear($_POST);
                $m = 'Usuario dado de alta.';
                Auditoria::anota('usuario.crear', trim($_POST['nombre'] ?? ''),
                    null, $_POST['rol'] ?? '');
            }
            $this->volver('/usuarios', $m, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar usuario: ' . $e->getMessage());
            // Un error de base de datos aquí casi siempre es de esquema, y
            // el mensaje genérico obliga a ir a buscar el log del servidor.
            $this->volver('/usuarios',
                'No se pudo guardar el usuario: ' . $e->getMessage(), 'error');
        }
    }

    /** Un administrador le pone contraseña nueva a otro. */
    public function restablecer()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $n = (new UsuarioRepo($db))->restablecerClave(
                (int)($_POST['id'] ?? 0), $_POST['clave'] ?? '');
            // La contraseña NO se registra, ni la vieja ni la nueva. Lo que
            // importa es quién la cambió y a quién; guardarla convertiría la
            // bitácora en el peor lugar donde buscar contraseñas.
            Auditoria::anota('usuario.clave', $n);
            $this->volver('/usuarios',
                'Contraseña de ' . $n . ' restablecida. Dísela en persona, no por escrito.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] restablecer: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo restablecer la contraseña.', 'error');
        }
    }

    public function alternar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $a = (new UsuarioRepo($db))->alternar(
                (int)($_POST['id'] ?? 0), $_SESSION['usuario_id'] ?? 0);
            Auditoria::anota('usuario.alternar', 'usuario ' . (int)($_POST['id'] ?? 0),
                $a ? 'inactivo' : 'activo', $a ? 'activo' : 'inactivo');
            $this->volver('/usuarios', $a ? 'Usuario activado.'
                : 'Usuario desactivado. Ya no puede entrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar usuario: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo cambiar el estado.', 'error');
        }
    }

    // ── Mi cuenta · sin rol de administrador ──

    const PESTANAS = [
        'perfil'    => 'Mi perfil',
        'plan'      => 'Plan',
        'fiscales'  => 'Datos fiscales',
        'comercio'  => 'Cobrar con tarjeta',
        'documentos'=> 'Documentos',
    ];

    public function miCuenta()
    {
        // Sin empresa no hay plan, ni datos fiscales, ni documentos: esas
        // pestañas son de una empresa y esta cuenta no pertenece a
        // ninguna. Le queda su perfil, que es lo que venía a ver.
        if (empty($_SESSION['empresa_db'])) { $this->miCuentaPlataforma(); return; }
        $db   = Conexion::de($_SESSION['empresa_db']);
        // Un cajero solo ve su perfil: lo fiscal y el alta de comercio
        // comprometen a la empresa entera.
        $suyas = \LibertyFin\Dominio\Permisos::puede('editar.empresa')
               ? self::PESTANAS : ['perfil' => self::PESTANAS['perfil']];
        $p = Peticion::opcion('t', array_keys($suyas), 'perfil');
        $foto = (new UsuarioRepo($db))->foto($_SESSION['usuario_id'] ?? 0);
        $_SESSION['lf_foto'] = $foto;

        $datos = [
            'titulo'    => 'Mi cuenta',
            'icono'     => 'cliente',
            'subtitulo' => $suyas[$p],
            'pestana'   => $p,
            'pestanas'  => $suyas,
            'foto'      => $foto,
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'empresa'   => null, 'fiscales' => [], 'comercio' => [],
            'documentos'=> [], 'estadoDocs' => [],
            'catalogo'  => null, 'pagosPlan' => [],
        ];

        $cuenta = new CuentaRepo($db);
        $cfg    = new ConfigRepo($db);

        // El estado de la documentación se muestra en TODAS las pestañas:
        // es lo que bloquea poder cobrar de verdad, y esconderlo en una
        // sola hace que se olvide.
        $datos['estadoDocs'] = $cuenta->estadoDocumentacion();

        if ($p === 'plan') {
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
                $datos['empresa'] = (new EmpresaRepo($principal))->uno($_SESSION['empresa_id'] ?? 0);

                // Contratar o renovar: el catálogo sale de config/planes.php
                // y los pagos de la base principal. Sin catálogo, la
                // pestaña sigue como antes (plan actual y "escríbenos").
                $datos['catalogo']  = PlanRepo::catalogo();
                $datos['pagosPlan'] = $datos['catalogo']
                    ? (new PlanRepo($principal))->deEmpresa($_SESSION['empresa_id'] ?? 0) : [];
            } catch (\Throwable $e) {
                error_log('[LibertyFin] plan: ' . $e->getMessage());
            }
        }
        if ($p === 'fiscales') {
            foreach (EmpresaRepo::FISCALES as $c) $datos['fiscales'][$c] = $cfg->valorDe('fiscal.' . $c, '');
        }
        if ($p === 'comercio')   $datos['comercio'] = $cuenta->comercio();
        if ($p === 'documentos') $datos['documentos'] = $cuenta->documentos();

        Plantilla::pagina('usuarios/cuenta', $datos);
        unset($_SESSION['lf_aviso']);
    }

    /** Datos fiscales. Viven en la base de la empresa. */
    public function guardarFiscales()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=fiscales');

        $rfc = mb_strtoupper(trim($_POST['rfc_fiscal'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            $this->volver('/cuenta?t=fiscales', 'El RFC no tiene el formato correcto.', 'error');
        }
        $cp = preg_replace('/\D/', '', (string)($_POST['cp_fiscal'] ?? ''));
        if ($cp !== '' && strlen($cp) !== 5) {
            $this->volver('/cuenta?t=fiscales', 'El código postal son 5 dígitos.', 'error');
        }
        $reg = trim($_POST['regimen_sat'] ?? '');
        if ($reg !== '' && !isset(CuentaRepo::REGIMENES[$reg])) {
            $this->volver('/cuenta?t=fiscales', 'Ese régimen fiscal no existe.', 'error');
        }

        try {
            $cfg = new ConfigRepo($db);
            $cfg->guardar('fiscal.tipo_persona', trim($_POST['tipo_persona'] ?? ''));
            $cfg->guardar('fiscal.rfc_fiscal',   $rfc);
            $cfg->guardar('fiscal.cp_fiscal',    $cp);
            $cfg->guardar('fiscal.razon_social', trim($_POST['razon_social'] ?? ''));
            $cfg->guardar('fiscal.regimen_sat',  $reg);
            $this->volver('/cuenta?t=fiscales', 'Datos fiscales guardados.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] fiscales: ' . $e->getMessage());
            $this->volver('/cuenta?t=fiscales', 'No se pudieron guardar.', 'error');
        }
    }

    /** El alta de comercio para procesar pagos. */
    public function guardarComercio()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=comercio');
        try {
            (new CuentaRepo($db))->guardarComercio($_POST, $_SESSION['usuario_id'] ?? 0);
            $this->volver('/cuenta?t=comercio', 'Datos de comercio guardados.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=comercio', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] comercio: ' . $e->getMessage());
            $this->volver('/cuenta?t=comercio', 'No se pudieron guardar.', 'error');
        }
    }

    /** La empresa elige un plan para pagar. */
    public function solicitarPlan()
    {
        if (empty($_SESSION['empresa_id'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta?t=plan');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $clave = (string)($_POST['plan'] ?? '');
            $periodo = ($_POST['periodo'] ?? '') === 'anual' ? 'anual' : 'mensual';
            (new PlanRepo($principal))->solicitar((int)$_SESSION['empresa_id'], $clave, $periodo);
            Auditoria::anota('plan.solicitar', $clave . ' · ' . $periodo, null, 'por pagar');
            $this->volver('/cuenta?t=plan',
                'Listo. Haz la transferencia con la referencia que aparece abajo y sube tu comprobante.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=plan', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] solicitar plan: ' . $e->getMessage());
            $this->volver('/cuenta?t=plan', 'No se pudo registrar la solicitud.', 'error');
        }
    }

    /** Sube el comprobante de la transferencia de un plan. */
    public function comprobantePlan()
    {
        if (empty($_SESSION['empresa_id'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $this->token('/cuenta?t=plan');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $ruta = \LibertyFin\Servicio\Archivos::documento($_FILES['archivo'] ?? [], 'pagoplan');
            $pago = (new PlanRepo($principal))->comprobante(
                (int)($_POST['id'] ?? 0), (int)$_SESSION['empresa_id'], $ruta);
            Auditoria::anota('plan.comprobante', $pago['referencia'] ?? '', null, 'en revisión');
            $this->volver('/cuenta?t=plan',
                'Recibimos tu comprobante. Lo revisamos en un día hábil y te avisamos aquí.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=plan', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] comprobante plan: ' . $e->getMessage());
            $this->volver('/cuenta?t=plan', 'No se pudo subir el comprobante.', 'error');
        }
    }

    /** Sube un documento para revisión. */
    public function subirDocumento()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta?t=documentos');
        $tipo = $_POST['tipo'] ?? '';
        try {
            $meta = [
                'nombre' => $_FILES['archivo']['name'] ?? null,
                'bytes'  => $_FILES['archivo']['size'] ?? null,
            ];
            $ruta = \LibertyFin\Servicio\Archivos::documento($_FILES['archivo'] ?? [], 'doc');
            $meta['mime'] = \LibertyFin\Servicio\Archivos::ultimoMime();
            (new CuentaRepo($db))->guardarDocumento($tipo, $ruta, $meta, $_SESSION['usuario_id'] ?? 0);
            $this->volver('/cuenta?t=documentos',
                'Documento subido. Un administrador de LibertyFin lo revisa en 24 a 72 horas.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta?t=documentos', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] documento: ' . $e->getMessage());
            $this->volver('/cuenta?t=documentos', 'No se pudo subir el documento.', 'error');
        }
    }

    public function cambiarClave()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta');
        try {
            (new UsuarioRepo($db))->cambiarClave(
                (int)($_SESSION['usuario_id'] ?? 0),
                $_POST['actual'] ?? '', $_POST['nueva'] ?? '');

            // Se cierra la sesión a propósito: si alguien cambió la
            // contraseña porque sospecha que se la sabían, dejar la sesión
            // viva no sirve de nada.
            Autenticar::salir();
            session_start();
            $_SESSION['lf_error'] = 'Contraseña cambiada. Entra de nuevo.';
            header('Location: /login'); exit;
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cambiarClave: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo cambiar la contraseña.', 'error');
        }
    }

    private function soloAdmin()
    {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            http_response_code(403);
            Plantilla::pagina('errores/404', ['titulo'=>'Sin permiso','icono'=>'alerta','subtitulo'=>'']);
            exit;
        }
    }

    private function token($destino = '/usuarios')
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver($destino, 'No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($destino, $texto, $tipo)
    {
        // Subida sin recargar: se contesta en JSON y NO se deja aviso en la
        // sesión, que si no saldría de nuevo en la siguiente carga.
        if (($_SERVER['HTTP_X_LF_AJAX'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $tipo === 'ok', 'texto' => $texto]);
            exit;
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $destino); exit;
    }

    /** Foto de perfil. Cada quien la suya, sin pedir permiso a nadie. */
    public function guardarFoto()
    {
        if (empty($_SESSION['empresa_db'])) {
            $this->volver('/cuenta', 'Esa acción es de una empresa y tu cuenta no pertenece a ninguna.', 'error');
        }
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta');
        $repo = new UsuarioRepo($db);
        $id   = (int)($_SESSION['usuario_id'] ?? 0);
        try {
            if (!empty($_POST['quitar'])) {
                $antes = $repo->foto($id);
                $repo->guardarFoto($id, '');
                unset($_SESSION['lf_foto']);
                if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
                $this->volver('/cuenta', 'Foto quitada.', 'ok');
            }
            $antes = $repo->foto($id);
            $ruta  = \LibertyFin\Servicio\Archivos::imagen($_FILES['foto'] ?? [], 'perfil');
            $repo->guardarFoto($id, $ruta);
            $_SESSION['lf_foto'] = $ruta;
            if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
            $this->volver('/cuenta', 'Foto actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] foto: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo guardar la foto.', 'error');
        }
    }

    /** Marca la guía como vista. De autoservicio: cada quien la suya. */
    public function guiaVista()
    {
        try {
            $db = Conexion::de($_SESSION['empresa_db']);
            (new UsuarioRepo($db))->marcarGuia($_SESSION['usuario_id'] ?? 0);
        } catch (\Throwable $e) { /* que no se marque es molesto, no grave */ }
        http_response_code(204);
        exit;
    }

    /** Volver a verla desde Mi cuenta. */
    public function verGuia()
    {
        $_SESSION['lf_mostrar_guia'] = true;
        header('Location: /'); exit;
    }

    /** Mi cuenta para un rol de plataforma: solo perfil y contraseña. */
    private function miCuentaPlataforma()
    {
        Plantilla::pagina('usuarios/cuenta', [
            'titulo'    => 'Mi cuenta',
            'icono'     => 'cliente',
            'subtitulo' => 'Mi perfil',
            'pestana'   => 'perfil',
            'pestanas'  => ['perfil' => 'Mi perfil'],
            'foto'      => '',
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
            'empresa'   => null, 'fiscales' => [], 'comercio' => [], 'documentos' => [],
            'estadoDocs'=> ['estado' => 'aprobada', 'faltan' => [], 'aprobados' => 0, 'total' => 0],
        ]);
        unset($_SESSION['lf_aviso']);
    }
}
