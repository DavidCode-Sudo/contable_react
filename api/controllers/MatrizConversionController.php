<?php
declare(strict_types=1);

namespace Api\Controllers;

use Api\Core\Controller;
use PDO;
use Throwable;

class MatrizConversionController extends Controller
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function validarRolContable(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->errorResponse("Sesión no iniciada. Acceso denegado.", 401);
        }

        $rolesPermitidos = ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'usuario'];
        
        $rolesSesion = [];
        if (!empty($_SESSION['usuario_roles']) && is_array($_SESSION['usuario_roles'])) {
            foreach ($_SESSION['usuario_roles'] as $r) {
                if (is_string($r)) {
                    $rolesSesion[] = strtolower(trim($r));
                } elseif (is_array($r)) {
                    foreach ($r as $val) {
                        if (is_string($val)) {
                            $rolesSesion[] = strtolower(trim($val));
                        }
                    }
                }
            }
        }

        $usuarioRol = strtolower(trim((string)($_SESSION['usuario_rol'] ?? $_SESSION['rol'] ?? '')));

        $tieneRol = false;
        if (!empty($usuarioRol) && in_array($usuarioRol, $rolesPermitidos, true)) {
            $tieneRol = true;
        } elseif (!empty($rolesSesion)) {
            foreach ($rolesSesion as $r) {
                if (in_array($r, $rolesPermitidos, true)) {
                    $tieneRol = true;
                    break;
                }
            }
        } else {
            // Usuario autenticado en sesión
            $tieneRol = true;
        }

        if (!$tieneRol) {
            $this->errorResponse("No posee privilegios contables para esta acción.", 403);
        }
    }

    private function asegurarTablaMatriz(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `matriz_conversion` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `partida_presupuestaria_id` int(11) NOT NULL,
                  `tipo_operacion` varchar(50) NOT NULL DEFAULT 'gasto',
                  `cuenta_contable_debe_id` int(11) NOT NULL,
                  `cuenta_contable_haber_id` int(11) DEFAULT NULL,
                  `descripcion` text DEFAULT NULL,
                  `activo` tinyint(1) NOT NULL DEFAULT 1,
                  `accion_centralizada_proyecto` varchar(255) DEFAULT NULL,
                  `clasificador_economico_codigo` varchar(50) DEFAULT NULL,
                  `clasificador_economico_nombre` varchar(255) DEFAULT NULL,
                  `prioridad` int(11) DEFAULT 1,
                  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_debe` (`cuenta_contable_debe_id`),
                  KEY `idx_haber` (`cuenta_contable_haber_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 1. Normalización automática de valores legados de tipo_operacion ('patrimonial' / 'pago' -> 'gasto')
            $this->db->exec("
                UPDATE matriz_conversion 
                SET tipo_operacion = 'gasto' 
                WHERE tipo_operacion IN ('patrimonial', 'pago', 'causacion') OR tipo_operacion IS NULL OR tipo_operacion = ''
            ");

            // 2. Saneamiento automático de duplicados históricos en BD (conserva la última regla m2)
            $this->db->exec("
                DELETE m1 FROM matriz_conversion m1
                INNER JOIN matriz_conversion m2 
                WHERE m1.id < m2.id 
                  AND m1.partida_presupuestaria_id = m2.partida_presupuestaria_id 
                  AND m1.tipo_operacion = m2.tipo_operacion
            ");

            // 3. Candado en MariaDB: Verificar y crear el índice único compuesto si no existe
            $checkIndex = $this->db->query("
                SHOW INDEX FROM `matriz_conversion` WHERE Key_name = 'idx_partida_operacion_unique'
            ")->fetch();

            if (!$checkIndex) {
                $this->db->exec("
                    ALTER TABLE `matriz_conversion` 
                    ADD UNIQUE INDEX `idx_partida_operacion_unique` (`partida_presupuestaria_id`, `tipo_operacion`)
                ");
            }

            // 4. Columna lote_importacion para el patrón Batch Rollback
            $checkLoteCol = $this->db->query("
                SHOW COLUMNS FROM `matriz_conversion` LIKE 'lote_importacion'
            ")->fetch();

            if (!$checkLoteCol) {
                $this->db->exec("
                    ALTER TABLE `matriz_conversion` 
                    ADD COLUMN `lote_importacion` VARCHAR(100) DEFAULT NULL,
                    ADD INDEX `idx_lote_importacion` (`lote_importacion`)
                ");
            }
        } catch (Throwable $e) {
            // Silencioso si la tabla/índice ya existe o falla controlada
        }
    }

    public function index(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $tipoOperacion = trim($_GET['tipo_operacion'] ?? '');
        $estado = trim($_GET['estado'] ?? '');
        $q = trim($_GET['q'] ?? '');

        try {
            $where = [];
            $params = [];

            if ($tipoOperacion !== '') {
                $where[] = "m.tipo_operacion = ?";
                $params[] = $tipoOperacion;
            }

            if ($estado !== '') {
                if ($estado === 'activa') {
                    $where[] = "(m.activo = 1 OR m.activo IS NULL)";
                } else if ($estado === 'inactiva') {
                    $where[] = "m.activo = 0";
                }
            }

            if ($q !== '') {
                $where[] = "(part.codigo LIKE ? OR part.codigo_completo LIKE ? OR part.nombre LIKE ? OR c_debe.codigo LIKE ? OR c_debe.nombre LIKE ? OR c_haber.codigo LIKE ? OR c_haber.nombre LIKE ?)";
                $like = "%{$q}%";
                array_push($params, $like, $like, $like, $like, $like, $like, $like);
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Conteo total
            $stmtCount = $this->db->prepare("
                SELECT COUNT(*) 
                FROM matriz_conversion m
                LEFT JOIN cuentas part ON m.partida_presupuestaria_id = part.id
                LEFT JOIN cuentas c_debe ON m.cuenta_contable_debe_id = c_debe.id
                LEFT JOIN cuentas c_haber ON m.cuenta_contable_haber_id = c_haber.id
                {$whereSql}
            ");
            $stmtCount->execute($params);
            $totalItems = (int)$stmtCount->fetchColumn();

            // Consulta de registros con doble alias para máxima compatibilidad
            $stmt = $this->db->prepare("
                SELECT 
                    m.*,
                    part.codigo as partida_codigo,
                    part.codigo_completo as partida_codigo_completo,
                    part.nombre as partida_nombre,
                    c_debe.codigo as debe_codigo,
                    c_debe.nombre as debe_nombre,
                    c_haber.codigo as haber_codigo,
                    c_haber.nombre as haber_nombre,
                    c_debe.codigo as cuenta_debe_codigo,
                    c_debe.nombre as cuenta_debe_nombre,
                    c_haber.codigo as cuenta_haber_codigo,
                    c_haber.nombre as cuenta_haber_nombre
                FROM matriz_conversion m
                LEFT JOIN cuentas part ON m.partida_presupuestaria_id = part.id
                LEFT JOIN cuentas c_debe ON m.cuenta_contable_debe_id = c_debe.id
                LEFT JOIN cuentas c_haber ON m.cuenta_contable_haber_id = c_haber.id
                {$whereSql}
                ORDER BY m.id DESC
                LIMIT ? OFFSET ?
            ");

            $paramPos = 1;
            foreach ($params as $val) {
                $stmt->bindValue($paramPos++, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue($paramPos++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($paramPos++, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $matriz = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mapear el valor numérico de 'activo' al enum 'estado' esperado por React
            foreach ($matriz as &$row) {
                $row['estado'] = (isset($row['activo']) && (int)$row['activo'] === 0) ? 'inactiva' : 'activa';
            }

            // Estadísticas generales de la matriz
            $stmtStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_general,
                    SUM(CASE WHEN activo = 1 OR activo IS NULL THEN 1 ELSE 0 END) as activas,
                    SUM(CASE WHEN tipo_operacion IN ('gasto', 'pago', 'patrimonial', 'causacion') THEN 1 ELSE 0 END) as gastos,
                    SUM(CASE WHEN tipo_operacion IN ('ingreso', 'recaudacion') THEN 1 ELSE 0 END) as ingresos
                FROM matriz_conversion
            ");
            $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

            $this->jsonResponse([
                'items' => $matriz,
                'paginacion' => [
                    'total' => $totalItems,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => max(1, (int)ceil($totalItems / $limit))
                ],
                'estadisticas' => [
                    'total_general' => (int)($stats['total_general'] ?? 0),
                    'activas' => (int)($stats['activas'] ?? 0),
                    'gastos' => (int)($stats['gastos'] ?? 0),
                    'ingresos' => (int)($stats['ingresos'] ?? 0),
                ]
            ]);
        } catch (Throwable $e) {
            error_log("MATRIZ INDEX ERROR: " . $e->getMessage() . " IN " . $e->getFile() . ":" . $e->getLine());
            $this->jsonResponse([
                'items' => [],
                'paginacion' => [
                    'total' => 0,
                    'page' => 1,
                    'limit' => $limit,
                    'pages' => 1
                ],
                'estadisticas' => [
                    'total_general' => 0,
                    'activas' => 0,
                    'gastos' => 0,
                    'ingresos' => 0
                ],
                'error_debug' => $e->getMessage() . " IN " . $e->getFile() . ":" . $e->getLine()
            ]);
        }
    }

    public function store(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $partidaId = (int)($data['partida_presupuestaria_id'] ?? 0);
        $tipoOperacion = trim($data['tipo_operacion'] ?? 'gasto');
        $cuentaDebeId = (int)($data['cuenta_contable_debe_id'] ?? 0);
        $cuentaHaberId = !empty($data['cuenta_contable_haber_id']) ? (int)$data['cuenta_contable_haber_id'] : null;
        $descripcion = trim($data['descripcion'] ?? '');
        
        // CORRECCIÓN: Adaptar el string de React a TINYINT(1) de MariaDB
        $activo = (isset($data['estado']) && $data['estado'] === 'inactiva') ? 0 : 1;
        
        // CORRECCIÓN: Rescatar variables de Clasificación Económica y Proyecto
        $accionProyecto = !empty($data['accion_centralizada_proyecto']) ? trim($data['accion_centralizada_proyecto']) : null;
        $ecoCodigo = !empty($data['clasificador_economico_codigo']) ? trim($data['clasificador_economico_codigo']) : null;
        $ecoNombre = !empty($data['clasificador_economico_nombre']) ? trim($data['clasificador_economico_nombre']) : null;

        if ($partidaId <= 0 || $cuentaDebeId <= 0) {
            $this->errorResponse('La partida presupuestaria y la cuenta debe son obligatorias.', 422);
        }

        try {
            // RESOLUCIÓN VECTOR 2: VALIDACIÓN CRUZADA ONAPRE ↔ ONCOP
            $stmtCodP = $this->db->prepare("SELECT COALESCE(codigo_completo, codigo) FROM cuentas WHERE id = ?");
            $stmtCodP->execute([$partidaId]);
            $codP = (string)$stmtCodP->fetchColumn();

            $stmtCodC = $this->db->prepare("SELECT COALESCE(codigo_completo, codigo) FROM cuentas WHERE id = ?");
            $stmtCodC->execute([$cuentaDebeId]);
            $codC = (string)$stmtCodC->fetchColumn();

            $incompatibilidad = $this->validarCompatibilidadContable($codP, $codC, $tipoOperacion);
            if ($incompatibilidad) {
                $this->errorResponse($incompatibilidad, 422);
            }

            // Validación de Unicidad (HTTP 409 Conflict)
            $stmtCheck = $this->db->prepare("SELECT id FROM matriz_conversion WHERE partida_presupuestaria_id = ? AND tipo_operacion = ? AND (activo = 1 OR activo IS NULL)");
            $stmtCheck->execute([$partidaId, $tipoOperacion]);
            if ($stmtCheck->fetchColumn()) {
                $this->errorResponse('Ya existe una regla de matriz activa para esta partida presupuestaria y tipo de operación.', 409);
            }
            // Inferencia Mágica de ONAPRE si está vacío
            if (empty($ecoCodigo) && function_exists('inferirClasificadorEconomico')) {
                $stmtPartida = $this->db->prepare("SELECT codigo_completo FROM cuentas WHERE id = ?");
                $stmtPartida->execute([$partidaId]);
                $codigoPartida = $stmtPartida->fetchColumn();

                if ($codigoPartida) {
                    $inferido = inferirClasificadorEconomico($codigoPartida);
                    if ($inferido) {
                        $ecoCodigo = $inferido['codigo'] ?? null;
                        $ecoNombre = $inferido['nombre'] ?? null;
                    }
                }
            }

            // Insert con soporte fallback para esquema activo/estado
            $sql = "INSERT INTO matriz_conversion 
                    (partida_presupuestaria_id, tipo_operacion, cuenta_contable_debe_id, cuenta_contable_haber_id, descripcion, activo)
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $partidaId, $tipoOperacion, $cuentaDebeId, $cuentaHaberId, $descripcion, $activo
            ]);

            $newId = (int)$this->db->lastInsertId();
            $this->jsonResponse(['id' => $newId, 'mensaje' => 'Regla de matriz creada exitosamente'], 201);
        } catch (Throwable $e) {
            $this->errorResponse('Error al crear regla de matriz: ' . $e->getMessage(), 500);
        }
    }

    public function update(string $id): void
    {
        $this->validarRolContable();
        $matrizId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $partidaId = (int)($data['partida_presupuestaria_id'] ?? 0);
        $tipoOperacion = trim($data['tipo_operacion'] ?? 'gasto');
        $cuentaDebeId = (int)($data['cuenta_contable_debe_id'] ?? 0);
        $cuentaHaberId = !empty($data['cuenta_contable_haber_id']) ? (int)$data['cuenta_contable_haber_id'] : null;
        $descripcion = trim($data['descripcion'] ?? '');
        $activo = (isset($data['estado']) && $data['estado'] === 'inactiva') ? 0 : 1;
        
        $accionProyecto = !empty($data['accion_centralizada_proyecto']) ? trim($data['accion_centralizada_proyecto']) : null;
        $ecoCodigo = !empty($data['clasificador_economico_codigo']) ? trim($data['clasificador_economico_codigo']) : null;
        $ecoNombre = !empty($data['clasificador_economico_nombre']) ? trim($data['clasificador_economico_nombre']) : null;

        if ($partidaId <= 0 || $cuentaDebeId <= 0) {
            $this->errorResponse('La partida y la cuenta DEBE son obligatorias.', 422);
        }

        try {
            // RESOLUCIÓN VECTOR 2: VALIDACIÓN CRUZADA ONAPRE ↔ ONCOP EN UPDATE
            $stmtCodP = $this->db->prepare("SELECT COALESCE(codigo_completo, codigo) FROM cuentas WHERE id = ?");
            $stmtCodP->execute([$partidaId]);
            $codP = (string)$stmtCodP->fetchColumn();

            $stmtCodC = $this->db->prepare("SELECT COALESCE(codigo_completo, codigo) FROM cuentas WHERE id = ?");
            $stmtCodC->execute([$cuentaDebeId]);
            $codC = (string)$stmtCodC->fetchColumn();

            $incompatibilidad = $this->validarCompatibilidadContable($codP, $codC, $tipoOperacion);
            if ($incompatibilidad) {
                $this->errorResponse($incompatibilidad, 422);
            }

            // CORRECCIÓN 1: Validar colisión en Update (ignorando el ID actual)
            $stmtCheck = $this->db->prepare("SELECT id FROM matriz_conversion WHERE partida_presupuestaria_id = ? AND tipo_operacion = ? AND id != ?");
            $stmtCheck->execute([$partidaId, $tipoOperacion, $matrizId]);
            if ($stmtCheck->fetchColumn()) {
                $this->errorResponse('Ya existe OTRA regla de conversión activa para esta partida y tipo de operación.', 409);
            }

            // CORRECCIÓN 2: Inferencia Mágica ONAPRE también en Update
            if (empty($ecoCodigo) && function_exists('inferirClasificadorEconomico')) {
                $stmtPartida = $this->db->prepare("SELECT codigo_completo FROM cuentas WHERE id = ?");
                $stmtPartida->execute([$partidaId]);
                $codigoPartida = $stmtPartida->fetchColumn();

                if ($codigoPartida) {
                    $inferido = inferirClasificadorEconomico((string)$codigoPartida);
                    if ($inferido) {
                        $ecoCodigo = $inferido['codigo'] ?? null;
                        $ecoNombre = $inferido['nombre'] ?? null;
                    }
                }
            }

            $sql = "UPDATE matriz_conversion 
                    SET partida_presupuestaria_id=?, tipo_operacion=?, cuenta_contable_debe_id=?, cuenta_contable_haber_id=?, descripcion=?, activo=?, accion_centralizada_proyecto=?, clasificador_economico_codigo=?, clasificador_economico_nombre=?
                    WHERE id=?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $partidaId, $tipoOperacion, $cuentaDebeId, $cuentaHaberId, $descripcion, 
                $activo, $accionProyecto, $ecoCodigo, $ecoNombre, $matrizId
            ]);

            $this->jsonResponse(['mensaje' => 'Regla de matriz actualizada exitosamente']);
        } catch (Throwable $e) {
            $this->errorResponse('Error al actualizar regla: ' . $e->getMessage(), 500);
        }
    }

    public function toggleEstado(string $id): void
    {
        $this->validarRolContable();
        $matrizId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        
        // Adaptar el string a entero para la tabla legacy
        $nuevoEstadoInt = (isset($data['estado']) && $data['estado'] === 'activa') ? 1 : 0;
        $estadoString = $nuevoEstadoInt === 1 ? 'activada' : 'inactivada';

        try {
            $stmt = $this->db->prepare("UPDATE matriz_conversion SET activo = ? WHERE id = ?");
            $stmt->execute([$nuevoEstadoInt, $matrizId]);
            $this->jsonResponse(['mensaje' => "Regla {$estadoString} exitosamente"]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al cambiar estado de la regla: ' . $e->getMessage(), 500);
        }
    }

    public function descargarPlantilla(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        if (ob_get_length()) {
            ob_clean();
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla_matriz_conversion.xls"');

        $ejemplosReales = [
            [
                'codigo_p' => '# EJEMPLO: 4.01.01.01.01',
                'nombre_p' => 'Sueldos básicos personal fijo a tiempo completo',
                'codigo_e' => '401',
                'nombre_e' => 'Gastos de Personal',
                'codigo_c' => '6.1.1.01.01.00',
                'nombre_c' => 'Gastos de Personal - Sueldos y Salarios Fijos',
                'tipo'     => 'gasto'
            ],
            [
                'codigo_p' => '# EJEMPLO: 4.01.01.02.00',
                'nombre_p' => 'Sueldos básicos personal fijo a tiempo parcial',
                'codigo_e' => '401',
                'nombre_e' => 'Gastos de Personal',
                'codigo_c' => '6.1.1.01.02.00',
                'nombre_c' => 'Gastos de Personal - Sueldos Tiempo Parcial',
                'tipo'     => 'gasto'
            ],
            [
                'codigo_p' => '# EJEMPLO: 4.02.05.03.00',
                'nombre_p' => 'Papelería y útiles de oficina',
                'codigo_e' => '402',
                'nombre_e' => 'Materiales y Suministros',
                'codigo_c' => '6.1.2.05.03.00',
                'nombre_c' => 'Gastos de Materiales - Papelería y Suministros',
                'tipo'     => 'gasto'
            ],
            [
                'codigo_p' => '# EJEMPLO: 4.03.04.01.00',
                'nombre_p' => 'Servicio de energía eléctrica',
                'codigo_e' => '403',
                'nombre_e' => 'Servicios no Personales',
                'codigo_c' => '6.1.3.04.01.00',
                'nombre_c' => 'Gastos de Servicios - Energía Eléctrica',
                'tipo'     => 'gasto'
            ],
            [
                'codigo_p' => '# EJEMPLO: 4.04.09.01.00',
                'nombre_p' => 'Muebles y equipos de oficina',
                'codigo_e' => '404',
                'nombre_e' => 'Inversión Real',
                'codigo_c' => '1.2.4.09.01.00',
                'nombre_c' => 'Activos - Muebles y Equipos de Oficina',
                'tipo'     => 'gasto'
            ],
        ];

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
  <Style ss:ID="TitleBanner">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="12" ss:Color="#1E3A8A" ss:Bold="1"/>
   <Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="InfoText">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#334155"/>
   <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#1E293B" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="ExampleRowStyle">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Dot" ss:Weight="1" ss:Color="#CBD5E1"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="9.5" ss:Color="#64748B" ss:Italic="1"/>
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Center">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
  <Style ss:ID="TextLeft">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Matriz de Conversión">
  <Table>
   <Column ss:Width="180"/>
   <Column ss:Width="300"/>
   <Column ss:Width="110"/>
   <Column ss:Width="180"/>
   <Column ss:Width="160"/>
   <Column ss:Width="300"/>
   <Column ss:Width="110"/>

   <!-- INSTRUCCIONES Y GUÍA DE USO -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="6" ss:StyleID="TitleBanner"><Data ss:Type="String">=== GUÍA CRÍTICA: GUÍA Y FUNCIONAMIENTO DE LA MATRIZ DE CONVERSIÓN (ARCHIVOS MIGRADOS) ===</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="InfoText"><Data ss:Type="String">1. PROPÓSITO E IMPORTANCIA: Articula las ejecuciones presupuestarias ONAPRE (Partidas 4.XX / 3.XX) con la Contabilidad Patrimonial SIGCOF (Cuentas 6.1.X / 1.2.X). Garantiza la generación automática de los asientos contables del libro diario al procesar compromisos y pagos.</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="InfoText"><Data ss:Type="String">2. ARCHIVOS MIGRADOS VS VIEJOS: Utilice únicamente la estructura de códigos del Plan Único de Cuentas actualizado (Ej: Partida 4.01.01.01.01 -&gt; Cuenta 6.1.1.01.01.00). No utilice catálogos o archivos legados viejos descatalogados.</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="InfoText"><Data ss:Type="String">3. OMISIÓN DE EJEMPLOS AL IMPORTAR: Las filas señaladas con "# EJEMPLO:" son guías de referencia de registros migrados y son OMITIDAS AUTOMÁTICAMENTE por el importador. Puede conservarlas o escribir sus datos debajo de ellas.</Data></Cell>
   </Row>
   <Row ss:Height="12"></Row>

   <!-- ENCABEZADOS DE LA TABLA -->
   <Row ss:Height="26">
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_presupuestario</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_presupuestario</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_economico</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_economico</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_patrimonial</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_patrimonial</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">tipo_operacion</Data></Cell>
   </Row>

   <!-- FILAS DE EJEMPLO REAL (SE IGNORAN EN IMPORTAR POR CONTENER '# EJEMPLO') -->
   <?php foreach ($ejemplosReales as $ej): ?>
   <Row ss:Height="20">
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['codigo_p']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['nombre_p']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['codigo_e']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['nombre_e']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['codigo_c']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['nombre_c']) ?></Data></Cell>
    <Cell ss:StyleID="ExampleRowStyle"><Data ss:Type="String"><?= htmlspecialchars($ej['tipo']) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <!-- ESTRUCTURA VACÍA PARA LLENADO DE DATOS (15 FILAS) -->
   <?php for ($i = 0; $i < 15; $i++): ?>
   <Row ss:Height="20">
    <Cell ss:StyleID="Center"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"></Data></Cell>
   </Row>
   <?php endfor; ?>
  </Table>
 </Worksheet>
</Workbook>
        <?php
        exit;
    }

    /**
     * Valida la compatibilidad técnica y de naturaleza entre la Partida Presupuestaria y la Cuenta Contable
     * según estándares ONAPRE / SIGCOF / ONCOP.
     */
    private function validarCompatibilidadContable(string $codigoPartida, string $codigoCuenta, string $tipoOperacion): ?string
    {
        $codigoPartidaLimpio = preg_replace('/[^0-9]/', '', $codigoPartida);
        $codigoCuentaLimpio  = preg_replace('/[^0-9]/', '', $codigoCuenta);

        $esPartidaEgreso  = (strpos($codigoPartidaLimpio, '4') === 0);
        $esPartidaIngreso = (strpos($codigoPartidaLimpio, '3') === 0);

        $primerDigitoCuenta = substr($codigoCuentaLimpio, 0, 1);

        if ($esPartidaIngreso) {
            if ($tipoOperacion !== 'ingreso') {
                return "Incompatibilidad Contable: La partida de ingreso '{$codigoPartida}' no puede asociarse a la operación '{$tipoOperacion}'. Debe ser 'ingreso'.";
            }
            if (in_array($primerDigitoCuenta, ['6'], true)) {
                return "Incompatibilidad Contable: La partida de ingreso '{$codigoPartida}' no puede imputarse a una cuenta contable de Gastos (Grupo 6).";
            }
        }

        if ($esPartidaEgreso) {
            if ($tipoOperacion === 'ingreso') {
                return "Incompatibilidad Contable: La partida de egreso '{$codigoPartida}' no puede asociarse al tipo de operación 'ingreso'.";
            }
            if (in_array($primerDigitoCuenta, ['5'], true)) {
                return "Incompatibilidad Contable: La partida de egreso '{$codigoPartida}' no puede imputarse a una cuenta contable de Ingresos (Grupo 5).";
            }
        }

        return null; // Compatible
    }

    public function importarMasivo(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $this->errorResponse('No se ha recibido un archivo válido para importar.', 400);
        }

        $tmpPath = $_FILES['archivo']['tmp_name'];
        $stream = fopen($tmpPath, 'rb');
        if (!$stream) {
            $this->errorResponse('No se pudo abrir el archivo para lectura.', 400);
        }

        $magicBytes = fread($stream, 4);
        $filasRaw = [];

        // VECTOR 1: BLINDAJE CON STREAMING CONTRA MEMORY LEAKS (.XLSX)
        if ($magicBytes === "PK\x03\x04") {
            fclose($stream);
            if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $filasRaw = $sheet->toArray();
                } catch (Throwable $e) {
                    $this->errorResponse('Error al procesar el archivo .xlsx con PhpSpreadsheet: ' . $e->getMessage(), 400);
                }
            } else {
                $this->errorResponse('El archivo subido es un paquete binario comprimido (.xlsx). Para garantizar la integridad de la memoria RAM del servidor, por favor guarde su hoja de cálculo en formato CSV (UTF-8) o Excel XML 2003 (.xls).', 400);
            }
        } else {
            rewind($stream);
            $sampleHeader = fread($stream, 1024);
            rewind($stream);

            // Detección de formato: XML Spreadsheet 2003 (.xls)
            if (strpos($sampleHeader, '<Workbook') !== false || strpos($sampleHeader, '<?xml') !== false) {
                // CIRCUIT BREAKER HOTFIX: Proteger la memoria RAM del servidor ante árboles DOM XML 2003 masivos
                if (filesize($tmpPath) > 5 * 1024 * 1024) {
                    fclose($stream);
                    $this->errorResponse('El archivo Excel 2003 (.xls) supera el límite máximo de 5MB para procesamiento XML. Para lotes de alto volumen, por favor conviértalo a formato CSV (UTF-8) antes de importarlo.', 400);
                }

                fclose($stream);
                $content = file_get_contents($tmpPath);
                $xml = @simplexml_load_string($content);
                if ($xml) {
                    $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
                    $rowsXml = $xml->xpath('//ss:Worksheet[1]//ss:Row');
                    foreach ($rowsXml as $rXml) {
                        $rowCells = [];
                        foreach ($rXml->Cell as $cell) {
                            $attrs = $cell->attributes('ss', true);
                            if (isset($attrs['Index'])) {
                                $targetIdx = ((int)$attrs['Index']) - 1;
                                while (count($rowCells) < $targetIdx) {
                                    $rowCells[] = '';
                                }
                            }
                            $rowCells[] = (string)($cell->Data ?? '');
                        }
                        if (!empty($rowCells)) {
                            $filasRaw[] = $rowCells;
                        }
                    }
                }
            } else {
                // Streaming de CSV directo mediante puntero sin cargar todo el archivo en RAM
                $bom = fread($stream, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($stream);
                }

                $headers = fgetcsv($stream, 2000, ',');
                if (!$headers || count($headers) < 3) {
                    rewind($stream);
                    if ($bom === "\xEF\xBB\xBF") fseek($stream, 3);
                    $headers = fgetcsv($stream, 2000, ';');
                    $delimitador = ';';
                } else {
                    $delimitador = ',';
                }

                while (($r = fgetcsv($stream, 2000, $delimitador)) !== false) {
                    $filasRaw[] = $r;
                }
                fclose($stream);
            }
        }

        // GENERACIÓN DE IDENTIFICADOR ÚNICO DE LOTE (BATCH ROLLBACK PATTERN)
        $loteId = 'LOTE_' . date('Ymd_His') . '_' . substr(md5((string)microtime(true)), 0, 6);

        $trackingLote = []; // Array de rastreo en memoria: tuplaHash => ['debeId', 'linea']
        $procesados = 0;
        $insertados = 0;
        $actualizados = 0;
        $omitidos = 0;
        $errores = 0;
        $detallesErrores = [];

        $this->db->beginTransaction();

        try {
            $numLinea = 0;
            foreach ($filasRaw as $row) {
                $numLinea++;
                $c0 = trim((string)($row[0] ?? ''));
                $rowCombined = implode(' ', $row);

                // Omitir encabezados, líneas vacías, comentarios, títulos de guía/reportes y filas etiquetadas como # EJEMPLO
                if (
                    empty($row) || 
                    count($row) < 3 || 
                    $c0 === '' || 
                    strpos($c0, '===') === 0 || 
                    strpos($c0, '#') === 0 || 
                    strpos($c0, '//') === 0 || 
                    strpos($c0, '[') === 0 ||
                    stripos($rowCombined, 'EJEMPLO') !== false ||
                    stripos($rowCombined, 'GUÍA') !== false ||
                    stripos($rowCombined, 'PROPÓSITO') !== false ||
                    stripos($rowCombined, 'INSTRUCCIONES') !== false ||
                    stripos($rowCombined, 'REPORTE OFICIAL') !== false ||
                    stripos($rowCombined, 'FECHA DE EMISIÓN') !== false ||
                    strtolower($c0) === 'codigo' || 
                    strtolower($c0) === 'codigo_presupuestario'
                ) {
                    continue;
                }
                $procesados++;
                $codigoPartida = trim($row[0]);
                $codigoPatrimonial = isset($row[4]) && trim($row[4]) !== '' ? trim($row[4]) : (isset($row[2]) ? trim($row[2]) : '');
                
                $tipoOperacionRaw = isset($row[6]) && trim((string)$row[6]) !== '' ? strtolower(trim((string)$row[6])) : 'gasto';

                // Diccionario de normalización canónica en la Capa de Aplicación (PHP)
                $diccionarioOperaciones = [
                    'pago' => 'gasto',
                    'patrimonial' => 'gasto',
                    'causacion' => 'gasto',
                    'recaudacion' => 'ingreso'
                ];

                $tipoOperacion = $diccionarioOperaciones[$tipoOperacionRaw] ?? $tipoOperacionRaw;
                if (empty($tipoOperacion) || trim($tipoOperacion) === '') {
                    $tipoOperacion = 'gasto';
                }

                // Resolver Partida Presupuestaria (Filtrado estricto: es_partida_presupuestaria = 1 o NULL)
                $stmtPartida = $this->db->prepare("
                    SELECT id FROM cuentas 
                    WHERE (codigo_completo = ? OR codigo = ?) 
                      AND (es_partida_presupuestaria = 1 OR es_partida_presupuestaria IS NULL)
                    LIMIT 1
                ");
                $stmtPartida->execute([$codigoPartida, $codigoPartida]);
                $partidaId = (int)$stmtPartida->fetchColumn();

                // Resolver Cuenta Contable DEBE (Filtrado estricto: es_partida_presupuestaria = 0 o NULL)
                $stmtDebe = $this->db->prepare("
                    SELECT id FROM cuentas 
                    WHERE (codigo = ? OR codigo_completo = ?) 
                      AND (es_partida_presupuestaria = 0 OR es_partida_presupuestaria IS NULL)
                    LIMIT 1
                ");
                $stmtDebe->execute([$codigoPatrimonial, $codigoPatrimonial]);
                $debeId = (int)$stmtDebe->fetchColumn();

                if (!$partidaId || !$debeId) {
                    $errores++;
                    $detallesErrores[] = "Línea {$numLinea}: Partida '{$codigoPartida}' o Cuenta '{$codigoPatrimonial}' no encontrada en el Plan de Cuentas.";
                    continue;
                }

                // VECTOR 2: VALIDACIÓN CRUZADA ONAPRE ↔ ONCOP EN IMPORTACIÓN MASIVA
                $incompatibilidad = $this->validarCompatibilidadContable($codigoPartida, $codigoPatrimonial, $tipoOperacion);
                if ($incompatibilidad) {
                    $errores++;
                    $detallesErrores[] = "Línea {$numLinea}: {$incompatibilidad}";
                    continue;
                }

                $tuplaHash = $partidaId . '_' . $tipoOperacion;

                // 🚨 RESOLUCIÓN CASO 2 & CASO 1: DETECCIÓN EN MEMORIA DEL LOTE
                if (isset($trackingLote[$tuplaHash])) {
                    $previoDebeId = $trackingLote[$tuplaHash]['debeId'];
                    $previoLinea  = $trackingLote[$tuplaHash]['linea'];

                    if ($previoDebeId !== $debeId) {
                        // CASO 2: CONFLICTO INTERNO EN EL MISMO ARCHIVO -> MARCAR ERROR ENTERPRISE
                        $errores++;
                        $detallesErrores[] = "Línea {$numLinea}: Conflicto interno. Partida '{$codigoPartida}' ya asignada a otra cuenta distinta en la línea {$previoLinea} de este mismo archivo.";
                        continue;
                    }

                    // CASO 1: DUPLICADO IDÉNTICO EN EL MISMO ARCHIVO -> OMITIR
                    $omitidos++;
                    continue;
                }

                // Registrar en memoria para rastreo del lote
                $trackingLote[$tuplaHash] = [
                    'debeId' => $debeId,
                    'linea'  => $numLinea
                ];

                // 🚨 CLASIFICACIÓN DE PRECISIÓN ENTERPRISE (NUEVAS vs ACTUALIZADAS vs OMITIDAS)
                try {
                    $stmtExist = $this->db->prepare("
                        SELECT id, cuenta_contable_debe_id, activo 
                        FROM matriz_conversion 
                        WHERE partida_presupuestaria_id = ? 
                          AND (tipo_operacion = ? OR TRIM(COALESCE(tipo_operacion, '')) = '')
                        LIMIT 1
                    ");
                    $stmtExist->execute([$partidaId, $tipoOperacion]);
                    $registroExistente = $stmtExist->fetch(PDO::FETCH_ASSOC);

                    if ($registroExistente) {
                        $existId   = (int)$registroExistente['id'];
                        $oldDebeId = (int)$registroExistente['cuenta_contable_debe_id'];
                        $oldActivo = (int)($registroExistente['activo'] ?? 1);

                        if ($oldDebeId === $debeId && $oldActivo === 1) {
                            // CASO 1: DUPLICADO IDÉNTICO -> La cuenta contable no cambió -> OMITIDO
                            // Normalizar tipo_operacion a canónico y refrescar firma de lote para Batch Rollback
                            $stmtUpdLote = $this->db->prepare("UPDATE matriz_conversion SET tipo_operacion = ?, lote_importacion = ? WHERE id = ?");
                            $stmtUpdLote->execute([$tipoOperacion, $loteId, $existId]);
                            $omitidos++;
                        } else {
                            // CASO 2: REEMPLAZO DE CUENTA -> La cuenta contable CAMBIÓ -> ACTUALIZADO
                            $stmtUpd = $this->db->prepare("UPDATE matriz_conversion SET cuenta_contable_debe_id = ?, tipo_operacion = ?, activo = 1, lote_importacion = ? WHERE id = ?");
                            $stmtUpd->execute([$debeId, $tipoOperacion, $loteId, $existId]);
                            $actualizados++;
                        }
                    } else {
                        // CASO 3: REGISTRO NUEVO -> NUEVA (Con safety net de UPSERT nativo anti 1062)
                        $stmtIns = $this->db->prepare("
                            INSERT INTO matriz_conversion 
                                (partida_presupuestaria_id, tipo_operacion, cuenta_contable_debe_id, activo, lote_importacion) 
                            VALUES 
                                (?, ?, ?, 1, ?)
                            ON DUPLICATE KEY UPDATE
                                cuenta_contable_debe_id = VALUES(cuenta_contable_debe_id),
                                tipo_operacion = VALUES(tipo_operacion),
                                activo = 1,
                                lote_importacion = VALUES(lote_importacion)
                        ");
                        $stmtIns->execute([$partidaId, $tipoOperacion, $debeId, $loteId]);
                        $insertados++;
                    }
                } catch (Throwable $eFila) {
                    $errores++;
                    $detallesErrores[] = "Línea {$numLinea}: " . $eFila->getMessage();
                }
            }

            if (is_resource($stream ?? null)) {
                fclose($stream);
            }
            $this->db->commit();

            $partesMsj = [];
            if ($insertados > 0) $partesMsj[] = "{$insertados} nueva(s)";
            if ($actualizados > 0) $partesMsj[] = "{$actualizados} actualizada(s)";
            if ($omitidos > 0) $partesMsj[] = "{$omitidos} duplicada(s) omitida(s)";
            if ($errores > 0) $partesMsj[] = "{$errores} con error";

            $resumenTexto = !empty($partesMsj) ? implode(', ', $partesMsj) : 'Sin cambios';

            $this->jsonResponse([
                'mensaje' => "Importación procesada exitosamente: {$resumenTexto}.",
                'lote_id' => $loteId,
                'procesados' => $procesados,
                'insertados' => $insertados,
                'actualizados' => $actualizados,
                'omitidos' => $omitidos,
                'errores' => $errores,
                'detalles' => $detallesErrores
            ]);
        } catch (Throwable $e) {
            if (is_resource($stream ?? null)) {
                fclose($stream);
            }
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse('Error en importación masiva: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Exporta toda la matriz de conversión registrada con la misma estructura y diseño de la plantilla
     * Endpoint: GET /api/catalogo/matriz/exportar
     */
    public function exportarMatriz(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        if (ob_get_length()) {
            ob_clean();
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="matriz_conversion_completa_' . date('Ymd_His') . '.xls"');

        $rows = [];
        try {
            $sql = "
                SELECT 
                    mc.id,
                    mc.tipo_operacion,
                    mc.activo,
                    mc.clasificador_economico_codigo,
                    mc.clasificador_economico_nombre,
                    COALESCE(NULLIF(p.codigo_completo, ''), p.codigo) as partida_codigo,
                    p.nombre as partida_nombre,
                    COALESCE(NULLIF(cd.codigo_completo, ''), cd.codigo) as debe_codigo,
                    cd.nombre as debe_nombre
                FROM matriz_conversion mc
                LEFT JOIN cuentas p ON mc.partida_presupuestaria_id = p.id
                LEFT JOIN cuentas cd ON mc.cuenta_contable_debe_id = cd.id
                ORDER BY partida_codigo ASC
            ";
            $stmt = $this->db->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Manejo silencioso de fallback
        }

        $w_cod_p = 180;
        $w_nom_p = 320;
        $w_cod_e = 120;
        $w_nom_e = 220;
        $w_cod_c = 160;
        $w_nom_c = 320;
        $w_tipo  = 120;

        foreach ($rows as $r) {
            $w_cod_p = max($w_cod_p, mb_strlen((string)($r['partida_codigo'] ?? '')) * 10);
            $w_nom_p = max($w_nom_p, mb_strlen((string)($r['partida_nombre'] ?? '')) * 8);
            $w_cod_e = max($w_cod_e, mb_strlen((string)($r['clasificador_economico_codigo'] ?? '')) * 10);
            $w_nom_e = max($w_nom_e, mb_strlen((string)($r['clasificador_economico_nombre'] ?? '')) * 8);
            $w_cod_c = max($w_cod_c, mb_strlen((string)($r['debe_codigo'] ?? '')) * 10);
            $w_nom_c = max($w_nom_c, mb_strlen((string)($r['debe_nombre'] ?? '')) * 8);
            $w_tipo  = max($w_tipo,  mb_strlen((string)($r['tipo_operacion'] ?? '')) * 10);
        }

        // Limitar los anchos máximos para que nombres extremadamente largos envuelvan el texto limpiamente (ss:WrapText="1")
        $w_nom_p = min($w_nom_p, 550);
        $w_nom_c = min($w_nom_c, 550);
        $w_nom_e = min($w_nom_e, 400);

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
  <Style ss:ID="TitleBanner">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" ss:Size="12" ss:Color="#1E3A8A" ss:Bold="1"/>
   <Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="InfoText">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#334155"/>
   <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#1E293B" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Center">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
  <Style ss:ID="TextLeft">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Matriz de Conversión">
  <Table>
   <Column ss:Width="<?= $w_cod_p ?>"/>
   <Column ss:Width="<?= $w_nom_p ?>"/>
   <Column ss:Width="<?= $w_cod_e ?>"/>
   <Column ss:Width="<?= $w_nom_e ?>"/>
   <Column ss:Width="<?= $w_cod_c ?>"/>
   <Column ss:Width="<?= $w_nom_c ?>"/>
   <Column ss:Width="<?= $w_tipo ?>"/>

   <!-- INSTRUCCIONES Y BANNER DE ENCABEZADO -->
   <Row ss:Height="24">
    <Cell ss:MergeAcross="6" ss:StyleID="TitleBanner"><Data ss:Type="String">=== REPORTE OFICIAL: MATRIZ DE CONVERSIÓN PRESUPUESTARIA - CONTABLE REGISTRADA ===</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:MergeAcross="6" ss:StyleID="InfoText"><Data ss:Type="String">Fecha de emisión: <?= date('d/m/Y H:i:s') ?> | Total Reglas de Equivalencia Registradas: <?= count($rows) ?></Data></Cell>
   </Row>
   <Row ss:Height="12"></Row>

   <!-- ENCABEZADOS DE LA TABLA (IDÉNTICOS A LA PLANTILLA) -->
   <Row ss:Height="26">
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_presupuestario</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_presupuestario</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_economico</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_economico</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">codigo_patrimonial</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">nombre_patrimonial</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">tipo_operacion</Data></Cell>
   </Row>

   <!-- FILAS DE DATOS DE LA MATRIZ REGISTRADA -->
   <?php foreach ($rows as $r): ?>
   <Row ss:Height="22">
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars($r['partida_codigo'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"><?= htmlspecialchars($r['partida_nombre'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars($r['clasificador_economico_codigo'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"><?= htmlspecialchars($r['clasificador_economico_nombre'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars($r['debe_codigo'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"><?= htmlspecialchars($r['debe_nombre'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars($r['tipo_operacion'] ?? 'gasto') ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
        <?php
        exit;
    }

    /**
     * Identifica el último lote importado por Excel/CSV y elimina únicamente sus registros (Batch Rollback).
     * Endpoint: POST /api/catalogo/matriz/deshacer-ultimo-lote
     */
    public function deshacerUltimoLote(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        try {
            $stmtLote = $this->db->query("
                SELECT lote_importacion, COUNT(*) as cantidad 
                FROM matriz_conversion 
                WHERE lote_importacion IS NOT NULL AND lote_importacion != ''
                GROUP BY lote_importacion 
                ORDER BY MAX(id) DESC 
                LIMIT 1
            ");
            $ultimoLote = $stmtLote ? $stmtLote->fetch(PDO::FETCH_ASSOC) : null;

            if (!$ultimoLote || empty($ultimoLote['lote_importacion'])) {
                $this->errorResponse('No se encontraron lotes de importación previos registrados para deshacer.', 404);
            }

            $loteId = $ultimoLote['lote_importacion'];

            $this->db->beginTransaction();

            $stmtDel = $this->db->prepare("DELETE FROM matriz_conversion WHERE lote_importacion = ?");
            $stmtDel->execute([$loteId]);
            $eliminados = $stmtDel->rowCount();

            $this->db->commit();

            $this->jsonResponse([
                'mensaje' => "Se revirtieron exitosamente {$eliminados} regla(s) pertenecientes al último lote de importación ({$loteId}).",
                'eliminados' => $eliminados,
                'lote_id' => $loteId
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse('Error al deshacer el último lote importado: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Vacía completamente la matriz de conversión de la base de datos (Reset Go-Live)
     * Rechaza la operación si ya existen asientos o comprobantes contables vinculados (Safe Delete).
     * Endpoint: POST /api/catalogo/matriz/vaciar
     */
    public function vaciarMatriz(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaMatriz();

        // 🚨 PROTECCIÓN DE TRAZABILIDAD ERP (FAIL-CLOSED SECURITY PATTERN)
        try {
            $stmtCheck = $this->db->query("SELECT 1 FROM detalles_asiento LIMIT 1");
            if ($stmtCheck && $stmtCheck->fetchColumn()) {
                $this->errorResponse('Acción Bloqueada (Protección de Trazabilidad): No se puede vaciar la matriz completa porque existen asientos contables registrados en el Libro Diario vinculados a estas reglas.', 409);
            }
        } catch (Throwable $e) {
            // FAIL-CLOSED: Si no se puede verificar la integridad o falla la consulta, abortar por seguridad
            $this->errorResponse('No se pudo verificar la integridad del Libro Diario para autorizar el vaciado. Operación abortada por seguridad de trazabilidad.', 500);
        }

        try {
            $this->db->beginTransaction();
            // Borrado DML transaccional seguro que respeta la integridad referencial y dispara triggers
            $this->db->exec("DELETE FROM matriz_conversion");
            $this->db->commit();

            $this->jsonResponse(['mensaje' => 'La matriz de conversión ha sido vaciada de manera segura (Eliminación DML Transaccional).']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse('Error al vaciar la matriz de conversión: ' . $e->getMessage(), 500);
        }
    }
}

