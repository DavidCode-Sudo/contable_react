<?php
require_once __DIR__ . '/../config/database/database.php';

// Función para verificar si el usuario está autenticado (mantener compatibilidad)
function verificarAutenticacion() {
    // La verificación ya se hace automáticamente en verificar_sesion.php
    return true;
}

// Función para obtener el saldo de una cuenta
function obtenerSaldoCuenta($cuenta_id, $fecha_inicio = null, $fecha_fin = null) {
    $conn = getConnection();
    
    $sql = "SELECT 
                COALESCE(SUM(da.debe), 0) as total_debe,
                COALESCE(SUM(da.haber), 0) as total_haber
            FROM detalles_asiento da
            INNER JOIN asientos a ON da.asiento_id = a.id
            WHERE da.cuenta_id = :cuenta_id 
            AND a.estado = 'confirmado'";
    
    $params = [':cuenta_id' => $cuenta_id];
    
    if ($fecha_inicio) {
        $sql .= " AND a.fecha >= :fecha_inicio";
        $params[':fecha_inicio'] = $fecha_inicio;
    }
    
    if ($fecha_fin) {
        $sql .= " AND a.fecha <= :fecha_fin";
        $params[':fecha_fin'] = $fecha_fin;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $resultado['total_debe'] - $resultado['total_haber'];
}

// Función generarNumeroFactura() movida a gestion_facturacion.php con formato profesional

// Función para formatear moneda (ahora en Bolívares)
function formatearMoneda($monto) {
    return 'Bs. ' . number_format($monto, 2, ',', '.');
}

// Función para formatear moneda en bolívares
function formatearMonedaBs($monto) {
    return 'Bs. ' . number_format($monto, 2, ',', '.');
}

// Función para formatear cantidades con hasta N decimales, sin ceros a la derecha
if (!function_exists('formatearCantidad')) {
    function formatearCantidad($numero, $decimales = 3) {
        $decimales = max(0, (int)$decimales);
        $s = number_format((float)$numero, $decimales, '.', ',');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

// Función para obtener la tasa de cambio actual
function obtenerTasaCambioActual() {
    static $schemaInfo = null;
    static $cachedRate = null;

    if ($cachedRate !== null) {
        return $cachedRate;
    }

    $conn = getConnection();

    if ($schemaInfo === null) {
        $schemaInfo = [
            'campo_fecha' => 'fecha',
            'tiene_activa' => false,
        ];

        try {
            $columnas = $conn->query("DESCRIBE tasas_cambio")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columnas as $col) {
                if ($col['Field'] === 'fecha_vigencia') {
                    $schemaInfo['campo_fecha'] = 'fecha_vigencia';
                }
                if ($col['Field'] === 'activa') {
                    $schemaInfo['tiene_activa'] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Error describiendo tasas_cambio: " . $e->getMessage());
        }
    }

    try {
        $campo_fecha = $schemaInfo['campo_fecha'];
        $tiene_activa = $schemaInfo['tiene_activa'];

        if ($tiene_activa) {
            $stmt = $conn->prepare(
                "SELECT tasa FROM tasas_cambio WHERE activa = 1 ORDER BY $campo_fecha DESC, id DESC LIMIT 1"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT tasa FROM tasas_cambio ORDER BY $campo_fecha DESC, id DESC LIMIT 1"
            );
        }

        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resultado) {
            $cachedRate = (float)$resultado['tasa'];
            return $cachedRate;
        }

        $stmt_config = $conn->prepare(
            "SELECT valor FROM configuracion_sistema WHERE clave = 'tasa_cambio_actual'"
        );
        $stmt_config->execute();
        $config = $stmt_config->fetch(PDO::FETCH_ASSOC);

        if ($config) {
            $cachedRate = (float)$config['valor'];
            return $cachedRate;
        }

    } catch (Exception $e) {
        error_log("Error obteniendo tasa de cambio: " . $e->getMessage());
    }

    $cachedRate = 36.50;
    return $cachedRate;
}

// Función para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Función para formatear fecha y hora de Venezuela
function formatearFechaVenezuela($fecha, $incluir_hora = true, $incluir_zona = false) {
    try {
        $fecha_venezuela = new DateTime($fecha);
        $fecha_venezuela->setTimezone(new DateTimeZone('America/Caracas'));
        
        if ($incluir_hora) {
            $formato = 'd/m/Y H:i:s';
        } else {
            $formato = 'd/m/Y';
        }
        
        $resultado = $fecha_venezuela->format($formato);
        
        if ($incluir_zona) {
            $resultado .= ' (Caracas, VE)';
        }
        
        return $resultado;
    } catch (Exception $e) {
        return date('d/m/Y H:i:s', strtotime($fecha));
    }
}

// Función para convertir nombres de días de inglés a español
function convertirDiaEspanol($fecha) {
    $dias_ingles_espanol = [
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes', 
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    ];
    
    $dia_ingles = date('l', strtotime($fecha));
    return $dias_ingles_espanol[$dia_ingles] ?? $dia_ingles;
}

// Función para obtener día en español de una fecha
function obtenerDiaEspanol($fecha) {
    return convertirDiaEspanol($fecha);
}

// ===============================
// BLOQUEO DE PERIODOS CONTABLES
// ===============================
function asegurarTablaPeriodosContables() {
    static $tablaAsegurada = false;
    if ($tablaAsegurada) { return; }
    $conn = getConnection();
    // Evitar ejecutar DDL dentro de una transacción activa (provoca commits implícitos en MySQL)
    try {
        if ($conn instanceof PDO && $conn->inTransaction()) {
            // Suponemos que la tabla ya existe. Si no existe, periodoEstaCerrado() manejará el caso.
            $tablaAsegurada = true;
            return;
        }
    } catch (Exception $e) { /* noop */ }

    $sql = "CREATE TABLE IF NOT EXISTS periodos_contables (
                id INT AUTO_INCREMENT PRIMARY KEY,
                desde DATE NOT NULL,
                hasta DATE NOT NULL,
                estado ENUM('cerrado','abierto') NOT NULL DEFAULT 'cerrado',
                creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_periodo (desde, hasta)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    try { $conn->exec($sql); } catch (Exception $e) { /* noop */ }
    $tablaAsegurada = true;
}

function periodoEstaCerrado($fecha) {
    // Intentar consultar; si la tabla no existe o hay error, asumir "no cerrado" para no bloquear operaciones
    try {
        asegurarTablaPeriodosContables();
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT 1 FROM periodos_contables WHERE estado='cerrado' AND :f BETWEEN desde AND hasta LIMIT 1");
        $stmt->execute([':f' => $fecha]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Si la tabla no existe (errno 1146) u otro error durante la transacción, no detener el flujo
        error_log('periodoEstaCerrado() ignorado: ' . $e->getMessage());
        return false;
    }
}

// Función para generar asiento contable automático
// $fecha es opcional; si no se especifica, se usa la fecha de hoy
function generarAsientoContable($descripcion, $detalles, $documento = null, $fecha = null, $conexion = null) {
    $conn = $conexion ?: getConnection();
    
    try {
        // Determinar fecha del asiento con hora
        // Por defecto usar fecha y hora actual
        $fecha_asiento = $fecha ?: date('Y-m-d H:i:s');
        // Si nos pasan solo fecha (Y-m-d), agregar hora actual para evitar 00:00:00
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_asiento)) {
            $fecha_asiento .= ' ' . date('H:i:s');
        }
        // Bloqueo de periodos: impedir asientos en periodos cerrados para la fecha indicada
        if (periodoEstaCerrado($fecha_asiento)) {
            throw new Exception('El periodo contable que abarca la fecha ' . $fecha_asiento . ' está CERRADO. No se pueden registrar asientos.');
        }
        // Respetar transacción existente (evita conflictos con transacciones de llamados)
        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) { 
            $conn->beginTransaction(); 
        }
        
        // Crear asiento
        $sql_asiento = "INSERT INTO asientos (fecha, descripcion, documento, usuario_id) 
                       VALUES (:fecha, :descripcion, :documento, :usuario_id)";
        
        $stmt = $conn->prepare($sql_asiento);
        $stmt->execute([
            ':fecha' => $fecha_asiento,
            ':descripcion' => $descripcion,
            ':documento' => $documento,
            ':usuario_id' => $_SESSION['usuario_id']
        ]);
        
        $asiento_id = $conn->lastInsertId();
        $total_debitos = 0;
        $total_creditos = 0;
        
        // Insertar detalles
        foreach ($detalles as $detalle) {
            $sql_detalle = "INSERT INTO detalles_asiento (asiento_id, cuenta_id, descripcion, debe, haber) 
                           VALUES (:asiento_id, :cuenta_id, :descripcion, :debe, :haber)";
            
            $stmt = $conn->prepare($sql_detalle);
            $stmt->execute([
                ':asiento_id' => $asiento_id,
                ':cuenta_id' => $detalle['cuenta_id'],
                ':descripcion' => $detalle['descripcion'],
                ':debe' => $detalle['debe'] ?? 0,
                ':haber' => $detalle['haber'] ?? 0
            ]);
            
            $total_debitos += $detalle['debe'] ?? 0;
            $total_creditos += $detalle['haber'] ?? 0;
        }
        
        // Actualizar totales del asiento
        $sql_update = "UPDATE asientos SET total_debitos = :debitos, total_creditos = :creditos 
                      WHERE id = :asiento_id";
        
        $stmt = $conn->prepare($sql_update);
        $stmt->execute([
            ':debitos' => $total_debitos,
            ':creditos' => $total_creditos,
            ':asiento_id' => $asiento_id
        ]);
        
        if ($transaccionPropia) { 
            $conn->commit(); 
        }
        
        // Registrar auditoría de asiento contable generado automáticamente
        try {
            registrarCreacion('asientos', 'asientos', $asiento_id, [
                'fecha' => $fecha_asiento,
                'descripcion' => $descripcion,
                'documento' => $documento,
                'total_debitos' => $total_debitos,
                'total_creditos' => $total_creditos,
                'estado' => 'borrador',
                'generado_automaticamente' => true
            ], "Asiento contable generado automáticamente: $descripcion");
        } catch (Exception $eAudit) { /* Auditoría silenciosa */ }
        
        return $asiento_id;
        
    } catch (Exception $e) {
        // Solo revertir si la transacción fue abierta aquí
        if ($transaccionPropia && $conn instanceof PDO) {
            try {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
            } catch (Exception $eRb) {
                // Evitar que un rollback sin transacción genere un nuevo error
            }
        }
        throw $e;
    }
}

// Función para obtener el balance de comprobación
function obtenerBalanceComprobacion($fecha_inicio = null, $fecha_fin = null, $filtro = 'movimientos') {
    $conn = getConnection();
    
    // Establecer fechas por defecto si no se proporcionan
    $fecha_inicio = $fecha_inicio ?: '1900-01-01';
    $fecha_fin = $fecha_fin ?: date('Y-m-d');
    
    $sql = "SELECT 
                c.id,
                c.codigo,
                c.nombre,
                c.tipo,
                c.naturaleza,
                
                -- Saldo anterior (hasta fecha_inicio - 1 día)
                COALESCE(SUM(CASE 
                    WHEN a.fecha < ? AND a.estado = 'confirmado' THEN 
                        CASE WHEN c.naturaleza = 'deudora' THEN da.debe - da.haber 
                             ELSE da.haber - da.debe END
                    ELSE 0 
                END), 0) as saldo_anterior,
                
                -- Movimiento del período
                COALESCE(SUM(CASE 
                    WHEN a.fecha BETWEEN ? AND ? AND a.estado = 'confirmado' THEN 
                        CASE WHEN c.naturaleza = 'deudora' THEN da.debe - da.haber 
                             ELSE da.haber - da.debe END
                    ELSE 0 
                END), 0) as movimiento_periodo,
                
                -- Saldo actual (hasta fecha_fin)
                COALESCE(SUM(CASE 
                    WHEN a.fecha <= ? AND a.estado = 'confirmado' THEN 
                        CASE WHEN c.naturaleza = 'deudora' THEN da.debe - da.haber 
                             ELSE da.haber - da.debe END
                    ELSE 0 
                END), 0) as saldo_actual,
                
                -- Totales para compatibilidad
                COALESCE(SUM(CASE 
                    WHEN a.fecha BETWEEN ? AND ? AND a.estado = 'confirmado' THEN da.debe 
                    ELSE 0 
                END), 0) as total_debe,
                
                COALESCE(SUM(CASE 
                    WHEN a.fecha BETWEEN ? AND ? AND a.estado = 'confirmado' THEN da.haber 
                    ELSE 0 
                END), 0) as total_haber,
                
                -- Saldo para compatibilidad
                COALESCE(SUM(CASE 
                    WHEN a.fecha <= ? AND a.estado = 'confirmado' THEN 
                        CASE WHEN c.naturaleza = 'deudora' THEN da.debe - da.haber 
                             ELSE da.haber - da.debe END
                    ELSE 0 
                END), 0) as saldo
                
            FROM cuentas c
            LEFT JOIN detalles_asiento da ON c.id = da.cuenta_id
            LEFT JOIN asientos a ON da.asiento_id = a.id
            WHERE c.estado = 'activa'";
    
    // Parámetros en orden: fecha_inicio (4 veces), fecha_fin (4 veces)
    $params = [
        $fecha_inicio,  // Para saldo_anterior
        $fecha_inicio,  // Para movimiento_periodo (inicio)
        $fecha_fin,     // Para movimiento_periodo (fin)
        $fecha_fin,     // Para saldo_actual
        $fecha_inicio,  // Para total_debe (inicio)
        $fecha_fin,     // Para total_debe (fin)
        $fecha_inicio,  // Para total_haber (inicio)
        $fecha_fin,     // Para total_haber (fin)
        $fecha_fin      // Para saldo (compatibilidad)
    ];
    
    $sql .= " GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza";
    
    // Aplicar filtro según el tipo solicitado
    if ($filtro == 'movimientos') {
        $sql .= " HAVING saldo_anterior != 0 OR movimiento_periodo != 0 OR saldo_actual != 0";
    }
    
    $sql .= " ORDER BY c.codigo";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para mostrar mensajes de alerta
function mostrarAlerta($mensaje, $tipo = 'success') {
    return "<div class='alert alert-{$tipo} alert-dismissible fade show' role='alert'>
                {$mensaje}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

// Función para limpiar datos de entrada
function limpiarDatos($datos) {
    return htmlspecialchars(trim($datos), ENT_QUOTES, 'UTF-8');
}

// ============================================
// FUNCIONES DE AUDITORÍA
// ============================================

// Función para registrar actividad en auditoría
function registrarAuditoria($accion, $modulo, $detalles = null, $tabla_afectada = null, $registro_id = null, $datos_anteriores = null, $datos_nuevos = null) {
    // No registrar auditoría del módulo de auditoría ni acciones de filtrado
    if ($modulo === 'auditoria' || $accion === 'search' || $accion === 'filter' || $accion === 'view') {
        return false;
    }
    
    $conn = getConnection();
    
    // Obtener información del usuario actual
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    
    // Obtener información del cliente
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        $sql = "INSERT INTO auditoria (usuario_id, accion, modulo, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_address, user_agent, detalles) 
                VALUES (:usuario_id, :accion, :modulo, :tabla_afectada, :registro_id, :datos_anteriores, :datos_nuevos, :ip_address, :user_agent, :detalles)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':accion' => $accion,
            ':modulo' => $modulo,
            ':tabla_afectada' => $tabla_afectada,
            ':registro_id' => $registro_id,
            ':datos_anteriores' => $datos_anteriores ? json_encode($datos_anteriores) : null,
            ':datos_nuevos' => $datos_nuevos ? json_encode($datos_nuevos) : null,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent,
            ':detalles' => $detalles
        ]);
        
        return $conn->lastInsertId();
    } catch (Exception $e) {
        // Log del error pero no interrumpir el flujo principal
        return false;
    }
}

// Función para registrar inicio de sesión
function registrarLogin($usuario_id, $detalles = null) {
    return registrarAuditoria('login', 'autenticacion', $detalles, 'usuarios', $usuario_id);
}

// Función para registrar cierre de sesión
function registrarLogout($usuario_id, $detalles = null) {
    return registrarAuditoria('logout', 'autenticacion', $detalles, 'usuarios', $usuario_id);
}

// Función para registrar creación de registro
function registrarCreacion($modulo, $tabla, $registro_id, $datos_nuevos, $detalles = null) {
    return registrarAuditoria('create', $modulo, $detalles, $tabla, $registro_id, null, $datos_nuevos);
}

// Función para registrar actualización de registro
function registrarActualizacion($modulo, $tabla, $registro_id, $datos_anteriores, $datos_nuevos, $detalles = null) {
    return registrarAuditoria('update', $modulo, $detalles, $tabla, $registro_id, $datos_anteriores, $datos_nuevos);
}

// Función para registrar eliminación de registro
function registrarEliminacion($modulo, $tabla, $registro_id, $datos_anteriores, $detalles = null) {
    return registrarAuditoria('delete', $modulo, $detalles, $tabla, $registro_id, $datos_anteriores, null);
}

// Función para registrar activación de registro
function registrarActivacion($modulo, $tabla, $registro_id, $detalles = null) {
    return registrarAuditoria('activate', $modulo, $detalles, $tabla, $registro_id);
}

// Función para registrar generación de reportes
function registrarReporte($modulo, $tipo_reporte, $detalles = null) {
    return registrarAuditoria('report', $modulo, $detalles, null, null);
}

// Función para registrar exportación de datos
function registrarExportacion($modulo, $formato, $detalles = null) {
    return registrarAuditoria('export', $modulo, $detalles, null, null);
}

// Función para registrar importación de datos
function registrarImportacion($modulo, $archivo, $detalles = null) {
    return registrarAuditoria('import', $modulo, $detalles, null, null);
}

// Función para registrar cambios de configuración
function registrarConfiguracion($modulo, $configuracion, $valor_anterior, $valor_nuevo, $detalles = null) {
    return registrarAuditoria('config', $modulo, $detalles, 'configuracion', null, $valor_anterior, $valor_nuevo);
}

// Función para registrar intentos de acceso no autorizado
function registrarAccesoNoAutorizado($modulo, $detalles = null) {
    return registrarAuditoria('unauthorized', $modulo, $detalles, null, null);
}

// Función para registrar errores del sistema
function registrarError($modulo, $error, $detalles = null) {
    return registrarAuditoria('error', $modulo, $detalles, null, null);
}

// Función para registrar inicio de sesión
function registrarInicioSesion($usuario_id) {
    $conn = getConnection();
    
    try {
        $sql = "INSERT INTO sesiones_usuarios (usuario_id, ip_address, user_agent) 
                VALUES (:usuario_id, :ip_address, :user_agent)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return $conn->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}

// Función para registrar cierre de sesión
function registrarCierreSesion($sesion_id) {
    $conn = getConnection();
    
    try {
        $sql = "UPDATE sesiones_usuarios SET fecha_fin = NOW(), estado = 'cerrada' WHERE id = :sesion_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':sesion_id' => $sesion_id]);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Función para obtener IP del cliente
function obtenerIPCliente() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    
    return $ip;
}

// Función para formatear fecha de auditoría
function formatearFechaAuditoria($fecha) {
    return date('d/m/Y H:i:s', strtotime($fecha));
}

// Función para limpiar datos sensibles para auditoría
function limpiarDatosSensibles($datos) {
    if (is_array($datos)) {
        $campos_sensibles = ['password', 'clave', 'token', 'api_key', 'secret'];
        foreach ($campos_sensibles as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = '***OCULTO***';
            }
        }
    }
    return $datos;
}

// Función para obtener descripción de acción
function obtenerDescripcionAccion($accion) {
    $descripciones = [
        'create' => 'Crear',
        'update' => 'Actualizar',
        'delete' => 'Eliminar',
        'activate' => 'Activar',
        'deactivate' => 'Desactivar',
        'login' => 'Iniciar Sesión',
        'logout' => 'Cerrar Sesión',
        'report' => 'Generar Reporte',
        'export' => 'Exportar',
        'import' => 'Importar',
        'config' => 'Configurar',
        'unauthorized' => 'Acceso No Autorizado',
        'error' => 'Error',
        'view' => 'Visualizar',
        'search' => 'Buscar',
        'filter' => 'Filtrar',
        'print' => 'Imprimir',
        'download' => 'Descargar',
        'upload' => 'Subir',
        'backup' => 'Respaldo',
        'restore' => 'Restaurar',
        'confirm' => 'Confirmar',
        'cancel' => 'Cancelar',
        'approve' => 'Aprobar',
        'reject' => 'Rechazar'
    ];
    
    return $descripciones[$accion] ?? ucfirst($accion);
}

// Función para obtener descripción de módulo
function obtenerDescripcionModulo($modulo) {
    $descripciones = [
        'usuarios' => 'Gestión de Usuarios',
        'roles' => 'Gestión de Roles',
        'clientes' => 'Gestión de Clientes',
        'proveedores' => 'Gestión de Proveedores',
        'inventario' => 'Gestión de Inventario',
        'facturacion' => 'Facturación',
        'contabilidad' => 'Contabilidad',
        'asientos' => 'Asientos Contables',
        'cuentas' => 'Catálogo de Partidas',
        'reportes' => 'Reportes',
        'auditoria' => 'Auditoría',
        'autenticacion' => 'Autenticación',
        'dashboard' => 'Dashboard',
        'configuracion' => 'Configuración',
        'backup' => 'Respaldo',
        'restore' => 'Restauración',
        'estados_financieros' => 'Estados Financieros',
        'libros' => 'Libros Contables',
        'cuentas_cobrar' => 'Cuentas por Cobrar',
        'cuentas_pagar' => 'Cuentas por Pagar'
    ];
    
    return $descripciones[$modulo] ?? ucfirst($modulo);
}

// =====================
// ROLES Y PERMISOS
// =====================

// Asegurar que existan los permisos básicos por módulo/acción
function asegurarPermisosBasicos() {
    $conn = getConnection();

    // Definir catálogo maestro de permisos por módulo
    // Solo permisos que realmente se usan en el código
    $catalogo = [
        // Principal
        'dashboard' => ['ver'],
        
        // Sistema
        'usuarios' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],
        'roles' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],

        // Contabilidad
        'catalogo' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],
        'asientos' => ['ver', 'crear', 'confirmar', 'anular'],
        'libros' => ['ver'],
        'cierre_contable' => ['ver', 'ejecutar'],
        'periodos_contables' => ['ver', 'desactivar'],

        // Operaciones
        'facturas' => ['ver', 'crear', 'editar', 'anular', 'imprimir'],
        'inventario' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],

        // Relaciones
        'clientes' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],
        'proveedores' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],
        'servicios' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],

        // Reportes
        'reportes' => ['ver'],
        'estados_financieros' => ['ver'],
        'cxc' => ['ver', 'cobrar'],
        'cxp' => ['ver', 'pagar'],

        // Presupuestos
        'presupuestos' => ['ver', 'crear', 'editar', 'desactivar', 'activar'],
        'presupuestos_config' => ['ver', 'crear', 'editar', 'desactivar', 'activar', 'ejecutar']
    ];

    // Ícono sugerido por módulo (opcional)
    $iconos = [
        'dashboard' => 'fas fa-home',
        'usuarios' => 'fas fa-users',
        'roles' => 'fas fa-user-shield',
        'catalogo' => 'fas fa-book',
        'asientos' => 'fas fa-file-invoice',
        'libros' => 'fas fa-book-open',
        'cierre_contable' => 'fas fa-lock',
        'periodos_contables' => 'fas fa-calendar-check',
        'facturas' => 'fas fa-file-invoice-dollar',
        'inventario' => 'fas fa-boxes',
        'clientes' => 'fas fa-user-tie',
        'proveedores' => 'fas fa-truck',
        'servicios' => 'fas fa-concierge-bell',
        'reportes' => 'fas fa-chart-bar',
        'estados_financieros' => 'fas fa-balance-scale',
        'cxc' => 'fas fa-hand-holding-usd',
        'cxp' => 'fas fa-money-bill-wave',
        'presupuestos' => 'fas fa-coins',
        'presupuestos_config' => 'fas fa-tools'
    ];

    // Orden sugerido por módulo para visualización
    $ordenModulo = [
        'dashboard' => 1,
        'usuarios' => 10,
        'roles' => 11,
        'catalogo' => 20,
        'asientos' => 21,
        'libros' => 22,
        'cierre_contable' => 23,
        'periodos_contables' => 24,
        'facturas' => 30,
        'inventario' => 32,
        'clientes' => 40,
        'proveedores' => 41,
        'servicios' => 42,
        'reportes' => 50,
        'estados_financieros' => 51,
        'cxc' => 52,
        'cxp' => 53,
        'presupuestos' => 60,
        'presupuestos_config' => 61
    ];

    // Crear índice único si no existe (modulo, accion) para evitar duplicados
    try {
        $conn->exec("ALTER TABLE permisos ADD UNIQUE KEY uq_modulo_accion (modulo, accion)");
    } catch (Exception $e) {
        // ignorar si ya existe
    }

    // Preparar consultas
    $stmtSel = $conn->prepare("SELECT id FROM permisos WHERE modulo = :modulo AND accion = :accion LIMIT 1");
    $stmtIns = $conn->prepare("INSERT INTO permisos (nombre, modulo, accion, descripcion, icono, orden, estado) VALUES (:nombre, :modulo, :accion, :descripcion, :icono, :orden, 'activo')");

    foreach ($catalogo as $modulo => $acciones) {
        foreach ($acciones as $idx => $accion) {
            $stmtSel->execute([':modulo' => $modulo, ':accion' => $accion]);
            $existe = $stmtSel->fetch(PDO::FETCH_ASSOC);
            if ($existe) { continue; }

            $nombre = ucfirst($accion) . ' ' . obtenerDescripcionModulo($modulo);
            $descripcion = 'Permite ' . strtolower(obtenerDescripcionAccion($accion)) . ' en el módulo ' . strtolower(obtenerDescripcionModulo($modulo));
            $icono = $iconos[$modulo] ?? null;
            $orden = ($ordenModulo[$modulo] ?? 90) * 100 + ($idx + 1);

            try {
                $stmtIns->execute([
                    ':nombre' => $nombre,
                    ':modulo' => $modulo,
                    ':accion' => $accion,
                    ':descripcion' => $descripcion,
                    ':icono' => $icono,
                    ':orden' => $orden
                ]);
            } catch (Exception $e) {
                // Registrar pero no interrumpir
            }
        }
    }
}

// Obtener todos los roles
function obtenerRoles() {
    $conn = getConnection();
    $stmt = $conn->query("SELECT * FROM roles ORDER BY nombre");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener todos los permisos organizados por módulo
function obtenerPermisos() {
    $conn = getConnection();
    $stmt = $conn->query("
        SELECT 
            p.*,
            CASE p.modulo
                WHEN 'dashboard' THEN 'Dashboard'
                WHEN 'usuarios' THEN 'Usuarios'
                WHEN 'roles' THEN 'Roles y Permisos'
                WHEN 'catalogo' THEN 'Catálogo de Partidas'
                WHEN 'asientos' THEN 'Asientos Contables'
                WHEN 'libros' THEN 'Libros Contables'
                WHEN 'facturacion' THEN 'Facturación'
                WHEN 'facturas' THEN 'Facturación'
                WHEN 'inventario' THEN 'Inventario'
                WHEN 'clientes' THEN 'Clientes'
                WHEN 'proveedores' THEN 'Proveedores'
                WHEN 'servicios' THEN 'Servicios'
                WHEN 'cxc' THEN 'Cuentas por Cobrar'
                WHEN 'cxp' THEN 'Cuentas por Pagar'
                WHEN 'reportes' THEN 'Reportes'
                WHEN 'cierre_contable' THEN 'Cierre Contable'
                WHEN 'periodos_contables' THEN 'Períodos Contables'
                WHEN 'estados_financieros' THEN 'Estados Financieros'
                ELSE p.modulo
            END as modulo_nombre,
            CASE p.accion
                WHEN 'ver' THEN 'Ver'
                WHEN 'crear' THEN 'Crear'
                WHEN 'editar' THEN 'Editar'
                WHEN 'desactivar' THEN 'Desactivar'
                WHEN 'anular' THEN 'Anular'
                WHEN 'confirmar' THEN 'Confirmar'
                WHEN 'ejecutar' THEN 'Ejecutar'
                WHEN 'imprimir' THEN 'Imprimir'
                WHEN 'exportar' THEN 'Exportar'
                WHEN 'activar' THEN 'Activar'
                WHEN 'pagar' THEN 'Pagar'
                WHEN 'cobrar' THEN 'Cobrar'
                ELSE p.accion
            END as accion_nombre
        FROM permisos p 
        ORDER BY 
            CASE p.modulo
                WHEN 'dashboard' THEN 0
                WHEN 'usuarios' THEN 1
                WHEN 'roles' THEN 2
                WHEN 'catalogo' THEN 3
                WHEN 'asientos' THEN 4
                WHEN 'libros' THEN 5
                WHEN 'cierre_contable' THEN 6
                WHEN 'periodos_contables' THEN 7
                WHEN 'facturacion' THEN 8
                WHEN 'facturas' THEN 8
                WHEN 'inventario' THEN 10
                WHEN 'clientes' THEN 11
                WHEN 'proveedores' THEN 12
                WHEN 'servicios' THEN 13
                WHEN 'reportes' THEN 14
                WHEN 'estados_financieros' THEN 15
                WHEN 'cxc' THEN 16
                WHEN 'cxp' THEN 17
                ELSE 99
            END,
            p.modulo, 
            CASE p.accion
                WHEN 'ver' THEN 1
                WHEN 'crear' THEN 2
                WHEN 'editar' THEN 3
                WHEN 'desactivar' THEN 4
                WHEN 'activar' THEN 5
                WHEN 'anular' THEN 6
                WHEN 'confirmar' THEN 7
                WHEN 'ejecutar' THEN 8
                WHEN 'imprimir' THEN 9
                WHEN 'pagar' THEN 10
                WHEN 'cobrar' THEN 11
                ELSE 99
            END
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener permisos de un rol
function obtenerPermisosPorRol($rol_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT permiso_id FROM roles_permisos WHERE rol_id = :rol_id");
    $stmt->execute([':rol_id' => $rol_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'permiso_id');
}

// Asignar permisos a un rol
function asignarPermisosARol($rol_id, $permisos) {
    $conn = getConnection();
    $conn->beginTransaction();
    $conn->prepare("DELETE FROM roles_permisos WHERE rol_id = ?")->execute([$rol_id]);
    foreach ($permisos as $permiso_id) {
        $conn->prepare("INSERT INTO roles_permisos (rol_id, permiso_id) VALUES (?, ?)")->execute([$rol_id, $permiso_id]);
    }
    $conn->commit();
}

// Obtener roles de un usuario
function obtenerRolesPorUsuario($usuario_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT rol_id FROM usuarios_roles WHERE usuario_id = :usuario_id");
    $stmt->execute([':usuario_id' => $usuario_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'rol_id');
}

// Asignar roles a un usuario
function asignarRolesAUsuario($usuario_id, $roles) {
    $conn = getConnection();
    $conn->beginTransaction();
    $conn->prepare("DELETE FROM usuarios_roles WHERE usuario_id = ?")->execute([$usuario_id]);
    foreach ($roles as $rol_id) {
        $conn->prepare("INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)")->execute([$usuario_id, $rol_id]);
    }
    $conn->commit();
}



// ============================================
// FUNCIONES DE ROLES Y PERMISOS
// ============================================

// Función para obtener roles del usuario
function obtenerRolesUsuario($usuario_id) {
    $conn = getConnection();
    
    $sql = "SELECT r.id, r.nombre, r.descripcion 
            FROM roles r 
            INNER JOIN usuarios_roles ur ON r.id = ur.rol_id 
            WHERE ur.usuario_id = :usuario_id AND r.estado = 'activo'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $usuario_id]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener permisos del usuario
function obtenerPermisosUsuario($usuario_id) {
    $conn = getConnection();
    
    $sql = "SELECT DISTINCT p.id, p.nombre, p.modulo, p.accion, p.descripcion, p.icono, p.orden
            FROM permisos p 
            INNER JOIN roles_permisos rp ON p.id = rp.permiso_id 
            INNER JOIN usuarios_roles ur ON rp.rol_id = ur.rol_id 
            WHERE ur.usuario_id = :usuario_id AND p.estado = 'activo'
            ORDER BY p.orden";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $usuario_id]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para verificar si un usuario tiene un permiso específico
function tienePermiso($usuario_id, $modulo, $accion) {
    $conn = getConnection();
    
    $sql = "SELECT COUNT(*) as total
            FROM permisos p 
            INNER JOIN roles_permisos rp ON p.id = rp.permiso_id 
            INNER JOIN usuarios_roles ur ON rp.rol_id = ur.rol_id 
            WHERE ur.usuario_id = :usuario_id 
            AND p.modulo = :modulo 
            AND p.accion = :accion 
            AND p.estado = 'activo'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':modulo' => $modulo,
        ':accion' => $accion
    ]);
    
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    return $resultado['total'] > 0;
}



// Función para obtener permisos organizados por módulo
function obtenerPermisosPorModulo($usuario_id) {
    $permisos = obtenerPermisosUsuario($usuario_id);
    $permisos_organizados = [];
    
    foreach ($permisos as $permiso) {
        $modulo = $permiso['modulo'];
        if (!isset($permisos_organizados[$modulo])) {
            $permisos_organizados[$modulo] = [];
        }
        $permisos_organizados[$modulo][] = $permiso;
    }
    
    return $permisos_organizados;
}

// Función para obtener todos los roles
function obtenerTodosLosRoles() {
    $conn = getConnection();
    
    $sql = "SELECT id, nombre, descripcion, estado FROM roles ORDER BY nombre";
    $stmt = $conn->query($sql);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener todos los permisos
function obtenerTodosLosPermisos() {
    $conn = getConnection();
    
    $sql = "SELECT id, nombre, modulo, accion, descripcion, icono, orden, estado 
            FROM permisos 
            ORDER BY orden, modulo, accion";
    $stmt = $conn->query($sql);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para obtener permisos de un rol específico
function obtenerPermisosRol($rol_id) {
    $conn = getConnection();
    
    $sql = "SELECT p.id, p.nombre, p.modulo, p.accion, p.descripcion, p.icono, p.orden
            FROM permisos p 
            INNER JOIN roles_permisos rp ON p.id = rp.permiso_id 
            WHERE rp.rol_id = :rol_id AND p.estado = 'activo'
            ORDER BY p.orden";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':rol_id' => $rol_id]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Función para asignar permisos a un rol
function asignarPermisosRol($rol_id, $permisos_ids) {
    $conn = getConnection();
    
    try {
        $conn->beginTransaction();
        
        // Eliminar permisos actuales del rol
        $sql_delete = "DELETE FROM roles_permisos WHERE rol_id = :rol_id";
        $stmt = $conn->prepare($sql_delete);
        $stmt->execute([':rol_id' => $rol_id]);
        
        // Insertar nuevos permisos
        if (!empty($permisos_ids)) {
            $sql_insert = "INSERT INTO roles_permisos (rol_id, permiso_id) VALUES (:rol_id, :permiso_id)";
            $stmt = $conn->prepare($sql_insert);
            
            foreach ($permisos_ids as $permiso_id) {
                $stmt->execute([
                    ':rol_id' => $rol_id,
                    ':permiso_id' => $permiso_id
                ]);
            }
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

// Función para asignar roles a un usuario
function asignarRolesUsuario($usuario_id, $roles_ids) {
    $conn = getConnection();
    
    try {
        $conn->beginTransaction();
        
        // Eliminar roles actuales del usuario
        $sql_delete = "DELETE FROM usuarios_roles WHERE usuario_id = :usuario_id";
        $stmt = $conn->prepare($sql_delete);
        $stmt->execute([':usuario_id' => $usuario_id]);
        
        // Insertar nuevos roles
        if (!empty($roles_ids)) {
            $sql_insert = "INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (:usuario_id, :rol_id)";
            $stmt = $conn->prepare($sql_insert);
            
            foreach ($roles_ids as $rol_id) {
                $stmt->execute([
                    ':usuario_id' => $usuario_id,
                    ':rol_id' => $rol_id
                ]);
            }
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

// Función para obtener el nombre del módulo
function obtenerNombreModulo($modulo) {
    $modulos = [
        'usuarios' => 'Usuarios',
        'roles' => 'Roles',
        'catalogo' => 'Catálogo de Partidas',
        'asientos' => 'Asientos Contables',
        'facturas' => 'Facturación',
        'clientes' => 'Clientes',
        'proveedores' => 'Proveedores',
        'reportes' => 'Reportes',
        'auditoria' => 'Auditoría'
    ];
    
    return $modulos[$modulo] ?? ucfirst($modulo);
}

// Función para obtener el ícono del módulo
function obtenerIconoModulo($modulo) {
    $iconos = [
        'usuarios' => 'fas fa-users',
        'roles' => 'fas fa-user-tag',
        'catalogo' => 'fas fa-book',
        'asientos' => 'fas fa-file-alt',
        'facturas' => 'fas fa-file-invoice',
        'clientes' => 'fas fa-users',
        'proveedores' => 'fas fa-truck',
        'reportes' => 'fas fa-chart-bar',
        'auditoria' => 'fas fa-history'
    ];
    
    return $iconos[$modulo] ?? 'fas fa-cog';
}

// Función para verificar si el usuario es administrador
function esAdministrador($usuario_id = null) {
    if ($usuario_id === null) {
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
    }
    
    $roles = obtenerRolesUsuario($usuario_id);
    foreach ($roles as $rol) {
        if (strtolower($rol['nombre']) === 'administrador') {
            return true;
        }
    }
    
    return false;
}

// Función para verificar si el usuario es contador
function esContador($usuario_id = null) {
    if ($usuario_id === null) {
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
    }
    
    $roles = obtenerRolesUsuario($usuario_id);
    foreach ($roles as $rol) {
        if (strtolower($rol['nombre']) === 'contador') {
            return true;
        }
    }
    
    return false;
}

if (!function_exists('flashRedirect')) {
    function flashRedirect(string $message, string $type = 'info', ?string $redirectUrl = null): void
    {
        $_SESSION['flash_alert'] = [
            'tipo' => $type,
            'mensaje' => $message
        ];
        $_SESSION['mensaje'] = $message;
        $_SESSION['tipo_mensaje'] = $type;
        $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
        $url = $redirectUrl ?? $baseUrl;
        header('Location: ' . $url);
        exit;
    }
}
?>