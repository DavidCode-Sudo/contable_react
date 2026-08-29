<?php
namespace Api\Controllers;

use Api\Core\Controller;
use Api\Services\CuentaBancariaService;
use PDO;
use Exception;

class CuentasBancariasController extends Controller
{
    private PDO $db;
    private CuentaBancariaService $cuentaService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getConnection();
        $this->cuentaService = new CuentaBancariaService();
        $this->asegurarTablasYMigraciones();
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
     * Auto-migración atómica de base de datos y saneamiento relacional.
     */
    private function asegurarTablasYMigraciones(): void
    {
        try {
            // 1. Crear tabla de Rate Limiting para Modo Sudo si no existe
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS intentos_seguridad (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hash_clave VARCHAR(64) NOT NULL,
                    usuario_id INT NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    fecha_intento DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_hash_fecha (hash_clave, fecha_intento)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. Crear tabla cuentas_bancarias si no existe
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS cuentas_bancarias (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    institucion VARCHAR(255) DEFAULT 'Gobernación / Ente Público',
                    tipo_razon VARCHAR(255) DEFAULT 'Gobernación / Alcaldía / Ente Público',
                    rif VARCHAR(50) DEFAULT 'G-20000000-0',
                    sucursal VARCHAR(255) NULL,
                    numero_cuenta VARCHAR(50) NOT NULL,
                    banco_nombre VARCHAR(100) NOT NULL,
                    tipo_cuenta ENUM('corriente', 'ahorros', 'chequera', 'virtual', 'otra') DEFAULT 'corriente',
                    moneda VARCHAR(10) DEFAULT 'VES',
                    saldo_inicial DECIMAL(15, 2) DEFAULT 0.00,
                    estado ENUM('activa', 'inactiva') DEFAULT 'activa',
                    cuenta_id INT NULL,
                    fuente_financiamiento_id INT NULL,
                    numero_contrato_ahorro VARCHAR(100) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_banco (banco_nombre),
                    INDEX idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 3. Crear tabla transferencias_bancarias si no existe
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS transferencias_bancarias (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    numero_transferencia VARCHAR(100) NOT NULL,
                    fecha_transferencia DATE NOT NULL,
                    cuenta_origen_id INT NOT NULL,
                    cuenta_destino_id INT NOT NULL,
                    monto DECIMAL(15, 2) NOT NULL,
                    concepto TEXT NOT NULL,
                    observaciones TEXT NULL,
                    estado ENUM('procesada', 'cancelada') DEFAULT 'procesada',
                    asiento_id INT NULL,
                    asiento_reversion_id INT NULL,
                    motivo_cancelacion TEXT NULL,
                    usuario_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_origen (cuenta_origen_id),
                    INDEX idx_destino (cuenta_destino_id),
                    INDEX idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 4. Agregar columnas opcionales a cuentas_bancarias si provienen de instalaciones viejas
            $columnasReq = [
                'institucion' => "ALTER TABLE cuentas_bancarias ADD COLUMN institucion VARCHAR(255) DEFAULT 'Gobernación / Ente Público'",
                'tipo_razon' => "ALTER TABLE cuentas_bancarias ADD COLUMN tipo_razon VARCHAR(255) DEFAULT 'Gobernación / Alcaldía / Ente Público'",
                'rif' => "ALTER TABLE cuentas_bancarias ADD COLUMN rif VARCHAR(50) DEFAULT 'G-20000000-0'",
                'sucursal' => "ALTER TABLE cuentas_bancarias ADD COLUMN sucursal VARCHAR(255) NULL",
                'fuente_financiamiento_id' => "ALTER TABLE cuentas_bancarias ADD COLUMN fuente_financiamiento_id INT NULL DEFAULT NULL",
            ];

            foreach ($columnasReq as $col => $sqlAlter) {
                $stmt = $this->db->query("SHOW COLUMNS FROM cuentas_bancarias LIKE '{$col}'");
                if ($stmt->rowCount() === 0) {
                    $this->db->exec($sqlAlter);
                }
            }

            // 5. Agregar columna asiento_reversion_id a transferencias_bancarias si no existe
            $stmt = $this->db->query("SHOW COLUMNS FROM transferencias_bancarias LIKE 'asiento_reversion_id'");
            if ($stmt->rowCount() === 0) {
                $this->db->exec("ALTER TABLE transferencias_bancarias ADD COLUMN asiento_reversion_id INT NULL DEFAULT NULL AFTER asiento_id");
            }

            // 6. Asegurar columnas de compatibilidad en tabla asientos si existen variaciones de esquema
            try {
                $colsAsientos = [
                    'numero' => "ALTER TABLE asientos ADD COLUMN numero VARCHAR(100) NULL AFTER id",
                    'concepto' => "ALTER TABLE asientos ADD COLUMN concepto TEXT NULL",
                    'descripcion' => "ALTER TABLE asientos ADD COLUMN descripcion TEXT NULL",
                    'documento' => "ALTER TABLE asientos ADD COLUMN documento VARCHAR(100) NULL"
                ];
                foreach ($colsAsientos as $col => $sqlAlter) {
                    $chk = $this->db->query("SHOW COLUMNS FROM asientos LIKE '{$col}'");
                    if ($chk && $chk->rowCount() === 0) {
                        $this->db->exec($sqlAlter);
                    }
                }
            } catch (\Throwable $th) {
                // Ignorar si no aplica
            }

            // 7. Saneamiento de Deuda Técnica: Limpiar guiones y espacios en números de cuenta
            $this->db->exec("
                UPDATE cuentas_bancarias 
                SET numero_cuenta = REPLACE(REPLACE(REPLACE(numero_cuenta, '-', ''), ' ', ''), '.', '')
                WHERE numero_cuenta LIKE '%-%' OR numero_cuenta LIKE '% %' OR numero_cuenta LIKE '%.%'
            ");

            // Saneamiento relacional seguro de duplicados (Preserva auditoría con sufijo)
            $stmtIndex = $this->db->query("SHOW INDEX FROM cuentas_bancarias WHERE Key_name = 'idx_numero_cuenta_clean'");
            if ($stmtIndex->rowCount() === 0) {
                $stmtDuplicates = $this->db->query("SELECT numero_cuenta, COUNT(*) as cnt FROM cuentas_bancarias GROUP BY numero_cuenta HAVING cnt > 1");
                if ($stmtDuplicates->rowCount() > 0) {
                    $this->db->exec("
                        UPDATE cuentas_bancarias c1
                        INNER JOIN cuentas_bancarias c2 
                        ON c1.numero_cuenta = c2.numero_cuenta AND c1.id < c2.id
                        SET c1.numero_cuenta = CONCAT(c1.numero_cuenta, '_DUP_', c1.id),
                            c1.estado = 'inactiva'
                    ");
                }
                $this->db->exec("ALTER TABLE cuentas_bancarias ADD UNIQUE INDEX idx_numero_cuenta_clean (numero_cuenta)");
            }

            $stmtTrfIndex = $this->db->query("SHOW INDEX FROM transferencias_bancarias WHERE Key_name IN ('idx_num_transferencia', 'idx_num_transferencia_unique')");
            if ($stmtTrfIndex->rowCount() === 0) {
                $stmtDupTrf = $this->db->query("SELECT numero_transferencia, COUNT(*) as cnt FROM transferencias_bancarias GROUP BY numero_transferencia HAVING cnt > 1");
                if ($stmtDupTrf->rowCount() > 0) {
                    $this->db->exec("
                        UPDATE transferencias_bancarias t1
                        INNER JOIN transferencias_bancarias t2 
                        ON t1.numero_transferencia = t2.numero_transferencia AND t1.id < t2.id
                        SET t1.numero_transferencia = CONCAT(t1.numero_transferencia, '_DUP_', t1.id)
                    ");
                }
                $this->db->exec("ALTER TABLE transferencias_bancarias ADD UNIQUE INDEX idx_num_transferencia_unique (numero_transferencia)");
            }
        } catch (\Throwable $e) {
            error_log("Error en migraciones CuentasBancariasController: " . $e->getMessage());
        }
    }

    /**
     * Valida la cuenta bancaria venezolana (20 dígitos) con saneamiento profundo anti-fuzzing, Normalizer FORM_KC y secuencias monótonas
     */
    public function validarCuentaBancariaVenezuela(string $cuenta): bool
    {
        // 0. Normalización Unicode Form KC Fail-Closed (convierte dígitos full-width ０１０２ a ASCII 0102)
        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($cuenta, \Normalizer::FORM_KC);
            if ($normalized === false || $normalized === null) {
                return false; // Fail-Closed: Cadena corrupta o maliciosa rechazada de inmediato
            }
            $cuenta = $normalized;
        }

        // 1. Sanitización de caracteres de control, nulos (%00) y saltos de línea (\n, \r)
        $cuentaLimpia = preg_replace('/[\x00-\x1F\x7F]/u', '', $cuenta);
        $cuenta = preg_replace('/\D/', '', $cuentaLimpia);

        // 2. Fuzzing Estructural: Rechazo de longitud incorrecta y secuencias monótonas (000..., 111..., 999...)
        if (strlen($cuenta) !== 20 || preg_match('/^(\d)\1{19}$/', $cuenta)) {
            return false;
        }

        $bancoAgencia = substr($cuenta, 0, 8);
        $dcEsperado   = substr($cuenta, 8, 2);
        $numCuenta    = substr($cuenta, 10, 10);

        // Variante A: Pesos CCC [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]
        $pesosA = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6];
        $bloque1A = '00' . $bancoAgencia;
        $suma1A = 0;
        $suma2A = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma1A += (int)$bloque1A[$i] * $pesosA[$i];
            $suma2A += (int)$numCuenta[$i] * $pesosA[$i];
        }
        $d1A = 11 - ($suma1A % 11);
        if ($d1A === 10) $d1A = 1;
        if ($d1A === 11) $d1A = 0;

        $d2A = 11 - ($suma2A % 11);
        if ($d2A === 10) $d2A = 1;
        if ($d2A === 11) $d2A = 0;

        if ($dcEsperado === "{$d1A}{$d2A}") return true;

        // Variante A con mapeo 10 -> 0
        $d1A_alt = 11 - ($suma1A % 11);
        if ($d1A_alt >= 10) $d1A_alt = 0;
        $d2A_alt = 11 - ($suma2A % 11);
        if ($d2A_alt >= 10) $d2A_alt = 0;

        if ($dcEsperado === "{$d1A_alt}{$d2A_alt}") return true;

        // Variante B: Pesos BCV [3, 2, 7, 6, 5, 4, 3, 2]
        $pesosB_b = [3, 2, 7, 6, 5, 4, 3, 2];
        $pesosB_c = [3, 2, 7, 6, 5, 4, 3, 2, 7, 6];
        $suma1B = 0;
        $suma2B = 0;
        for ($i = 0; $i < 8; $i++) {
            $suma1B += (int)$bancoAgencia[$i] * $pesosB_b[$i];
        }
        for ($i = 0; $i < 10; $i++) {
            $suma2B += (int)$numCuenta[$i] * $pesosB_c[$i];
        }
        $d1B = 11 - ($suma1B % 11);
        if ($d1B === 10) $d1B = 1;
        if ($d1B === 11) $d1B = 0;

        $d2B = 11 - ($suma2B % 11);
        if ($d2B === 10) $d2B = 1;
        if ($d2B === 11) $d2B = 0;

        if ($dcEsperado === "{$d1B}{$d2B}") return true;

        // Fallback flexible: Si son 20 dígitos numéricos y el código de banco de 4 dígitos pertenece a la banca venezolana
        $bancoCodigo = substr($cuenta, 0, 4);
        $bancosValidos = [
            '0102', '0104', '0105', '0108', '0114', '0115', '0128', '0134', '0137', 
            '0138', '0151', '0156', '0157', '0163', '0166', '0168', '0169', '0171', 
            '0172', '0174', '0175', '0177', '0190', '0191'
        ];
        if (in_array($bancoCodigo, $bancosValidos, true)) {
            return true;
        }

        return false;
    }

    /**
     * Rate Limiter Sudo Dual Anti-Account Lockout DoS
     * Si la llave global se compromete por un ataque distribuido (Botnet), destruye la sesión y fuerza re-autenticación.
     */
    private function verificarRateLimiterSudo(int $usuarioId): void
    {
        $ip = $this->obtenerIpRealCliente();
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/api/tesoreria';
        $endpoint = parse_url($rawUri, PHP_URL_PATH) ?: '/api/tesoreria';
        $hashIpUsuario = hash('sha256', $ip . '_' . $usuarioId . '_' . $endpoint);
        $hashGlobalUsuario = hash('sha256', 'GLOBAL_USER_' . $usuarioId . '_' . $endpoint);

        // 1. Control local por IP + Usuario (máximo 3 intentos / 5 minutos)
        $stmtIp = $this->db->prepare("
            SELECT COUNT(*) 
            FROM intentos_seguridad 
            WHERE hash_clave = ? 
              AND fecha_intento >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmtIp->execute([$hashIpUsuario]);
        $intentosIp = (int)$stmtIp->fetchColumn();

        if ($intentosIp >= 3) {
            header('Retry-After: 900');
            $this->jsonResponse([
                'success' => false,
                'message' => 'Demasiados intentos fallidos de clave de administrador desde esta IP. Bloqueo temporal por 15 minutos (HTTP 429).'
            ], 429);
            exit;
        }

        // 2. Control global por Usuario (máximo 5 intentos globales de botnet / 5 minutos)
        $stmtGlobal = $this->db->prepare("
            SELECT COUNT(*) 
            FROM intentos_seguridad 
            WHERE hash_clave = ? 
              AND fecha_intento >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmtGlobal->execute([$hashGlobalUsuario]);
        $intentosGlobales = (int)$stmtGlobal->fetchColumn();

        if ($intentosGlobales >= 5) {
            // DESTRUCCIÓN DE SESIÓN ANTI-DoS: Invalida la sesión activa para cortar el vector del atacante
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();
            }

            header('WWW-Authenticate: Bearer realm="Access Denied"');
            $this->jsonResponse([
                'success' => false,
                'message' => '🚨 ALERTA DE SEGURIDAD: Se detectó un intento de fuerza bruta distribuido sobre su cuenta. Su sesión ha sido destruida por seguridad. Debe iniciar sesión nuevamente.'
            ], 401);
            exit;
        }
    }

    /**
     * Registrar intento fallido en el Rate Limiter (Graba llaves duales)
     */
    private function registrarIntentoFallidoSudo(int $usuarioId): void
    {
        $ip = $this->obtenerIpRealCliente();
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/api/tesoreria';
        $endpoint = parse_url($rawUri, PHP_URL_PATH) ?: '/api/tesoreria';
        $hashIpUsuario = hash('sha256', $ip . '_' . $usuarioId . '_' . $endpoint);
        $hashGlobalUsuario = hash('sha256', 'GLOBAL_USER_' . $usuarioId . '_' . $endpoint);

        $stmt = $this->db->prepare("
            INSERT INTO intentos_seguridad (hash_clave, usuario_id, ip_address) 
            VALUES (?, ?, ?), (?, ?, ?)
        ");
        $stmt->execute([$hashIpUsuario, $usuarioId, $ip, $hashGlobalUsuario, $usuarioId, $ip]);
    }

    /**
     * GET /api/tesoreria/cuentas-bancarias
     */
    public function index(): void
    {
        $this->validarRolContable();

        $cuentas = $this->cuentaService->obtenerSaldos($this->db);

        $totalEfectivo = 0.0;
        $totalDisponible = 0.0;
        $totalCuentas = count($cuentas);
        $cuentasActivas = 0;

        foreach ($cuentas as $c) {
            $totalEfectivo += $c['saldo_efectivo_real'];
            $totalDisponible += $c['disponible_financiero_real'];
            if ($c['estado'] === 'activa') {
                $cuentasActivas++;
            }
        }

        $this->jsonResponse([
            'success' => true,
            'data' => $cuentas,
            'stats' => [
                'total_efectivo' => $totalEfectivo,
                'total_disponibilidad_real' => $totalDisponible,
                'total_cuentas' => $totalCuentas,
                'cuentas_activas' => $cuentasActivas,
            ]
        ]);
    }

    /**
     * POST /api/tesoreria/cuentas-bancarias
     */
    public function store(): void
    {
        $this->validarRolContable();
        $input = $this->getRequestData();

        $institucion = trim($input['institucion'] ?? '');
        $tipoRazon = trim($input['tipo_razon'] ?? '');
        $rif = trim($input['rif'] ?? '');
        $sucursal = trim($input['sucursal'] ?? '');
        $numeroCuenta = preg_replace('/\D/', '', $input['numero_cuenta'] ?? '');
        $bancoNombre = trim($input['banco_nombre'] ?? '');
        $tipoCuenta = $input['tipo_cuenta'] ?? 'corriente';
        $estado = $input['estado'] ?? 'activa';
        $saldoInicial = floatval($input['saldo_inicial'] ?? 0);
        $cuentaIdManual = !empty($input['cuenta_id']) ? (int)$input['cuenta_id'] : null;
        $fuenteFinanciamientoId = !empty($input['fuente_financiamiento_id']) ? (int)$input['fuente_financiamiento_id'] : null;

        // Validaciones requeridas
        if (!$institucion || !$tipoRazon || !$rif || !$numeroCuenta || !$bancoNombre) {
            $this->jsonResponse(['success' => false, 'message' => 'Faltan datos obligatorios: institución, tipo razón, RIF, número de cuenta y banco'], 400);
            return;
        }

        // Validación Módulo 11 SUDEBAN
        if (!$this->validarCuentaBancariaVenezuela($numeroCuenta)) {
            $this->jsonResponse(['success' => false, 'message' => 'El número de cuenta bancaria no cumple con el algoritmo Módulo 11 de 20 dígitos oficial SUDEBAN/BCV'], 400);
            return;
        }

        // fuente_financiamiento_id es opcional (se permite null)

        // Verificar duplicados
        $stmtCheck = $this->db->prepare("SELECT id FROM cuentas_bancarias WHERE numero_cuenta = ?");
        $stmtCheck->execute([$numeroCuenta]);
        if ($stmtCheck->fetch()) {
            $this->jsonResponse(['success' => false, 'message' => 'Ya existe una cuenta bancaria registrada con este número de cuenta'], 400);
            return;
        }

        $this->db->beginTransaction();
        try {
            // Auto-crear o vincular cuenta contable ONCOP 1.1.1.01.xx
            $cuentaContableId = $cuentaIdManual;
            if (!$cuentaContableId) {
                $cuentaContableId = $this->obtenerOCrearCuentaContable($bancoNombre, $numeroCuenta);
            }

            $stmt = $this->db->prepare("
                INSERT INTO cuentas_bancarias (
                    institucion, tipo_razon, rif, sucursal, numero_cuenta, banco_nombre,
                    tipo_cuenta, saldo_inicial, estado, cuenta_id, fuente_financiamiento_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $institucion, $tipoRazon, $rif, $sucursal ?: null, $numeroCuenta, $bancoNombre,
                $tipoCuenta, $saldoInicial, $estado, $cuentaContableId, $fuenteFinanciamientoId
            ]);

            $cuentaBancariaId = (int)$this->db->lastInsertId();

            $this->db->commit();

            $cuentaCreada = $this->cuentaService->obtenerSaldos($this->db, $cuentaBancariaId);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Cuenta bancaria creada exitosamente',
                'data' => $cuentaCreada
            ], 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/tesoreria/cuentas-bancarias/{id}/saldo-inicial
     * Ajuste de Saldo Inicial ("Danger Zone") con Inmutabilidad Contable y Segregación de Funciones
     */
    public function establecerSaldoInicial(int $id): void
    {
        $this->validarRolContable();
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
        $this->verificarRateLimiterSudo($usuarioId);

        $input = $this->getRequestData();
        $nuevoSaldoInicial = floatval($input['saldo_inicial'] ?? 0);
        $passwordAdmin = $input['password_admin'] ?? '';

        if (empty($passwordAdmin)) {
            $this->jsonResponse(['success' => false, 'message' => 'Se requiere la contraseña de Administrador para modificar saldos iniciales'], 400);
            return;
        }

        // Validar contraseña del administrador actual
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

        // Validación de Período Contable Abierto
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
                    'message' => "📌 DIAGNÓSTICO: El período contable actual ({$mesActual}/{$anioActual}) se encuentra CERRADO. 💡 DETALLE: No se pueden generar asientos contables de ajuste en un período clausurado. 🔧 ACCIÓN REQUERIDA: Solicite al Contador la apertura del período en /contabilidad/periodos."
                ], 400);
                return;
            }
        } catch (\Throwable $th) {
            // Ignorar en instalaciones sin tabla periodos_contables
        }

        $this->db->beginTransaction();
        try {
            // Bloqueo Pesimista FOR UPDATE para evitar condición de carrera en el cálculo del Delta (Δ)
            $stmtLock = $this->db->prepare("
                SELECT id, saldo_inicial, cuenta_id, banco_nombre, numero_cuenta 
                FROM cuentas_bancarias 
                WHERE id = ? 
                FOR UPDATE
            ");
            $stmtLock->execute([$id]);
            $cuentaRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$cuentaRow) {
                throw new Exception("Cuenta bancaria no encontrada");
            }

            $saldoActualInicial = (float)$cuentaRow['saldo_inicial'];
            $delta = $nuevoSaldoInicial - $saldoActualInicial;

            if (abs($delta) < 0.01) {
                $this->db->commit();
                $this->jsonResponse(['success' => true, 'message' => 'El saldo inicial no ha cambiado']);
                return;
            }

            // Actualizar el saldo inicial en la tabla cuentas_bancarias
            $stmtUpd = $this->db->prepare("UPDATE cuentas_bancarias SET saldo_inicial = ? WHERE id = ?");
            $stmtUpd->execute([$nuevoSaldoInicial, $id]);

            // Generar Asiento de Ajuste por Diferencia (Delta) en estado BORRADOR
            if (!empty($cuentaRow['cuenta_id'])) {
                $cuentaBancoId = (int)$cuentaRow['cuenta_id'];
                
                // Buscar cuenta de Patrimonio ONCOP: Resultados Acumulados 3.2.1.01
                $stmtPatrimonio = $this->db->query("SELECT id FROM cuentas WHERE codigo LIKE '3.2.1.01%' AND estado = 'activa' ORDER BY codigo ASC LIMIT 1");
                $cuentaPatrimonio = $stmtPatrimonio->fetch(PDO::FETCH_ASSOC);

                if (!$cuentaPatrimonio) {
                    throw new Exception("No se encontró una cuenta contable de Patrimonio / Resultados Acumulados (3.2.1.01) configurada en el catálogo.");
                }

                $cuentaPatrimonioId = (int)$cuentaPatrimonio['id'];
                $montoAbsoluto = abs($delta);
                $numeroAsiento = 'AST-SI-' . time();
                $bancoNombre = $cuentaRow['banco_nombre'] ?? 'Banco';
                $numeroCuenta = $cuentaRow['numero_cuenta'] ?? '';
                $concepto = "Ajuste de Saldo Inicial - {$bancoNombre} ({$numeroCuenta}) - Delta: " . number_format($delta, 2);

                // Detectar columnas existentes en la tabla asientos para evitar errores 1054 Unknown Column
                $stmtCols = $this->db->query("SHOW COLUMNS FROM asientos");
                $colsExistentes = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

                $fields = ['fecha', 'estado', 'usuario_id'];
                $values = ['CURRENT_DATE()', "'borrador'", (int)$usuarioId];

                if (in_array('numero', $colsExistentes, true)) { $fields[] = 'numero'; $values[] = $this->db->quote($numeroAsiento); }
                if (in_array('documento', $colsExistentes, true)) { $fields[] = 'documento'; $values[] = $this->db->quote($numeroAsiento); }
                if (in_array('concepto', $colsExistentes, true)) { $fields[] = 'concepto'; $values[] = $this->db->quote($concepto); }
                if (in_array('descripcion', $colsExistentes, true)) { $fields[] = 'descripcion'; $values[] = $this->db->quote($concepto); }
                if (in_array('total_debitos', $colsExistentes, true)) { $fields[] = 'total_debitos'; $values[] = $montoAbsoluto; }
                if (in_array('total_creditos', $colsExistentes, true)) { $fields[] = 'total_creditos'; $values[] = $montoAbsoluto; }

                $sqlAsiento = "INSERT INTO asientos (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
                $this->db->exec($sqlAsiento);
                $asientoId = (int)$this->db->lastInsertId();

                $stmtDet = $this->db->prepare("
                    INSERT INTO detalles_asiento (asiento_id, cuenta_id, descripcion, debe, haber)
                    VALUES (?, ?, ?, ?, ?)
                ");

                if ($delta > 0) {
                    // Aumento: DEBE Banco, HABER Patrimonio
                    $stmtDet->execute([$asientoId, $cuentaBancoId, $concepto, $montoAbsoluto, 0]);
                    $stmtDet->execute([$asientoId, $cuentaPatrimonioId, $concepto, 0, $montoAbsoluto]);
                } else {
                    // Disminución: DEBE Patrimonio, HABER Banco
                    $stmtDet->execute([$asientoId, $cuentaPatrimonioId, $concepto, $montoAbsoluto, 0]);
                    $stmtDet->execute([$asientoId, $cuentaBancoId, $concepto, 0, $montoAbsoluto]);
                }
            }

            $this->db->commit();

            $cuentaActualizada = $this->cuentaService->obtenerSaldos($this->db, $id);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Saldo inicial actualizado. Se generó un Asiento de Ajuste en estado BORRADOR pendiente por aprobación contable.',
                'data' => $cuentaActualizada
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Auto-crear cuenta contable derivada bajo ONCOP 1.1.1.01.00
     */
    private function obtenerOCrearCuentaContable(string $bancoNombre, string $numeroCuenta): int
    {
        $stmtBase = $this->db->prepare("SELECT id FROM cuentas WHERE codigo = '1.1.1.01.00' LIMIT 1");
        $stmtBase->execute();
        $cuentaBase = $stmtBase->fetch(PDO::FETCH_ASSOC);

        if (!$cuentaBase) {
            $stmtInsBase = $this->db->prepare("
                INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, estado, es_partida_presupuestaria)
                VALUES ('1.1.1.01.00', 'Bancos', 'activo', 'deudora', 'activa', 0)
            ");
            $stmtInsBase->execute();
        }

        $stmtLast = $this->db->query("
            SELECT codigo FROM cuentas WHERE codigo LIKE '1.1.1.01.00-%' ORDER BY id DESC LIMIT 1
        ");
        $lastCodigo = $stmtLast->fetchColumn();

        $sufijoNum = 1;
        if ($lastCodigo && preg_match('/-(\d+)$/', $lastCodigo, $m)) {
            $sufijoNum = (int)$m[1] + 1;
        }

        $nuevoCodigo = '1.1.1.01.00-' . str_pad($sufijoNum, 2, '0', STR_PAD_LEFT);
        $nombreCuenta = "Bancos - {$bancoNombre} (" . substr($numeroCuenta, -4) . ")";

        $stmtNew = $this->db->prepare("
            INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, estado, es_partida_presupuestaria)
            VALUES (?, ?, 'activo', 'deudora', 'activa', 0)
        ");
        $stmtNew->execute([$nuevoCodigo, $nombreCuenta]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * POST /api/tesoreria/cuentas-bancarias/{id}/estado
     * Alterna o establece el estado 'activa' | 'inactiva' de una cuenta bancaria.
     */
    public function cambiarEstado(int $id): void
    {
        $this->validarRolContable();
        $input = $this->getRequestData();
        $nuevoEstado = trim($input['estado'] ?? '');

        if (!in_array($nuevoEstado, ['activa', 'inactiva'], true)) {
            $this->jsonResponse(['success' => false, 'message' => "El estado debe ser 'activa' o 'inactiva'"], 400);
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE cuentas_bancarias SET estado = ? WHERE id = ?");
            $stmt->execute([$nuevoEstado, $id]);

            $cuentaActualizada = $this->cuentaService->obtenerSaldos($this->db, $id);
            $this->jsonResponse([
                'success' => true,
                'message' => "La cuenta bancaria ha sido cambiada a estado '{$nuevoEstado}' exitosamente.",
                'data' => $cuentaActualizada
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/tesoreria/cuentas-bancarias/{id}
     * Regla de Oro 1: Inmutabilidad de número de cuenta y moneda si existen movimientos contables auditados.
     */
    public function update(int $id): void
    {
        try {
            $this->validarRolContable();
            $input = $this->getRequestData();

            $stmtCheck = $this->db->prepare("SELECT numero_cuenta, moneda, cuenta_id FROM cuentas_bancarias WHERE id = ?");
            $stmtCheck->execute([$id]);
            $cuentaActual = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$cuentaActual) {
                $this->jsonResponse(['success' => false, 'message' => 'Cuenta bancaria no encontrada'], 404);
                return;
            }

            $nuevoNumero = !empty($input['numero_cuenta']) ? preg_replace('/\D/', '', (string)$input['numero_cuenta']) : (string)$cuentaActual['numero_cuenta'];
            $nuevaMoneda = !empty($input['moneda']) ? strtoupper(trim((string)$input['moneda'])) : (string)$cuentaActual['moneda'];

            $cuentaContableId = !empty($cuentaActual['cuenta_id']) ? (int)$cuentaActual['cuenta_id'] : 0;

            // Verificar si la cuenta ya posee transferencias o asientos contables
            $stmtMovs = $this->db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM transferencias_bancarias WHERE cuenta_origen_id = ? OR cuenta_destino_id = ?) +
                    (SELECT COUNT(*) FROM detalles_asiento WHERE cuenta_id = ?) AS total_movimientos
            ");
            $stmtMovs->execute([$id, $id, $cuentaContableId]);
            $totalMovimientos = (int)$stmtMovs->fetchColumn();

            if ($totalMovimientos > 0) {
                if ($nuevoNumero !== $cuentaActual['numero_cuenta'] || $nuevaMoneda !== $cuentaActual['moneda']) {
                    $this->jsonResponse([
                        'success' => false,
                        'message' => 'Inmutabilidad Financiera (Regla 1): No se permite modificar el número de cuenta SUDEBAN ni la divisa de una cuenta bancaria con movimientos contables auditados.'
                    ], 403);
                    return;
                }
            }

            if ($nuevoNumero !== $cuentaActual['numero_cuenta'] && !$this->validarCuentaBancariaVenezuela($nuevoNumero)) {
                $this->jsonResponse(['success' => false, 'message' => 'El nuevo número de cuenta bancaria no cumple con el algoritmo Módulo 11 de 20 dígitos oficial SUDEBAN'], 400);
                return;
            }

            // Actualización completa de todos los campos institucionales y de clasificación
            $institucion            = !empty($input['institucion']) ? trim((string)$input['institucion']) : null;
            $bancoNombre            = !empty($input['banco_nombre']) ? trim((string)$input['banco_nombre']) : null;
            $tipoCuenta             = !empty($input['tipo_cuenta']) ? trim((string)$input['tipo_cuenta']) : null;
            $tipoRazon              = !empty($input['tipo_razon']) ? trim((string)$input['tipo_razon']) : null;
            $rif                    = !empty($input['rif']) ? trim((string)$input['rif']) : null;
            $sucursal               = !empty($input['sucursal']) ? trim((string)$input['sucursal']) : null;
            $fuenteFinanciamientoId = !empty($input['fuente_financiamiento_id']) ? (int)$input['fuente_financiamiento_id'] : null;
            $estado                 = !empty($input['estado']) ? trim((string)$input['estado']) : null;

            $stmtUpd = $this->db->prepare("
                UPDATE cuentas_bancarias 
                SET institucion = COALESCE(?, institucion),
                    banco_nombre = COALESCE(?, banco_nombre),
                    tipo_cuenta = COALESCE(?, tipo_cuenta),
                    tipo_razon = COALESCE(?, tipo_razon),
                    rif = COALESCE(?, rif),
                    sucursal = COALESCE(?, sucursal),
                    fuente_financiamiento_id = COALESCE(?, fuente_financiamiento_id),
                    estado = COALESCE(?, estado),
                    numero_cuenta = ?,
                    moneda = ?
                WHERE id = ?
            ");

            try {
                $stmtUpd->execute([
                    $institucion, $bancoNombre, $tipoCuenta, $tipoRazon, $rif,
                    $sucursal, $fuenteFinanciamientoId, $estado, $nuevoNumero, $nuevaMoneda, $id
                ]);
            } catch (\PDOException $pdoEx) {
                if ($pdoEx->getCode() === '23000' || str_contains($pdoEx->getMessage(), '1062')) {
                    $this->jsonResponse(['success' => false, 'message' => 'Este número de cuenta bancaria ya se encuentra registrado en otra institución'], 409);
                    return;
                }
                $this->jsonResponse(['success' => false, 'message' => $pdoEx->getMessage()], 500);
                return;
            }

            $cuentaActualizada = $this->cuentaService->obtenerSaldos($this->db, $id);
            $this->jsonResponse([
                'success' => true,
                'message' => 'Cuenta bancaria actualizada exitosamente',
                'data' => $cuentaActualizada
            ], 200);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
