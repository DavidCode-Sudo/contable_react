<?php
declare(strict_types=1);

namespace Api\Controllers;

use Api\Core\Controller;
use PDO;
use Throwable;

class AsientosContablesController extends Controller
{
    private PDO $db;
    private static bool $tablasAseguradas = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        if (!self::$tablasAseguradas) {
            $this->asegurarTablasYTriggers();
            self::$tablasAseguradas = true;
        }
    }

    private function validarRolContable(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->jsonResponse(['success' => false, 'message' => "Autenticación requerida. Inicie sesión para continuar."], 401);
            exit;
        }

        try {
            $stmt = $this->db->prepare("SELECT rol FROM usuarios WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $rawRol = (string)$stmt->fetchColumn();
            $rol = strtolower(trim($rawRol));

            $rolesPermitidos = ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'tesorero'];

            if (!empty($rol) && !in_array($rol, $rolesPermitidos, true)) {
                $this->jsonResponse(['success' => false, 'message' => "Acceso denegado. Se requieren permisos contables para ejecutar esta acción."], 403);
                exit;
            }
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => "Error al verificar los permisos del usuario."], 500);
            exit;
        }

        return (int)$usuarioId;
    }

    private function asegurarTablasYTriggers(): void
    {
        try {
            // 1. Tabla secuencias_asientos
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `secuencias_asientos` (
                  `ejercicio` INT PRIMARY KEY,
                  `ultimo_numero` INT NOT NULL DEFAULT 0,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 2. Tabla asientos
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `asientos` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `numero` VARCHAR(100) NOT NULL UNIQUE COMMENT 'TMP-YYYY-XXXXXX en borrador, AS-YYYY-XXXXXX al confirmar',
                  `fecha` DATE NOT NULL,
                  `concepto` TEXT NOT NULL,
                  `tipo` ENUM('manual', 'automatico', 'cierre', 'ajuste') NOT NULL DEFAULT 'manual',
                  `estado` ENUM('borrador', 'confirmado', 'anulado') NOT NULL DEFAULT 'borrador',
                  `es_automatico` TINYINT(1) NOT NULL DEFAULT 0,
                  `origen` VARCHAR(100) NULL DEFAULT 'manual',
                  `origen_id` INT NULL DEFAULT NULL,
                  `asiento_anulacion_id` INT NULL DEFAULT NULL,
                  `total_debe` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
                  `total_haber` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
                  `usuario_id` INT NOT NULL DEFAULT 1,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  INDEX `idx_estado_fecha_id` (`estado`, `fecha`, `id`),
                  INDEX `idx_numero` (`numero`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Auto-migración defensiva de columnas Legacy a la Arquitectura Moderna
            try {
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `numero` VARCHAR(100) NULL AFTER `id`;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `concepto` TEXT NULL AFTER `fecha`;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `tipo` ENUM('manual', 'automatico', 'cierre', 'ajuste') NOT NULL DEFAULT 'manual';");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `total_debe` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `total_haber` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `documento` VARCHAR(255) NULL;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `tipo_ingreso` VARCHAR(100) NULL;");
                $this->db->exec("ALTER TABLE `asientos` ADD COLUMN IF NOT EXISTS `correlativo_ingreso` VARCHAR(100) NULL;");

                $this->db->exec("ALTER TABLE `detalles_asiento` ADD COLUMN IF NOT EXISTS `concepto` TEXT NULL;");
                $this->db->exec("ALTER TABLE `detalles_asiento` ADD COLUMN IF NOT EXISTS `moneda_origen` VARCHAR(10) NOT NULL DEFAULT 'VES';");
                $this->db->exec("ALTER TABLE `detalles_asiento` ADD COLUMN IF NOT EXISTS `monto_origen` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00;");
                $this->db->exec("ALTER TABLE `detalles_asiento` ADD COLUMN IF NOT EXISTS `tasa_cambio` DECIMAL(15,4) UNSIGNED NOT NULL DEFAULT 1.0000;");
                $this->db->exec("ALTER TABLE `detalles_asiento` ADD COLUMN IF NOT EXISTS `orden` INT NOT NULL DEFAULT 1;");

                // Migración automática de campos de texto legacy (descripcion -> concepto)
                $this->db->exec("UPDATE `asientos` SET `concepto` = `descripcion` WHERE (`concepto` IS NULL OR `concepto` = '') AND `descripcion` IS NOT NULL;");
                $this->db->exec("UPDATE `detalles_asiento` SET `concepto` = `descripcion` WHERE (`concepto` IS NULL OR `concepto` = '') AND `descripcion` IS NOT NULL;");
                $this->db->exec("UPDATE `asientos` SET `numero` = CONCAT('AS-', YEAR(COALESCE(`fecha`, CURRENT_DATE)), '-', LPAD(`id`, 6, '0')) WHERE `numero` IS NULL OR `numero` = '';");
            } catch (Throwable $e) {}

            // 3. Tabla detalles_asiento
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `detalles_asiento` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `asiento_id` INT NOT NULL,
                  `cuenta_id` INT NOT NULL,
                  `moneda_origen` VARCHAR(10) NOT NULL DEFAULT 'VES',
                  `monto_origen` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
                  `tasa_cambio` DECIMAL(15,4) UNSIGNED NOT NULL DEFAULT 1.0000,
                  `debe` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT 'Presentación en VES',
                  `haber` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00 COMMENT 'Presentación en VES',
                  `concepto` TEXT NULL,
                  `orden` INT NOT NULL DEFAULT 1,
                  FOREIGN KEY (`asiento_id`) REFERENCES `asientos`(`id`) ON DELETE CASCADE,
                  FOREIGN KEY (`cuenta_id`) REFERENCES `cuentas`(`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 4. Tabla saldos_cuentas_mensuales bimonetaria
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `saldos_cuentas_mensuales` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `cuenta_id` INT NOT NULL,
                  `ejercicio` INT NOT NULL,
                  `mes` TINYINT UNSIGNED NOT NULL COMMENT '1..12 ordinarios, 13 ajuste pre-cierre, 14 cierre definitivo',
                  `moneda` VARCHAR(10) NOT NULL DEFAULT 'VES',
                  `saldo_inicial_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Presentación Legal VES',
                  `debitos_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Presentación Legal VES',
                  `creditos_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Presentación Legal VES',
                  `saldo_final_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Presentación Legal VES',
                  `saldo_inicial_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Divisa Origen USD/EUR',
                  `debitos_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Divisa Origen USD/EUR',
                  `creditos_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Divisa Origen USD/EUR',
                  `saldo_final_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Divisa Origen USD/EUR',
                  UNIQUE KEY `uk_cuenta_periodo_moneda` (`cuenta_id`, `ejercicio`, `mes`, `moneda`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 5. Tabla periodos_contables
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `periodos_contables` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `ejercicio` INT NOT NULL,
                  `mes` TINYINT UNSIGNED NOT NULL COMMENT '1..12 ordinarios, 13 ajuste pre-cierre, 14 cierre definitivo',
                  `tipo_periodo` ENUM('ordinario', 'ajuste', 'cierre') NOT NULL DEFAULT 'ordinario',
                  `nombre` VARCHAR(100) NOT NULL,
                  `fecha_inicio` DATE NOT NULL,
                  `fecha_fin` DATE NOT NULL,
                  `estado` ENUM('abierto', 'cerrado') NOT NULL DEFAULT 'abierto',
                  `fecha_cierre` DATETIME NULL,
                  `cerrado_por` INT NULL,
                  UNIQUE KEY `uk_periodo_rango` (`fecha_inicio`, `fecha_fin`, `tipo_periodo`),
                  UNIQUE KEY `uk_ejercicio_mes` (`ejercicio`, `mes`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Triggers Defensivos Append-Only en Cabecera
            $this->db->exec("DROP TRIGGER IF EXISTS `trg_prevent_delete_asientos`;");
            $this->db->exec("
                CREATE TRIGGER `trg_prevent_delete_asientos` 
                BEFORE DELETE ON `asientos`
                FOR EACH ROW
                BEGIN
                    IF OLD.estado != 'borrador' THEN
                        SIGNAL SQLSTATE '45000' 
                        SET MESSAGE_TEXT = 'Inmutabilidad Financiera: Prohibida la eliminación física de asientos contables procesados o confirmados.';
                    END IF;
                END;
            ");

            $this->db->exec("DROP TRIGGER IF EXISTS `trg_prevent_update_asientos`;");
            $this->db->exec("
                CREATE TRIGGER `trg_prevent_update_asientos` 
                BEFORE UPDATE ON `asientos`
                FOR EACH ROW
                BEGIN
                    IF OLD.estado != 'borrador' THEN
                        IF NEW.fecha != OLD.fecha 
                           OR NEW.concepto != OLD.concepto 
                           OR NEW.total_debe != OLD.total_debe 
                           OR NEW.total_haber != OLD.total_haber
                           OR NEW.tipo != OLD.tipo 
                           OR NEW.origen != OLD.origen THEN
                            SIGNAL SQLSTATE '45000' 
                            SET MESSAGE_TEXT = 'Inmutabilidad Financiera: Violación detectada. Prohibida la alteración de datos financieros en comprobantes procesados.';
                        END IF;
                    END IF;
                END;
            ");

            // Triggers Defensivos Append-Only en Detalles
            $this->db->exec("DROP TRIGGER IF EXISTS `trg_prevent_delete_detalles_asiento`;");
            $this->db->exec("
                CREATE TRIGGER `trg_prevent_delete_detalles_asiento` 
                BEFORE DELETE ON `detalles_asiento`
                FOR EACH ROW
                BEGIN
                    DECLARE v_estado VARCHAR(20);
                    SELECT estado INTO v_estado FROM asientos WHERE id = OLD.asiento_id;
                    IF v_estado IS NOT NULL AND v_estado != 'borrador' THEN
                        SIGNAL SQLSTATE '45000' 
                        SET MESSAGE_TEXT = 'Inmutabilidad Financiera: Prohibida la eliminación física de renglones de asientos procesados.';
                    END IF;
                END;
            ");

            $this->db->exec("DROP TRIGGER IF EXISTS `trg_prevent_update_detalles_asiento`;");
            $this->db->exec("
                CREATE TRIGGER `trg_prevent_update_detalles_asiento` 
                BEFORE UPDATE ON `detalles_asiento`
                FOR EACH ROW
                BEGIN
                    DECLARE v_estado VARCHAR(20);
                    SELECT estado INTO v_estado FROM asientos WHERE id = OLD.asiento_id;
                    IF v_estado IS NOT NULL AND v_estado != 'borrador' THEN
                        SIGNAL SQLSTATE '45000' 
                        SET MESSAGE_TEXT = 'Inmutabilidad Financiera: Prohibida la modificación física de renglones de asientos procesados.';
                    END IF;
                END;
            ");
        } catch (Throwable $e) {
            // Silencioso si la BD no permite triggers o ya existen
        }
    }

    private function validarCuadrePartidaDoble(array $detalles): void
    {
        if (count($detalles) < 2) {
            throw new \InvalidArgumentException('Un asiento contable requiere al menos 2 renglones de movimiento en Partida Doble');
        }

        $totalDebe = 0.00;
        $totalHaber = 0.00;

        foreach ($detalles as $idx => $d) {
            $debe = (float)($d['debe'] ?? 0);
            $haber = (float)($d['haber'] ?? 0);

            if ($debe < 0 || $haber < 0) {
                throw new \InvalidArgumentException("El renglón N° " . ($idx + 1) . " contiene un monto negativo inválido.");
            }

            // Regla 11 Enterprise: OR Inclusivo ($debe > 0 || $haber > 0) para permitir integraciones automáticas y renglones de neteo
            if ($debe == 0 && $haber == 0) {
                throw new \InvalidArgumentException("El renglón N° " . ($idx + 1) . " debe poseer un valor positivo mayor a cero en el Debe o en el Haber.");
            }

            $totalDebe += $debe;
            $totalHaber += $haber;
        }

        if ($totalDebe <= 0 || $totalHaber <= 0) {
            throw new \InvalidArgumentException('El comprobante contable debe poseer un valor total estrictamente mayor a cero');
        }

        if (abs($totalDebe - $totalHaber) >= 0.01) {
            throw new \InvalidArgumentException(sprintf('Descuadre contable detectado: Total Debe (%s VES) != Total Haber (%s VES)', number_format($totalDebe, 2), number_format($totalHaber, 2)));
        }
    }

    public function obtenerCorrelativoIngreso(): void
    {
        $this->validarRolContable();

        try {
            $tipoIngresoKey = trim($_GET['tipo_ingreso'] ?? '');
            $fecha = $_GET['fecha'] ?? date('Y-m-d');
            $anio = (int)date('Y', strtotime($fecha));

            $mapaPrefijos = [
                'ingresos_propios' => ['prefijo' => 'ING-PRO', 'nombre' => 'Ingresos Propios'],
                'Ingresos Propios' => ['prefijo' => 'ING-PRO', 'nombre' => 'Ingresos Propios'],
                'transferencias_recibidas' => ['prefijo' => 'TRANS-REC', 'nombre' => 'Transferencias Recibidas'],
                'Transferencias Recibidas' => ['prefijo' => 'TRANS-REC', 'nombre' => 'Transferencias Recibidas'],
                'donaciones' => ['prefijo' => 'DON', 'nombre' => 'Donaciones'],
                'Donaciones' => ['prefijo' => 'DON', 'nombre' => 'Donaciones'],
                'otros_ingresos' => ['prefijo' => 'OTROS-ING', 'nombre' => 'Otros Ingresos'],
                'Otros Ingresos' => ['prefijo' => 'OTROS-ING', 'nombre' => 'Otros Ingresos'],
            ];

            if (empty($tipoIngresoKey) || !isset($mapaPrefijos[$tipoIngresoKey])) {
                $this->jsonResponse(['success' => false, 'message' => 'Tipo de ingreso no válido'], 400);
                return;
            }

            $info = $mapaPrefijos[$tipoIngresoKey];
            $prefijo = $info['prefijo'];
            $patron = "{$prefijo}-{$anio}-%";

            $stmt = $this->db->prepare("
                SELECT documento 
                FROM asientos 
                WHERE (tipo_ingreso = :tipo_ingreso OR documento LIKE :patron)
                  AND documento IS NOT NULL
                ORDER BY CAST(SUBSTRING(documento, -6) AS UNSIGNED) DESC 
                LIMIT 1
            ");
            $stmt->execute([
                ':tipo_ingreso' => $tipoIngresoKey,
                ':patron' => $patron
            ]);
            $ultimoNum = $stmt->fetchColumn();

            $secuencial = 1;
            if ($ultimoNum) {
                $partes = explode('-', (string)$ultimoNum);
                $ultimoSec = (int)end($partes);
                if ($ultimoSec > 0) {
                    $secuencial = $ultimoSec + 1;
                }
            }

            $numeroGenerado = sprintf("%s-%d-%06d", $prefijo, $anio, $secuencial);

            $this->jsonResponse([
                'success' => true,
                'prefijo' => $prefijo,
                'nombre' => $info['nombre'],
                'numero' => $numeroGenerado,
                'secuencial' => $secuencial
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function index(): void
    {
        $this->validarRolContable();

        try {
            $lastFecha = $_GET['last_fecha'] ?? null;
            $lastId = !empty($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $estado = $_GET['estado'] ?? null;
            $search = trim($_GET['q'] ?? '');

            $where = ["1=1"];
            $params = [];

            if ($estado) {
                $where[] = "a.estado = :estado";
                $params[':estado'] = $estado;
            }

            if (!empty($lastFecha) && $lastId > 0) {
                $where[] = "(a.fecha, a.id) < (:last_fecha, :last_id)";
                $params[':last_fecha'] = $lastFecha;
                $params[':last_id'] = $lastId;
            }

            if ($search !== '') {
                $where[] = "(a.numero LIKE :search OR COALESCE(a.concepto, '') LIKE :search OR COALESCE(a.descripcion, '') LIKE :search)";
                $params[':search'] = "%{$search}%";
            }

            $whereSql = implode(' AND ', $where);

            $sql = "
                SELECT a.*, 
                       COALESCE(a.concepto, a.descripcion, 'Asiento Contable') as concepto,
                       COALESCE(a.numero, CONCAT('AS-', YEAR(COALESCE(a.fecha, CURRENT_DATE)), '-', LPAD(a.id, 6, '0'))) as numero,
                       COALESCE(a.total_debe, a.total_debitos, 0.00) as total_debe,
                       COALESCE(a.total_haber, a.total_creditos, 0.00) as total_haber,
                       u.nombre_completo as usuario_nombre
                FROM asientos a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                WHERE {$whereSql}
                ORDER BY COALESCE(a.fecha, CURRENT_DATE) DESC, a.id DESC
                LIMIT {$limit}
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $asientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $lastItem = !empty($asientos) ? end($asientos) : null;

            $this->jsonResponse([
                'success' => true,
                'data' => [
                    'items' => $asientos,
                    'next_cursor' => $lastItem ? [
                        'last_fecha' => $lastItem['fecha'],
                        'last_id' => (int)$lastItem['id']
                    ] : null,
                    'has_more' => count($asientos) === $limit
                ]
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(int $id): void
    {
        $this->validarRolContable();
        try {
            $asiento = $this->obtenerAsientoCabecera($id);
            if (!$asiento) {
                $this->jsonResponse(['success' => false, 'message' => 'Comprobante no encontrado'], 404);
                return;
            }

            $detalles = $this->obtenerDetallesAsiento($id);

            $this->jsonResponse([
                'success' => true,
                'data' => array_merge($asiento, ['detalles' => $detalles])
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(): void
    {
        $usuarioId = $this->validarRolContable();

        try {
            $data = $this->jsonInput();

            $fecha = $data['fecha'] ?? date('Y-m-d');
            $concepto = trim($data['concepto'] ?? '');
            $tipo = $data['tipo'] ?? 'manual';
            $tipoIngreso = trim($data['tipo_ingreso'] ?? '');
            $documento = trim($data['documento'] ?? '');

            if (!empty($tipoIngreso) && strtolower($tipoIngreso) !== '-- seleccione tipo --') {
                $concepto .= " [Tipo: {$tipoIngreso}]";
            }
            if (!empty($documento)) {
                $concepto .= " [Doc: {$documento}]";
            }

            $esAutomatico = !empty($data['es_automatico']) ? 1 : 0;
            $origen = $data['origen'] ?? 'manual';
            $origenId = !empty($data['origen_id']) ? (int)$data['origen_id'] : null;
            $detalles = $data['detalles'] ?? [];

            if (empty($concepto)) {
                $this->jsonResponse(['success' => false, 'message' => 'El concepto del asiento es obligatorio'], 400);
                return;
            }

            $this->validarCuadrePartidaDoble($detalles);

            $ejercicio = (int)date('Y', strtotime($fecha));
            $tempNumero = sprintf("TMP-%d-%s", $ejercicio, strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));

            $totalDebe = 0.00;
            $totalHaber = 0.00;
            foreach ($detalles as $d) {
                $totalDebe += (float)($d['debe'] ?? 0);
                $totalHaber += (float)($d['haber'] ?? 0);
            }

            $this->db->beginTransaction();

            $stmtHeader = $this->db->prepare("
                INSERT INTO asientos (numero, fecha, concepto, tipo, estado, es_automatico, origen, origen_id, total_debe, total_haber, usuario_id)
                VALUES (?, ?, ?, ?, 'borrador', ?, ?, ?, ?, ?, ?)
            ");
            $stmtHeader->execute([
                $tempNumero, $fecha, $concepto, $tipo, $esAutomatico, $origen, $origenId, $totalDebe, $totalHaber, $usuarioId
            ]);
            $asientoId = (int)$this->db->lastInsertId();

            $stmtDet = $this->db->prepare("
                INSERT INTO detalles_asiento (asiento_id, cuenta_id, moneda_origen, monto_origen, tasa_cambio, debe, haber, concepto, orden)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $orden = 1;
            foreach ($detalles as $d) {
                $cId = (int)$d['cuenta_id'];
                $monedaOrigen = $d['moneda_origen'] ?? 'VES';
                $montoOrigen = (float)($d['monto_origen'] ?? 0);
                $tasaCambio = (float)($d['tasa_cambio'] ?? 1.0000);
                $debe = (float)($d['debe'] ?? 0);
                $haber = (float)($d['haber'] ?? 0);
                $conceptoDet = $d['concepto'] ?? $concepto;

                $stmtDet->execute([$asientoId, $cId, $monedaOrigen, $montoOrigen, $tasaCambio, $debe, $haber, $conceptoDet, $orden++]);
            }

            $this->db->commit();

            $this->jsonResponse([
                'success' => true,
                'message' => 'Borrador de asiento creado exitosamente',
                'data' => [
                    'id' => $asientoId,
                    'numero' => $tempNumero
                ]
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function update(int $id): void
    {
        $this->validarRolContable();

        try {
            $asiento = $this->obtenerAsientoCabecera($id);
            if (!$asiento) {
                $this->jsonResponse(['success' => false, 'message' => 'Comprobante no encontrado'], 404);
                return;
            }

            if ($asiento['estado'] !== 'borrador') {
                $this->jsonResponse(['success' => false, 'message' => 'Solo se pueden modificar asientos en estado BORRADOR'], 400);
                return;
            }

            $data = $this->jsonInput();
            $fecha = $data['fecha'] ?? $asiento['fecha'];
            $concepto = trim($data['concepto'] ?? $asiento['concepto']);
            $tipo = $data['tipo'] ?? $asiento['tipo'];
            $detalles = $data['detalles'] ?? [];

            if (!empty($detalles)) {
                $this->validarCuadrePartidaDoble($detalles);
            }

            $this->db->beginTransaction();

            $totalDebe = (float)$asiento['total_debe'];
            $totalHaber = (float)$asiento['total_haber'];

            if (!empty($detalles)) {
                $totalDebe = 0.00;
                $totalHaber = 0.00;
                foreach ($detalles as $d) {
                    $totalDebe += (float)($d['debe'] ?? 0);
                    $totalHaber += (float)($d['haber'] ?? 0);
                }

                $this->db->prepare("DELETE FROM detalles_asiento WHERE asiento_id = ?")->execute([$id]);

                $stmtDet = $this->db->prepare("
                    INSERT INTO detalles_asiento (asiento_id, cuenta_id, moneda_origen, monto_origen, tasa_cambio, debe, haber, concepto, orden)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $orden = 1;
                foreach ($detalles as $d) {
                    $stmtDet->execute([
                        $id,
                        (int)$d['cuenta_id'],
                        $d['moneda_origen'] ?? 'VES',
                        (float)($d['monto_origen'] ?? 0),
                        (float)($d['tasa_cambio'] ?? 1.0000),
                        (float)($d['debe'] ?? 0),
                        (float)($d['haber'] ?? 0),
                        $d['concepto'] ?? $concepto,
                        $orden++
                    ]);
                }
            }

            $stmtUpd = $this->db->prepare("
                UPDATE asientos 
                SET fecha = ?, concepto = ?, tipo = ?, total_debe = ?, total_haber = ? 
                WHERE id = ? AND estado = 'borrador'
            ");
            $stmtUpd->execute([$fecha, $concepto, $tipo, $totalDebe, $totalHaber, $id]);

            $this->db->commit();
            $this->jsonResponse(['success' => true, 'message' => 'Comprobante borrador actualizado exitosamente']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function confirmar(int $id): void
    {
        $this->validarRolContable();

        $db = $this->db;
        $db->beginTransaction();

        try {
            $asiento = $this->obtenerAsientoCabecera($id);
            if (!$asiento) {
                $this->jsonResponse(['success' => false, 'message' => 'Comprobante no encontrado'], 404);
                return;
            }

            if ($asiento['estado'] !== 'borrador') {
                $this->jsonResponse(['success' => false, 'message' => 'Solo se pueden confirmar comprobantes en estado BORRADOR'], 400);
                return;
            }

            $detalles = $this->obtenerDetallesAsiento($id);
            $this->validarCuadrePartidaDoble($detalles);

            $fecha = $asiento['fecha'];
            $ejercicio = (int)date('Y', strtotime($fecha));
            $tipoAsiento = $asiento['tipo'];

            // Regla 21: Mapeo de Mes Virtual (1..14)
            if ($tipoAsiento === 'cierre') {
                $mes = 14;
            } elseif ($tipoAsiento === 'ajuste' && date('m-d', strtotime($fecha)) === '12-31') {
                $mes = 13;
            } else {
                $mes = (int)date('n', strtotime($fecha));
            }

            // Regla 13: Validación Anti-Cierre
            $stmtCierre = $db->prepare("
                SELECT COUNT(*) FROM periodos_contables 
                WHERE ejercicio = ? AND mes >= ? AND estado = 'cerrado'
            ");
            $stmtCierre->execute([$ejercicio, $mes]);
            if ((int)$stmtCierre->fetchColumn() > 0) {
                $this->jsonResponse(['success' => false, 'message' => "Operación abortada: Existen períodos contables CERRADOS en el ejercicio {$ejercicio} posteriores o iguales a la fecha del asiento."], 400);
                return;
            }

            // Regla 2 y 3: Consumo Atómico de Secuencia Legal con Bloqueo Pessimistic (FOR UPDATE)
            $db->prepare("
                INSERT INTO secuencias_asientos (ejercicio, ultimo_numero)
                VALUES (?, 0)
                ON DUPLICATE KEY UPDATE ultimo_numero = ultimo_numero
            ")->execute([$ejercicio]);

            $stmtLock = $db->prepare("
                SELECT ultimo_numero FROM secuencias_asientos 
                WHERE ejercicio = ? FOR UPDATE
            ");
            $stmtLock->execute([$ejercicio]);
            $ultimoNum = (int)$stmtLock->fetchColumn();
            $correlativoNum = $ultimoNum + 1;

            $db->prepare("
                UPDATE secuencias_asientos 
                SET ultimo_numero = ? 
                WHERE ejercicio = ?
            ")->execute([$correlativoNum, $ejercicio]);

            $prefix = 'AS';
            if ($tipoAsiento === 'cierre') {
                $prefix = 'CI';
            } elseif ($tipoAsiento === 'ajuste') {
                $prefix = 'AJ';
            }
            $numeroOficial = sprintf("%s-%04d-%06d", $prefix, $ejercicio, $correlativoNum);

            // Actualizar Cabecera a CONFIRMADO con su número legal inmutable
            $stmtUpd = $db->prepare("
                UPDATE asientos 
                SET numero = ?, estado = 'confirmado', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUpd->execute([$numeroOficial, $id]);

            // Regla 18: Doble Upsert Bimonetario Atómico (VES Legal + Divisa Origen)
            $stmtUpsert = $db->prepare("
                INSERT INTO saldos_cuentas_mensuales 
                    (cuenta_id, ejercicio, mes, moneda, 
                     saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                     saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, 0.00, 0.00, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE 
                    debitos_base = debitos_base + VALUES(debitos_base),
                    creditos_base = creditos_base + VALUES(creditos_base),
                    debitos_origen = debitos_origen + VALUES(debitos_origen),
                    creditos_origen = creditos_origen + VALUES(creditos_origen)
            ");

            $cuentasIds = [];
            foreach ($detalles as $d) {
                $cId = (int)$d['cuenta_id'];
                $cuentasIds[] = $cId;

                $debeVES = (float)$d['debe'];
                $haberVES = (float)$d['haber'];
                $monedaOrigen = $d['moneda_origen'] ?? 'VES';
                $montoOrigen = (float)($d['monto_origen'] ?? 0.00);

                $debeOrigen = ($debeVES > 0) ? $montoOrigen : 0.00;
                $haberOrigen = ($haberVES > 0) ? $montoOrigen : 0.00;

                // 1. SIEMPRE en VES (Moneda Legal)
                $stmtUpsert->execute([$cId, $ejercicio, $mes, 'VES', $debeVES, $haberVES, $debeOrigen, $haberOrigen]);

                // 2. En Divisa Origen (si es USD/EUR)
                if ($monedaOrigen !== 'VES') {
                    $stmtUpsert->execute([$cId, $ejercicio, $mes, $monedaOrigen, $debeVES, $haberVES, $debeOrigen, $haberOrigen]);
                }
            }

            // Regla 9 & 28: Ripple Update Guiado por el Mapa Legal de Períodos
            $cuentasUnicas = array_values(array_unique($cuentasIds));
            $this->propagarRippleUpdateSaldosMensuales($db, $cuentasUnicas, $ejercicio, $mes);

            $db->commit();
            $this->jsonResponse(['success' => true, 'message' => "Asiento confirmado exitosamente con comprobante N° {$numeroOficial}"]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function anular(int $id, ?string $fechaReversionParam = null): void
    {
        $usuarioId = $this->validarRolContable();

        try {
            $data = $this->jsonInput();
            $fechaReversionParam = $data['fecha_reversion'] ?? $fechaReversionParam;
            $motivoParam = trim($data['motivo'] ?? '');

            if (empty($motivoParam)) {
                $this->jsonResponse(['success' => false, 'message' => 'El motivo de anulación es obligatorio por normas de auditoría'], 400);
                return;
            }

            $db = $this->db;
            $db->beginTransaction();

            $asientoOrig = $this->obtenerAsientoCabecera($id);
            if (!$asientoOrig) {
                $db->rollBack();
                $this->jsonResponse(['success' => false, 'message' => 'Comprobante original no encontrado'], 404);
                return;
            }

            // Regla 24: Guardia de Estado Estricta
            if ($asientoOrig['estado'] !== 'confirmado') {
                $db->rollBack();
                $this->jsonResponse([
                    'success' => false,
                    'message' => "Solo se pueden anular comprobantes en estado CONFIRMADO (Estado actual: {$asientoOrig['estado']})"
                ], 400);
                return;
            }

            $detallesOrig = $this->obtenerDetallesAsiento($id);

            $fechaReversion = !empty($fechaReversionParam) ? trim($fechaReversionParam) : date('Y-m-d');
            $ejercicioReversion = (int)date('Y', strtotime($fechaReversion));
            
            // Regla 21: Mapeo de Mes Virtual heredando el tipo del asiento original (13 para Ajuste, 14 para Cierre)
            $tipoOrig = $asientoOrig['tipo'];
            if ($tipoOrig === 'cierre') {
                $mesReversion = 14;
            } elseif ($tipoOrig === 'ajuste' && date('m-d', strtotime($fechaReversion)) === '12-31') {
                $mesReversion = 13;
            } else {
                $mesReversion = (int)date('n', strtotime($fechaReversion));
            }

            // Validar si el período de la reversión está cerrado
            $stmtCierre = $db->prepare("SELECT COUNT(*) FROM periodos_contables WHERE ejercicio = ? AND mes = ? AND estado = 'cerrado'");
            $stmtCierre->execute([$ejercicioReversion, $mesReversion]);
            if ((int)$stmtCierre->fetchColumn() > 0) {
                $db->rollBack();
                $this->jsonResponse(['success' => false, 'message' => "La fecha de reversión ({$fechaReversion}) pertenece a un período CERRADO"], 400);
                return;
            }

            // 1. Asiento de Reversión consume secuencia atómica oficial con bloqueo Pessimistic (FOR UPDATE)
            $db->prepare("
                INSERT INTO secuencias_asientos (ejercicio, ultimo_numero)
                VALUES (?, 0)
                ON DUPLICATE KEY UPDATE ultimo_numero = ultimo_numero
            ")->execute([$ejercicioReversion]);

            $stmtLockRev = $db->prepare("
                SELECT ultimo_numero FROM secuencias_asientos 
                WHERE ejercicio = ? FOR UPDATE
            ");
            $stmtLockRev->execute([$ejercicioReversion]);
            $ultimoNumRev = (int)$stmtLockRev->fetchColumn();
            $correlativoNumRev = $ultimoNumRev + 1;

            $db->prepare("
                UPDATE secuencias_asientos 
                SET ultimo_numero = ? 
                WHERE ejercicio = ?
            ")->execute([$correlativoNumRev, $ejercicioReversion]);

            $prefixRev = 'AS';
            if ($tipoOrig === 'cierre') {
                $prefixRev = 'CI';
            } elseif ($tipoOrig === 'ajuste') {
                $prefixRev = 'AJ';
            }
            $numeroReversion = sprintf("%s-%04d-%06d", $prefixRev, $ejercicioReversion, $correlativoNumRev);

            $conceptoReversion = "Reversión contable automática de comprobante N° {$asientoOrig['numero']} [Motivo: {$motivoParam}]: " . $asientoOrig['concepto'];

            $stmtInsHeader = $db->prepare("
                INSERT INTO asientos (numero, fecha, concepto, tipo, estado, es_automatico, origen, origen_id, total_debe, total_haber, usuario_id)
                VALUES (?, ?, ?, ?, 'confirmado', 1, 'reversion', ?, ?, ?, ?)
            ");
            $stmtInsHeader->execute([
                $numeroReversion,
                $fechaReversion,
                $conceptoReversion,
                $tipoOrig, // Hereda el tipo original (cierre, ajuste, manual)
                $id,
                $asientoOrig['total_haber'], // Invertido
                $asientoOrig['total_debe'],  // Invertido
                $usuarioId
            ]);
            $reversionId = (int)$db->lastInsertId();

            // Regla 19: Enlace Bidireccional
            $db->prepare("UPDATE asientos SET estado = 'anulado', asiento_anulacion_id = ? WHERE id = ?")->execute([$reversionId, $id]);

            // 2. Revertir renglones inmutables
            $stmtInsDet = $db->prepare("
                INSERT INTO detalles_asiento (asiento_id, cuenta_id, moneda_origen, monto_origen, tasa_cambio, debe, haber, concepto, orden)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($detallesOrig as $d) {
                $stmtInsDet->execute([
                    $reversionId,
                    $d['cuenta_id'],
                    $d['moneda_origen'],
                    $d['monto_origen'],
                    $d['tasa_cambio'],
                    $d['haber'], // Invertido
                    $d['debe'],  // Invertido
                    "Reversión: " . ($d['concepto'] ?? ''),
                    $d['orden']
                ]);
            }

            // 3. Paso 0 Upsert Bimonetario para la Reversión
            $stmtUpsert = $db->prepare("
                INSERT INTO saldos_cuentas_mensuales 
                    (cuenta_id, ejercicio, mes, moneda, 
                     saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                     saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, 0.00, 0.00, ?, ?, 0.00)
                ON DUPLICATE KEY UPDATE 
                    debitos_base = debitos_base + VALUES(debitos_base),
                    creditos_base = creditos_base + VALUES(creditos_base),
                    debitos_origen = debitos_origen + VALUES(debitos_origen),
                    creditos_origen = creditos_origen + VALUES(creditos_origen)
            ");

            $cuentasIds = [];
            foreach ($detallesOrig as $d) {
                $cId = (int)$d['cuenta_id'];
                $cuentasIds[] = $cId;

                $debeVES = (float)$d['haber']; // Reversión invertida
                $haberVES = (float)$d['debe'];
                $monedaOrigen = $d['moneda_origen'] ?? 'VES';
                $montoOrigen = (float)($d['monto_origen'] ?? 0.00);

                $debeOrigen = ($debeVES > 0) ? $montoOrigen : 0.00;
                $haberOrigen = ($haberVES > 0) ? $montoOrigen : 0.00;

                $stmtUpsert->execute([$cId, $ejercicioReversion, $mesReversion, 'VES', $debeVES, $haberVES, $debeOrigen, $haberOrigen]);

                if ($monedaOrigen !== 'VES') {
                    $stmtUpsert->execute([$cId, $ejercicioReversion, $mesReversion, $monedaOrigen, $debeVES, $haberVES, $debeOrigen, $haberOrigen]);
                }
            }

            // 4. Ripple Update para la Reversión
            $cuentasUnicas = array_values(array_unique($cuentasIds));
            $this->propagarRippleUpdateSaldosMensuales($db, $cuentasUnicas, $ejercicioReversion, $mesReversion);

            $db->commit();
            $this->jsonResponse(['success' => true, 'message' => "Comprobante anulado exitosamente con contra-asiento N° {$numeroReversion}"]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function propagarRippleUpdateSaldosMensuales(PDO $db, array $cuentasIds, int $ejercicio, int $mesInicio): void
    {
        sort($cuentasIds, SORT_NUMERIC);

        // Regla 28: Mapa Legal de Períodos
        $stmtMap = $db->prepare("SELECT mes FROM periodos_contables WHERE ejercicio = ? AND mes >= ? ORDER BY mes ASC");
        $stmtMap->execute([$ejercicio, $mesInicio]);
        $mesesValidos = $stmtMap->fetchAll(PDO::FETCH_COLUMN);

        if (empty($mesesValidos)) {
            // Política Fail-Closed: Bloqueo de seguridad si el ejercicio fiscal no tiene un calendario de períodos aperturado
            throw new \RuntimeException("📌 DIAGNÓSTICO: Operación Abortada. No existe un calendario de períodos contables aperturado para el ejercicio {$ejercicio}. 💡 DETALLE: El sistema impide la autogeneración de saldos en meses inexistentes sin apertura fiscal legal. 🔧 ACCIÓN REQUERIDA: Ingrese al módulo de Períodos Contables y aperture el ejercicio {$ejercicio}.");
        }

        foreach ($cuentasIds as $cuentaId) {
            $stmtNat = $db->prepare("SELECT naturaleza, codigo FROM cuentas WHERE id = ?");
            $stmtNat->execute([$cuentaId]);
            $cuentaInfo = $stmtNat->fetch(PDO::FETCH_ASSOC);

            $esAcreedora = ($cuentaInfo['naturaleza'] ?? 'deudora') === 'acreedora';
            $esCuentaReal = in_array(substr((string)($cuentaInfo['codigo'] ?? '1'), 0, 1), ['1', '2', '3'], true);

            $stmtMon = $db->prepare("SELECT DISTINCT moneda FROM saldos_cuentas_mensuales WHERE cuenta_id = ? AND ejercicio = ?");
            $stmtMon->execute([$cuentaId, $ejercicio]);
            $monedas = $stmtMon->fetchAll(PDO::FETCH_COLUMN) ?: ['VES'];

            foreach ($monedas as $moneda) {
                foreach ($mesesValidos as $m) {
                    $stmtIns = $db->prepare("
                        INSERT INTO saldos_cuentas_mensuales (cuenta_id, ejercicio, mes, moneda, 
                            saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                            saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen)
                        VALUES (?, ?, ?, ?, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00)
                        ON DUPLICATE KEY UPDATE id = id
                    ");
                    $stmtIns->execute([$cuentaId, $ejercicio, (int)$m, $moneda]);
                }

                $inMeses = implode(',', array_map('intval', $mesesValidos));
                $stmt = $db->prepare("
                    SELECT mes, debitos_base, creditos_base, debitos_origen, creditos_origen 
                    FROM saldos_cuentas_mensuales 
                    WHERE cuenta_id = ? AND ejercicio = ? AND moneda = ? AND mes IN ({$inMeses})
                    ORDER BY mes ASC 
                    FOR UPDATE
                ");
                $stmt->execute([$cuentaId, $ejercicio, $moneda]);
                $periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $saldoAcumuladoBase = 0.00;
                $saldoAcumuladoOrigen = 0.00;

                if ($mesInicio > 1) {
                    $stmtAnt = $db->prepare("
                        SELECT saldo_final_base, saldo_final_origen 
                        FROM saldos_cuentas_mensuales 
                        WHERE cuenta_id = ? AND ejercicio = ? AND moneda = ? AND mes < ? 
                        ORDER BY mes DESC LIMIT 1
                    ");
                    $stmtAnt->execute([$cuentaId, $ejercicio, $moneda, $mesInicio]);
                    $rowAnt = $stmtAnt->fetch(PDO::FETCH_ASSOC);
                    if ($rowAnt) {
                        $saldoAcumuladoBase = (float)$rowAnt['saldo_final_base'];
                        $saldoAcumuladoOrigen = (float)$rowAnt['saldo_final_origen'];
                    }
                } else {
                    if ($esCuentaReal) {
                        $stmtAntAnio = $db->prepare("
                            SELECT saldo_final_base, saldo_final_origen 
                            FROM saldos_cuentas_mensuales 
                            WHERE cuenta_id = ? AND ejercicio = ? AND moneda = ? 
                            ORDER BY mes DESC LIMIT 1
                        ");
                        $stmtAntAnio->execute([$cuentaId, $ejercicio - 1, $moneda]);
                        $rowAntAnio = $stmtAntAnio->fetch(PDO::FETCH_ASSOC);
                        if ($rowAntAnio) {
                            $saldoAcumuladoBase = (float)$rowAntAnio['saldo_final_base'];
                            $saldoAcumuladoOrigen = (float)$rowAntAnio['saldo_final_origen'];
                        }
                    }
                }

                $upd = $db->prepare("
                    UPDATE saldos_cuentas_mensuales 
                    SET saldo_inicial_base = ?, saldo_final_base = ?,
                        saldo_inicial_origen = ?, saldo_final_origen = ?
                    WHERE cuenta_id = ? AND ejercicio = ? AND mes = ? AND moneda = ?
                ");

                foreach ($periodos as $p) {
                    $debBase = (float)$p['debitos_base'];
                    $credBase = (float)$p['creditos_base'];
                    $debOrig = (float)$p['debitos_origen'];
                    $credOrig = (float)$p['creditos_origen'];

                    if ($esAcreedora) {
                        $finBase = $saldoAcumuladoBase + ($credBase - $debBase);
                        $finOrig = $saldoAcumuladoOrigen + ($credOrig - $debOrig);
                    } else {
                        $finBase = $saldoAcumuladoBase + ($debBase - $credBase);
                        $finOrig = $saldoAcumuladoOrigen + ($debOrig - $credOrig);
                    }

                    $upd->execute([$saldoAcumuladoBase, $finBase, $saldoAcumuladoOrigen, $finOrig, $cuentaId, $ejercicio, (int)$p['mes'], $moneda]);

                    $saldoAcumuladoBase = $finBase;
                    $saldoAcumuladoOrigen = $finOrig;
                }

                if ($esCuentaReal && !empty($periodos)) {
                    $stmtSig = $db->prepare("
                        SELECT id FROM saldos_cuentas_mensuales 
                        WHERE cuenta_id = ? AND ejercicio = ? AND moneda = ? AND mes = 1 
                        FOR UPDATE
                    ");
                    $stmtSig->execute([$cuentaId, $ejercicio + 1, $moneda]);
                    if ($stmtSig->fetchColumn()) {
                        $this->propagarRippleUpdateSaldosMensuales($db, [$cuentaId], $ejercicio + 1, 1);
                    }
                }
            }
        }
    }

    private function obtenerAsientoCabecera(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM asientos WHERE id = ?");
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    private function obtenerDetallesAsiento(int $asientoId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, c.codigo as cuenta_codigo, c.nombre as cuenta_nombre, c.naturaleza as cuenta_naturaleza
            FROM detalles_asiento d
            JOIN cuentas c ON d.cuenta_id = c.id
            WHERE d.asiento_id = ?
            ORDER BY d.orden ASC, d.id ASC
        ");
        $stmt->execute([$asientoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
