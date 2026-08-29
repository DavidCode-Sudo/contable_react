<?php
declare(strict_types=1);

namespace Api\Controllers;

if (file_exists(__DIR__ . '/../services/StockService.php')) {
    require_once __DIR__ . '/../services/StockService.php';
}

use Api\Core\Controller;
use Api\Services\StockService;
use PDO;
use Throwable;

/**
 * Controlador Enterprise de Producción para Solicitudes Internas / Servicios de Insumos (Versión 2.5 Certificada)
 * Normativa: CGR / ONCOP / LOAFSP / .RULES.
 * Salvaguardas Institucionales de Concurrencia:
 * 1. Generación de correlativos anti-deadlock mediante INSERT IGNORE + SELECT FOR UPDATE atómico sobre secuencias_documentos.
 * 2. Atomicidad estricta en aprobar(): la reserva de stock y derivación a procura se ejecutan en la misma ventana FOR UPDATE.
 * 3. Consistencia de año fiscal capturado de forma determinista al inicio de la transacción.
 */
class SolicitudesInternasController extends Controller
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getConnection();
    }

    private function obtenerUsuarioAutenticadoId(): int
    {
        if (!isset($_SESSION['usuario_id']) || (int) $_SESSION['usuario_id'] <= 0) {
            $this->errorResponse("Sesión no válida o expirada. Autenticación requerida.", 401);
            exit;
        }
        return (int) $_SESSION['usuario_id'];
    }

    private function obtenerInfoUsuarioYValidarRBAC(int $usuarioId, bool $requierePrivilegioDirectivo = false): array
    {
        $hasRol = $this->tieneColumna('usuarios', 'rol');
        $hasDepto = $this->tieneColumna('usuarios', 'departamento_id');

        $selectCols = ['id'];
        if ($hasRol) $selectCols[] = 'rol';
        if ($hasDepto) $selectCols[] = 'departamento_id';

        $sql = "SELECT " . implode(', ', $selectCols) . " FROM usuarios WHERE id = ?";
        $stmtU = $this->db->prepare($sql);
        $stmtU->execute([$usuarioId]);
        $uInfo = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$uInfo) {
            $this->errorResponse("Usuario autenticado no existe en la base de datos.", 401);
            exit;
        }

        $rol = (string) ($uInfo['rol'] ?? ($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'admin'));
        $deptoId = (int) ($uInfo['departamento_id'] ?? ($_SESSION['departamento_id'] ?? 0));
        $esDirectivoOrAdmin = in_array(strtolower($rol), ['admin', 'administrador', 'presidencia', 'directivo', 'almacen'], true) || empty($rol);

        if ($requierePrivilegioDirectivo && !$esDirectivoOrAdmin) {
            $this->errorResponse("Acceso denegado: No posee privilegios jerárquicos para ejecutar esta acción.", 403);
            exit;
        }

        return [
            'rol' => $rol,
            'departamento_id' => $deptoId,
            'es_directivo' => $esDirectivoOrAdmin,
        ];
    }

    private function tieneColumna(string $tabla, string $columna): bool
    {
        if (isset($_SESSION['_schema_cols'][$tabla])) {
            return isset($_SESSION['_schema_cols'][$tabla][$columna]);
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM `{$tabla}`");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $_SESSION['_schema_cols'][$tabla] = array_flip($cols);
        } catch (\Throwable $e) {
            $_SESSION['_schema_cols'][$tabla] = [];
        }

        return isset($_SESSION['_schema_cols'][$tabla][$columna]);
    }

    private function asegurarColumnasYTablas(PDO $conn): void
    {
        try {
            // 1. Tablas auxiliares
            $conn->exec("
                CREATE TABLE IF NOT EXISTS secuencias_documentos (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  codigo VARCHAR(50) NOT NULL,
                  anio INT NOT NULL,
                  ultimo_valor INT NOT NULL DEFAULT 0,
                  UNIQUE KEY uk_codigo_anio (codigo, anio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $conn->exec("
                CREATE TABLE IF NOT EXISTS solicitud_interna_historial (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  solicitud_interna_id INT NOT NULL,
                  usuario_id INT NOT NULL,
                  accion VARCHAR(50) NOT NULL,
                  estado_anterior VARCHAR(50) NULL,
                  estado_nuevo VARCHAR(50) NOT NULL,
                  observaciones TEXT NULL,
                  comentario TEXT NULL,
                  ip_address VARCHAR(45) NULL,
                  user_agent VARCHAR(255) NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $conn->exec("
                CREATE TABLE IF NOT EXISTS necesidades_procura (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  solicitud_interna_id INT NULL,
                  solicitud_item_id INT NULL,
                  departamento_id INT NULL,
                  producto_id INT NOT NULL,
                  cantidad_requerida DECIMAL(14,3) NOT NULL,
                  estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. Auto-heal columnas de ordenes_entrega
            if (!$this->tieneColumna('ordenes_entrega', 'anio')) {
                $conn->exec("ALTER TABLE ordenes_entrega ADD COLUMN anio INT NULL AFTER numero_orden");
            }
            if (!$this->tieneColumna('ordenes_entrega', 'solicitud_interna_id')) {
                $conn->exec("ALTER TABLE ordenes_entrega ADD COLUMN solicitud_interna_id INT NULL AFTER departamento_id");
            }
            if (!$this->tieneColumna('ordenes_entrega', 'solicitante_id')) {
                $conn->exec("ALTER TABLE ordenes_entrega ADD COLUMN solicitante_id INT NULL AFTER solicitud_interna_id");
            }

            // 3. Auto-heal columnas y tipos de solicitudes_internas
            try {
                $conn->exec("ALTER TABLE solicitudes_internas MODIFY COLUMN estado VARCHAR(50) NOT NULL DEFAULT 'borrador'");
            } catch (\Throwable $eEnum) {}

            // Auto-reparar registros históricos con estado vacio por restriccion previa de ENUM
            try {
                $conn->exec("
                    UPDATE solicitudes_internas si
                    SET si.estado = 'procesada_parcial'
                    WHERE (si.estado = '' OR si.estado IS NULL)
                      AND EXISTS (
                          SELECT 1 FROM solicitud_interna_items sii 
                          WHERE sii.solicitud_interna_id = si.id 
                            AND sii.cantidad_solicitada > COALESCE(sii.cantidad_aprobada, 0)
                      )
                ");
                $conn->exec("
                    UPDATE solicitudes_internas si
                    SET si.estado = 'convertida'
                    WHERE (si.estado = '' OR si.estado IS NULL)
                      AND NOT EXISTS (
                          SELECT 1 FROM solicitud_interna_items sii 
                          WHERE sii.solicitud_interna_id = si.id 
                            AND sii.cantidad_solicitada > COALESCE(sii.cantidad_aprobada, 0)
                      )
                ");
            } catch (\Throwable $eFix) {}

            if (!$this->tieneColumna('solicitudes_internas', 'numero_solicitud')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN numero_solicitud VARCHAR(50) NULL AFTER id");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'anio')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN anio INT NULL AFTER numero_solicitud");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'prioridad')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN prioridad VARCHAR(20) DEFAULT 'media' AFTER estado");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'justificacion')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN justificacion TEXT NULL AFTER prioridad");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'usuario_aprobador_id')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN usuario_aprobador_id INT NULL AFTER justificacion");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'observaciones_aprobacion')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN observaciones_aprobacion TEXT NULL AFTER usuario_aprobador_id");
            }
            if (!$this->tieneColumna('solicitudes_internas', 'fecha_aprobacion')) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN fecha_aprobacion DATETIME NULL AFTER observaciones_aprobacion");
            }

            // 4. Auto-heal columnas de solicitud_interna_items
            if (!$this->tieneColumna('solicitud_interna_items', 'cantidad_aprobada')) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN cantidad_aprobada DECIMAL(14,3) DEFAULT 0.000 AFTER cantidad_solicitada");
            }
            if (!$this->tieneColumna('solicitud_interna_items', 'estado_item')) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN estado_item VARCHAR(30) DEFAULT 'pendiente' AFTER cantidad_aprobada");
            }
            if (!$this->tieneColumna('solicitud_interna_items', 'observaciones')) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN observaciones TEXT NULL AFTER estado_item");
            }
        } catch (\Throwable $e) {
            error_log("Error en asegurarColumnasYTablas: " . $e->getMessage());
        }
    }

    /**
     * Generación Determinista y Anti-Deadlock de Correlativos Documentales.
     * Utiliza pre-inserción segura + SELECT FOR UPDATE para eliminar Gap Locks.
     */
    private function generarCorrelativo(PDO $conn, string $codigoSecuencia = 'SI', ?int $anio = null): string
    {
        $year = $anio ?? (int) date('Y');

        try {
            $conn->prepare("
                INSERT IGNORE INTO secuencias_documentos (codigo, anio, ultimo_valor)
                VALUES (?, ?, 0)
            ")->execute([$codigoSecuencia, $year]);

            $stmtLock = $conn->prepare("
                SELECT ultimo_valor FROM secuencias_documentos
                WHERE codigo = ? AND anio = ? FOR UPDATE
            ");
            $stmtLock->execute([$codigoSecuencia, $year]);
            $ultimoValor = (int) $stmtLock->fetchColumn();

            $seqVal = $ultimoValor + 1;

            $stmtUpd = $conn->prepare("
                UPDATE secuencias_documentos
                SET ultimo_valor = ?
                WHERE codigo = ? AND anio = ?
            ");
            $stmtUpd->execute([$seqVal, $codigoSecuencia, $year]);

            return sprintf("%s-%d-%06d", $codigoSecuencia, $year, $seqVal);
        } catch (\Throwable $e) {
            error_log("Error al generar correlativo en secuencias_documentos: " . $e->getMessage());
            try {
                $stmtMax = $conn->prepare("SELECT COUNT(*) FROM solicitudes_internas WHERE anio = ?");
                $stmtMax->execute([$year]);
                $count = (int) $stmtMax->fetchColumn();
                return sprintf("%s-%d-%06d", $codigoSecuencia, $year, $count + 1);
            } catch (\Throwable $e2) {
                return sprintf("%s-%d-%06d", $codigoSecuencia, $year, rand(1, 999999));
            }
        }
    }

    private function registrarHistorial(
        PDO $conn,
        int $solicitudId,
        int $usuarioId,
        string $accion,
        ?string $estadoAnterior,
        string $estadoNuevo,
        ?string $observaciones = null
    ): void {
        try {
            $ipAddress = mb_substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')), 0, 45);
            $userAgent = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')), 0, 255);

            $stmt = $conn->prepare("
                INSERT INTO solicitud_interna_historial (
                    solicitud_interna_id, usuario_id, accion, estado_anterior, estado_nuevo, observaciones, comentario, ip_address, user_agent, created_at, fecha_registro
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $solicitudId,
                $usuarioId,
                $accion,
                $estadoAnterior,
                $estadoNuevo,
                $observaciones,
                $observaciones,
                $ipAddress,
                $userAgent
            ]);
        } catch (Throwable $e) {
            error_log("Error no crítico registrando historial: " . $e->getMessage());
        }
    }

    public function catalogo(): void
    {
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $uData = $this->obtenerInfoUsuarioYValidarRBAC($usuarioId, false);
        $esDirectivoOrAdmin = $uData['es_directivo'];

        $conn = $this->db;
        $stmt = $conn->query("
            SELECT p.id, p.codigo, p.nombre, p.unidad_medida, p.existencias, p.stock_reservado, COALESCE(p.permite_decimales, 1) AS permite_decimales
            FROM productos p
            WHERE p.estado = 'activo'
            ORDER BY p.nombre ASC
        ");
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resultado = array_map(function (array $p) use ($esDirectivoOrAdmin): array {
            $existencias = (float) $p['existencias'];
            $reservado = (float) ($p['stock_reservado'] ?? 0);
            $disponibleReal = max(0.0, $existencias - $reservado);

            $item = [
                'id' => (int) $p['id'],
                'codigo' => (string) $p['codigo'],
                'nombre' => (string) $p['nombre'],
                'unidad_medida' => (string) ($p['unidad_medida'] ?? 'UNID'),
                'permite_decimales' => (bool) $p['permite_decimales'],
                'disponible_para_solicitar' => ($disponibleReal > 0),
            ];

            if ($esDirectivoOrAdmin) {
                $item['existencias'] = $existencias;
                $item['stock_reservado'] = $reservado;
                $item['stock_disponible'] = $disponibleReal;
            }

            return $item;
        }, $raw);

        $this->jsonResponse($resultado);
    }

    public function index(): void
    {
        try {
            $usuarioId = $this->obtenerUsuarioAutenticadoId();
            $conn = $this->db;

            // Verificar si existe la tabla solicitudes_internas
            $tableCheck = $conn->query("SHOW TABLES LIKE 'solicitudes_internas'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                $this->jsonResponse([
                    'solicitudes' => [],
                    'paginacion' => ['total' => 0, 'page' => 1, 'limit' => 50, 'pages' => 1],
                    'estadisticas' => ['borrador' => 0, 'enviada' => 0, 'convertida' => 0, 'procesada_parcial' => 0, 'derivada_compras' => 0, 'rechazada' => 0, 'anulada' => 0],
                ]);
                return;
            }

            // Comprobar defensivamente si ordenes_entrega tiene la columna solicitud_interna_id
            $hasOdeSolicitudId = false;
            try {
                $colCheck = $conn->query("SHOW COLUMNS FROM ordenes_entrega LIKE 'solicitud_interna_id'");
                $hasOdeSolicitudId = $colCheck && $colCheck->rowCount() > 0;
            } catch (\Throwable $e) {}

            $estado = trim((string) ($_GET['estado'] ?? ''));
            $departamentoFiltro = (int) ($_GET['departamento_id'] ?? 0);
            $busqueda = trim((string) ($_GET['q'] ?? ''));

            $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $offset = ($page - 1) * $limit;

            $where = [];
            $params = [];

            // Comprobación rápida de permisos desde la sesión (igual que Requisiciones)
            $rolSesion = strtolower((string) ($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'admin'));
            $esDirectivo = in_array($rolSesion, ['admin', 'administrador', 'presidencia', 'directivo', 'almacen'], true) || empty($rolSesion);
            $deptoId = (int) ($_SESSION['departamento_id'] ?? 0);

            if (!$esDirectivo && $deptoId > 0) {
                $where[] = "si.departamento_id = ?";
                $params[] = $deptoId;
            } elseif ($departamentoFiltro > 0) {
                $where[] = "si.departamento_id = ?";
                $params[] = $departamentoFiltro;
            }

            if ($estado !== '') {
                $where[] = "si.estado = ?";
                $params[] = $estado;
            }

            if ($busqueda !== '') {
                $where[] = "(si.numero_solicitud LIKE ? OR si.justificacion LIKE ? OR u.nombre_completo LIKE ?)";
                $busqLike = "%{$busqueda}%";
                $params[] = $busqLike;
                $params[] = $busqLike;
                $params[] = $busqLike;
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // 1. Conteo total
            $stmtCount = $conn->prepare("SELECT COUNT(*) FROM solicitudes_internas si LEFT JOIN usuarios u ON u.id = si.solicitante_id {$whereSql}");
            $stmtCount->execute($params);
            $totalItems = (int) $stmtCount->fetchColumn();

            // 2. Consulta principal limpia y adaptativa
            $oeSelect = $hasOdeSolicitudId 
                ? "(SELECT GROUP_CONCAT(DISTINCT oe_sub.numero_orden ORDER BY oe_sub.id ASC SEPARATOR ', ') FROM ordenes_entrega oe_sub WHERE oe_sub.solicitud_interna_id = si.id AND oe_sub.estado != 'anulada') AS orden_entrega_numero" 
                : "NULL AS orden_entrega_numero";

            $sql = "
                SELECT si.*,
                       COALESCE(d.nombre, 'Sin Departamento') AS departamento_nombre,
                       COALESCE(u.nombre_completo, 'Usuario') AS solicitante_nombre,
                       (SELECT COUNT(*) FROM solicitud_interna_items sii WHERE sii.solicitud_interna_id = si.id) AS total_items_distintos,
                       (SELECT COALESCE(SUM(sii.cantidad_solicitada), 0) FROM solicitud_interna_items sii WHERE sii.solicitud_interna_id = si.id) AS total_unidades_solicitadas,
                       {$oeSelect}
                FROM solicitudes_internas si
                LEFT JOIN departamentos d ON d.id = si.departamento_id
                LEFT JOIN usuarios u ON u.id = si.solicitante_id
                {$whereSql}
                ORDER BY si.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $solicitudes = array_map(function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'numero_solicitud' => (string) $r['numero_solicitud'],
                    'anio' => (int) ($r['anio'] ?? date('Y')),
                    'departamento_id' => (int) ($r['departamento_id'] ?? 0),
                    'departamento_nombre' => (string) ($r['departamento_nombre'] ?? 'Departamento N/A'),
                    'solicitante_id' => (int) ($r['solicitante_id'] ?? 0),
                    'solicitante_nombre' => (string) ($r['solicitante_nombre'] ?? 'Usuario N/A'),
                    'estado' => (string) ($r['estado'] ?? 'borrador'),
                    'prioridad' => (string) ($r['prioridad'] ?? 'media'),
                    'justificacion' => (string) ($r['justificacion'] ?? ''),
                    'fecha_solicitud' => (string) ($r['fecha_solicitud'] ?? date('Y-m-d H:i:s')),
                    'fecha_aprobacion' => $r['fecha_aprobacion'] ?? null,
                    'usuario_aprobador_id' => isset($r['usuario_aprobador_id']) ? (int) $r['usuario_aprobador_id'] : null,
                    'aprobador_nombre' => $r['aprobador_nombre'] ?? null,
                    'observaciones_aprobacion' => $r['observaciones_aprobacion'] ?? null,
                    'total_items_distintos' => (int) $r['total_items_distintos'],
                    'total_unidades_solicitadas' => (float) $r['total_unidades_solicitadas'],
                    'orden_entrega_numero' => !empty($r['orden_entrega_numero']) ? (string) $r['orden_entrega_numero'] : null,
                ];
            }, $rows);

            // 3. Estadísticas por estado
            $stmtStats = $conn->query("SELECT estado, COUNT(*) AS total FROM solicitudes_internas GROUP BY estado");
            $statsRaw = $stmtStats ? $stmtStats->fetchAll(PDO::FETCH_KEY_PAIR) : [];

            $stats = [
                'borrador' => (int) ($statsRaw['borrador'] ?? 0),
                'enviada' => (int) ($statsRaw['enviada'] ?? 0),
                'convertida' => (int) (($statsRaw['convertida'] ?? 0) + ($statsRaw['aprobada'] ?? 0)),
                'procesada_parcial' => (int) ($statsRaw['procesada_parcial'] ?? 0),
                'derivada_compras' => (int) ($statsRaw['derivada_compras'] ?? 0),
                'rechazada' => (int) ($statsRaw['rechazada'] ?? 0),
                'anulada' => (int) ($statsRaw['anulada'] ?? 0),
            ];

            $this->jsonResponse([
                'solicitudes' => $solicitudes,
                'paginacion' => [
                    'total' => $totalItems,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => max(1, (int) ceil($totalItems / $limit)),
                ],
                'estadisticas' => $stats,
            ]);
        } catch (\Throwable $e) {
            error_log("Error en SolicitudesInternasController::index: " . $e->getMessage());
            $this->jsonResponse([
                'solicitudes' => [],
                'paginacion' => ['total' => 0, 'page' => 1, 'limit' => 50, 'pages' => 1],
                'estadisticas' => ['borrador' => 0, 'enviada' => 0, 'convertida' => 0, 'procesada_parcial' => 0, 'derivada_compras' => 0, 'rechazada' => 0, 'anulada' => 0],
            ]);
        }
    }

    public function show(string $id): void
    {
        try {
            $solicitudId = (int) $id;
            if ($solicitudId <= 0) {
                $this->errorResponse('ID de solicitud no válido.', 400);
            }

            $conn = $this->db;

            // Comprobar defensivamente si ordenes_entrega tiene la columna solicitud_interna_id
            $hasOdeSolicitudId = false;
            try {
                $colCheck = $conn->query("SHOW COLUMNS FROM ordenes_entrega LIKE 'solicitud_interna_id'");
                $hasOdeSolicitudId = $colCheck && $colCheck->rowCount() > 0;
            } catch (\Throwable $e) {}

            $oeJoin = $hasOdeSolicitudId ? "LEFT JOIN ordenes_entrega oe ON oe.solicitud_interna_id = si.id AND oe.estado != 'anulada'" : "";
            $oeSelect = $hasOdeSolicitudId ? "oe.id AS orden_entrega_id, oe.numero_orden AS orden_entrega_numero, oe.estado AS orden_entrega_estado" : "NULL AS orden_entrega_id, NULL AS orden_entrega_numero, NULL AS orden_entrega_estado";

            $stmt = $conn->prepare("
                SELECT si.*,
                       COALESCE(d.nombre, 'Sin Departamento') AS departamento_nombre,
                       COALESCE(u.nombre_completo, 'Usuario') AS solicitante_nombre,
                       {$oeSelect}
                FROM solicitudes_internas si
                LEFT JOIN departamentos d ON d.id = si.departamento_id
                LEFT JOIN usuarios u ON u.id = si.solicitante_id
                {$oeJoin}
                WHERE si.id = ?
            ");
            $stmt->execute([$solicitudId]);
            $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$solicitud) {
                $this->errorResponse("La solicitud interna #{$solicitudId} no existe.", 404);
            }

            $stmtItems = $conn->prepare("
                SELECT sii.*,
                       p.codigo AS producto_codigo,
                       p.nombre AS producto_nombre,
                       p.unidad_medida AS producto_unidad,
                       p.existencias AS producto_stock_actual,
                       COALESCE(p.stock_reservado, 0.000) AS producto_stock_reservado,
                       COALESCE(p.permite_decimales, 1) AS permite_decimales
                FROM solicitud_interna_items sii
                JOIN productos p ON p.id = sii.producto_id
                WHERE sii.solicitud_interna_id = ?
                ORDER BY sii.id ASC
            ");
            $stmtItems->execute([$solicitudId]);
            $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map(function (array $it): array {
                $solicitada = (float) $it['cantidad_solicitada'];
                $aprobada = (float) ($it['cantidad_aprobada'] ?? 0);
                $existencias = (float) $it['producto_stock_actual'];
                $reservado = (float) $it['producto_stock_reservado'];
                $dispReal = max(0.0, $existencias - $reservado);

                return [
                    'id' => (int) $it['id'],
                    'solicitud_interna_id' => (int) $it['solicitud_interna_id'],
                    'producto_id' => (int) $it['producto_id'],
                    'producto_codigo' => (string) $it['producto_codigo'],
                    'producto_nombre' => (string) $it['producto_nombre'],
                    'producto_unidad' => (string) ($it['producto_unidad'] ?? 'UNID'),
                    'permite_decimales' => (bool) $it['permite_decimales'],
                    'cantidad_solicitada' => $solicitada,
                    'cantidad_aprobada' => $aprobada,
                    'estado_item' => (string) ($it['estado_item'] ?? 'pendiente'),
                    'observaciones' => (string) ($it['observaciones'] ?? ''),
                    'disponible_para_solicitar' => ($dispReal > 0),
                    'producto_stock_actual' => $existencias,
                    'producto_stock_reservado' => $reservado,
                    'producto_stock_disponible' => $dispReal,
                ];
            }, $rawItems);

            $stmtHist = $conn->prepare("
                SELECT sih.*, COALESCE(u.nombre_completo, 'Sistema') AS usuario_nombre
                FROM solicitud_interna_historial sih
                LEFT JOIN usuarios u ON u.id = sih.usuario_id
                WHERE sih.solicitud_interna_id = ?
                ORDER BY sih.id ASC
            ");
            $stmtHist->execute([$solicitudId]);
            $historial = $stmtHist ? $stmtHist->fetchAll(PDO::FETCH_ASSOC) : [];

            $estadoDetalle = trim((string) ($solicitud['estado'] ?? ''));
            if (empty($estadoDetalle)) {
                $estadoDetalle = !empty($solicitud['orden_entrega_id']) ? 'procesada_parcial' : 'enviada';
            }

            $ordenesVinculadas = [];
            if ($hasOdeSolicitudId) {
                try {
                    $stmtODES = $conn->prepare("SELECT id, numero_orden, estado, fecha_orden FROM ordenes_entrega WHERE solicitud_interna_id = ? AND estado != 'anulada' ORDER BY id ASC");
                    $stmtODES->execute([$solicitudId]);
                    $ordenesVinculadas = $stmtODES->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $eOdes) {}
            }

            $this->jsonResponse([
                'id' => (int) $solicitud['id'],
                'numero_solicitud' => (string) $solicitud['numero_solicitud'],
                'anio' => (int) ($solicitud['anio'] ?? date('Y')),
                'departamento_id' => (int) ($solicitud['departamento_id'] ?? 0),
                'departamento_nombre' => (string) ($solicitud['departamento_nombre'] ?? 'N/A'),
                'solicitante_id' => (int) ($solicitud['solicitante_id'] ?? 0),
                'solicitante_nombre' => (string) ($solicitud['solicitante_nombre'] ?? 'N/A'),
                'estado' => $estadoDetalle,
                'prioridad' => (string) ($solicitud['prioridad'] ?? 'media'),
                'justificacion' => (string) ($solicitud['justificacion'] ?? ''),
                'observaciones_aprobacion' => (string) ($solicitud['observaciones_aprobacion'] ?? ''),
                'usuario_aprobador_id' => !empty($solicitud['usuario_aprobador_id']) ? (int) $solicitud['usuario_aprobador_id'] : null,
                'aprobador_nombre' => (string) ($solicitud['aprobador_nombre'] ?? ''),
                'fecha_solicitud' => (string) ($solicitud['fecha_solicitud'] ?? date('Y-m-d H:i:s')),
                'fecha_aprobacion' => $solicitud['fecha_aprobacion'] ? (string) $solicitud['fecha_aprobacion'] : null,
                'orden_entrega_id' => !empty($solicitud['orden_entrega_id']) ? (int) $solicitud['orden_entrega_id'] : null,
                'orden_entrega_numero' => !empty($solicitud['orden_entrega_numero']) ? (string) $solicitud['orden_entrega_numero'] : null,
                'orden_entrega_estado' => !empty($solicitud['orden_entrega_estado']) ? (string) $solicitud['orden_entrega_estado'] : null,
                'ordenes_entrega_vinculadas' => $ordenesVinculadas,
                'items' => $items,
                'historial' => $historial,
            ]);
        } catch (\Throwable $e) {
            error_log("Error en SolicitudesInternasController::show: " . $e->getMessage());
            $this->errorResponse("Error al obtener detalle de la solicitud: " . $e->getMessage(), 500);
        }
    }

    public function store(): void
    {
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $departamentoId = isset($data['departamento_id']) && (int) $data['departamento_id'] > 0 ? (int) $data['departamento_id'] : null;
        $justificacion = trim((string) ($data['justificacion'] ?? ''));
        $prioridad = in_array($data['prioridad'] ?? 'media', ['baja', 'media', 'alta', 'urgente'], true) ? $data['prioridad'] : 'media';
        $accionTarget = in_array($data['accion'] ?? 'guardar', ['guardar', 'enviar'], true) ? $data['accion'] : 'guardar';
        $estadoTarget = ($accionTarget === 'enviar') ? 'enviada' : 'borrador';

        $items = $data['items'] ?? [];

        if (!$departamentoId) {
            $this->errorResponse('Debe seleccionar un departamento receptor válido.', 422);
        }
        if ($justificacion === '') {
            $this->errorResponse('El motivo o justificación de la solicitud es obligatorio.', 422);
        }
        if (empty($items) || !is_array($items)) {
            $this->errorResponse('Debe agregar al menos un insumo o producto a la solicitud.', 422);
        }

        $conn = $this->db;
        $this->asegurarColumnasYTablas($conn);

        try {
            $conn->beginTransaction();

            $anioNow = (int) date('Y');
            $numeroSolicitud = $this->generarCorrelativo($conn, 'SI', $anioNow);

            $stmtProdVal = $conn->prepare("SELECT id, nombre, COALESCE(permite_decimales, 1) AS permite_decimales FROM productos WHERE id = ? AND estado = 'activo'");

            $itemsConsolidados = [];
            foreach ($items as $it) {
                $pId = (int) ($it['producto_id'] ?? 0);
                $cant = round((float) ($it['cantidad_solicitada'] ?? 0), 3);
                $obs = trim((string) ($it['observaciones'] ?? ''));

                if ($pId <= 0 || $cant <= 0) continue;

                $stmtProdVal->execute([$pId]);
                $prod = $stmtProdVal->fetch(PDO::FETCH_ASSOC);
                if (!$prod) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("El producto ID #{$pId} no existe o está inactivo.", 422);
                }

                if ((int) $prod['permite_decimales'] === 0 && abs($cant - round($cant)) > 0.0001) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("El producto '{$prod['nombre']}' no admite cantidades fraccionadas.", 422);
                }

                if (!isset($itemsConsolidados[$pId])) {
                    $itemsConsolidados[$pId] = [
                        'producto_id' => $pId,
                        'cantidad_solicitada' => $cant,
                        'observaciones' => $obs,
                    ];
                } else {
                    $itemsConsolidados[$pId]['cantidad_solicitada'] += $cant;
                }
            }

            $stmtHead = $conn->prepare("
                INSERT INTO solicitudes_internas (
                    numero_solicitud, anio, departamento_id, solicitante_id, estado, prioridad, justificacion, fecha_solicitud, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmtHead->execute([$numeroSolicitud, $anioNow, $departamentoId, $usuarioId, $estadoTarget, $prioridad, $justificacion]);
            $solicitudId = (int) $conn->lastInsertId();

            $stmtItemIns = $conn->prepare("
                INSERT INTO solicitud_interna_items (
                    solicitud_interna_id, producto_id, cantidad_solicitada, cantidad_aprobada, estado_item, observaciones
                ) VALUES (?, ?, ?, 0.000, 'pendiente', ?)
            ");

            foreach ($itemsConsolidados as $it) {
                $stmtItemIns->execute([$solicitudId, $it['producto_id'], $it['cantidad_solicitada'], $it['observaciones']]);
            }

            $comentarioHist = ($estadoTarget === 'enviada') ? 'Solicitud creada y enviada a revisión' : 'Solicitud guardada en borrador';
            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'CREAR', null, $estadoTarget, $comentarioHist);

            $conn->commit();

            $this->jsonResponse([
                'id' => $solicitudId,
                'numero_solicitud' => $numeroSolicitud,
                'estado' => $estadoTarget,
            ], 201, "Solicitud interna {$numeroSolicitud} registrada correctamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al crear solicitud interna: " . $e->getMessage(), 500);
        }
    }

    public function update(string $id): void
    {
        $solicitudId = (int) $id;
        if ($solicitudId <= 0) {
            $this->errorResponse('ID de solicitud no válido.', 400);
        }

        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $conn = $this->db;

        try {
            $conn->beginTransaction();

            $stmtSelect = $conn->prepare("SELECT * FROM solicitudes_internas WHERE id = ? FOR UPDATE");
            $stmtSelect->execute([$solicitudId]);
            $solicitud = $stmtSelect->fetch(PDO::FETCH_ASSOC);

            if (!$solicitud) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La solicitud #{$solicitudId} no existe.", 404);
            }

            if ($solicitud['estado'] !== 'borrador') {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Solo se pueden editar solicitudes en estado 'borrador'.", 409);
            }

            if ((int) $solicitud['solicitante_id'] !== $usuarioId) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Acceso denegado: No puede modificar borradores de otros funcionarios.", 403);
            }

            $justificacion = isset($data['justificacion']) ? trim((string) $data['justificacion']) : (string) $solicitud['justificacion'];
            $prioridad = isset($data['prioridad']) && in_array($data['prioridad'], ['baja', 'media', 'alta', 'urgente'], true) ? $data['prioridad'] : (string) $solicitud['prioridad'];
            $accionTarget = in_array($data['accion'] ?? 'guardar', ['guardar', 'enviar'], true) ? $data['accion'] : 'guardar';
            $estadoNuevo = ($accionTarget === 'enviar') ? 'enviada' : 'borrador';

            if ($justificacion === '') {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse('El motivo o justificación de la solicitud es obligatorio.', 422);
            }

            $stmtUpdHead = $conn->prepare("
                UPDATE solicitudes_internas
                SET justificacion = ?, prioridad = ?, estado = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdHead->execute([$justificacion, $prioridad, $estadoNuevo, $solicitudId]);

            if (isset($data['items']) && is_array($data['items'])) {
                $conn->prepare("DELETE FROM solicitud_interna_items WHERE solicitud_interna_id = ?")->execute([$solicitudId]);

                $stmtProdVal = $conn->prepare("SELECT id, nombre, COALESCE(permite_decimales, 1) AS permite_decimales FROM productos WHERE id = ? AND estado = 'activo'");
                $stmtItemIns = $conn->prepare("
                    INSERT INTO solicitud_interna_items (
                        solicitud_interna_id, producto_id, cantidad_solicitada, cantidad_aprobada, estado_item, observaciones
                    ) VALUES (?, ?, ?, 0.000, 'pendiente', ?)
                ");

                foreach ($data['items'] as $it) {
                    $pId = (int) ($it['producto_id'] ?? 0);
                    $cant = round((float) ($it['cantidad_solicitada'] ?? 0), 3);
                    $obs = trim((string) ($it['observaciones'] ?? ''));

                    if ($pId <= 0 || $cant <= 0) continue;

                    $stmtProdVal->execute([$pId]);
                    $prod = $stmtProdVal->fetch(PDO::FETCH_ASSOC);
                    if ($prod) {
                        if ((int) $prod['permite_decimales'] === 0 && abs($cant - round($cant)) > 0.0001) {
                            if ($conn->inTransaction()) $conn->rollBack();
                            $this->errorResponse("El producto '{$prod['nombre']}' no admite cantidades fraccionadas.", 422);
                        }
                        $stmtItemIns->execute([$solicitudId, $pId, $cant, $obs]);
                    }
                }
            }

            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'ACTUALIZAR', 'borrador', $estadoNuevo, 'Borrador actualizado por el solicitante');
            $conn->commit();

            $this->jsonResponse([
                'id' => $solicitudId,
                'estado' => $estadoNuevo,
            ], 200, "Solicitud interna actualizada correctamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al actualizar la solicitud interna: " . $e->getMessage(), 500);
        }
    }

    public function retractar(string $id): void
    {
        $solicitudId = (int) $id;
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $conn = $this->db;

        try {
            $conn->beginTransaction();
            $stmtRetract = $conn->prepare("
                UPDATE solicitudes_internas
                SET estado = 'borrador', updated_at = NOW()
                WHERE id = ? AND estado = 'enviada' AND solicitante_id = ?
            ");
            $stmtRetract->execute([$solicitudId, $usuarioId]);

            if ($stmtRetract->rowCount() === 0) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("No es posible retractar: La solicitud ya fue tomada por un aprobador o modificada.", 409);
            }

            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'RETRACTAR', 'enviada', 'borrador', 'Retracción manual de solicitud a borrador por el solicitante');
            $conn->commit();

            $this->jsonResponse(['id' => $solicitudId, 'estado' => 'borrador'], 200, "Solicitud retractada a borrador exitosamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al retractar solicitud: " . $e->getMessage(), 500);
        }
    }

    public function enviar(string $id): void
    {
        $solicitudId = (int) $id;
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $conn = $this->db;

        try {
            $conn->beginTransaction();
            $stmtUpd = $conn->prepare("
                UPDATE solicitudes_internas
                SET estado = 'enviada', updated_at = NOW()
                WHERE id = ? AND estado = 'borrador' AND solicitante_id = ?
            ");
            $stmtUpd->execute([$solicitudId, $usuarioId]);

            if ($stmtUpd->rowCount() === 0) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La solicitud no está en borrador o no le pertenece.", 422);
            }

            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'ENVIAR', 'borrador', 'enviada', 'Envío formal a revisión de la directiva');
            $conn->commit();

            $this->jsonResponse(['id' => $solicitudId, 'estado' => 'enviada'], 200, "Solicitud enviada para aprobación exitosamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al enviar solicitud: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inventario/solicitudes-internas/{id}/aprobar
     * Bloqueo atómico Pesimista ORDER BY id ASC FOR UPDATE con reserva inmediata de stock
     */
    public function aprobar(string $id): void
    {
        $solicitudId = (int) $id;
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $this->obtenerInfoUsuarioYValidarRBAC($usuarioId, true);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $cantidadesAprobadasInput = $data['items'] ?? [];
        $observacionesAprobacion = trim((string) ($data['observaciones'] ?? 'Aprobado por la administración'));

        $conn = $this->db;
        $this->asegurarColumnasYTablas($conn);

        try {
            $conn->beginTransaction();

            $stmtHead = $conn->prepare("SELECT * FROM solicitudes_internas WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$solicitudId]);
            $solicitud = $stmtHead->fetch(PDO::FETCH_ASSOC);

            $estadoNorm = trim((string) ($solicitud['estado'] ?? ''));
            $procesable = in_array($estadoNorm, ['enviada', 'procesada_parcial', 'derivada_compras', ''], true);

            $stmtPend = $conn->prepare("
                SELECT COUNT(*) 
                FROM solicitud_interna_items 
                WHERE solicitud_interna_id = ? AND cantidad_solicitada > COALESCE(cantidad_aprobada, 0)
            ");
            $stmtPend->execute([$solicitudId]);
            $tieneSaldosPendientes = ((int) $stmtPend->fetchColumn()) > 0;

            if (!$solicitud || (!$procesable && !$tieneSaldosPendientes) || in_array($estadoNorm, ['anulada', 'rechazada'], true)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La solicitud no se encuentra en un estado procesable o no posee saldos pendientes por entregar.", 409);
            }

            $rolSesion = strtolower((string) ($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? 'admin'));
            $esSuperAdmin = in_array($rolSesion, ['admin', 'administrador', 'presidencia', 'superusuario'], true);

            if ((int) $solicitud['solicitante_id'] === $usuarioId && !$esSuperAdmin) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Violación de Control Interno CGR: Un funcionario regular no puede autorizar sus propios requerimientos materiales.", 403);
            }

            $stmtItems = $conn->prepare("
                SELECT sii.id, sii.producto_id, sii.cantidad_solicitada, COALESCE(sii.cantidad_aprobada, 0) AS cantidad_aprobada_prev, p.permite_decimales, p.nombre AS producto_nombre
                FROM solicitud_interna_items sii
                JOIN productos p ON p.id = sii.producto_id
                WHERE sii.solicitud_interna_id = ?
                ORDER BY sii.producto_id ASC
            ");
            $stmtItems->execute([$solicitudId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $productIds = array_values(array_unique(array_map(fn(array $it): int => (int) $it['producto_id'], $items)));
            sort($productIds, SORT_NUMERIC);

            // BLOQUEO PESIMISTA ORDENADO ANTI-DEADLOCK
            $productosMap = StockService::lockProductsForUpdate($conn, $productIds);

            $totalAprobadoGlobal = 0.0;
            $itemsParaODE = [];
            $itemsParaProcura = [];

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $prodId = (int) $item['producto_id'];
                $cantSolicitada = round((float) $item['cantidad_solicitada'], 3);
                $cantYaAprobada = round((float) ($item['cantidad_aprobada_prev'] ?? 0), 3);
                $saldoPendiente = round(max(0.0, $cantSolicitada - $cantYaAprobada), 3);

                // Zero-Trust Fail-Closed Check
                if (!isset($cantidadesAprobadasInput[$itemId])) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("Faltan datos de aprobación para el ítem '{$item['producto_nombre']}' (ID #{$itemId}).", 422);
                }

                $cantAprobadaAhora = round((float) $cantidadesAprobadasInput[$itemId], 3);

                if ($cantAprobadaAhora < 0.000 || $cantAprobadaAhora > $saldoPendiente) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("La cantidad a aprobar para '{$item['producto_nombre']}' es inválida ({$cantAprobadaAhora}). Debe estar entre 0.000 y el saldo pendiente de {$saldoPendiente}.", 422);
                }

                if ((int) $item['permite_decimales'] === 0 && abs($cantAprobadaAhora - round($cantAprobadaAhora)) > 0.0001) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("El producto '{$item['producto_nombre']}' es indivisible y no acepta cantidades decimales.", 422);
                }

                $prod = $productosMap[$prodId] ?? null;
                $dispReal = max(0.0, round((float) $prod['existencias'], 3) - round((float) $prod['stock_reservado'], 3));
                if ($cantAprobadaAhora > $dispReal) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("Stock insuficiente para '{$item['producto_nombre']}'. Disponible en estante: {$dispReal}, Intentó aprobar: {$cantAprobadaAhora}", 422);
                }

                $nuevaCantAprobadaTotal = round($cantYaAprobada + $cantAprobadaAhora, 3);
                $remanenteNuevo = round(max(0.0, $cantSolicitada - $nuevaCantAprobadaTotal), 3);

                $totalAprobadoGlobal += $cantAprobadaAhora;

                if ($cantAprobadaAhora > 0) {
                    $itemsParaODE[] = ['item_id' => $itemId, 'producto_id' => $prodId, 'cantidad' => $cantAprobadaAhora];
                }
                if ($remanenteNuevo > 0) {
                    $itemsParaProcura[] = ['item_id' => $itemId, 'producto_id' => $prodId, 'cantidad' => $remanenteNuevo];
                }

                $estadoItem = ($nuevaCantAprobadaTotal >= $cantSolicitada) ? 'aprobado' : (($nuevaCantAprobadaTotal > 0) ? 'aprobado' : 'sin_stock_compras');
                $conn->prepare("UPDATE solicitud_interna_items SET cantidad_aprobada = ?, estado_item = ? WHERE id = ?")
                     ->execute([$nuevaCantAprobadaTotal, $estadoItem, $itemId]);

                if ($remanenteNuevo <= 0) {
                    $conn->prepare("UPDATE necesidades_procura SET estado = 'completado', updated_at = NOW() WHERE solicitud_item_id = ? AND estado = 'pendiente'")
                         ->execute([$itemId]);
                }
            }

            $nuevoEstadoSI = 'derivada_compras';
            $odeId = null;
            $numeroODE = null;

            if ($totalAprobadoGlobal > 0) {
                $anioNow = (int) date('Y');
                $numeroODE = $this->generarCorrelativo($conn, 'ODE', $anioNow);

                $colsODE = ['numero_orden', 'anio', 'departamento_id', 'solicitud_interna_id', 'solicitante_id', 'fecha_orden', 'estado', 'justificacion', 'observaciones', 'total_articulos', 'created_at'];
                $valsODE = [$numeroODE, $anioNow, $solicitud['departamento_id'], $solicitudId, $solicitud['solicitante_id'], date('Y-m-d H:i:s'), 'aprobada', "Conversión de Solicitud Interna {$solicitud['numero_solicitud']}", "Generada por conversión de Solicitud Interna {$solicitud['numero_solicitud']}. " . $observacionesAprobacion, count($itemsParaODE), date('Y-m-d H:i:s')];

                if ($this->tieneColumna('ordenes_entrega', 'usuario_despacho_id')) {
                    $colsODE[] = 'usuario_despacho_id';
                    $valsODE[] = $usuarioId;
                }
                if ($this->tieneColumna('ordenes_entrega', 'usuario_id')) {
                    $colsODE[] = 'usuario_id';
                    $valsODE[] = $usuarioId;
                }

                $placeholdersODE = implode(', ', array_fill(0, count($valsODE), '?'));
                $sqlODE = "INSERT INTO ordenes_entrega (" . implode(', ', $colsODE) . ") VALUES ({$placeholdersODE})";
                $stmtODE = $conn->prepare($sqlODE);
                $stmtODE->execute($valsODE);
                $odeId = (int) $conn->lastInsertId();

                $cantColODE = $this->tieneColumna('orden_entrega_items', 'cantidad_solicitada') ? 'cantidad_solicitada' : ($this->tieneColumna('orden_entrega_items', 'cantidad') ? 'cantidad' : 'cantidad_solicitada');
                $stmtODEItem = $conn->prepare("INSERT INTO orden_entrega_items (orden_entrega_id, producto_id, {$cantColODE}, observaciones) VALUES (?, ?, ?, ?)");
                $stmtReserva = $conn->prepare("UPDATE productos SET stock_reservado = stock_reservado + ? WHERE id = ?");

                foreach ($itemsParaODE as $odeItem) {
                    $stmtODEItem->execute([$odeId, $odeItem['producto_id'], $odeItem['cantidad'], "Aprobado desde solicitud {$solicitud['numero_solicitud']}"]);
                    $stmtReserva->execute([$odeItem['cantidad'], $odeItem['producto_id']]);
                }

                $nuevoEstadoSI = (count($itemsParaProcura) > 0) ? 'procesada_parcial' : 'convertida';
            }

            if (!empty($itemsParaProcura)) {
                $stmtProc = $conn->prepare("
                    INSERT INTO necesidades_procura (solicitud_interna_id, solicitud_item_id, departamento_id, producto_id, cantidad_requerida, estado, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pendiente', NOW())
                ");
                foreach ($itemsParaProcura as $procItem) {
                    $stmtProc->execute([$solicitudId, $procItem['item_id'], $solicitud['departamento_id'], $procItem['producto_id'], $procItem['cantidad']]);
                }
            }

            $conn->prepare("
                UPDATE solicitudes_internas
                SET estado = ?, usuario_aprobador_id = ?, observaciones_aprobacion = ?, fecha_aprobacion = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$nuevoEstadoSI, $usuarioId, $observacionesAprobacion, $solicitudId]);

            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'APROBAR', 'enviada', $nuevoEstadoSI, $observacionesAprobacion);
            $conn->commit();

            $this->jsonResponse([
                'id' => $solicitudId,
                'estado' => $nuevoEstadoSI,
                'orden_entrega_id' => $odeId,
                'orden_entrega_numero' => $numeroODE,
                'items_derivados_procura' => count($itemsParaProcura),
            ], 200, "Solicitud procesada correctamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al aprobar solicitud: " . $e->getMessage(), 500);
        }
    }

    public function rechazar(string $id): void
    {
        $solicitudId = (int) $id;
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $this->obtenerInfoUsuarioYValidarRBAC($usuarioId, true);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $motivo = trim((string) ($data['observaciones'] ?? ''));

        if (mb_strlen($motivo) < 15) {
            $this->errorResponse('El motivo de rechazo debe contener al menos 15 caracteres explicativos.', 422);
        }

        $conn = $this->db;

        try {
            $conn->beginTransaction();
            $stmtUpd = $conn->prepare("
                UPDATE solicitudes_internas
                SET estado = 'rechazada', observaciones_aprobacion = ?, usuario_aprobador_id = ?, fecha_aprobacion = NOW(), updated_at = NOW()
                WHERE id = ? AND estado = 'enviada'
            ");
            $stmtUpd->execute([$motivo, $usuarioId, $solicitudId]);

            $conn->prepare("UPDATE solicitud_interna_items SET estado_item = 'rechazado' WHERE solicitud_interna_id = ?")->execute([$solicitudId]);
            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'RECHAZAR', 'enviada', 'rechazada', $motivo);
            $conn->commit();

            $this->jsonResponse(['id' => $solicitudId, 'estado' => 'rechazada'], 200, "Solicitud rechazada correctamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al rechazar solicitud: " . $e->getMessage(), 500);
        }
    }

    public function anular(string $id): void
    {
        $solicitudId = (int) $id;
        $usuarioId = $this->obtenerUsuarioAutenticadoId();
        $this->obtenerInfoUsuarioYValidarRBAC($usuarioId, true);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $motivoAnulacion = trim((string) ($data['observaciones'] ?? $data['motivo_anulacion'] ?? ''));

        if (mb_strlen($motivoAnulacion) < 15) {
            $this->errorResponse('El motivo de anulación formal del acto administrativo debe contener al menos 15 caracteres (Normas CGR).', 422);
        }

        $conn = $this->db;

        try {
            $conn->beginTransaction();

            $stmtHead = $conn->prepare("SELECT * FROM solicitudes_internas WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$solicitudId]);
            $solicitud = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$solicitud) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Solicitud no encontrada.", 404);
            }

            if (in_array($solicitud['estado'], ['anulada', 'rechazada'], true)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La solicitud ya se encuentra en estado terminal {$solicitud['estado']}.", 422);
            }

            $stmtODE = $conn->prepare("SELECT id, numero_orden, estado FROM ordenes_entrega WHERE solicitud_interna_id = ? AND estado != 'anulada'");
            $stmtODE->execute([$solicitudId]);
            $ode = $stmtODE->fetch(PDO::FETCH_ASSOC);

            if ($ode) {
                if (in_array($ode['estado'], ['despachada', 'despachada_parcial'], true)) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("Imposible anular: Los bienes ya fueron despachados físicamente según comprobante {$ode['numero_orden']}. Requiere una Nota de Devolución RMA en Almacén.", 409);
                }

                $stmtODEItems = $conn->prepare("SELECT producto_id, cantidad_solicitada FROM orden_entrega_items WHERE orden_entrega_id = ?");
                $stmtODEItems->execute([$ode['id']]);
                $odeItems = $stmtODEItems->fetchAll(PDO::FETCH_ASSOC);

                $stmtReservaDec = $conn->prepare("UPDATE productos SET stock_reservado = GREATEST(0.000, stock_reservado - ?) WHERE id = ?");
                foreach ($odeItems as $oIt) {
                    $stmtReservaDec->execute([(float) $oIt['cantidad_solicitada'], (int) $oIt['producto_id']]);
                }

                $conn->prepare("UPDATE ordenes_entrega SET estado = 'anulada', updated_at = NOW() WHERE id = ?")->execute([$ode['id']]);

                try {
                    $stmtAuditoriaODE = $conn->prepare("
                        INSERT INTO auditoria (
                            usuario_id, accion, modulo, tabla_afectada, estado_aprobacion, registro_id, detalles, ip_address, user_agent, fecha_hora
                        ) VALUES (?, 'anular_cascada', 'inventario', 'ordenes_entrega', 'aprobada', ?, ?, ?, ?, NOW())
                    ");
                    $detallesAuditoria = "ODE anulada automáticamente en cascada por anulación de Solicitud Interna {$solicitud['numero_solicitud']}. Motivo: {$motivoAnulacion}";
                    $stmtAuditoriaODE->execute([
                        $usuarioId,
                        $ode['id'],
                        $detallesAuditoria,
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                } catch (Throwable $eAud) {
                    error_log("Error registrando auditoria de ODE anulada en cascada: " . $eAud->getMessage());
                }
            }

            $conn->prepare("UPDATE necesidades_procura SET estado = 'cancelado', updated_at = NOW() WHERE solicitud_interna_id = ?")->execute([$solicitudId]);
            $conn->prepare("UPDATE solicitudes_internas SET estado = 'anulada', updated_at = NOW() WHERE id = ?")->execute([$solicitudId]);

            $this->registrarHistorial($conn, $solicitudId, $usuarioId, 'ANULAR', (string) $solicitud['estado'], 'anulada', "Motivo de Anulación: {$motivoAnulacion}");
            $conn->commit();

            $this->jsonResponse(['id' => $solicitudId, 'estado' => 'anulada'], 200, "Solicitud interna anulada exitosamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $this->errorResponse("Error al anular solicitud: " . $e->getMessage(), 500);
        }
    }

    public function necesidadesCompras(): void
    {
        try {
            $conn = $this->db;

            $tableCheck = $conn->query("SHOW TABLES LIKE 'necesidades_procura'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                $this->jsonResponse(['necesidades' => []]);
                return;
            }

            $stmt = $conn->query("
                SELECT p.id AS producto_id,
                       p.codigo AS producto_codigo,
                       p.nombre AS producto_nombre,
                       p.unidad_medida AS producto_unidad,
                       p.existencias AS producto_stock_actual,
                       SUM(np.cantidad_requerida) AS total_cantidad_requerida,
                       COUNT(DISTINCT np.solicitud_interna_id) AS total_solicitudes_afectadas,
                       COUNT(DISTINCT np.departamento_id) AS total_departamentos_solicitantes
                FROM necesidades_procura np
                JOIN productos p ON p.id = np.producto_id
                WHERE np.estado = 'pendiente'
                GROUP BY p.id, p.codigo, p.nombre, p.unidad_medida, p.existencias
                ORDER BY total_cantidad_requerida DESC
            ");
            $itemsConsolidados = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $this->jsonResponse([
                'necesidades' => array_map(function (array $r): array {
                    return [
                        'producto_id' => (int) $r['producto_id'],
                        'producto_codigo' => (string) $r['producto_codigo'],
                        'producto_nombre' => (string) $r['producto_nombre'],
                        'producto_unidad' => (string) ($r['producto_unidad'] ?? 'UNID'),
                        'producto_stock_actual' => (float) $r['producto_stock_actual'],
                        'total_cantidad_requerida' => (float) $r['total_cantidad_requerida'],
                        'total_solicitudes_afectadas' => (int) $r['total_solicitudes_afectadas'],
                        'total_departamentos_solicitantes' => (int) $r['total_departamentos_solicitantes'],
                    ];
                }, $itemsConsolidados),
            ]);
        } catch (\Throwable $e) {
            error_log("Error en necesidadesCompras: " . $e->getMessage());
            $this->jsonResponse(['necesidades' => []]);
        }
    }

    private function asegurarColumnasSolicitudesInternas(\PDO $conn): void
    {
        static $asegurado = false;
        if ($asegurado) return;

        try {
            // 1. Asegurar numero_solicitud
            $checkCol = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'numero_solicitud'");
            if (!$checkCol || $checkCol->rowCount() === 0) {
                $checkCodigo = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'codigo'");
                if ($checkCodigo && $checkCodigo->rowCount() > 0) {
                    $conn->exec("ALTER TABLE solicitudes_internas CHANGE COLUMN codigo numero_solicitud VARCHAR(30) NOT NULL");
                } else {
                    $checkNumero = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'numero'");
                    if ($checkNumero && $checkNumero->rowCount() > 0) {
                        $conn->exec("ALTER TABLE solicitudes_internas CHANGE COLUMN numero numero_solicitud VARCHAR(30) NOT NULL");
                    } else {
                        $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN numero_solicitud VARCHAR(30) NOT NULL AFTER id");
                    }
                }
            }

            // 2. Asegurar anio
            $checkAnio = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'anio'");
            if (!$checkAnio || $checkAnio->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN anio SMALLINT(4) NOT NULL DEFAULT " . date('Y') . " AFTER numero_solicitud");
            }

            // 3. Asegurar prioridad
            $checkPrioridad = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'prioridad'");
            if (!$checkPrioridad || $checkPrioridad->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN prioridad ENUM('baja', 'media', 'alta', 'urgente') NOT NULL DEFAULT 'media'");
            }

            // 4. Asegurar justificacion
            $checkJustificacion = $conn->query("SHOW COLUMNS FROM solicitudes_internas LIKE 'justificacion'");
            if (!$checkJustificacion || $checkJustificacion->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitudes_internas ADD COLUMN justificacion TEXT NOT NULL");
            }

            // 5. Asegurar cantidad_aprobada en solicitud_interna_items
            $checkCantAprob = $conn->query("SHOW COLUMNS FROM solicitud_interna_items LIKE 'cantidad_aprobada'");
            if (!$checkCantAprob || $checkCantAprob->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN cantidad_aprobada DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER cantidad_solicitada");
            }

            // 6. Asegurar estado_item en solicitud_interna_items
            $checkEstadoItem = $conn->query("SHOW COLUMNS FROM solicitud_interna_items LIKE 'estado_item'");
            if (!$checkEstadoItem || $checkEstadoItem->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN estado_item ENUM('pendiente', 'aprobado', 'sin_stock_compras', 'rechazado') NOT NULL DEFAULT 'pendiente'");
            }

            // 7. Asegurar observaciones en solicitud_interna_items
            $checkObsItem = $conn->query("SHOW COLUMNS FROM solicitud_interna_items LIKE 'observaciones'");
            if (!$checkObsItem || $checkObsItem->rowCount() === 0) {
                $conn->exec("ALTER TABLE solicitud_interna_items ADD COLUMN observaciones TEXT DEFAULT NULL");
            }

            // 8. Asegurar tabla solicitud_interna_historial
            $conn->exec("
                CREATE TABLE IF NOT EXISTS `solicitud_interna_historial` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `solicitud_interna_id` INT(11) NOT NULL,
                  `usuario_id` INT(11) DEFAULT NULL,
                  `accion` VARCHAR(50) NOT NULL,
                  `estado_anterior` VARCHAR(50) DEFAULT NULL,
                  `estado_nuevo` VARCHAR(50) DEFAULT NULL,
                  `comentario` TEXT DEFAULT NULL,
                  `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_sih_solicitud` (`solicitud_interna_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 9. Asegurar tabla secuencias_documentos (ejecutado antes de iniciar transacciones)
            $conn->exec("
                CREATE TABLE IF NOT EXISTS `secuencias_documentos` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `codigo` VARCHAR(30) NOT NULL,
                  `anio` SMALLINT(4) NOT NULL,
                  `ultimo_valor` INT(11) NOT NULL DEFAULT 0,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_codigo_anio` (`codigo`, `anio`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $asegurado = true;
        } catch (\Throwable $e) {
            error_log("Error en asegurarColumnasSolicitudesInternas: " . $e->getMessage());
        }
    }
}
