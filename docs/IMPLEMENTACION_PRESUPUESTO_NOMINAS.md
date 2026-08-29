# Implementación: Integración de Nóminas con Presupuesto

## ✅ Funcionalidades Implementadas

### 1. **Validación de Presupuesto al Generar Nómina**
- Se valida disponibilidad presupuestaria antes de crear la nómina
- Calcula monto estimado (salarios base + 30% margen)
- Busca presupuesto de partida 401 (Gastos de Personal)
- Muestra advertencia si no hay suficiente presupuesto (no bloquea, pero alerta)

### 2. **Registro en Presupuesto al Confirmar Nómina**
- Al confirmar nómina, se registra como **CAUSADO** en el presupuesto
- Actualiza campos: `causado`, `por_pagar`, `saldo_por_comprometer`
- Impacto en tiempo real en el presupuesto
- Guarda relación: `nominas.presupuesto_id` y `nominas.monto_presupuestado`

### 3. **Visualización de Impacto Presupuestario**
- Módulo nuevo: `modulos/presupuestos/impacto_nominas.php`
- Muestra resumen del presupuesto de gastos de personal
- Lista todas las nóminas confirmadas y su impacto
- Estadísticas: Crédito Vigente, Comprometido, Causado, Disponible

### 4. **Información en Vista de Nómina**
- Se muestra impacto presupuestario cuando la nómina está confirmada
- Información visible para el área de Presupuesto

---

## 📝 Archivos Modificados/Creados

### Modificados:
1. **`includes/util_nomina.php`**
   - ✅ Agregadas funciones: `obtenerCuentaGastosPersonal()`
   - ✅ Agregadas funciones: `buscarPresupuestoGastosPersonal()`
   - ✅ Agregadas funciones: `validarPresupuestoNomina()`
   - ✅ Agregadas funciones: `registrarNominaEnPresupuesto()`
   - ✅ Modificada: `generarNominaMasiva()` - Validación de presupuesto
   - ✅ Modificada: `confirmarNomina()` - Registro en presupuesto

2. **`modulos/nominas/ver_nomina.php`**
   - ✅ Agregada visualización de impacto presupuestario

### Creados:
3. **`modulos/presupuestos/impacto_nominas.php`** - NUEVO
   - Módulo completo de visualización de impacto

4. **`database/scripts/agregar_campos_presupuesto_nominas.sql`** - NUEVO
   - Script para agregar campos necesarios a la tabla `nominas`

---

## 🔧 Pasos para Completar la Implementación

### **PASO 1: Ejecutar Script SQL** (OBLIGATORIO)

Ejecutar el script SQL para agregar campos a la tabla `nominas`:

```sql
-- Ejecutar: database/scripts/agregar_campos_presupuesto_nominas.sql

ALTER TABLE `nominas` 
ADD COLUMN `presupuesto_id` INT(11) DEFAULT NULL AFTER `periodo_id`,
ADD COLUMN `monto_presupuestado` DECIMAL(14,2) DEFAULT NULL AFTER `total_neto`,
ADD COLUMN `asiento_id` INT(11) DEFAULT NULL AFTER `monto_presupuestado`,
ADD KEY `idx_nominas_presupuesto` (`presupuesto_id`),
ADD KEY `idx_nominas_asiento` (`asiento_id`);
```

**Cómo ejecutar:**
1. Abrir phpMyAdmin o cliente MySQL
2. Seleccionar la base de datos `sistema_contable` (o el nombre que uses)
3. Ejecutar el contenido del archivo `database/scripts/agregar_campos_presupuesto_nominas.sql`

---

### **PASO 2: Verificar/Crear Presupuesto de Gastos de Personal** (OBLIGATORIO)

**Requisito:** Debe existir un presupuesto asignado para la partida 401 (Gastos de Personal)

**Cómo verificar:**
1. Ir a módulo de Presupuestos
2. Buscar presupuestos con cuenta que tenga código `401%`
3. Verificar que esté activo para el período contable actual

**Si no existe, crear uno:**
1. Ir a `modulos/presupuestos/gestion_presupuestos.php`
2. Crear nuevo presupuesto:
   - **Cuenta:** Buscar cuenta con código 401 (Gastos de Personal)
   - **Período:** Período contable activo
   - **Tipo Movimiento:** Gasto
   - **Montos:** Asignar presupuesto mensual según necesidades

---

### **PASO 3: Verificar Cuentas Contables** (OPCIONAL pero recomendado)

El sistema busca automáticamente las cuentas, pero es mejor tenerlas configuradas:

**Cuentas necesarias:**
1. **Gasto de Nómina:** Cuenta que contenga "nómina", "nomina" o "sueld" en el nombre
   - Ejemplo: "401.01.01.01 - Sueldos básicos personal fijo"
2. **Sueldos por Pagar:** Cuenta que contenga "sueldos por pagar" o "por pagar"
   - Ejemplo: "210.01.00.00 - Sueldos por Pagar"

**Si no existen:**
- El sistema confirmará la nómina pero no generará asiento contable
- Recomendado crear estas cuentas en el módulo de Contabilidad

---

## 🔍 Flujo de Uso

### **Generar Nómina:**
1. Usuario genera nómina normalmente
2. Sistema valida presupuesto disponible (estimación)
3. Si no hay suficiente, muestra advertencia pero permite continuar
4. Nómina se crea en estado "borrador"

### **Confirmar Nómina:**
1. Usuario confirma nómina desde `ver_nomina.php`
2. Sistema valida presupuesto disponible (monto real)
3. Si NO hay suficiente, **BLOQUEA** la confirmación y muestra error
4. Si hay suficiente:
   - Genera asiento contable
   - Registra como CAUSADO en presupuesto
   - Actualiza disponibilidad en tiempo real
   - Cambia estado a "confirmada"

### **Visualizar Impacto:**
1. Ir a `modulos/presupuestos/impacto_nominas.php`
2. Ver resumen del presupuesto de gastos de personal
3. Ver lista de nóminas confirmadas y su impacto
4. Ver estadísticas: Vigente, Comprometido, Causado, Disponible

---

## 📊 Estructura de Datos

### Tabla `nominas` (Nuevos campos):
```sql
presupuesto_id INT(11)      -- ID del presupuesto de gastos de personal
monto_presupuestado DECIMAL -- Monto registrado como causado
asiento_id INT(11)          -- ID del asiento contable generado
```

### Flujo de Presupuesto:
```
1. Nómina generada → Estado: "borrador" → NO impacta presupuesto
2. Nómina confirmada → Estado: "confirmada" → Impacta como CAUSADO
3. Pago ejecutado → (Futuro) → Impacta como PAGADO
```

---

## ⚠️ Notas Importantes

1. **Validación en Confirmación:** La validación estricta ocurre al **CONFIRMAR**, no al generar
2. **Sin Presupuesto:** Si no existe presupuesto, el sistema mostrará advertencias pero permitirá continuar (depende de la política de la organización)
3. **Período Contable:** Debe haber un período contable ACTIVO para que funcione la validación
4. **Partida 401:** El sistema busca automáticamente cuentas que empiecen con código "401"

---

## 🧪 Pruebas Sugeridas

1. **Generar nómina con presupuesto suficiente:**
   - ✅ Debe generar sin problemas
   - ✅ Al confirmar, debe registrar en presupuesto

2. **Generar nómina sin presupuesto:**
   - ⚠️ Debe mostrar advertencia pero permitir generar
   - ❌ Al confirmar, debe BLOQUEAR con mensaje claro

3. **Generar nómina que excede presupuesto:**
   - ⚠️ Al generar: Muestra advertencia pero permite
   - ❌ Al confirmar: BLOQUEA con mensaje de exceso

4. **Visualizar impacto:**
   - ✅ Verificar que aparece información correcta
   - ✅ Verificar cálculos de disponibilidad

---

## ✅ Estado de Implementación

- ✅ Validación de presupuesto al generar
- ✅ Registro en presupuesto al confirmar
- ✅ Visualización de impacto
- ✅ Actualización en tiempo real
- ✅ Manejo de errores y validaciones

**Próximos pasos sugeridos:**
- Implementar cálculo automático de prestaciones
- Implementar sistema de contratos HP
- Crear generación automática de pagos

---

**Fecha de Implementación:** Basado en requisitos del audio

