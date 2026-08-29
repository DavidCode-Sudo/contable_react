<?php
/**
 * Utilidades para el manejo de inventario
 * Sistema Contable Profesional
 */

require_once __DIR__ . '/../config/database/database.php';

/**
 * Genera un código de producto correlativo con prefijo y dígitos fijos.
 * Formato por defecto: PRD-00001, PRD-00002, ...
 */
function generarCodigoProducto(PDO $conn, string $prefijo = 'PRD-', int $digitos = 5): string {
    $stmt = $conn->prepare("SELECT codigo FROM productos WHERE codigo LIKE ? ORDER BY codigo DESC LIMIT 1");
    $like = $prefijo . '%';
    $stmt->execute([$like]);
    $ultimo = $stmt->fetchColumn();
    if ($ultimo && strncmp($ultimo, $prefijo, strlen($prefijo)) === 0) {
        $numerico = substr($ultimo, strlen($prefijo));
        // Remover ceros a la izquierda de forma segura
        $num = (int) ltrim($numerico, '0');
        $nuevo = $num + 1;
    } else {
        $nuevo = 1;
    }
    return $prefijo . str_pad((string)$nuevo, $digitos, '0', STR_PAD_LEFT);
}

/**
 * Registra un movimiento de inventario
 */
function registrarMovimientoInventario($producto_id, $cantidad, $tipo, $razon, $usuario_id, $documento_referencia = null, $precio_unitario = 0, $orden_entrega_id = null, $requisicion_id = null, $estado = 'activo') {
    $conn = getConnection();
    
    try {
        // Evitar transacciones anidadas: solo iniciar si no hay una activa
        $startedHere = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedHere = true;
        }
        
        // Calcular valor total
        $valor_total = $cantidad * $precio_unitario;
        
        // Obtener existencias actuales del producto
        $stmt_stock = $conn->prepare("SELECT existencias FROM productos WHERE id = ?");
        $stmt_stock->execute([$producto_id]);
        $stock_actual = $stmt_stock->fetchColumn() ?: 0;
        
        // Calcular nuevo stock
        $stock_anterior = (float)$stock_actual;
        $stock_nuevo = $tipo === 'entrada' 
            ? $stock_anterior + (float)$cantidad 
            : $stock_anterior - (float)$cantidad;
        
        // Asegurar que el stock no sea negativo
        if ($stock_nuevo < 0) {
            throw new Exception("No hay suficiente stock disponible. Stock actual: {$stock_anterior}, Cantidad solicitada: {$cantidad}");
        }
        
        // Insertar movimiento
        $stmt = $conn->prepare("
            INSERT INTO movimientos_inventario 
            (producto_id, cantidad, tipo, estado, razon, usuario_id, documento_referencia, precio_unitario, valor_total, stock_anterior, stock_nuevo, orden_entrega_id, requisicion_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $producto_id, $cantidad, $tipo, $estado, $razon, $usuario_id, 
            $documento_referencia, $precio_unitario, $valor_total, 
            $stock_anterior, $stock_nuevo, $orden_entrega_id, $requisicion_id
        ]);
        
        // Actualizar existencias en la tabla productos
        $stmt_update = $conn->prepare("UPDATE productos SET existencias = ? WHERE id = ?");
        $stmt_update->execute([$stock_nuevo, $producto_id]);
        
        if ($startedHere) {
            $conn->commit();
        }
        return $conn->lastInsertId();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            // Solo hacer rollback si esta función inició la transacción o si hay una activa
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Anula movimientos de inventario de una requisición
 * Marca los movimientos como anulados en lugar de eliminar o crear salidas
 */
function anularMovimientosRequisicion($requisicion_id, $usuario_id, $razon_anulacion = '') {
    $conn = getConnection();
    
    try {
        // Evitar transacciones anidadas
        $startedHere = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedHere = true;
        }
        
        // Obtener todos los movimientos activos de esta requisición
        $stmt_movs = $conn->prepare("
            SELECT mi.*, p.codigo, p.nombre as producto_nombre 
            FROM movimientos_inventario mi
            INNER JOIN productos p ON p.id = mi.producto_id
            WHERE mi.requisicion_id = ? AND mi.estado = 'activo'
        ");
        $stmt_movs->execute([$requisicion_id]);
        $movimientos = $stmt_movs->fetchAll(PDO::FETCH_ASSOC);
        
        $movimientos_anulados = 0;
        foreach ($movimientos as $mov) {
            // Anular el movimiento marcándolo como anulado
            $stmt_anular = $conn->prepare("
                UPDATE movimientos_inventario 
                SET estado = 'anulado' 
                WHERE id = ?
            ");
            $stmt_anular->execute([$mov['id']]);
            
            // Revertir el stock del producto
            // Si fue entrada, restar; si fue salida, sumar
            $cantidad_ajustar = $mov['tipo'] === 'entrada' 
                ? -$mov['cantidad'] 
                : +$mov['cantidad'];
            
            $stmt_update = $conn->prepare("
                UPDATE productos 
                SET existencias = GREATEST(0, existencias + ?) 
                WHERE id = ?
            ");
            $stmt_update->execute([$cantidad_ajustar, $mov['producto_id']]);
            
            $movimientos_anulados++;
        }
        
        if ($startedHere) {
            $conn->commit();
        }
        
        return $movimientos_anulados;
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Obtiene el stock actual de un producto
 */
function obtenerStockProducto($producto_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT existencias FROM productos WHERE id = ?");
    $stmt->execute([$producto_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (float)$result['existencias'] : 0;
}

/**
 * Verifica si hay suficiente stock para una salida
 */
function verificarStockDisponible($producto_id, $cantidad_requerida) {
    $stock_actual = obtenerStockProducto($producto_id);
    return $stock_actual >= $cantidad_requerida;
}

/**
 * Obtiene productos con stock bajo (por debajo del punto de reorden)
 */
function obtenerProductosStockBajo() {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_principal_id = pr.id
        WHERE p.estado = 'activo' 
        AND p.existencias <= p.punto_reorden 
        AND p.punto_reorden > 0
        ORDER BY (p.existencias / NULLIF(p.punto_reorden, 0)) ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene el historial de movimientos de un producto
 */
function obtenerHistorialProducto($producto_id, $limite = 50) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT m.*, u.nombre_completo as usuario_nombre,
               oe.numero as orden_numero,
               r.numero as requisicion_numero
        FROM movimientos_inventario m
        LEFT JOIN usuarios u ON m.usuario_id = u.id
        LEFT JOIN ordenes_entrega oe ON m.orden_entrega_id = oe.id
        LEFT JOIN requisiciones r ON m.requisicion_id = r.id
        WHERE m.producto_id = ?
        ORDER BY m.fecha DESC
        LIMIT ?
    ");
    $stmt->execute([$producto_id, $limite]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Procesa la recepción de una requisición aprobada
 */
function procesarRecepcionRequisicion($requisicion_id, $items_recibidos, $usuario_id) {
    $conn = getConnection();
    
    try {
        // Evitar transacciones anidadas: solo iniciar si no hay una activa
        $startedHere = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedHere = true;
        }
        
        // Obtener datos de la requisición
        $requisicion = fetchOne("SELECT * FROM requisiciones WHERE id = ? AND estado = 'aprobada'", [$requisicion_id]);
        if (!$requisicion) {
            throw new Exception("Requisición no encontrada o no está aprobada");
        }
        
        $total_items_procesados = 0;
        
        foreach ($items_recibidos as $item_id => $cantidad_recibida) {
            if ($cantidad_recibida <= 0) continue;
            
            // Obtener item de requisición
            $item = fetchOne("SELECT * FROM requisicion_items WHERE id = ? AND requisicion_id = ?", [$item_id, $requisicion_id]);
            if (!$item) continue;
            
            // Si el item tiene producto_id asociado, actualizar inventario
            if ($item['producto_id']) {
                registrarMovimientoInventario(
                    $item['producto_id'],
                    $cantidad_recibida,
                    'entrada',
                    "Recepción de requisición {$requisicion['numero']}",
                    $usuario_id,
                    $requisicion['numero'],
                    $item['precio'],
                    null,
                    $requisicion_id
                );
                // Actualizar costo del producto usando costo promedio ponderado
                // nuevo_costo = (existencias*costo_actual + cantidad_recibida*precio_recibido) / (existencias + cantidad_recibida)
                $prodInfo = fetchOne("SELECT existencias, costo FROM productos WHERE id = ?", [$item['producto_id']]);
                $existenciasActuales = (float)($prodInfo['existencias'] ?? 0);
                $costoActual = (float)($prodInfo['costo'] ?? 0);
                $existenciasNuevas = $existenciasActuales + (float)$cantidad_recibida;
                $nuevoCosto = $existenciasNuevas > 0
                    ? (($existenciasActuales * $costoActual) + ((float)$cantidad_recibida * (float)$item['precio'])) / $existenciasNuevas
                    : (float)$item['precio'];
                $conn->prepare("UPDATE productos SET costo = :costo WHERE id = :id")
                     ->execute([':costo' => $nuevoCosto, ':id' => $item['producto_id']]);
            } else {
                // Si no tiene producto asociado, crear el producto automáticamente
                $nuevo_producto_id = crearProductoDesdeRequisicion($item, $usuario_id);
                
                // Actualizar el item con el producto creado
                $conn->prepare("UPDATE requisicion_items SET producto_id = ? WHERE id = ?")
                     ->execute([$nuevo_producto_id, $item_id]);
                
                // Registrar movimiento
                registrarMovimientoInventario(
                    $nuevo_producto_id,
                    $cantidad_recibida,
                    'entrada',
                    "Recepción de requisición {$requisicion['numero']} - Producto nuevo",
                    $usuario_id,
                    $requisicion['numero'],
                    $item['precio'],
                    null,
                    $requisicion_id
                );
                // Establecer costo usando promedio ponderado (para nuevos productos será el precio recibido)
                $prodInfo = fetchOne("SELECT existencias, costo FROM productos WHERE id = ?", [$nuevo_producto_id]);
                $existenciasActuales = (float)($prodInfo['existencias'] ?? 0);
                $costoActual = (float)($prodInfo['costo'] ?? 0);
                $existenciasNuevas = $existenciasActuales + (float)$cantidad_recibida;
                $nuevoCosto = $existenciasNuevas > 0
                    ? (($existenciasActuales * $costoActual) + ((float)$cantidad_recibida * (float)$item['precio'])) / $existenciasNuevas
                    : (float)$item['precio'];
                $conn->prepare("UPDATE productos SET costo = :costo WHERE id = :id")
                     ->execute([':costo' => $nuevoCosto, ':id' => $nuevo_producto_id]);
            }
            
            // Registrar recepción en la nueva tabla de recepciones
            $conn->prepare("INSERT INTO recepciones_items (requisicion_id, item_id, cantidad_recibida, usuario_id) VALUES (?, ?, ?, ?)")
                 ->execute([$requisicion_id, $item_id, $cantidad_recibida, $usuario_id]);
            
            $total_items_procesados++;
        }
        
        if ($total_items_procesados > 0) {
            // Actualizar estado de requisición a recibida
            // IMPORTANTE: Este UPDATE activará el trigger que genera el causado automáticamente
            $conn->prepare("UPDATE requisiciones SET estado = 'recibida', recibido_por = ?, fecha_recepcion = NOW() WHERE id = ?")
                 ->execute([$usuario_id, $requisicion_id]);
            
            // Registrar en historial
            $conn->prepare("INSERT INTO requisicion_historial (requisicion_id, estado_desde, estado_hasta, comentario, usuario_id) VALUES (?, 'aprobada', 'recibida', 'Recepción procesada - Productos agregados al inventario - Causado generado automáticamente', ?)")
                 ->execute([$requisicion_id, $usuario_id]);
        }
        
        if ($startedHere) {
            $conn->commit();
        }
        return $total_items_procesados;
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Crea un producto automáticamente desde un item de requisición
 */
function crearProductoDesdeRequisicion($item, $usuario_id) {
    $conn = getConnection();
    // NUEVO: generar código correlativo como en el inventario (PRD-XXXXX)
    if (!function_exists('generarCodigoProducto')) {
        require_once __DIR__ . '/util_inventario.php';
    }
    $codigo = generarCodigoProducto($conn, 'PRD-', 5);
    // Usar el precio del item (ya está en Bs)
    $precio = isset($item['precio']) ? (float)$item['precio'] : 0;
    $stmt = $conn->prepare("
        INSERT INTO productos (codigo, nombre, descripcion, precio, costo, existencias, unidad_medida, estado) 
        VALUES (?, ?, ?, ?, 0, 0, ?, 'activo')
    ");
    $descripcion_profesional = $item['descripcion'] . " - Producto agregado al catálogo";
    $stmt->execute([
        $codigo,
        $item['descripcion'],
        $descripcion_profesional,
        $precio,
        $item['unidad']
    ]);
    return $conn->lastInsertId();
}

/**
 * Obtiene productos disponibles para órdenes de entrega
 */
function obtenerProductosDisponibles($busqueda = '', $limite = 100) {
    $conn = getConnection();
    
    $where = "WHERE p.estado = 'activo' AND p.existencias > 0";
    $params = [];
    
    if (!empty($busqueda)) {
        $where .= " AND (p.codigo LIKE ? OR p.nombre LIKE ? OR p.descripcion LIKE ?)";
        $busqueda_param = "%{$busqueda}%";
        $params = [$busqueda_param, $busqueda_param, $busqueda_param];
    }
    
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        {$where}
        ORDER BY p.nombre ASC
        LIMIT ?
    ");
    
    $params[] = $limite;
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Procesa una orden de entrega
 */
function procesarOrdenEntrega($orden_id, $items_entregados, $usuario_entrega_id) {
    $conn = getConnection();
    
    try {
        // Evitar transacciones anidadas: solo iniciar si no hay una activa
        $startedHere = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedHere = true;
        }
        
        // Obtener orden
        $orden = fetchOne("SELECT * FROM ordenes_entrega WHERE id = ? AND estado = 'autorizada'", [$orden_id]);
        if (!$orden) {
            throw new Exception("Orden no encontrada o no está autorizada");
        }
        
        $total_items_procesados = 0;
        
        foreach ($items_entregados as $item_id => $cantidad_entregada) {
            if ($cantidad_entregada <= 0) continue;
            
            // Obtener item de orden
            $item = fetchOne("SELECT * FROM orden_entrega_items WHERE id = ? AND orden_entrega_id = ?", [$item_id, $orden_id]);
            if (!$item) continue;
            
            // Verificar stock disponible
            if (!verificarStockDisponible($item['producto_id'], $cantidad_entregada)) {
                throw new Exception("Stock insuficiente para el producto ID: {$item['producto_id']}");
            }
            
            // Registrar salida de inventario
            registrarMovimientoInventario(
                $item['producto_id'],
                $cantidad_entregada,
                'salida',
                "Entrega orden {$orden['numero']} - {$orden['motivo']}",
                $usuario_entrega_id,
                $orden['numero'],
                $item['precio_unitario_usd'],
                $orden_id,
                null
            );
            
            // Actualizar cantidad entregada
            $conn->prepare("UPDATE orden_entrega_items SET cantidad_entregada = cantidad_entregada + ? WHERE id = ?")
                 ->execute([$cantidad_entregada, $item_id]);
            
            $total_items_procesados++;
        }
        
        if ($total_items_procesados > 0) {
            // Actualizar estado de orden
            $conn->prepare("UPDATE ordenes_entrega SET estado = 'entregada', entregado_por = ?, fecha_entrega = NOW() WHERE id = ?")
                 ->execute([$usuario_entrega_id, $orden_id]);
            
            // Registrar en historial
            $conn->prepare("INSERT INTO orden_entrega_historial (orden_entrega_id, estado_desde, estado_hasta, comentario, usuario_id) VALUES (?, 'autorizada', 'entregada', 'Entrega procesada', ?)")
                 ->execute([$orden_id, $usuario_entrega_id]);
        }
        
        $conn->commit();
        return $total_items_procesados;
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Genera reporte de inventario
 */
function generarReporteInventario($filtros = []) {
    $conn = getConnection();
    
    $where = "WHERE p.estado = 'activo'";
    $params = [];
    
    if (!empty($filtros['categoria_id'])) {
        $where .= " AND p.categoria_id = ?";
        $params[] = $filtros['categoria_id'];
    }
    
    if (!empty($filtros['stock_bajo'])) {
        $where .= " AND p.existencias <= p.punto_reorden AND p.punto_reorden > 0";
    }
    
    if (!empty($filtros['sin_stock'])) {
        $where .= " AND p.existencias = 0";
    }
    
    $stmt = $conn->prepare("
        SELECT p.*, 
               c.nombre as categoria_nombre,
               pr.nombre as proveedor_nombre,
               (p.existencias * p.costo) as valor_inventario,
               CASE 
                   WHEN p.existencias = 0 THEN 'Sin Stock'
                   WHEN p.existencias <= p.punto_reorden AND p.punto_reorden > 0 THEN 'Stock Bajo'
                   WHEN p.existencias <= p.stock_minimo AND p.stock_minimo > 0 THEN 'Stock Mínimo'
                   ELSE 'Normal'
               END as estado_stock
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN proveedores pr ON p.proveedor_principal_id = pr.id
        {$where}
        ORDER BY p.nombre ASC
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene estadísticas del inventario
 */
function obtenerEstadisticasInventario() {
    $conn = getConnection();
    
    $stats = [];
    
    // Total productos activos
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'");
    $stmt->execute();
    $stats['total_productos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Productos sin stock
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo' AND existencias = 0");
    $stmt->execute();
    $stats['sin_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Productos con stock bajo
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo' AND existencias <= punto_reorden AND punto_reorden > 0");
    $stmt->execute();
    $stats['stock_bajo'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Valor total del inventario
    $stmt = $conn->prepare("SELECT SUM(existencias * costo) as valor_total FROM productos WHERE estado = 'activo'");
    $stmt->execute();
    $stats['valor_total'] = $stmt->fetch(PDO::FETCH_ASSOC)['valor_total'] ?: 0;
    
    // Movimientos del mes actual
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM movimientos_inventario WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())");
    $stmt->execute();
    $stats['movimientos_mes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    return $stats;
}

