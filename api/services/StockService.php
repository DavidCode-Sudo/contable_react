<?php
declare(strict_types=1);

namespace Api\Services;

use PDO;
use Exception;
use Throwable;

/**
 * Servicio Centralizado para Mutaciones de Inventario (ACID + Bloqueo Pesimista)
 * Erradica condiciones de carrera entre movimientos manuales y recepciones de requisiciones.
 */
class StockService
{
    /**
     * Adquiere de forma centralizada y pesimista el bloqueo FOR UPDATE sobre un conjunto de productos.
     * Garantiza la deduplicación y el ordenamiento estrictamente ascendente (SORT_NUMERIC)
     * para erradicar cualquier posibilidad de deadlock en MySQL/MariaDB.
     *
     * @param PDO $conn Conexión a BD dentro de transacción
     * @param array $productIds Lista de IDs de productos a bloquear
     * @param int $timeoutSeconds Timeout de espera de cerrojo (por defecto 5s)
     * @return array Mapa de productos indexado por ID (int)
     */
    public static function lockProductsForUpdate(PDO $conn, array $productIds, int $timeoutSeconds = 5): array
    {
        $conn->exec("SET SESSION innodb_lock_wait_timeout = {$timeoutSeconds}");

        $cleanIds = [];
        foreach ($productIds as $id) {
            $pId = (int) $id;
            if ($pId > 0) {
                $cleanIds[] = $pId;
            }
        }

        $cleanIds = array_values(array_unique($cleanIds));
        if (empty($cleanIds)) {
            return [];
        }

        sort($cleanIds, SORT_NUMERIC);
        $inClause = implode(',', array_fill(0, count($cleanIds), '?'));

        $stmt = $conn->prepare("
            SELECT id, nombre, existencias, COALESCE(stock_reservado, 0.000) AS stock_reservado, costo, precio, unidad_medida, COALESCE(permite_decimales, 1) AS permite_decimales
            FROM productos
            WHERE id IN ({$inClause})
            ORDER BY id ASC
            FOR UPDATE
        ");
        $stmt->execute($cleanIds);
        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $productosMap = [];
        foreach ($prods as $p) {
            $productosMap[(int) $p['id']] = $p;
        }

        return $productosMap;
    }

    /**
     * Restaura el timeout de bloqueo de la sesión al valor global del servidor MariaDB/MySQL.
     * Previene el 'drift' de variables de sesión en entornos de workers persistentes o connection pooling.
     */
    public static function restoreLockWaitTimeout(PDO $conn): void
    {
        try {
            $conn->exec("SET SESSION innodb_lock_wait_timeout = @@global.innodb_lock_wait_timeout");
        } catch (Throwable $e) {
            // Silencioso si la conexión PDO ya se cerró
        }
    }
    /**
     * Muta el stock de un producto de forma atómica y pesimista.
     *
     * @param PDO $conn Conexión a la base de datos (debe estar dentro o iniciar transacción)
     * @param int $productoId ID del producto
     * @param float $cantidad Cantidad a ajustar (siempre mayor a 0)
     * @param string $tipo 'entrada' | 'salida'
     * @param string $razon Motivo u observación
     * @param int|null $requisicionId ID de la requisición asociada (si aplica)
     * @param int|null $ordenEntregaId ID de la orden de entrega asociada (si aplica)
     * @param int $usuarioId ID del usuario que ejecuta la acción
     * @param float|null $costoEntrada Costo unitario para el cálculo de Costo Promedio Ponderado (solo entradas)
     * @return array Resumen con stock_anterior, stock_nuevo, costo_nuevo y movimiento_id
     * @throws Exception Si no hay stock suficiente o falla la operación
     */
    public static function mutarStock(
        PDO $conn,
        int $productoId,
        float $cantidad,
        string $tipo,
        string $razon,
        ?int $requisicionId = null,
        ?int $ordenEntregaId = null,
        int $usuarioId = 1,
        ?float $costoEntrada = null,
        ?string $motivoCodigo = null,
        ?string $documentoReferencia = null
    ): array {
        $cantidad = (float) $cantidad;

        if ($cantidad <= 0) {
            throw new Exception("📌 DIAGNÓSTICO: Cantidad no válida para el movimiento. 💡 DETALLE: Se ingresó una cantidad menor o igual a cero ({$cantidad}). 🔧 ACCIÓN REQUERIDA: Ingrese una cantidad mayor a cero.");
        }

        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['entrada', 'salida'], true)) {
            throw new Exception("📌 DIAGNÓSTICO: Tipo de movimiento de inventario inválido. 💡 DETALLE: El tipo enviado fue '{$tipo}'. 🔧 ACCIÓN REQUERIDA: Seleccione 'entrada' o 'salida'.");
        }

        // Sanitización de documentoReferencia (limpieza de tags y truncado defensivo a 100 caracteres)
        $documentoReferencia = $documentoReferencia !== null ? mb_substr(trim(strip_tags((string)$documentoReferencia)), 0, 100) : null;
        if ($documentoReferencia === '') {
            $documentoReferencia = null;
        }

        // VALIDACIÓN DEFENSIVA BACKEND DE REGLA DE NEGOCIO:
        // Toda entrada de stock con saldo positivo exige obligatoriamente documento respaldante (Factura, Nota o Acta)
        if ($tipo === 'entrada' && ($documentoReferencia === null || $documentoReferencia === '')) {
            throw new Exception("📌 DIAGNÓSTICO: Documento de referencia obligatorio. 💡 DETALLE: Las entradas de inventario requieren registrar el comprobante físico o digital de soporte. 🔧 ACCIÓN REQUERIDA: Ingrese el número de Factura, Nota de Entrega o Acta de Recepción antes de guardar.");
        }

        $transaccionIniciadaAqui = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $transaccionIniciadaAqui = true;
        }

        try {
            // 1. BLOQUEO PESIMISTA FOR UPDATE SOBRE EL PRODUCTO
            $stmt = $conn->prepare('SELECT id, existencias, costo, precio, nombre, codigo FROM productos WHERE id = ? FOR UPDATE');
            $stmt->execute([$productoId]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new Exception("📌 DIAGNÓSTICO: Producto no encontrado. 💡 DETALLE: El producto con ID #{$productoId} no existe en el catálogo. 🔧 ACCIÓN REQUERIDA: Seleccione un producto válido del listado.");
            }

            $stockAnterior = (float) ($producto['existencias'] ?? 0.0);
            $costoActual = (float) ($producto['costo'] ?? 0.0);

            if ($tipo === 'salida') {
                // REGLA DE ORO CONTABLE: Las salidas NUNCA aceptan costo externo ni alteran el CPP del inventario.
                // Usan strictly el costo unitario actual vigente.
                $costoEntrada = null;
                $costoTransaccion = $costoActual;

                if ($stockAnterior < $cantidad) {
                    throw new Exception("📌 DIAGNÓSTICO: Stock físico insuficiente para el movimiento. 💡 DETALLE: Insumo: '{$producto['nombre']}' (Código: {$producto['codigo']}). Existencias físicas en almacén: {$stockAnterior}. Cantidad a descontar: {$cantidad}. 🔧 ACCIÓN REQUERIDA: Registre primero la entrada de mercancía o reduzca la cantidad de salida.");
                }
                $stockNuevo = $stockAnterior - $cantidad;
                $costoNuevo = $costoActual; // Mantiene el CPP intacto en salidas
            } else {
                // ENTRADA: RECALCULAR COSTO PROMEDIO PONDERADO (CPP)
                // REGLA CONTABLE PREVENTIVA: Si se recibe una donacion/ingreso sin costo (0 o null),
                // se toma por defecto el $costoActual para no distorsionar el CPP a $0.00.
                if ($costoEntrada === null || $costoEntrada <= 0) {
                    $costoTransaccion = $costoActual;
                } else {
                    $costoTransaccion = (float) $costoEntrada;
                }

                $stockNuevo = round($stockAnterior + $cantidad, 3);

                // GUARDA DE SEGURIDAD CONTRA DIVISIÓN POR CERO O STOCK PREVIO NEGATIVO (NIC 2 / CPP)
                $denominadorCPP = $stockAnterior + $cantidad;
                if ($denominadorCPP <= 0.0001) {
                    $costoNuevo = round($costoTransaccion, 4);
                } else {
                    $costoNuevo = round(
                        (($stockAnterior * $costoActual) + ($cantidad * $costoTransaccion)) / $denominadorCPP,
                        4
                    );
                }
            }

            // 2. ACTUALIZACIÓN ATÓMICA EN LA TABLA PRODUCTOS
            $stmtUpdate = $conn->prepare('UPDATE productos SET existencias = ?, costo = ? WHERE id = ?');
            $stmtUpdate->execute([$stockNuevo, $costoNuevo, $productoId]);

            // 3. REGISTRO EN LA TABLA MOVIMIENTOS_INVENTARIO CON CÓDIGO DE MOTIVO BI
            $valorTotal = round($cantidad * ($costoEntrada ?? $costoNuevo), 2);
            $stmtMov = $conn->prepare("
                INSERT INTO movimientos_inventario (
                    producto_id, cantidad, tipo, estado, razon, motivo_codigo, documento_referencia,
                    precio_unitario, valor_total, stock_anterior, stock_nuevo,
                    requisicion_id, orden_entrega_id, usuario_id, fecha
                ) VALUES (?, ?, ?, 'activo', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtMov->execute([
                $productoId,
                $cantidad,
                $tipo,
                $razon,
                $motivoCodigo,
                $documentoReferencia,
                $costoEntrada ?? $costoNuevo,
                $valorTotal,
                $stockAnterior,
                $stockNuevo,
                $requisicionId,
                $ordenEntregaId,
                $usuarioId,
            ]);

            $movimientoId = (int) $conn->lastInsertId();

            if ($transaccionIniciadaAqui) {
                $conn->commit();
            }

            return [
                'movimiento_id' => $movimientoId,
                'producto_id' => $productoId,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'costo_anterior' => $costoActual,
                'costo_nuevo' => $costoNuevo,
            ];
        } catch (Throwable $e) {
            if ($transaccionIniciadaAqui && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
}
