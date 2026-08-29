# Flujo Completo de Pago de Nóminas

## 📋 Resumen del Flujo

El proceso de pago de nóminas en el sistema contable debe seguir este flujo completo:

```
1. Configuración → 2. Generación → 3. Confirmación → 4. Generación de Órdenes → 5. Pago → 6. Registro Contable
```

---

## 🔄 Flujo Detallado Paso a Paso

### **FASE 1: Configuración (Ya Implementado ✅)**

#### 1.1. Configurar Período de Nómina
- **Ubicación:** `modulos/nominas/gestion_periodos.php`
- Crear período de nómina (ej: "Enero 2025")
- Definir fechas de inicio y fin

#### 1.2. Configurar Empleados y Conceptos
- **Ubicación:** `modulos/rrhh/gestion_empleados.php`
- Registrar empleados activos
- Asignar salario base
- **Importante:** Registrar datos bancarios del empleado:
  - Banco
  - Número de cuenta
  - Tipo de cuenta (corriente/ahorro)
  - Cédula/RIF

#### 1.3. Configurar Conceptos de Nómina
- **Ubicación:** `modulos/rrhh/gestion_conceptos.php`
- Definir percepciones (sueldo, bonos, etc.)
- Definir deducciones (ISLR, IVSS, etc.)

#### 1.4. Asignar Conceptos a Empleados
- **Ubicación:** `modulos/rrhh/gestion_empleado_conceptos.php`
- Vincular conceptos específicos a cada empleado

---

### **FASE 2: Generación de Nómina (Ya Implementado ✅)**

#### 2.1. Generar Nómina Masiva
- **Ubicación:** `modulos/nominas/gestion_nominas.php`
- **Función:** `generarNominaMasiva()` en `includes/util_nomina.php`
- Seleccionar período de nómina
- Seleccionar empleados (o todos los activos)
- El sistema:
  - Calcula totales por empleado
  - Genera recibos individuales
  - Valida presupuesto (estimado) ⚠️
  - Crea registro en tabla `nominas` con estado `'generada'`

#### 2.2. Revisión de Nómina Generada
- Ver detalle de cada empleado
- Verificar cálculos
- Imprimir recibos de prueba

---

### **FASE 3: Confirmación de Nómina (Ya Implementado ✅)**

#### 3.1. Confirmar Nómina
- **Ubicación:** `modulos/nominas/gestion_nominas.php`
- **Función:** `confirmarNomina()` en `includes/util_nomina.php`
- El sistema:
  - Valida presupuesto estricto (bloquea si no hay suficiente) ⚠️
  - Registra como "causado" en presupuesto (partida 401)
  - Actualiza campo `presupuesto_id` en `nominas`
  - Cambia estado a `'confirmada'`
  - Estado de recibos: `'pendiente'` (listos para pagar)

**⚠️ IMPORTANTE:** Al confirmar, se registra como **CAUSADO** en el presupuesto, pero aún NO está pagado.

---

### **FASE 4: Generación de Órdenes de Pago (⚠️ POR IMPLEMENTAR)**

#### 4.1. Generar Órdenes de Pago desde Nómina Confirmada
- **Nueva Funcionalidad Requerida**
- **Ubicación:** `modulos/nominas/gestion_nominas.php`
- **Proceso:**
  1. Seleccionar nómina confirmada
  2. Botón: "Generar Órdenes de Pago"
  3. El sistema genera:
     - Una orden de pago por empleado (o una orden masiva)
     - Usa datos bancarios del empleado
     - Monto = `total_neto` del recibo
     - Beneficiario = nombre completo del empleado
     - Concepto = "Pago de nómina [período] - [empleado]"
     - Presupuesto = el mismo vinculado a la nómina
  4. Las órdenes se crean con estado `'emitida'`
  5. Se vincula `nomina_empleado_id` en las órdenes (nuevo campo)

#### 4.2. Exportar Constancia Bancaria
- **Ya existe:** `modulos/presupuestos/generar_constancia_bancaria.php`
- Generar PDF por cada orden para llevar al banco
- El banco ejecuta las transferencias

---

### **FASE 5: Ejecución de Pago (⚠️ PARCIALMENTE IMPLEMENTADO)**

#### 5.1. Registrar Pagos Ejecutados
- **Ubicación:** `modulos/presupuestos/ordenes_pago.php`
- Cuando el banco confirma las transferencias:
  1. Marcar orden como `'pagada'`
  2. Ingresar referencia bancaria
  3. Fecha de pago efectivo
  4. El sistema debe:
     - Actualizar estado de `nominas_empleados` de `'pendiente'` a `'pagado'`
     - Registrar pago presupuestario (actualizar campo `pagado` en `presupuestos`)
     - Generar asiento contable automático

#### 5.2. Generar Asiento Contable del Pago
- **Ubicación:** Automático al marcar como pagada
- **Asiento:**
  ```
  DEBE:   Gasto de Personal (401)         → Monto total
  HABER:  Banco/Caja (activo)              → Monto total
  ```
- Actualizar `total_pagado` en presupuesto

---

### **FASE 6: Registro Contable Final (Ya Implementado ✅)**

#### 6.1. Asiento Contable al Confirmar Nómina
- **Ya funciona:** Al confirmar se genera asiento de causación
- **Asiento:**
  ```
  DEBE:   Gasto de Personal (401)         → Monto total
  HABER:  Por Pagar - Personal (pasivo)   → Monto total
  ```

#### 6.2. Asiento Contable al Pagar
- **Se genera automáticamente:** Al marcar orden como pagada
- **Asiento:**
  ```
  DEBE:   Por Pagar - Personal (pasivo)   → Monto pagado
  HABER:  Banco/Caja (activo)              → Monto pagado
  ```

---

## 🗄️ Estructura de Datos Requerida

### Tabla `nominas`
```sql
- id
- numero
- periodo_id
- estado: 'generada' | 'confirmada' | 'pagada' | 'anulada'
- presupuesto_id  ✅ (ya existe)
- monto_presupuestado  ✅ (ya existe)
```

### Tabla `nominas_empleados`
```sql
- id
- nomina_id
- empleado_id
- estado: 'pendiente' | 'pagado' | 'anulado'  ✅ (ya existe)
- total_neto
```

### Tabla `ordenes_pago`
```sql
- id
- numero_orden
- presupuesto_id
- beneficiario
- monto
- estado: 'emitida' | 'pagada' | 'anulada'
- nomina_empleado_id  ⚠️ (NUEVO - vincular con recibo de nómina)
- banco_beneficiario
- numero_cuenta_beneficiario
- tipo_cuenta_beneficiario
```

### Tabla `empleados`
```sql
- id
- nombres
- apellidos
- banco  ✅ (ya existe)
- numero_cuenta  ✅ (ya existe)
- tipo_cuenta  ✅ (ya existe)
- cedula  ✅ (ya existe)
```

---

## 🔧 Funcionalidades Requeridas

### ✅ Ya Implementado:
1. Generación de nómina masiva
2. Confirmación de nómina con validación presupuestaria
3. Registro como causado en presupuesto
4. Visualización de impacto presupuestario
5. Sistema de órdenes de pago (para proveedores)

### ⚠️ Por Implementar:

#### 1. Generar Órdenes de Pago desde Nómina
- **Archivo:** `modulos/nominas/generar_ordenes_pago.php` (nuevo)
- **Función:** `generarOrdenesPagoDesdeNomina($nomina_id)`
- Genera una orden por cada empleado en la nómina
- Usa datos bancarios del empleado automáticamente

#### 2. Vincular Órdenes con Recibos de Nómina
- **Cambio:** Agregar campo `nomina_empleado_id` en `ordenes_pago`
- Permite rastrear qué recibo de nómina fue pagado con qué orden

#### 3. Actualizar Estado al Pagar
- **Modificar:** Función de marcar orden como pagada
- Cuando se marca orden como pagada:
  - Actualizar `nominas_empleados.estado = 'pagado'`
  - Actualizar `presupuestos.pagado` (sumar monto)
  - Verificar si toda la nómina está pagada para cambiar estado general

#### 4. Visualización de Órdenes Generadas
- **Modificar:** `modulos/nominas/ver_nomina.php`
- Mostrar listado de órdenes de pago generadas desde esta nómina
- Enlaces a constancias bancarias
- Estado de cada orden

---

## 📊 Flujo de Estados

```
NÓMINA:
generada → confirmada → (parcialmente pagada) → pagada

RECIBO DE EMPLEADO:
pendiente → pagado

ÓRDEN DE PAGO:
emitida → pagada

PRESUPUESTO:
disponible → comprometido → causado → pagado
```

---

## 🎯 Próximos Pasos de Implementación

1. **Agregar campo `nomina_empleado_id` a tabla `ordenes_pago`**
2. **Crear función `generarOrdenesPagoDesdeNomina()`**
3. **Agregar botón "Generar Órdenes de Pago" en `gestion_nominas.php`**
4. **Modificar función de pago para actualizar estado de recibos**
5. **Crear vista de órdenes generadas desde nómina**

---

## 📝 Notas Importantes

- Las nóminas se registran como **causadas** al confirmar, no al pagar
- El presupuesto tiene dos campos: `causado` (comprometido) y `pagado` (ejecutado)
- Una nómina puede generar múltiples órdenes de pago (una por empleado)
- Las órdenes de pago pueden pagarse parcialmente o en diferentes fechas
- El sistema debe permitir pagos parciales de nóminas

