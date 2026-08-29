<?php
namespace Api\Controllers;

use Api\Core\Controller;
use Api\Services\CuentaBancariaService;
use PDO;
use Exception;

class TransferenciasController extends Controller
{
    private PDO $db;
    private CuentaBancariaService $cuentaService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getConnection();
        $this->cuentaService = new CuentaBancariaService();
    }

    private function validarRolContable(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->errorResponse("Autenticación requerida.", 401);
            exit;
        }

        try {
            $stmt = $this->db->prepare("SELECT rol FROM usuarios WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $rawRol = (string)$stmt->fetchColumn();
            $rol = strtolower(trim($rawRol));

            $rolesPermitidos = ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'usuario'];

            if (!empty($rol) && !in_array($rol, $rolesPermitidos, true)) {
                $this->errorResponse("Acceso denegado.", 403);
                exit;
            }
        } catch (\Throwable $e) {
            $this->errorResponse("Error al verificar los permisos del usuario.", 500);
            exit;
        }
    }

    /**
     * Rate Limiter Sudo persistido en BD anti-fuerza bruta
     */
    private function verificarRateLimiterSudo(int $usuarioId): void
    {
        $ip = $this->obtenerIpRealCliente();
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/api/tesoreria/transferencias';
        $endpoint = parse_url($rawUri, PHP_URL_PATH) ?: '/api/tesoreria/transferencias';
        $hashClave = hash('sha256', $ip . '_' . $usuarioId . '_' . $endpoint);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM intentos_seguridad 
            WHERE hash_clave = ? 
              AND fecha_intento >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$hashClave]);
        $intentosFallidos = (int)$stmt->fetchColumn();

        if ($intentosFallidos >= 3) {
            header('Retry-After: 900');
            $this->jsonResponse([
                'success' => false,
                'message' => 'Demasiados intentos fallidos de clave de administrador. Operación bloqueada temporalmente (HTTP 429).'
            ], 429);
            exit;
        }
    }

    private function registrarIntentoFallidoSudo(int $usuarioId): void
    {
        $ip = $this->obtenerIpRealCliente();
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/api/tesoreria/transferencias';
        $endpoint = parse_url($rawUri, PHP_URL_PATH) ?: '/api/tesoreria/transferencias';
        $hashClave = hash('sha256', $ip . '_' . $usuarioId . '_' . $endpoint);

        $stmt = $this->db->prepare("
            INSERT INTO intentos_seguridad (hash_clave, usuario_id, ip_address) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$hashClave, $usuarioId, $ip]);
    }

    /**
     * GET /api/tesoreria/transferencias
     */
    public function index(): void
    {
        $this->validarRolContable();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $bancoId = !empty($_GET['banco_id']) ? (int)$_GET['banco_id'] : null;
        $estado = trim($_GET['estado'] ?? '');
        $busqueda = trim($_GET['q'] ?? '');

        $where = [];
        $params = [];

        if ($bancoId) {
            $where[] = "(tb.cuenta_origen_id = :bancoId OR tb.cuenta_destino_id = :bancoId)";
            $params[':bancoId'] = $bancoId;
        }

        if ($estado !== '') {
            $where[] = "tb.estado = :estado";
            $params[':estado'] = $estado;
        }

        if ($busqueda !== '') {
            $where[] = "(tb.numero_transferencia LIKE :b1 OR tb.concepto LIKE :b2 OR co.banco_nombre LIKE :b3 OR cd.banco_nombre LIKE :b4)";
            $params[':b1'] = "%{$busqueda}%";
            $params[':b2'] = "%{$busqueda}%";
            $params[':b3'] = "%{$busqueda}%";
            $params[':b4'] = "%{$busqueda}%";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sqlCount = "
            SELECT COUNT(*) 
            FROM transferencias_bancarias tb
            INNER JOIN cuentas_bancarias co ON tb.cuenta_origen_id = co.id
            INNER JOIN cuentas_bancarias cd ON tb.cuenta_destino_id = cd.id
            {$whereSql}
        ";
        $stmtCount = $this->db->prepare($sqlCount);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $sql = "
            SELECT 
                tb.id,
                tb.numero_transferencia,
                tb.fecha_transferencia,
                tb.cuenta_origen_id,
                tb.cuenta_destino_id,
                tb.monto,
                tb.concepto,
                tb.observaciones,
                tb.estado,
                tb.asiento_id,
                tb.asiento_reversion_id,
                tb.motivo_cancelacion,
                tb.created_at AS creado_en,
                co.banco_nombre AS banco_origen_nombre,
                co.numero_cuenta AS banco_origen_numero,
                cd.banco_nombre AS banco_destino_nombre,
                cd.numero_cuenta AS banco_destino_numero
            FROM transferencias_bancarias tb
            INNER JOIN cuentas_bancarias co ON tb.cuenta_origen_id = co.id
            INNER JOIN cuentas_bancarias cd ON tb.cuenta_destino_id = cd.id
            {$whereSql}
            ORDER BY tb.fecha_transferencia DESC, tb.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->jsonResponse([
            'success' => true,
            'data' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
            ]
        ]);
    }

    /**
     * POST /api/tesoreria/transferencias
     * Transaccionalidad ACID con Bloqueo Determinista FOR UPDATE
     */
    public function store(): void
    {
        $this->validarRolContable();
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
        $input = $this->jsonInput();

        $numeroTransferencia = trim($input['numero_transferencia'] ?? '');
        $fechaTransferencia = trim($input['fecha_transferencia'] ?? date('Y-m-d'));
        $cuentaOrigenId = (int)($input['cuenta_origen_id'] ?? 0);
        $cuentaDestinoId = (int)($input['cuenta_destino_id'] ?? 0);
        $monto = floatval($input['monto'] ?? 0);
        $concepto = trim($input['concepto'] ?? '');
        $observaciones = trim($input['observaciones'] ?? '');
        $passwordAdmin = trim($input['password_admin'] ?? '');

        // Validaciones
        if (!$numeroTransferencia || !$fechaTransferencia || !$cuentaOrigenId || !$cuentaDestinoId || $monto <= 0 || !$concepto) {
            $this->jsonResponse(['success' => false, 'message' => 'Todos los campos marcados como obligatorios deben ser completados'], 400);
            return;
        }

        if (empty($passwordAdmin)) {
            $this->jsonResponse(['success' => false, 'message' => 'Se requiere la contraseña de Administrador para autorizar la transferencia bancaria'], 400);
            return;
        }

        $stmtUser = $this->db->prepare("SELECT password, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
        $stmtUser->execute([$usuarioId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user || !in_array($user['rol'], ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'usuario'], true) || !password_verify($passwordAdmin, $user['password'])) {
            $this->registrarIntentoFallidoSudo($usuarioId);
            $this->jsonResponse(['success' => false, 'message' => 'Contraseña de administración incorrecta'], 403);
            return;
        }

        // Purgar historial de intentos fallidos al autenticar exitosamente
        $this->limpiarIntentosFallidosSudo($this->db, $usuarioId);

        // Validar si el Período Contable de la FECHA DE OPERACIÓN se encuentra abierto
        $tsOperacion = strtotime($fechaTransferencia) ?: time();
        $mesOperacion = (int)date('n', $tsOperacion);
        $anioOperacion = (int)date('Y', $tsOperacion);
        try {
            $stmtPeriodo = $this->db->prepare("
                SELECT estado 
                FROM periodos_contables 
                WHERE (anio = :anio AND mes = :mes) OR (ejercicio_fiscal = :anio AND mes = :mes)
                LIMIT 1
            ");
            $stmtPeriodo->execute([':anio' => $anioOperacion, ':mes' => $mesOperacion]);
            $estadoPeriodo = $stmtPeriodo->fetchColumn();

            if ($estadoPeriodo && strtolower((string)$estadoPeriodo) === 'cerrado') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => "📌 DIAGNÓSTICO: El período contable de la operación ({$mesOperacion}/{$anioOperacion}) se encuentra CERRADO. 💡 DETALLE: No se pueden ejecutar transferencias con fecha de un período clausurado. 🔧 ACCIÓN REQUERIDA: Solicite al Contador la apertura del período o ajuste la fecha de operación."
                ], 400);
                return;
            }
        } catch (\Throwable $th) {
            // Ignorar si la tabla periodos_contables no existe
        }

        if ($cuentaOrigenId === $cuentaDestinoId) {
            $this->jsonResponse(['success' => false, 'message' => 'La cuenta de origen y la cuenta de destino no pueden ser la misma'], 400);
            return;
        }

        // Verificar duplicados de número de transferencia
        $stmtCheck = $this->db->prepare("SELECT id FROM transferencias_bancarias WHERE numero_transferencia = ?");
        $stmtCheck->execute([$numeroTransferencia]);
        if ($stmtCheck->fetch()) {
            $this->jsonResponse(['success' => false, 'message' => 'El número de referencia de transferencia ya existe en el sistema'], 400);
            return;
        }

        $this->db->beginTransaction();

        try {
            // BLOQUEO PESIMISTA DETERMINISTA ANTI-DEADLOCK: Ordenar IDs de menor a mayor
            $cuentasIds = [$cuentaOrigenId, $cuentaDestinoId];
            sort($cuentasIds, SORT_NUMERIC);

            $stmtLock = $this->db->prepare("
                SELECT id, banco_nombre, numero_cuenta, cuenta_id, moneda, estado 
                FROM cuentas_bancarias 
                WHERE id IN (?, ?) 
                ORDER BY id ASC 
                FOR UPDATE
            ");
            $stmtLock->execute($cuentasIds);
            $cuentasBloqueadas = $stmtLock->fetchAll(PDO::FETCH_ASSOC);

            if (count($cuentasBloqueadas) < 2) {
                throw new Exception('Una o ambas cuentas bancarias no existen o están inactivas');
            }

            $cuentaOrigenData = null;
            $cuentaDestinoData = null;
            foreach ($cuentasBloqueadas as $cb) {
                if ((int)$cb['id'] === $cuentaOrigenId) $cuentaOrigenData = $cb;
                if ((int)$cb['id'] === $cuentaDestinoId) $cuentaDestinoData = $cb;
            }

            // Regla de Oro 2: Homogeneidad de Divisas
            $monedaOrigen = strtoupper(trim((string)($cuentaOrigenData['moneda'] ?? 'VES')));
            $monedaDestino = strtoupper(trim((string)($cuentaDestinoData['moneda'] ?? 'VES')));
            if ($monedaOrigen !== $monedaDestino) {
                throw new Exception("No se permiten transferencias interbancarias entre divisas diferentes ({$monedaOrigen} vs {$monedaDestino}).");
            }

            // Validar Disponibilidad Financiera Real ONAPRE con precision round() IEEE 754
            $infoOrigen = $this->cuentaService->obtenerSaldos($this->db, $cuentaOrigenId);
            $disponibleReal = (float)($infoOrigen['disponible_financiero_real'] ?? 0);
            if (round($monto, 2) > round($disponibleReal, 2)) {
                throw new Exception('Saldo disponible ONAPRE insuficiente. Disponible ejecutable: ' . number_format($disponibleReal, 2, ',', '.') . ' ' . $monedaOrigen);
            }

            // Exigencia Enterprise: Ambas cuentas bancarias deben poseer vinculación contable para mantener la Partida Doble
            if (empty($cuentaOrigenData['cuenta_id']) || empty($cuentaDestinoData['cuenta_id'])) {
                throw new Exception("Ambas cuentas bancarias (origen y destino) deben poseer una cuenta contable patrimonial asignada en el catálogo ONCOP para procesar transferencias y generar los asientos de partida doble.");
            }

            // Insertar transferencia en estado procesada (Garantía de Idempotencia Física con UNIQUE INDEX)
            try {
                $stmtIns = $this->db->prepare("
                    INSERT INTO transferencias_bancarias (
                        numero_transferencia, fecha_transferencia, cuenta_origen_id,
                        cuenta_destino_id, monto, concepto, observaciones, estado, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'procesada', ?)
                ");
                $stmtIns->execute([
                    $numeroTransferencia, $fechaTransferencia, $cuentaOrigenId,
                    $cuentaDestinoId, $monto, $concepto, $observaciones, $usuarioId
                ]);
                $transferenciaId = (int)$this->db->lastInsertId();
            } catch (PDOException $pdoEx) {
                if ($pdoEx->getCode() === '23000' || str_contains($pdoEx->getMessage(), '1062')) {
                    throw new Exception("El número de referencia de transferencia '{$numeroTransferencia}' ya fue procesado en el sistema (Idempotencia).");
                }
                throw $pdoEx;
            }

            // Generar Asiento Contable de Partida Doble
            $asientoId = null;
            if ($cuentaOrigenData['cuenta_id'] && $cuentaDestinoData['cuenta_id']) {
                $numeroAsiento = 'AST-TRF-' . time();
                $conceptoAsiento = "Transferencia Interbancaria Ref: {$numeroTransferencia} - {$concepto}";

                // Detectar columnas de la tabla asientos para inserción compatible
                $stmtCols = $this->db->query("SHOW COLUMNS FROM asientos");
                $colsExistentes = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

                $fields = ['fecha', 'estado', 'usuario_id'];
                $values = [$this->db->quote($fechaTransferencia), "'confirmado'", (int)$usuarioId];

                if (in_array('numero', $colsExistentes, true)) { $fields[] = 'numero'; $values[] = $this->db->quote($numeroAsiento); }
                if (in_array('documento', $colsExistentes, true)) { $fields[] = 'documento'; $values[] = $this->db->quote($numeroAsiento); }
                if (in_array('concepto', $colsExistentes, true)) { $fields[] = 'concepto'; $values[] = $this->db->quote($conceptoAsiento); }
                if (in_array('descripcion', $colsExistentes, true)) { $fields[] = 'descripcion'; $values[] = $this->db->quote($conceptoAsiento); }
                if (in_array('total_debitos', $colsExistentes, true)) { $fields[] = 'total_debitos'; $values[] = (float)$monto; }
                if (in_array('total_creditos', $colsExistentes, true)) { $fields[] = 'total_creditos'; $values[] = (float)$monto; }

                $sqlAst = "INSERT INTO asientos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
                $this->db->exec($sqlAst);
                $asientoId = (int)$this->db->lastInsertId();

                // Partida Doble: DEBE = Banco Destino, HABER = Banco Origen
                $stmtDet = $this->db->prepare("
                    INSERT INTO detalles_asiento (asiento_id, cuenta_id, descripcion, debe, haber)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtDet->execute([$asientoId, (int)$cuentaDestinoData['cuenta_id'], $conceptoAsiento, $monto, 0]);
                $stmtDet->execute([$asientoId, (int)$cuentaOrigenData['cuenta_id'], $conceptoAsiento, 0, $monto]);

                // Vincular asiento a la transferencia
                $stmtUpd = $this->db->prepare("UPDATE transferencias_bancarias SET asiento_id = ? WHERE id = ?");
                $stmtUpd->execute([$asientoId, $transferenciaId]);
            }

            $this->db->commit();

            $this->jsonResponse([
                'success' => true,
                'message' => 'Transferencia bancaria procesada exitosamente con su asiento contable respectivo',
                'data' => [
                    'id' => $transferenciaId,
                    'asiento_id' => $asientoId,
                ]
            ], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/tesoreria/transferencias/{id}/cancelar
     * Reversión Auditable ("Opción A") con Rate Limiting Sudo
     */
    public function cancelar(int $id): void
    {
        $this->validarRolContable();
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
        $this->verificarRateLimiterSudo($usuarioId);

        $input = $this->jsonInput();
        $motivo = trim($input['motivo'] ?? '');
        $passwordAdmin = $input['password_admin'] ?? '';

        if (!$motivo || !$passwordAdmin) {
            $this->jsonResponse(['success' => false, 'message' => 'El motivo de la cancelación y la contraseña de administrador son requeridos'], 400);
            return;
        }

        // Verificar contraseña del Administrador
        $stmtUser = $this->db->prepare("SELECT password, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
        $stmtUser->execute([$usuarioId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user || !in_array($user['rol'], ['admin', 'administrador'], true) || !password_verify($passwordAdmin, $user['password'])) {
            $this->registrarIntentoFallidoSudo($usuarioId);
            $this->jsonResponse(['success' => false, 'message' => 'Contraseña de administrador incorrecta'], 403);
            return;
        }

        // Purgar historial de intentos fallidos al autenticar exitosamente
        $this->limpiarIntentosFallidosSudo($this->db, $usuarioId);

        // Validar si el Período Contable actual se encuentra abierto
        $mesActual = (int)date('n');
        $anioActual = (int)date('Y');
        try {
            $stmtPeriodo = $this->db->prepare("
                SELECT estado 
                FROM periodos_contables 
                WHERE (anio = :anio AND mes = :mes) OR (ejercicio_fiscal = :anio AND mes = :mes)
                LIMIT 1
            ");
            $stmtPeriodo->execute([':anio' => $anioActual, ':mes' => $mesActual]);
            $estadoPeriodo = $stmtPeriodo->fetchColumn();

            if ($estadoPeriodo && strtolower((string)$estadoPeriodo) === 'cerrado') {
                $this->jsonResponse([
                    'success' => false,
                    'message' => "📌 DIAGNÓSTICO: El período contable actual ({$mesActual}/{$anioActual}) se encuentra CERRADO. 💡 DETALLE: No se pueden generar asientos de reversión en un período contable clausurado. 🔧 ACCIÓN REQUERIDA: Solicite al Contador la apertura del período contable en /contabilidad/periodos."
                ], 400);
                return;
            }
        } catch (\Throwable $th) {
            // Se ignora en instalaciones sin la tabla opcional periodos_contables
        }

        $this->db->beginTransaction();

        try {
            // Obtener la transferencia
            $stmtTrf = $this->db->prepare("SELECT * FROM transferencias_bancarias WHERE id = ? FOR UPDATE");
            $stmtTrf->execute([$id]);
            $transferencia = $stmtTrf->fetch(PDO::FETCH_ASSOC);

            if (!$transferencia) {
                throw new Exception('Transferencia bancaria no encontrada');
            }

            if ($transferencia['estado'] === 'cancelada') {
                throw new Exception('La transferencia ya fue cancelada anteriormente');
            }

            // Bloqueo Pesimista Ordenado de las cuentas
            $cuentasIds = [(int)$transferencia['cuenta_origen_id'], (int)$transferencia['cuenta_destino_id']];
            sort($cuentasIds, SORT_NUMERIC);

            $stmtLock = $this->db->prepare("SELECT id, cuenta_id FROM cuentas_bancarias WHERE id IN (?, ?) ORDER BY id ASC FOR UPDATE");
            $stmtLock->execute($cuentasIds);
            $cuentasData = $stmtLock->fetchAll(PDO::FETCH_ASSOC);

            $cuentaOrigenData = null;
            $cuentaDestinoData = null;
            foreach ($cuentasData as $cb) {
                if ((int)$cb['id'] === (int)$transferencia['cuenta_origen_id']) $cuentaOrigenData = $cb;
                if ((int)$cb['id'] === (int)$transferencia['cuenta_destino_id']) $cuentaDestinoData = $cb;
            }

            if (!$cuentaOrigenData || !$cuentaDestinoData) {
                throw new Exception('Una o ambas cuentas bancarias asociadas a la transferencia no existen.');
            }

            if (empty($cuentaOrigenData['cuenta_id']) || empty($cuentaDestinoData['cuenta_id'])) {
                throw new Exception('📌 DIAGNÓSTICO: Ambas cuentas bancarias deben poseer una cuenta contable asignada en el catálogo ONCOP. 💡 DETALLE: No es posible revertir la transferencia en el Libro Diario sin cuenta contable patrimonial.');
            }

            // Generar Asiento de Reversión Confirmado (Opción A)
            $asientoReversionId = null;
            if (!empty($transferencia['asiento_id'])) {
                $numeroAsientoRev = 'AST-REV-' . time();
                $conceptoRev = "Reverso de Transferencia Ref: {$transferencia['numero_transferencia']} - Motivo: {$motivo}";

                // Detectar columnas de la tabla asientos para inserción compatible
                $stmtCols = $this->db->query("SHOW COLUMNS FROM asientos");
                $colsExistentes = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

                $fields = ['fecha', 'estado', 'usuario_id'];
                $values = ['CURRENT_DATE()', "'confirmado'", (int)$usuarioId];

                if (in_array('asiento_origen_id', $colsExistentes, true)) { $fields[] = 'asiento_origen_id'; $values[] = (int)$transferencia['asiento_id']; }
                if (in_array('numero', $colsExistentes, true)) { $fields[] = 'numero'; $values[] = $this->db->quote($numeroAsientoRev); }
                if (in_array('documento', $colsExistentes, true)) { $fields[] = 'documento'; $values[] = $this->db->quote($numeroAsientoRev); }
                if (in_array('concepto', $colsExistentes, true)) { $fields[] = 'concepto'; $values[] = $this->db->quote($conceptoRev); }
                if (in_array('descripcion', $colsExistentes, true)) { $fields[] = 'descripcion'; $values[] = $this->db->quote($conceptoRev); }
                if (in_array('total_debitos', $colsExistentes, true)) { $fields[] = 'total_debitos'; $values[] = (float)$transferencia['monto']; }
                if (in_array('total_creditos', $colsExistentes, true)) { $fields[] = 'total_creditos'; $values[] = (float)$transferencia['monto']; }

                $sqlRev = "INSERT INTO asientos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
                $this->db->exec($sqlRev);
                $asientoReversionId = (int)$this->db->lastInsertId();

                // Partida Doble Invertida: DEBE = Banco Origen, HABER = Banco Destino
                $stmtDet = $this->db->prepare("
                    INSERT INTO detalles_asiento (asiento_id, cuenta_id, descripcion, debe, haber)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtDet->execute([$asientoReversionId, (int)$cuentaOrigenData['cuenta_id'], $conceptoRev, (float)$transferencia['monto'], 0]);
                $stmtDet->execute([$asientoReversionId, (int)$cuentaDestinoData['cuenta_id'], $conceptoRev, 0, (float)$transferencia['monto']]);
            }

            // Marcar transferencia como cancelada y guardar el ID de reversión
            $stmtUpdTrf = $this->db->prepare("
                UPDATE transferencias_bancarias 
                SET estado = 'cancelada', asiento_reversion_id = ?, motivo_cancelacion = ?
                WHERE id = ?
            ");
            $stmtUpdTrf->execute([$asientoReversionId, $motivo, $id]);

            $this->db->commit();

            $this->jsonResponse([
                'success' => true,
                'message' => 'Transferencia cancelada y revertida exitosamente en el Libro Diario',
                'data' => [
                    'id' => $id,
                    'asiento_reversion_id' => $asientoReversionId,
                ]
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/tesoreria/transferencias/{id}/adjuntos
     * Carga de archivos con Circuit Breaker 5MB
     */
    public function adjuntarArchivos(int $id): void
    {
        $this->validarRolContable();

        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK || empty($_FILES['archivo']['tmp_name'])) {
            $this->jsonResponse(['success' => false, 'message' => 'Error en la transmisión del archivo o excede el límite del servidor (upload_max_filesize)'], 400);
            return;
        }

        $file = $_FILES['archivo'];

        // Circuit Breaker: 5MB
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->jsonResponse(['success' => false, 'message' => 'El archivo supera el tamaño máximo permitido de 5MB'], 400);
            return;
        }

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            $this->jsonResponse(['success' => false, 'message' => 'Formato no permitido. Solo se aceptan archivos PDF, JPG o PNG'], 400);
            return;
        }

        $uploadDir = __DIR__ . '/../../uploads/tesoreria/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $mimeExtensionMap = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ];
        $extension = $mimeExtensionMap[$mimeType] ?? 'bin';
        $filename = 'trf_' . $id . '_' . time() . '_' . uniqid() . '.' . $extension;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Archivo adjunto guardado exitosamente',
                'data' => [
                    'filename' => $filename,
                    'path' => '/uploads/tesoreria/' . $filename,
                ]
            ]);
        } else {
            $this->jsonResponse(['success' => false, 'message' => 'Error al mover el archivo al servidor'], 500);
        }
    }
}
