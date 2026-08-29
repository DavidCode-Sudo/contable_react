# 🔴 Lo que Falta Implementar para que Funcione Correctamente la Nómina

## 📋 Resumen Ejecutivo

El sistema de nóminas está **87.5% completo**. Solo falta **1 funcionalidad crítica** para cerrar el ciclo completo de nómina.

---

## ❌ FUNCIONALIDAD FALTANTE

### **1. Integración Automática de Pagos con Nóminas** 🔴 **CRÍTICO**

**Problema:**
Cuando se marca una orden de pago como "pagada", el sistema NO actualiza automáticamente el estado del recibo de nómina asociado. Esto significa que:

- ✅ Las órdenes se marcan como pagadas
- ✅ El presupuesto se actualiza (`presupuesto.pagado += monto`)
- ✅ El asiento contable se genera
- ❌ **PERO** los recibos de nómina siguen en estado `pendiente` en lugar de `pagado`

**Impacto:**
- Los empleados no tienen visibilidad de que su nómina fue pagada
- No se puede generar reportes de nóminas pagadas vs pendientes
- El seguimiento de pagos de nómina no está completo
- La trazabilidad se pierde

---

## 📍 UBICACIÓN DEL CÓDIGO

**Archivo:** `modulos/presupuestos/ordenes_pago.php`

**Línea donde agregar:** Después de la línea **1084**, antes de `$conn->commit();`

**Contexto:** Dentro de la sección que marca la orden como pagada (aproximadamente líneas 900-1095)

---

## 💻 CÓDIGO A IMPLEMENTAR

### **Código Completo:**

```php
// ACTUALIZAR ESTADO DE RECIBO DE NÓMINA SI LA ORDEN ESTÁ VINCULADA
if (!empty($orden['nomina_empleado_id'])) {
    try {
        // Actualizar estado del recibo de nómina a 'pagado'
        $stmt_nomina = $conn->prepare("UPDATE nominas_empleados 
                                       SET estado = 'pagado' 
                                       WHERE id = ? AND estado = 'pendiente'");
        $stmt_nomina->execute([$orden['nomina_empleado_id']]);
        
        // Obtener el ID de la nómina para verificar si todos los recibos están pagados
        $stmt_nom_id = $conn->prepare("SELECT nomina_id FROM nominas_empleados WHERE id = ?");
        $stmt_nom_id->execute([$orden['nomina_empleado_id']]);
        $nomina_id = $stmt_nom_id->fetchColumn();
        
        if ($nomina_id) {
            // Verificar si todos los recibos de la nómina están pagados
            $stmt_check = $conn->prepare("SELECT COUNT(*) as pendientes 
                                         FROM nominas_empleados 
                                         WHERE nomina_id = ? AND estado = 'pendiente'");
            $stmt_check->execute([$nomina_id]);
            $pendientes = (int)$stmt_check->fetchColumn();
            
            // Si todos los recibos están pagados, opcionalmente actualizar estado de nómina
            // (Esto es opcional, ya que los recibos individuales son lo más importante)
            if ($pendientes == 0) {
                // Opcional: Registrar en log que toda la nómina está pagada
                error_log("Nómina ID {$nomina_id}: Todos los recibos están pagados");
            }
        }
        
        // Registrar auditoría
        try {
            registrarActualizacion(
                'nominas_empleados',
                'nominas_empleados',
                $orden['nomina_empleado_id'],
                ['estado' => 'pendiente'],
                ['estado' => 'pagado'],
                "Recibo marcado como pagado por orden de pago {$orden['numero_orden']}"
            );
        } catch (Exception $e) {
            error_log("Error en auditoría de recibo de nómina: " . $e->getMessage());
        }
        
    } catch (Exception $e) {
        // No fallar toda la transacción si hay error actualizando nómina
        // Solo registrar en log
        error_log("Error actualizando estado de recibo de nómina ID {$orden['nomina_empleado_id']}: " . $e->getMessage());
    }
}
```

### **Ubicación Exacta en el Código:**

Buscar esta sección (aproximadamente línea 1082):

```php
                    // Registrar en historial de la requisición para trazabilidad
                    if (!empty($orden['requisicion_id'])) {
                        $estado_historial = $estado_requisicion ?: 'recibida';
                        $comentario_historial = sprintf('Orden de pago %s marcada como pagada', $orden['numero_orden']);
                        $stmt_historial = $conn->prepare("INSERT INTO requisicion_historial (requisicion_id, estado_desde, estado_hasta, comentario, usuario_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt_historial->execute([
                            $orden['requisicion_id'],
                            $estado_historial,
                            'pagada',
                            $comentario_historial,
                            $_SESSION['usuario_id']
                        ]);
                    }

                    // ⬇️ AQUÍ AGREGAR EL CÓDIGO NUEVO ⬇️
                    
                    $conn->commit();
```

**Insertar el código nuevo JUSTO ANTES de `$conn->commit();`**

---

## ✅ PASOS DE IMPLEMENTACIÓN

### **Paso 1: Abrir el archivo**
```
modulos/presupuestos/ordenes_pago.php
```

### **Paso 2: Buscar la línea**
Buscar la línea que contiene:
```php
$conn->commit();
```
Dentro de la sección que procesa el pago de la orden (aproximadamente línea 1084).

### **Paso 3: Insertar el código**
Antes de `$conn->commit();`, agregar el código completo proporcionado arriba.

### **Paso 4: Verificar**
- Asegurarse de que el código está dentro de la transacción (antes de `commit()`)
- Verificar que hay manejo de errores (try-catch)
- Verificar que no rompe la funcionalidad existente

### **Paso 5: Probar**
1. Generar una nómina
2. Confirmarla
3. Generar órdenes de pago
4. Marcar una orden como pagada
5. Verificar que el recibo de nómina cambió a estado `pagado`

---

## 🧪 CASOS DE PRUEBA

### **Caso 1: Orden de pago con nómina**
```
1. Generar nómina para 3 empleados
2. Confirmar nómina
3. Generar órdenes de pago (3 órdenes)
4. Marcar orden 1 como pagada
   → Verificar: nominas_empleados.estado = 'pagado' para empleado 1
5. Marcar orden 2 como pagada
   → Verificar: nominas_empleados.estado = 'pagado' para empleado 2
6. Marcar orden 3 como pagada
   → Verificar: nominas_empleados.estado = 'pagado' para empleado 3
   → Verificar: Todos los recibos están pagados
```

### **Caso 2: Orden de pago sin nómina (proveedor)**
```
1. Crear orden de pago para proveedor (sin nomina_empleado_id)
2. Marcar como pagada
   → Verificar: No debe dar error
   → Verificar: Sistema funciona normalmente
```

### **Caso 3: Orden de pago ya pagada**
```
1. Marcar orden como pagada (primera vez)
   → Verificar: Recibo cambia a 'pagado'
2. Intentar marcar nuevamente (si es posible)
   → Verificar: No debe dar error
   → Verificar: Estado sigue siendo 'pagado'
```

---

## 📊 IMPACTO DE LA IMPLEMENTACIÓN

### **Antes:**
```
Orden Pagada → ❌ Recibo sigue en 'pendiente'
```

### **Después:**
```
Orden Pagada → ✅ Recibo actualizado a 'pagado'
```

### **Beneficios:**
1. ✅ Trazabilidad completa del pago
2. ✅ Reportes de nóminas pagadas vs pendientes
3. ✅ Visibilidad para empleados de su estado de pago
4. ✅ Cierre completo del ciclo de nómina
5. ✅ Auditoría completa de pagos

---

## 🔍 VERIFICACIÓN POST-IMPLEMENTACIÓN

### **Verificar en Base de Datos:**

```sql
-- Verificar que los recibos se actualizan correctamente
SELECT 
    ne.id,
    ne.recibo_numero,
    ne.estado,
    op.numero_orden,
    op.estado as orden_estado,
    op.nomina_empleado_id
FROM nominas_empleados ne
LEFT JOIN ordenes_pago op ON op.nomina_empleado_id = ne.id
WHERE op.estado = 'pagada'
AND ne.estado != 'pagado';  -- No debería haber resultados
```

### **Verificar en la Interfaz:**

1. Ir a `modulos/nominas/ver_nomina.php?id=X`
2. Ver los empleados de la nómina
3. Verificar que los empleados con órdenes pagadas muestran estado "pagado"

---

## 📝 NOTAS IMPORTANTES

1. **No romper funcionalidad existente:**
   - El código solo se ejecuta si `nomina_empleado_id` existe
   - Si hay error, solo se registra en log, no falla la transacción

2. **Manejo de errores:**
   - El código está dentro de try-catch
   - Los errores se registran en log pero no detienen el proceso

3. **Auditoría:**
   - Se registra la actualización en la tabla de auditoría
   - Se guarda quién y cuándo se actualizó

4. **Transacciones:**
   - El código está dentro de la transacción existente
   - Si hay rollback, también se revierte la actualización de nómina

---

## ✅ CHECKLIST DE IMPLEMENTACIÓN

```
☐ Abrir archivo ordenes_pago.php
☐ Localizar línea $conn->commit() (aproximadamente 1084)
☐ Copiar código completo
☐ Pegar antes de $conn->commit()
☐ Verificar sintaxis (sin errores)
☐ Probar caso 1: Orden con nómina
☐ Probar caso 2: Orden sin nómina
☐ Verificar en base de datos
☐ Verificar en interfaz
☐ Confirmar que no hay errores en logs
```

---

## 🎯 RESUMEN

**Solo falta 1 funcionalidad:**
- ❌ Actualizar estado de recibos de nómina cuando se marca orden como pagada

**Archivo a modificar:**
- `modulos/presupuestos/ordenes_pago.php`

**Línea aproximada:**
- Línea 1084 (antes de `$conn->commit()`)

**Complejidad:**
- ⭐ Baja (código simple, solo actualización de estado)

**Tiempo estimado:**
- 15-30 minutos (incluyendo pruebas)

**Prioridad:**
- 🔴 CRÍTICA (cierra el ciclo completo de nómina)

---

**Última actualización:** Lista de funcionalidades faltantes
**Versión:** 1.0

