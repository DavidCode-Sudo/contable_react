<?php
declare(strict_types=1);

namespace Api\Controllers;

use Api\Core\Controller;
use PDO;
use Throwable;

class ConfiguracionCuentasController extends Controller
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function validarRolAdmin(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->errorResponse("Sesión no iniciada. Acceso denegado.", 401);
        }
    }

    private function asegurarTabla(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `configuracion_cuentas_sistema` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `concepto` varchar(100) NOT NULL,
                  `cuenta_codigo` varchar(50) NOT NULL,
                  `descripcion` text DEFAULT NULL,
                  `activa` tinyint(1) NOT NULL DEFAULT 1,
                  `fecha_actualizacion` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_concepto` (`concepto`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Sembrar registros semilla por defecto si está vacía
            $count = (int)$this->db->query("SELECT COUNT(*) FROM configuracion_cuentas_sistema")->fetchColumn();
            if ($count === 0) {
                $semillas = [
                    ['cuenta_caja_general', '1.1.1.01.01.00', 'Caja General para fondos en efectivo', 1],
                    ['cuenta_banco_principal', '1.1.1.02.01.00', 'Banco Principal Pagador', 1],
                    ['cuenta_gastos_personal', '6.1.1.01.01.00', 'Gastos de Personal - Sueldos y Salarios', 1],
                    ['cuenta_retencion_sso', '2.1.2.01.01.00', 'Retención SSO por Pagar', 1],
                    ['cuenta_retencion_lph', '2.1.2.01.02.00', 'Retención FAOV / LPH por Pagar', 1],
                    ['cuenta_retencion_islr', '2.1.2.02.01.00', 'Retención ISLR por Pagar', 1],
                    ['cuenta_retencion_iva', '2.1.2.02.02.00', 'Retención IVA por Pagar', 1],
                    ['cuenta_donaciones_inventario', '3.1.2.01.01.00', 'Patrimonio por Donaciones de Inventario', 1],
                    ['cuenta_sobrantes_inventario', '5.1.2.01.01.00', 'Ingresos Extraordinarios por Sobrantes de Inventario', 1],
                    ['cuenta_apertura_patrimonio', '3.1.1.01.01.00', 'Patrimonio Institucional / Apertura de Inventario', 1],
                ];
                $stmtInst = $this->db->prepare("INSERT IGNORE INTO configuracion_cuentas_sistema (concepto, cuenta_codigo, descripcion, activa) VALUES (?, ?, ?, ?)");
                foreach ($semillas as $s) {
                    $stmtInst->execute($s);
                }
            }
        } catch (Throwable $e) {
            // Silencioso si ya existe
        }
    }

    public function index(): void
    {
        $this->validarRolAdmin();
        $this->asegurarTabla();

        try {
            $stmt = $this->db->query("SELECT * FROM configuracion_cuentas_sistema ORDER BY id ASC");
            $configuraciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $codigos = array_values(array_unique(array_filter(array_map(function($item) {
                return trim((string)($item['cuenta_codigo'] ?? ''));
            }, $configuraciones))));

            $mapaCuentas = [];
            if (!empty($codigos)) {
                $placeholders = implode(',', array_fill(0, count($codigos), '?'));
                $params = array_merge($codigos, $codigos);
                
                $sqlCuentas = "
                    SELECT id, codigo, codigo_completo, nombre 
                    FROM cuentas 
                    WHERE codigo IN ({$placeholders}) OR codigo_completo IN ({$placeholders})
                ";
                $stmtCuentas = $this->db->prepare($sqlCuentas);
                $stmtCuentas->execute($params);
                $cuentasData = $stmtCuentas->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cuentasData as $c) {
                    $nombre = $c['nombre'] ?? '';
                    $idCuenta = (int)$c['id'];
                    if (!empty($c['codigo'])) {
                        $mapaCuentas[trim((string)$c['codigo'])] = ['id' => $idCuenta, 'nombre' => $nombre];
                    }
                    if (!empty($c['codigo_completo'])) {
                        $mapaCuentas[trim((string)$c['codigo_completo'])] = ['id' => $idCuenta, 'nombre' => $nombre];
                    }
                }
            }

            foreach ($configuraciones as &$item) {
                $cod = trim((string)($item['cuenta_codigo'] ?? ''));
                $item['cuenta_nombre'] = $mapaCuentas[$cod]['nombre'] ?? null;
                $item['cuenta_id'] = $mapaCuentas[$cod]['id'] ?? null;
                $item['activa'] = (int)($item['activa'] ?? 1);
            }
            unset($item);

            $this->jsonResponse([
                'items' => $configuraciones,
                'total' => count($configuraciones)
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar configuración de cuentas: ' . $e->getMessage(), 500);
        }
    }

    public function update(string $id): void
    {
        $this->validarRolAdmin();
        $this->asegurarTabla();
        $configId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $cuentaCodigo = trim($data['cuenta_codigo'] ?? '');
        $descripcion = trim($data['descripcion'] ?? '');
        $activa = (isset($data['activa']) && $data['activa'] === false) ? 0 : 1;

        if (empty($cuentaCodigo)) {
            $this->errorResponse('El código de cuenta es obligatorio.', 422);
        }

        try {
            $stmt = $this->db->prepare("UPDATE configuracion_cuentas_sistema SET cuenta_codigo = ?, descripcion = ?, activa = ? WHERE id = ?");
            $stmt->execute([$cuentaCodigo, $descripcion, $activa, $configId]);

            $this->jsonResponse(['mensaje' => 'Configuración de cuenta actualizada exitosamente']);
        } catch (Throwable $e) {
            $this->errorResponse('Error al actualizar configuración: ' . $e->getMessage(), 500);
        }
    }

    public function crearFaltantes(): void
    {
        $this->validarRolAdmin();
        $this->asegurarTabla();

        $creadas = 0;
        $errores = 0;
        $detalles = [];

        try {
            $stmt = $this->db->query("
                SELECT ccs.cuenta_codigo, ccs.descripcion 
                FROM configuracion_cuentas_sistema ccs 
                WHERE ccs.activa = 1 AND ccs.cuenta_codigo IS NOT NULL AND ccs.cuenta_codigo != ''
            ");
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($filas as $fila) {
                $codigo = trim((string)($fila['cuenta_codigo'] ?? ''));
                $descripcion = trim((string)($fila['descripcion'] ?? ''));
                if ($codigo === '') continue;

                $stmtCheck = $this->db->prepare("
                    SELECT id FROM cuentas 
                    WHERE (codigo = ? OR codigo_completo = ?)
                ");
                $stmtCheck->execute([$codigo, $codigo]);
                
                if (!$stmtCheck->fetch()) {
                    try {
                        $primerDigito = substr($codigo, 0, 1);
                        $tipo = 'activo';
                        $naturaleza = 'deudora';

                        if ($primerDigito === '2') {
                            $tipo = 'pasivo';
                            $naturaleza = 'acreedora';
                        } elseif ($primerDigito === '3') {
                            $tipo = 'patrimonio';
                            $naturaleza = 'acreedora';
                        } elseif ($primerDigito === '5') {
                            $tipo = 'ingreso';
                            $naturaleza = 'acreedora';
                        } elseif ($primerDigito === '6') {
                            $tipo = 'gasto';
                            $naturaleza = 'deudora';
                        }

                        $nombreCuenta = !empty($descripcion) ? $descripcion : ("Cuenta de Sistema " . $codigo);

                        $stmtInst = $this->db->prepare("
                            INSERT INTO cuentas (codigo, codigo_completo, nombre, tipo, naturaleza, estado, es_partida_presupuestaria) 
                            VALUES (?, ?, ?, ?, ?, 'activa', 0)
                        ");
                        $stmtInst->execute([$codigo, $codigo, $nombreCuenta, $tipo, $naturaleza]);
                        $creadas++;
                    } catch (Throwable $ex) {
                        $errores++;
                        $detalles[] = "Error en {$codigo}: " . $ex->getMessage();
                    }
                }
            }

            $this->jsonResponse([
                'mensaje' => "Proceso completado: {$creadas} cuenta(s) creada(s), {$errores} error(es).",
                'creadas' => $creadas,
                'errores' => $errores,
                'detalles' => $detalles
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error crítico en el proceso de auto-sanación: ' . $e->getMessage(), 500);
        }
    }
}
