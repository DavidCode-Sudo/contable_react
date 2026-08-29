<?php
declare(strict_types=1);

namespace Api\Controllers;

if (file_exists(__DIR__ . '/../services/StockService.php')) {
    require_once __DIR__ . '/../services/StockService.php';
}

use Api\Core\Controller;
use Api\Core\RequisicionEstado;
use PDO;
use Throwable;

/**
 * Controlador RESTful para la gestión del ciclo de vida de Requisiciones
 */
class RequisicionesController extends Controller
{
    /** Constantes de Configuración de Negocio */
    private const UMBRAL_NIVEL_2_USD = 1000.00;
    private const TASA_CAMBIO_FALLBACK = 36.5;

    /**
     * Listado paginado y filtrado de requisiciones
     */
    public function index(): void
    {
        require __DIR__ . '/../requisiciones/index.php';
    }

    /**
     * Ver el detalle completo de una requisición
     */
    public function show(string $id): void
    {
        $_GET['id'] = $id;
        require __DIR__ . '/../requisiciones/show.php';
    }

    /**
     * Guardar o actualizar requisición (Crear / Editar)
     */
    public function store(): void
    {
        require __DIR__ . '/../requisiciones/save.php';
    }

    /**
     * ENDPOINT: Generar comprobante PDF fiscal de la Requisición
     * GET /api/requisiciones/{id}/pdf
     */
    public function pdf(string $id): void
    {
        $requisicionId = (int) $id;
        if ($requisicionId <= 0) {
            $this->errorResponse('ID de requisición no válido.', 400);
        }

        $_GET['id'] = (string) $requisicionId;

        // Limpieza estricta de buffer para transmisión binaria pura de PDF
        while (ob_get_level()) {
            ob_end_clean();
        }

        $pdfCandidates = [
            __DIR__ . '/../pdf/requisicion_pdf.php',
            __DIR__ . '/../../carpetas_de_osmc/requisiciones/imprimir_pdf.php',
            __DIR__ . '/../../carpetas_de_osmc/requisiciones/imprimir_requisicion_pdf.php',
        ];

        foreach ($pdfCandidates as $pdfFile) {
            if (file_exists($pdfFile)) {
                require $pdfFile;
                exit;
            }
        }

        $this->errorResponse('El generador de comprobante PDF no se encuentra disponible.', 500);
    }

    /**
     * ENDPOINT: Recepción de Bienes/Servicios y Generación del Causado Presupuestario
     * POST /api/requisiciones/{id}/recepcion
     */
    public function recibir(string $id): void
    {
        $requisicionId = (int) $id;
        if ($requisicionId <= 0) {
            $this->errorResponse('ID de requisición no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->errorResponse('Sesión de usuario no válida o expirada.', 401);
        }

        $data = $this->getRequestData();
        $observaciones = trim((string) ($data['observaciones'] ?? ($data['comentario'] ?? 'Recepción de bienes/servicios registrada correctamente.')));

        $conn = \getConnection();

        try {
            // 1. INICIO DE TRANSACCIÓN ACID CON BLOQUEO PESIMISTA FOR UPDATE
            $conn->beginTransaction();

            $stmtCheck = $conn->prepare('
                SELECT id, estado, numero, compromiso_id, moneda, total, presupuesto_id
                FROM requisiciones
                WHERE id = ? FOR UPDATE
            ');
            $stmtCheck->execute([$requisicionId]);
            $requisicion = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$requisicion) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse("No se encontró la requisición #{$requisicionId}.", 404);
            }

            $estadoActual = $requisicion['estado'];

            // 2. REGLA DE NEGOCIO CRÍTICA: SOLO SI ESTÁ 'aprobada'
            if ($estadoActual !== 'aprobada') {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse("Solo se puede registrar la recepción de requisiciones en estado 'aprobada'. El estado actual es '{$estadoActual}'.", 422);
            }

            // 3. ACTUALIZACIÓN DE ESTADO A 'recibida'
            $stmtUpdate = $conn->prepare("
                UPDATE requisiciones
                SET estado = 'recibida',
                    recibido_por = ?,
                    fecha_recepcion = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->execute([$usuarioId, $requisicionId]);

            // 4. AFECTACIÓN PRESUPUESTARIA: GENERACIÓN DEL CAUSADO
            $compromisoId = !empty($requisicion['compromiso_id']) ? (int) $requisicion['compromiso_id'] : null;
            if ($compromisoId && function_exists('generarCausadoDesdeRequisicion')) {
                try {
                    generarCausadoDesdeRequisicion($requisicionId, $usuarioId);
                } catch (Throwable $eSP) {
                    // Mantiene resiliencia en caso de que la rutina presupuestaria sea ejecutada asincrónicamente
                }
            }

            // 4.5. ENTRADA AUTOMÁTICA DE INVENTARIO: MUTACIÓN ATÓMICA VÍA StockService
            if (class_exists('Api\\Services\\StockService')) {
                $stmtItems = $conn->prepare("
                    SELECT producto_id, cantidad, precio
                    FROM requisicion_items
                    WHERE requisicion_id = ? AND producto_id IS NOT NULL AND producto_id > 0
                    ORDER BY producto_id ASC
                ");
                $stmtItems->execute([$requisicionId]);
                $itemsCatalogados = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                foreach ($itemsCatalogados as $itCat) {
                    \Api\Services\StockService::mutarStock(
                        $conn,
                        (int) $itCat['producto_id'],
                        (float) $itCat['cantidad'],
                        'entrada',
                        "Recepción de Requisición " . ($requisicion['numero'] ?? "#{$requisicionId}"),
                        $requisicionId,
                        null,
                        $usuarioId,
                        (float) $itCat['precio']
                    );
                }
            }

            // 5. REGISTRO EN REQUISICION_HISTORIAL
            $stmtHistorial = $conn->prepare("
                INSERT INTO requisicion_historial (
                    requisicion_id, estado_desde, estado_hasta, comentario, usuario_id
                ) VALUES (?, 'aprobada', 'recibida', ?, ?)
            ");
            $stmtHistorial->execute([$requisicionId, $observaciones, $usuarioId]);

            // 6. REGISTRO EN AUDITORÍA GENERAL CON IP REAL
            $ipReal = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (str_contains($ipReal, ',')) {
                $ipReal = trim(explode(',', $ipReal)[0]);
            }

            $stmtAudit = $conn->prepare("
                INSERT INTO auditoria (
                    usuario_id, modulo, accion, detalles, ip_address
                ) VALUES (?, 'requisiciones', 'recepcion_productos', ?, ?)
            ");
            $auditDetalles = json_encode([
                'requisicion_id' => $requisicionId,
                'numero' => $requisicion['numero'],
                'compromiso_id' => $compromisoId,
                'observaciones' => $observaciones,
            ], JSON_UNESCAPED_UNICODE);
            $stmtAudit->execute([$usuarioId, $auditDetalles, $ipReal]);

            // 7. COMMIT ACID
            if ($conn->inTransaction()) {
                $conn->commit();
            }

            $this->jsonResponse([
                'id' => $requisicionId,
                'numero' => $requisicion['numero'],
                'estado_anterior' => 'aprobada',
                'estado_nuevo' => 'recibida',
                'compromiso_id' => $compromisoId,
            ], 200, "Recepción de mercancía/servicios registrada correctamente para la requisición #{$requisicionId}.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $this->errorResponse('Error al procesar la recepción de bienes/servicios: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Endpoint de Eliminación (Método destroy)
     * Regla: SOLO se pueden eliminar físicamente requisiciones en estado 'borrador'.
     */
    public function destroy(string $id): void
    {
        $requisicionId = (int) $id;
        if ($requisicionId <= 0) {
            $this->errorResponse('ID de requisición no válido.', 400);
        }

        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->errorResponse('Sesión de usuario no válida o expirada.', 401);
        }

        $conn = \getConnection();

        try {
            // 1. INICIO DE TRANSACCIÓN ACID CON BLOQUEO PESIMISTA FOR UPDATE
            $conn->beginTransaction();

            $stmtCheck = $conn->prepare('SELECT id, estado, numero FROM requisiciones WHERE id = ? FOR UPDATE');
            $stmtCheck->execute([$requisicionId]);
            $requisicion = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$requisicion) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse("No se encontró la requisición #{$requisicionId}.", 404);
            }

            // 2. REGLA DE NEGOCIO CRÍTICA: SOLO 'borrador' PUEDE SER ELIMINADO
            if ($requisicion['estado'] !== 'borrador') {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse(
                    "Por normativas contables y de auditoría, el documento en estado '{$requisicion['estado']}' no se puede eliminar. Solo los borradores permiten eliminación física.",
                    422
                );
            }

            // 3. ELIMINACIÓN EN CASCADA
            $stmtDelItems = $conn->prepare('DELETE FROM requisicion_items WHERE requisicion_id = ?');
            $stmtDelItems->execute([$requisicionId]);

            $stmtDelHist = $conn->prepare('DELETE FROM requisicion_historial WHERE requisicion_id = ?');
            $stmtDelHist->execute([$requisicionId]);

            $stmtDelHeader = $conn->prepare('DELETE FROM requisiciones WHERE id = ?');
            $stmtDelHeader->execute([$requisicionId]);

            // 4. REGISTRO EN AUDITORÍA
            $ipReal = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (str_contains($ipReal, ',')) {
                $ipReal = trim(explode(',', $ipReal)[0]);
            }

            $stmtAudit = $conn->prepare("
                INSERT INTO auditoria (
                    usuario_id, modulo, accion, detalles, ip_address
                ) VALUES (?, 'requisiciones', 'eliminar_borrador', ?, ?)
            ");
            $auditDetalles = json_encode([
                'requisicion_id' => $requisicionId,
                'numero' => $requisicion['numero'],
                'estado_anterior' => 'borrador',
            ], JSON_UNESCAPED_UNICODE);
            $stmtAudit->execute([$usuarioId, $auditDetalles, $ipReal]);

            // 5. COMMIT ACID
            $conn->commit();

            $this->jsonResponse([
                'id' => $requisicionId,
                'numero' => $requisicion['numero'],
            ], 200, "Requisición borrador #{$requisicionId} eliminada correctamente.");
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $this->errorResponse('Error al intentar eliminar el borrador: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cambiar Estado con Aprobación Jerárquica y Optimización de I/O
     */
    public function cambiarEstado(string $id): void
    {
        $requisicionId = (int) $id;
        if ($requisicionId <= 0) {
            $this->errorResponse('ID de requisición no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->errorResponse('Sesión de usuario no válida o expirada.', 401);
        }

        $data = $this->getRequestData();
        $estadoNuevo = trim((string) ($data['estado_nuevo'] ?? ''));
        $comentario = trim((string) ($data['comentario'] ?? ''));

        // 1. VALIDACIÓN DE ENTRADAS
        $estadosPermitidos = ['enviada', 'aprobada', 'pendiente_presupuesto', 'rechazada', 'anulada'];
        if (!in_array($estadoNuevo, $estadosPermitidos, true)) {
            $this->errorResponse('Estado destino no válido. Opciones: ' . implode(', ', $estadosPermitidos), 422);
        }

        if ($comentario === '') {
            $this->errorResponse('Debe incluir una observación o comentario justificando el cambio de estado.', 422);
        }

        // OPTIMIZACIÓN SÉNIOR: Petición I/O de Red fuera de la transacción pesimista de MySQL
        $tasaCambio = self::TASA_CAMBIO_FALLBACK;
        $funcFile = __DIR__ . '/../../includes/funciones_contables.php';
        if (file_exists($funcFile)) {
            require_once $funcFile;
        }
        if (function_exists('obtenerTasaCambioActual')) {
            try {
                $tasa = (float) \obtenerTasaCambioActual();
                if ($tasa > 0) {
                    $tasaCambio = $tasa;
                }
            } catch (Throwable $eTasa) {
                $tasaCambio = self::TASA_CAMBIO_FALLBACK;
            }
        }

        $conn = \getConnection();

        try {
            // 2. INICIO DE TRANSACCIÓN RÁPIDA CON BLOQUEO PESIMISTA FOR UPDATE
            $conn->beginTransaction();

            $stmtCheck = $conn->prepare('SELECT id, estado, numero, total, moneda FROM requisiciones WHERE id = ? FOR UPDATE');
            $stmtCheck->execute([$requisicionId]);
            $requisicion = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$requisicion) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse("No se encontró la requisición #{$requisicionId}.", 404);
            }

            $estadoActual = $requisicion['estado'];
            $moneda = $requisicion['moneda'] ?? 'VES';
            $total = (float) ($requisicion['total'] ?? 0.0);

            // CÁLCULO DE MONTO EN USD REFERENCIAL CON TASA EN MEMORIA
            $totalUsd = ($moneda === 'USD') ? $total : ($tasaCambio > 0 ? $total / $tasaCambio : $total);

            // 3. APROBACIÓN JERÁRQUICA: EVALUACIÓN DE UMBRAL FINANCIERO ($1,000.00 USD)
            $estadoDestinoReal = $estadoNuevo;
            $comentarioFinal = $comentario;

            if ($estadoNuevo === 'aprobada') {
                if ($totalUsd > self::UMBRAL_NIVEL_2_USD && in_array($estadoActual, ['enviada', 'pendiente', 'borrador'], true)) {
                    $estadoDestinoReal = 'pendiente_nivel_2';
                    $comentarioFinal = "Requiere firma de aprobación Nivel 2 por superar umbral financiero ($" . number_format($totalUsd, 2, '.', ',') . " USD). Observación: " . $comentario;
                }
            }

            // SEGURIDAD RBAC: CONTROL DE PRIVILEGIOS PARA NIVELES SUPERIORES
            if ($estadoActual === 'pendiente_nivel_2' && $estadoDestinoReal === 'aprobada') {
                $esDirector = $this->verificarPermisoNivel2($conn, $usuarioId);
                if (!$esDirector) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $this->errorResponse('No tiene privilegios suficientes para autorizar la firma de Nivel 2 (Director / Gerente Financiero).', 403);
                }
            }

            // 4. MÁQUINA DE ESTADOS BLINDADA
            if ($estadoActual === 'anulada') {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $this->errorResponse('Esta requisición ya fue anulada y no puede ser modificada.', 422);
            }

            if ($estadoActual === $estadoDestinoReal) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $nombreEst = RequisicionEstado::getLabel($estadoActual);
                $this->errorResponse("La requisición ya se encuentra en estado: {$nombreEst}.", 422);
            }

            $transicionValida = match ($estadoDestinoReal) {
                'enviada' => in_array($estadoActual, ['borrador'], true),
                'aprobada', 'pendiente_presupuesto', 'pendiente_nivel_2', 'rechazada' => in_array($estadoActual, ['enviada', 'pendiente', 'pendiente_direccion', 'pendiente_presupuesto', 'borrador', 'pendiente_nivel_2'], true),
                'anulada' => in_array($estadoActual, ['borrador', 'enviada', 'pendiente', 'pendiente_direccion', 'pendiente_presupuesto', 'pendiente_nivel_2', 'aprobada'], true),
                default => false,
            };

            if (!$transicionValida) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $estDesde = RequisicionEstado::getLabel($estadoActual);
                $estHasta = RequisicionEstado::getLabel($estadoDestinoReal);
                $this->errorResponse("No se permite cambiar la requisición de '{$estDesde}' a '{$estHasta}'. Por favor, recargue la página.", 422);
            }

            // 5. ACTUALIZACIÓN DEL ESTADO Y NIVELES DE APROBACIÓN EN CABECERA
            if ($estadoDestinoReal === 'pendiente_presupuesto') {
                $stmtUpdate = $conn->prepare("
                    UPDATE requisiciones
                    SET estado = 'pendiente_presupuesto',
                        aprobacion_nivel_1 = 'aprobada',
                        usuario_aprobacion_1 = ?,
                        fecha_aprobacion_1 = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$usuarioId, $requisicionId]);
            } elseif ($estadoDestinoReal === 'aprobada') {
                $stmtUpdate = $conn->prepare("
                    UPDATE requisiciones
                    SET estado = 'aprobada',
                        aprobacion_nivel_1 = 'aprobada',
                        aprobacion_nivel_2 = 'aprobada',
                        validacion_presupuestaria = 'aprobada',
                        usuario_aprobacion_2 = ?,
                        fecha_aprobacion_2 = NOW(),
                        aprobado_por = ?,
                        fecha_aprobacion = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$usuarioId, $usuarioId, $requisicionId]);
            } elseif ($estadoDestinoReal === 'rechazada') {
                $stmtUpdate = $conn->prepare("
                    UPDATE requisiciones
                    SET estado = 'rechazada',
                        validacion_presupuestaria = 'rechazada'
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$requisicionId]);
            } else {
                $stmtUpdate = $conn->prepare('UPDATE requisiciones SET estado = ? WHERE id = ?');
                $stmtUpdate->execute([$estadoDestinoReal, $requisicionId]);
            }

            // 6. REGISTRO EN REQUISICION_HISTORIAL
            $stmtHistorial = $conn->prepare("
                INSERT INTO requisicion_historial (
                    requisicion_id, estado_desde, estado_hasta, comentario, usuario_id
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmtHistorial->execute([$requisicionId, $estadoActual, $estadoDestinoReal, $comentarioFinal, $usuarioId]);

            // 7. REGISTRO EN AUDITORÍA GENERAL CON IP REAL DE PROXY
            $ipReal = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (str_contains($ipReal, ',')) {
                $ipReal = trim(explode(',', $ipReal)[0]);
            }

            $stmtAudit = $conn->prepare("
                INSERT INTO auditoria (
                    usuario_id, modulo, accion, detalles, ip_address
                ) VALUES (?, 'requisiciones', ?, ?, ?)
            ");
            $auditDetalles = json_encode([
                'requisicion_id' => $requisicionId,
                'numero' => $requisicion['numero'],
                'estado_anterior' => $estadoActual,
                'estado_nuevo' => $estadoDestinoReal,
                'monto_usd' => round($totalUsd, 2),
                'comentario' => $comentarioFinal,
            ], JSON_UNESCAPED_UNICODE);
            $stmtAudit->execute([$usuarioId, "cambiar_estado_{$estadoDestinoReal}", $auditDetalles, $ipReal]);

            // 8. AFECTACIÓN PRESUPUESTARIA (SP de compromiso y Orden de Pago automática si pasa a aprobada)
            $compromisoId = null;
            $ordenPagoId = null;

            if ($estadoDestinoReal === 'aprobada') {
                try {
                    $stmtCompromiso = $conn->prepare("CALL generar_compromiso_desde_requisicion(?, ?, NOW(), @p_compromiso_id, @p_resultado)");
                    $stmtCompromiso->execute([$requisicionId, $usuarioId]);
                } catch (Throwable $eSP) {
                    $stmtCompromiso = $conn->prepare("CALL generar_compromiso_desde_requisicion(?, ?, @p_compromiso_id, @p_resultado)");
                    $stmtCompromiso->execute([$requisicionId, $usuarioId]);
                }

                $stmtResult = $conn->query("SELECT @p_compromiso_id AS compromiso_id, @p_resultado AS mensaje_resultado");
                $resultadoSP = $stmtResult->fetch(PDO::FETCH_ASSOC);

                if (empty($resultadoSP['compromiso_id'])) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $mensajeError = $resultadoSP['mensaje_resultado'] ?? 'Error interno del motor de base de datos al generar el compromiso.';
                    $this->errorResponse("Afectación presupuestaria fallida: " . $mensajeError, 422);
                }

                $compromisoId = (int) $resultadoSP['compromiso_id'];

                // GENERACIÓN AUTOMÁTICA DE ORDEN DE PAGO VINCULADA
                if ($compromisoId > 0 && !empty($requisicion['presupuesto_id'])) {
                    $stmtCheckOP = $conn->prepare("SELECT id FROM ordenes_pago WHERE requisicion_id = ? AND estado != 'anulada' LIMIT 1");
                    $stmtCheckOP->execute([$requisicionId]);
                    $opExistente = $stmtCheckOP->fetchColumn();

                    if (!$opExistente) {
                        $numOP = 'OP-' . date('Y') . '-' . str_pad((string) $requisicionId, 5, '0', STR_PAD_LEFT);
                        $stmtInsOP = $conn->prepare("
                            INSERT INTO ordenes_pago (
                                numero_orden, fecha_orden, presupuesto_id, proveedor_id, requisicion_id,
                                compromiso_id, beneficiario, concepto, monto, estado, tipo_pago, creado_por
                            ) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'emitida', 'transferencia', ?)
                        ");
                        $beneficiarioNombre = !empty($requisicion['proveedor_nombre'])
                            ? $requisicion['proveedor_nombre']
                            : 'Proveedor Requisición #' . ($requisicion['numero'] ?? $requisicionId);
                        $conceptoOP = 'Orden de pago emitida automáticamente por aprobación de Requisición #' . ($requisicion['numero'] ?? $requisicionId);
                        $stmtInsOP->execute([
                            $numOP,
                            $requisicion['presupuesto_id'],
                            $requisicion['proveedor_id'] ?? null,
                            $requisicionId,
                            $compromisoId,
                            $beneficiarioNombre,
                            $conceptoOP,
                            $requisicion['total'],
                            $usuarioId,
                        ]);
                        $ordenPagoId = (int) $conn->lastInsertId();
                    } else {
                        $ordenPagoId = (int) $opExistente;
                    }
                }
            }

            // 9. COMMIT DE TRANSACCIÓN ACID
            if ($conn->inTransaction()) {
                $conn->commit();
            }

            $mensajeFinal = ($estadoDestinoReal === 'pendiente_nivel_2')
                ? "La requisición supera el umbral de $" . number_format(self::UMBRAL_NIVEL_2_USD, 2) . " USD y ha sido promovida a estado 'pendiente_nivel_2' (Firma de Director requerida)."
                : "Requisición actualizada a estado '{$estadoDestinoReal}' correctamente.";

            $this->jsonResponse([
                'id' => $requisicionId,
                'estado_anterior' => $estadoActual,
                'estado_nuevo' => $estadoDestinoReal,
                'numero' => $requisicion['numero'],
                'compromiso_id' => $compromisoId,
                'monto_usd' => round($totalUsd, 2),
            ], 200, $mensajeFinal);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $this->errorResponse('Error al procesar el cambio de estado: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Auxiliar de Seguridad RBAC para Aprobación Nivel 2
     */
    private function verificarPermisoNivel2(PDO $conn, int $usuarioId): bool
    {
        $stmtUser = $conn->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $stmtUser->execute([$usuarioId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($user && in_array(strtolower((string) $user['rol']), ['admin', 'director'], true)) {
            return true;
        }

        if (function_exists('tienePermiso')) {
            if (tienePermiso($usuarioId, 'requisiciones', 'aprobar_nivel_2')) {
                return true;
            }
        }

        return false;
    }
}
