# Guía Rápida: Gestión de Nómina y Verificación por Partidas

## 🚀 Flujo Rápido en 5 Pasos

### 1️⃣ **Generar Nómina**
```
📍 URL: /modulos/nominas/gestion_nominas.php
📋 Acción: Botón "Generar Nómina"
✅ Resultado: Nómina en estado "borrador"
```

### 2️⃣ **Verificar Presupuesto** (Automático al confirmar)
```
📊 El sistema verifica automáticamente:
   - Busca presupuesto de partida 401
   - Calcula: disponible = vigente - comprometido - causado - pagado
   - Si no hay suficiente → ERROR
   - Si hay suficiente → CONTINÚA
```

### 3️⃣ **Confirmar Nómina**
```
📍 URL: /modulos/nominas/gestion_nominas.php?accion=confirmar&id=X
✅ Acciones automáticas:
   - Valida presupuesto
   - Genera asiento contable
   - Registra como CAUSADO en presupuesto
   - Estado: "confirmada"
```

### 4️⃣ **Generar Órdenes de Pago**
```
📍 URL: /modulos/nominas/gestion_nominas.php
📋 Acción: Botón "Generar Órdenes de Pago" (ícono factura)
✅ Resultado:
   - Una orden por cada empleado
   - Datos bancarios automáticos
   - Presupuesto: comprometido += total
```

### 5️⃣ **Pagar Órdenes**
```
📍 URL: /modulos/presupuestos/ordenes_pago.php
📋 Acción: Marcar como "pagada"
✅ Resultado:
   - Presupuesto: pagado += monto
   - Recibos: estado = "pagado"
```

---

## 📊 Verificación de Presupuesto por Partidas

### Consulta SQL Rápida

```sql
-- Ver disponibilidad de partida 401
SELECT 
    c.codigo AS partida,
    (pr.credito_vigente - pr.comprometido - pr.causado - pr.pagado) AS disponible
FROM presupuestos pr
INNER JOIN cuentas c ON pr.cuenta_id = c.id
WHERE c.codigo LIKE '401%'
  AND pr.periodo_id = (SELECT id FROM periodos_contables WHERE estado = 'abierto');
```

---

## ✅ Checklist de Verificación

### ¿Tiene todo implementado?

- [x] ✅ Generación de nóminas masivas
- [x] ✅ Validación de presupuesto por partidas (401)
- [x] ✅ Confirmación con registro presupuestario
- [x] ✅ Generación automática de órdenes de pago
- [x] ✅ Integración con presupuesto en tiempo real
- [x] ✅ Visualización de impacto presupuestario

### **RESULTADO: ✅ TODO ESTÁ IMPLEMENTADO**

---

## 📁 Archivos Clave

| Función | Archivo |
|---------|---------|
| Generar nómina | `includes/util_nomina.php` → `generarNominaMasiva()` |
| Validar presupuesto | `includes/util_nomina.php` → `validarPresupuestoNomina()` |
| Confirmar nómina | `includes/util_nomina.php` → `confirmarNomina()` |
| Generar órdenes | `includes/util_nomina.php` → `generarOrdenesPagoDesdeNomina()` |
| Buscar presupuesto | `includes/util_nomina.php` → `buscarPresupuestoGastosPersonal()` |

---

## 🎯 Ejemplo Práctico Completo

### Escenario: Pagar Nómina de Enero 2025

```
1. Generar Nómina
   → Total: Bs. 25,000.00
   → Estado: borrador

2. Verificar Presupuesto
   → Disponible: Bs. 27,000.00
   → ✅ HAY SUFICIENTE

3. Confirmar Nómina
   → Asiento contable generado
   → Presupuesto: causado += 25,000.00
   → Estado: confirmada

4. Generar Órdenes
   → 5 órdenes creadas (una por empleado)
   → Presupuesto: comprometido += 25,000.00

5. Pagar Órdenes
   → Órdenes marcadas como pagadas
   → Presupuesto: pagado += 25,000.00
   → Recibos: estado = pagado
```

---

## 📞 Soporte

Para más detalles, consultar:
- `docs/EJEMPLOS_GESTION_NOMINA.md` - Ejemplos completos
- `docs/FLUJO_PAGO_NOMINAS.md` - Flujo detallado
- `docs/REQUISITOS_NOMINA_COMPLETOS.md` - Requisitos del sistema

---

**Versión:** 1.0 | **Fecha:** 2025

