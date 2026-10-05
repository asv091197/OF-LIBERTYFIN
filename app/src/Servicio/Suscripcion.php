<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;

/**
 * Estado de la suscripción para el aviso que se muestra en todo el sistema.
 *
 * El vencimiento vive en la base PRINCIPAL, no en la de la empresa, y el
 * aviso sale en cada pantalla. Preguntarlo en cada carga sería una consulta
 * más por página para un dato que cambia una vez al año; se guarda en la
 * sesión y se vuelve a leer cada diez minutos. Así, cuando se aprueba un
 * pago, el aviso desaparece solo poco después sin pedir que se vuelva a
 * entrar.
 */
final class Suscripcion
{
    /** Desde cuántos días antes de vencer se empieza a avisar. */
    const AVISAR_DESDE = 15;
    const REVISAR_CADA = 600;   // segundos

    /**
     * @return array|null ['dias' => int, 'fecha' => 'd/m/Y'] si hay que
     *                    avisar; null si no toca o no aplica.
     */
    public static function aviso()
    {
        // Solo quien puede hacer algo con él: pagar es de administrador.
        // Un cajero con el aviso no puede actuar y solo se preocupa.
        if (empty($_SESSION['empresa_id']) || !Permisos::puede('editar.empresa')) return null;

        $c = $_SESSION['lf_suscr'] ?? null;
        if (!$c || (time() - (int)($c['t'] ?? 0)) > self::REVISAR_CADA) {
            $c = ['t' => time(), 'venc' => null];
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
                $st = $principal->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
                $st->execute([(int)$_SESSION['empresa_id']]);
                $v = $st->fetchColumn();
                $c['venc'] = $v ?: null;
            } catch (\Throwable $e) {
                // Sin el dato, no se avisa: un aviso equivocado es peor
                // que ninguno.
                error_log('[LibertyFin] suscripcion: ' . $e->getMessage());
            }
            $_SESSION['lf_suscr'] = $c;
        }
        if (empty($c['venc'])) return null;

        $ts = strtotime($c['venc']);
        if (!$ts) return null;
        $dias = (int)floor(($ts - strtotime('today')) / 86400);
        if ($dias > self::AVISAR_DESDE) return null;

        return ['dias' => $dias, 'fecha' => date('d/m/Y', $ts)];
    }
}
