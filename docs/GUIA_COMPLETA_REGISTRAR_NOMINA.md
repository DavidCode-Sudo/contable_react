# Guía Completa: Cómo Registrar una Nómina Paso a Paso

## 📋 Índice
1. [Configuración Inicial (Primera Vez)](#configuración-inicial)
2. [Crear Período de Nómina](#crear-período)
3. [Generar Nómina](#generar-nómina)
4. [Enviar a Aprobación Presupuestaria](#enviar-aprobación)
5. [Aprobar desde Presupuesto](#aprobar-presupuesto)
6. [Confirmar Nómina](#confirmar-nómina)
7. [Generar Órdenes de Pago](#generar-órdenes)
8. [Ejecutar Pagos](#ejecutar-pagos)

---

## 🔧 CONFIGURACIÓN INICIAL (Solo Primera Vez)

### **PASO 1: Configurar Empleados**

**Ubicación:** `modulos/rrhh/gestion_empleados.php`

**Datos obligatorios para cada empleado:**

```
┌─────────────────────────────────────────────────────────┐
│ DATOS OBLIGATORIOS DEL EMPLEADO                        │
├─────────────────────────────────────────────────────────┤
│ ✅ Código:              EMP-001 (único)                │
│ ✅ Nombres:             Juan                           │
│ ✅ Apellidos:           Pérez                          │
│ ✅ Identificación:      12345678 (Cédula)              │
│ ✅ Fecha de Ingreso:     2024-01-15                     │
│ ✅ Salario Base:         5,000.00 (Bs.)                │
│ ✅ Estado:              activo                          │
│                                                          │
│ DATOS PARA PAGOS (Obligatorios para generar órdenes):  │
│ ✅ Banco:               Banco de Venezuela             │
│ ✅ Número de Cuenta:    01021234567890123456            │
│ ✅ Tipo de Cuenta:      corriente o ahorro             │
│                                                          │
│ DATOS OPCIONALES:                                       │
│ ⚪ Fecha de Nacimiento:  1985-05-20 (para Banesco)      │
│ ⚪ Sexo:                 M o F (para Banesco)           │
│ ⚪ Departamento:        Administración                  │
│ ⚪ Tipo de Contrato:     fijo/temporal/honorarios       │
└─────────────────────────────────────────────────────────┘
```

**Ejemplo de registro:**
```
Código:           EMP-001
Nombres:          Juan
Apellidos:        Pérez
Identificación:  12345678
Fecha Ingreso:    15/01/2024
Salario Base:     5,000.00 Bs.
Banco:            Banco de Venezuela
Número Cuenta:    01021234567890123456
Tipo Cuenta:      corriente
Estado:           activo
```

---

### **PASO 2: Crear Conceptos de Nómina**

**Ubicación:** `modulos/rrhh/gestion_conceptos.php`

**Conceptos comunes a crear:**

#### **A. Percepciones (Ingresos para el empleado):**

| Código | Nombre | Tipo | Método | Valor | Ejemplo |
|--------|--------|------|--------|-------|---------|
| `SUELDO` | Sueldo Base | percepción | fijo | 0 | Se toma del empleado |
| `BONO_ALIM` | Bono de Alimentación | percepción | fijo | 500.00 | Bs. 500 fijo |
| `PRIMA_ANT` | Prima de Antigüedad | percepción | porcentaje_salario | 5 | 5% del salario |
| `HORAS_EXT` | Horas Extras | percepción | personalizado | 0 | Según cálculo |
| `AEM` | Aporte Empleador Ahorro | percepción | porcentaje_salario | 2 | 2% del salario |

**Ejemplo de creación:**
```
Código:           BONO_ALIM
Nombre:          Bono de Alimentación
Tipo:            percepción
Método:          fijo
Valor:           500.00
Orden:           10
Estado:          activo
```

#### **B. Deducciones (Descuentos del empleado):**

| Código | Nombre | Tipo | Método | Valor | Ejemplo |
|--------|--------|------|--------|-------|---------|
| `IVSS` | Aporte IVSS | deducción | porcentaje_salario | 4 | 4% del salario |
| `FAOV` | Ahorro Habitacional | deducción | porcentaje_salario | 1 | 1% del salario |
| `ISLR` | Impuesto sobre la Renta | deducción | personalizado | 0 | Según tabla |
| `DESC_VAR` | Descuentos Varios | deducción | fijo | 100.00 | Bs. 100 fijo |

**Ejemplo de creación:**
```
Código:           IVSS
Nombre:          Aporte IVSS
Tipo:            deducción
Método:          porcentaje_salario
Valor:           4.00
Orden:           20
Estado:          activo
```

---

### **PASO 3: Asignar Conceptos a Empleados**

**Ubicación:** `modulos/rrhh/gestion_empleado_conceptos.php`

**Para cada empleado, asignar conceptos:**

**Ejemplo: Empleado Juan Pérez (Salario: Bs. 5,000.00)**

| Concepto | Tipo | Método | Parámetro | Cantidad | Cálculo |
|----------|------|--------|-----------|----------|---------|
| Bono Alimentación | percepción | fijo | 500.00 | 1 | 500.00 × 1 = 500.00 |
| Prima Antigüedad | percepción | porcentaje | 5.00 | 1 | (5,000 × 5%) × 1 = 250.00 |
| IVSS | deducción | porcentaje | 4.00 | 1 | (5,000 × 4%) × 1 = 200.00 |
| Ahorro Habitacional | deducción | porcentaje | 1.00 | 1 | (5,000 × 1%) × 1 = 50.00 |

**Resultado del cálculo:**
```
Salario Base:       5,000.00
+ Percepciones:       750.00  (500 + 250)
- Deducciones:        250.00  (200 + 50)
= Neto a Pagar:     5,500.00
```

**Cómo asignar:**
1. Seleccionar empleado
2. Hacer clic en "Agregar Concepto"
3. Seleccionar concepto de la lista
4. Configurar método, parámetro y cantidad
5. Guardar

---

## 📅 PASO 4: Crear Período de Nómina

**Ubicación:** `modulos/nominas/gestion_periodos.php`

**Datos a completar:**

```
┌─────────────────────────────────────────────────────────┐
│ CREAR PERÍODO DE NÓMINA                                │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ Código:            ENE-2025                            │
│ Descripción:       Nómina de Enero 2025                 │
│ Fecha Inicio:      01/01/2025                           │
│ Fecha Fin:         15/01/2025                           │
│ Periodicidad:      quincenal (o semanal/mensual)        │
│ Estado:            abierto                               │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

**Ejemplo:**
- **Código:** `ENE-2025-Q1` (Enero 2025 - Primera Quincena)
- **Descripción:** Nómina Primera Quincena de Enero 2025
- **Fecha Inicio:** `2025-01-01`
- **Fecha Fin:** `2025-01-15`
- **Periodicidad:** `quincenal`
- **Estado:** `abierto` (debe estar abierto para generar nóminas)

**Pasos:**
1. Ir a `modulos/nominas/gestion_periodos.php`
2. Hacer clic en "Nuevo Período"
3. Completar todos los campos
4. Guardar

---

## 💰 PASO 5: Generar Nómina

**Ubicación:** `modulos/nominas/gestion_nominas.php`

### **Proceso Paso a Paso:**

#### **1. Abrir Modal de Generación**

```
1. Ir a: modulos/nominas/gestion_nominas.php
2. Hacer clic en botón: "Generar Nómina" (azul, con ícono de rayo)
3. Se abre un modal
```

#### **2. Seleccionar Período**

```
En el modal, campo "Período":
┌─────────────────────────────────────┐
│ Período: [Dropdown]                 │
│   - Seleccionar: ENE-2025-Q1        │
│   - O el período que corresponda    │
└─────────────────────────────────────┘
```

#### **3. Seleccionar Empleados**

```
Campo "Empleados (opcional)":
┌─────────────────────────────────────┐
│ Empleados: [Multi-select]           │
│   ☑ EMP-001 - Juan Pérez            │
│   ☑ EMP-002 - María González         │
│   ☑ EMP-003 - Carlos Rodríguez       │
│   ☐ EMP-004 - Ana Martínez           │
│                                     │
│ Nota: Si no seleccionas ninguno,     │
│       se generará para TODOS los     │
│       empleados activos              │
└─────────────────────────────────────┘
```

**Opciones:**
- **Seleccionar empleados específicos:** Solo genera nómina para los seleccionados
- **No seleccionar ninguno:** Genera para todos los empleados activos

#### **4. Generar**

```
Hacer clic en botón: "Generar"
```

**Lo que hace el sistema automáticamente:**

```
1. ✅ Crea registro de nómina (ej: NOM-2025-00001)
2. ✅ Para cada empleado seleccionado:
   - Obtiene salario base
   - Obtiene conceptos asignados
   - Calcula percepciones:
     * Fijo: valor × cantidad
     * Porcentaje: (salario × porcentaje/100) × cantidad
     * Personalizado: valor directo
   - Calcula deducciones:
     * Mismo proceso
   - Calcula neto: salario + percepciones - deducciones
   - Genera número de recibo (ej: REC-2025-000001)
   - Genera recibo HTML
   - Guarda en nominas_empleados
3. ✅ Actualiza totales de la nómina
4. ✅ Estado: borrador
```

**Resultado:**
```
Nómina creada: NOM-2025-00001
Estado: borrador
Total Bruto: Bs. 25,000.00
Total Deducciones: Bs. 2,500.00
Total Neto: Bs. 22,500.00
```

---

## 📤 PASO 6: Enviar a Aprobación Presupuestaria

**Ubicación:** `modulos/nominas/gestion_nominas.php` o `ver_nomina.php`

### **Proceso:**

```
1. Ver la nómina en estado "borrador"
2. Hacer clic en botón: "Enviar a Presupuesto" (ícono de avión)
3. Confirmar en el diálogo
```

**Lo que pasa:**
```
Estado cambia: borrador → pendiente_validacion_presupuesto
Aprobación:    pendiente
Notificación:  Alerta amarilla visible en el detalle
```

**Pantalla muestra:**
```
⚠️ Pendiente de Aprobación Presupuestaria

Esta nómina está esperando aprobación del área de Presupuesto.
```

---

## ✅ PASO 7: Aprobar desde Presupuesto

**Usuario:** Personal del área de Presupuesto (con permiso `nominas:aprobar_presupuesto`)

**Ubicación:** `modulos/nominas/gestion_nominas.php` o `ver_nomina.php`

### **Proceso:**

#### **1. Ver Nómina Pendiente**

```
La nómina aparece con estado: "Pendiente Validacion Presupuesto"
Badge de color: Naranja/amarillo
Botón visible: "Aprobar desde Presupuesto" (azul)
```

#### **2. Abrir Modal de Aprobación**

```
Hacer clic en: "Aprobar desde Presupuesto"
Se abre modal con información:
```

**Información mostrada:**
```
┌─────────────────────────────────────────────────────────┐
│ NÓMINA: NOM-2025-00001                                   │
│ MONTO TOTAL: Bs. 22,500.00                               │
│                                                          │
│ El sistema validará automáticamente:                    │
│ ✅ Disponibilidad mensual                                │
│ ✅ Disponibilidad anual                                  │
│                                                          │
│ Comentarios (opcional):                                 │
│ [_________________________________]                      │
└─────────────────────────────────────────────────────────┘
```

#### **3. Validación Automática**

**El sistema valida automáticamente:**

```
1. Busca presupuesto de partida 401 (Gastos de Personal)
2. Calcula disponibilidad mensual:
   disponible_mes = monto_mes - comprometido_mes - pagado_mes
3. Calcula disponibilidad anual:
   disponible_anual = credito_vigente - comprometido - causado - pagado
4. Compara con monto de nómina:
   Si disponible >= monto_nomina → ✅ PERMITE APROBAR
   Si disponible < monto_nomina → ❌ BLOQUEA CON ERROR
```

**Ejemplo de validación:**
```
Monto Nómina:        Bs. 22,500.00
Disponible Mensual:  Bs. 27,000.00  ✅
Disponible Anual:    Bs. 32,000.00  ✅
Resultado:           ✅ APROBADO
```

**Si no hay suficiente:**
```
Monto Nómina:        Bs. 30,000.00
Disponible Mensual:  Bs. 25,000.00  ❌
Mensaje:             "SALDO INSUFICIENTE EN EL MES. 
                      Disponible: Bs. 25,000.00, 
                      Requerido: Bs. 30,000.00"
Resultado:           ❌ BLOQUEADO - NO SE PUEDE APROBAR
```

#### **4. Aprobar o Rechazar**

**Opción A: Aprobar**
```
1. Completar comentarios (opcional)
2. Hacer clic en: "Aprobar desde Presupuesto"
3. Confirmar
4. Sistema valida presupuesto
5. Si hay suficiente → ✅ APRUEBA
   Estado: aprobada_presupuesto
6. Si no hay suficiente → ❌ BLOQUEA
   Muestra error y no aprueba
```

**Opción B: Rechazar**
```
1. Hacer clic en: "Rechazar"
2. Ingresar motivo (OBLIGATORIO)
3. Confirmar
4. Estado vuelve a: borrador
5. RRHH puede ver motivo del rechazo
```

**Información guardada al aprobar:**
```
✅ Usuario que aprobó
✅ Fecha y hora de aprobación
✅ Comentarios
✅ Snapshot de presupuesto (JSON con todos los datos)
✅ Validaciones mensual y anual
```

---

## ✅ PASO 8: Confirmar Nómina (RRHH)

**Ubicación:** `modulos/nominas/gestion_nominas.php` o `ver_nomina.php`

### **Requisito:**
```
La nómina DEBE estar en estado: aprobada_presupuesto
Si no está aprobada, el sistema bloquea la confirmación
```

### **Proceso:**

```
1. Ver nómina con estado: "Aprobada Presupuesto"
2. Botón visible: "Confirmar Nómina" (verde)
3. Hacer clic en confirmar
4. Confirmar en diálogo
```

**Lo que hace el sistema automáticamente:**

```
1. ✅ Valida que está aprobada por Presupuesto
2. ✅ Busca cuentas contables:
   - Gasto de Nómina (401)
   - Sueldos por Pagar (210)
3. ✅ Genera asiento contable:
   DEBE:   Gasto de Nómina (401)         → Bs. 22,500.00
   HABER:  Sueldos por Pagar (210)       → Bs. 22,500.00
4. ✅ Registra en presupuesto como CAUSADO:
   presupuesto.causado += 22,500.00
   presupuesto.disponibilidad -= 22,500.00
5. ✅ Estado: confirmada
```

**Resultado:**
```
✅ Nómina confirmada
✅ Asiento contable generado (ID: ASI-2025-000123)
✅ Presupuesto actualizado
✅ Estado: confirmada
```

---

## 💳 PASO 9: Generar Órdenes de Pago

**Ubicación:** `modulos/nominas/gestion_nominas.php`

### **Requisito:**
```
La nómina DEBE estar en estado: confirmada
```

### **Proceso:**

```
1. Ver nómina confirmada
2. Botón visible: "Generar Órdenes de Pago" (ícono de factura)
3. Hacer clic y confirmar
```

**Lo que hace el sistema automáticamente:**

```
Para cada empleado en la nómina:

1. ✅ Crea orden de pago:
   - Número: OP-2025-00001, OP-2025-00002, etc.
   - Beneficiario: Nombre completo del empleado
   - Monto: total_neto del recibo
   - Banco: banco del empleado
   - Cuenta: numero_cuenta del empleado
   - Concepto: "Pago de nómina ENE-2025-Q1 - Juan Pérez"
   - Presupuesto: el mismo de la nómina
   - Estado: emitida

2. ✅ Vincula con recibo de nómina:
   ordenes_pago.nomina_empleado_id = nominas_empleados.id

3. ✅ Actualiza presupuesto:
   presupuesto.comprometido += total_neto
```

**Resultado:**
```
✅ 5 órdenes de pago generadas (una por empleado)
✅ Total: Bs. 22,500.00
✅ Estado: emitida
✅ Presupuesto: comprometido += 22,500.00
```

---

## 💰 PASO 10: Ejecutar Pagos

**Ubicación:** `modulos/presupuestos/ordenes_pago.php`

### **Proceso:**

```
1. Ver órdenes de pago generadas
2. Para cada orden:
   - Verificar que el banco haya ejecutado la transferencia
   - Obtener referencia bancaria
   - Marcar como "pagada"
   - Ingresar fecha de pago
   - Ingresar referencia bancaria
```

**Lo que hace el sistema automáticamente:**

```
1. ✅ Actualiza estado de orden: emitida → pagada
2. ✅ Actualiza estado de recibo: pendiente → pagado
3. ✅ Actualiza presupuesto:
   presupuesto.pagado += monto_pagado
4. ✅ Genera asiento contable del pago:
   DEBE:   Sueldos por Pagar (210)       → Bs. 22,500.00
   HABER:  Banco (1.1.1.x)              → Bs. 22,500.00
```

**Resultado Final:**
```
✅ Órdenes marcadas como pagadas
✅ Recibos marcados como pagados
✅ Presupuesto actualizado: pagado += 22,500.00
✅ Asientos contables generados
✅ Proceso completo
```

---

## 📊 Resumen Visual del Flujo Completo

```
┌─────────────────────────────────────────────────────────────┐
│ FLUJO COMPLETO DE NÓMINA                                    │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ CONFIGURACIÓN (Solo Primera Vez)                           │
│ ─────────────────────────────────────────────────────────── │
│ 1. Crear Empleados                                         │
│    • Datos personales                                      │
│    • Salario base                                          │
│    • Datos bancarios (obligatorio)                         │
│                                                              │
│ 2. Crear Conceptos de Nómina                               │
│    • Percepciones (bono, prima, etc.)                      │
│    • Deducciones (IVSS, ahorro, etc.)                      │
│                                                              │
│ 3. Asignar Conceptos a Empleados                           │
│    • Configurar método de cálculo                          │
│    • Configurar valores/porcentajes                        │
│                                                              │
│ PROCESO MENSUAL/QUINCENAL                                   │
│ ─────────────────────────────────────────────────────────── │
│ 4. Crear Período de Nómina                                 │
│    • Código: ENE-2025-Q1                                   │
│    • Fechas: 01/01/2025 a 15/01/2025                       │
│    • Estado: abierto                                        │
│                                                              │
│ 5. Generar Nómina                                          │
│    • Seleccionar período                                   │
│    • Seleccionar empleados (o todos)                       │
│    • Sistema calcula automáticamente                       │
│    • Estado: borrador                                       │
│                                                              │
│ 6. Enviar a Aprobación Presupuestaria                      │
│    • Botón: "Enviar a Presupuesto"                         │
│    • Estado: pendiente_validacion_presupuesto               │
│                                                              │
│ 7. Presupuesto Aprueba                                     │
│    • Validación automática de presupuesto                  │
│    • Si hay suficiente → APRUEBA                           │
│    • Si no hay suficiente → RECHAZA o BLOQUEA               │
│    • Estado: aprobada_presupuesto                           │
│                                                              │
│ 8. RRHH Confirma Nómina                                    │
│    • Solo si está aprobada                                 │
│    • Genera asiento contable                               │
│    • Registra como causado en presupuesto                  │
│    • Estado: confirmada                                     │
│                                                              │
│ 9. Generar Órdenes de Pago                                 │
│    • Una orden por empleado                                │
│    • Datos bancarios automáticos                           │
│    • Estado: emitida                                        │
│                                                              │
│ 10. Ejecutar Pagos                                         │
│     • Marcar órdenes como pagadas                          │
│     • Registrar referencia bancaria                        │
│     • Presupuesto: pagado += monto                        │
│     • Estado: pagada                                        │
└─────────────────────────────────────────────────────────────┘
```

---

## 📝 Ejemplo Completo: Nómina de Enero 2025

### **Configuración Inicial (Ya hecha):**

```
Empleado 1: Juan Pérez
- Código: EMP-001
- Salario: Bs. 5,000.00
- Banco: Banco de Venezuela
- Cuenta: 01021234567890123456
- Conceptos:
  + Bono Alimentación: Bs. 500.00 (fijo)
  + Prima Antigüedad: 5% del salario
  - IVSS: 4% del salario
  - Ahorro Habitacional: 1% del salario

Empleado 2: María González
- Código: EMP-002
- Salario: Bs. 6,000.00
- Banco: Banesco
- Cuenta: 01341234567890123456
- Conceptos: (similares)
```

### **Paso 1: Crear Período**

```
Código: ENE-2025-Q1
Descripción: Primera Quincena de Enero 2025
Fecha Inicio: 01/01/2025
Fecha Fin: 15/01/2025
Periodicidad: quincenal
Estado: abierto
```

### **Paso 2: Generar Nómina**

```
Modal "Generar Nómina":
┌─────────────────────────────────────┐
│ Período: [ENE-2025-Q1 ▼]            │
│                                     │
│ Empleados:                          │
│ ☑ EMP-001 - Juan Pérez             │
│ ☑ EMP-002 - María González         │
│ ☐ EMP-003 - Carlos Rodríguez       │
│                                     │
│ [Cancelar] [Generar]                │
└─────────────────────────────────────┘
```

**Resultado del cálculo:**

```
Empleado: Juan Pérez
─────────────────────────────────────
Salario Base:         5,000.00
+ Bono Alimentación:     500.00
+ Prima Antigüedad:      250.00
- IVSS:                 -200.00
- Ahorro Habitacional:   -50.00
─────────────────────────────────────
Neto a Pagar:          5,500.00

Empleado: María González
─────────────────────────────────────
Salario Base:         6,000.00
+ Bono Alimentación:     500.00
+ Prima Antigüedad:      300.00
- IVSS:                 -240.00
- Ahorro Habitacional:   -60.00
─────────────────────────────────────
Neto a Pagar:          6,500.00

TOTALES NÓMINA:
─────────────────────────────────────
Total Bruto:          12,000.00
Total Deducciones:       550.00
Total Neto:           11,500.00
```

**Nómina creada:**
```
Número: NOM-2025-00001
Estado: borrador
Fecha: 2025-01-15
```

### **Paso 3: Enviar a Aprobación**

```
Botón: "Enviar a Presupuesto" (ícono avión)
Confirmar: "¿Enviar esta nómina a aprobación presupuestaria?"
```

**Estado cambia:**
```
Estado: pendiente_validacion_presupuesto
Alerta: ⚠️ Pendiente de Aprobación Presupuestaria
```

### **Paso 4: Presupuesto Aprueba**

```
Usuario de Presupuesto:
1. Ve nómina pendiente
2. Hace clic: "Aprobar desde Presupuesto"
3. Modal muestra:
   - Nómina: NOM-2025-00001
   - Monto: Bs. 11,500.00
   - Validación automática:
     * Disponible mensual: Bs. 27,000.00 ✅
     * Disponible anual: Bs. 32,000.00 ✅
4. Ingresa comentario: "Aprobado - Presupuesto disponible"
5. Hace clic: "Aprobar desde Presupuesto"
```

**Resultado:**
```
✅ Nómina aprobada
Estado: aprobada_presupuesto
Aprobada por: María Rodríguez (Presupuesto)
Fecha: 15/01/2025 14:30
```

### **Paso 5: RRHH Confirma**

```
Usuario de RRHH:
1. Ve nómina aprobada
2. Hace clic: "Confirmar Nómina"
3. Confirmar en diálogo
```

**Resultado:**
```
✅ Nómina confirmada
✅ Asiento contable generado: ASI-2025-000123
✅ Presupuesto actualizado:
   - causado += 11,500.00
   - disponibilidad -= 11,500.00
Estado: confirmada
```

### **Paso 6: Generar Órdenes**

```
Botón: "Generar Órdenes de Pago"
Confirmar: "¿Generar órdenes para NOM-2025-00001?"
```

**Órdenes generadas:**
```
OP-2025-00001 → Juan Pérez → Bs. 5,500.00 → Banco de Venezuela
OP-2025-00002 → María González → Bs. 6,500.00 → Banesco
```

### **Paso 7: Ejecutar Pagos**

```
En ordenes_pago.php:
1. Ver orden OP-2025-00001
2. Marcar como "pagada"
3. Ingresar:
   - Fecha de pago: 16/01/2025
   - Referencia bancaria: TRANS-2025-001234
4. Guardar
```

**Resultado:**
```
✅ Orden marcada como pagada
✅ Recibo marcado como pagado
✅ Presupuesto: pagado += 5,500.00
✅ Asiento contable del pago generado
```

---

## 🎯 Checklist de Registro de Nómina

### **Antes de Generar Nómina:**

- [ ] ✅ Empleados creados con datos completos
- [ ] ✅ Conceptos de nómina creados
- [ ] ✅ Conceptos asignados a empleados
- [ ] ✅ Período de nómina creado y abierto
- [ ] ✅ Presupuesto de partida 401 configurado

### **Al Generar Nómina:**

- [ ] ✅ Seleccionar período correcto
- [ ] ✅ Seleccionar empleados (o dejar vacío para todos)
- [ ] ✅ Verificar que los cálculos sean correctos
- [ ] ✅ Revisar totales antes de enviar

### **Al Enviar a Aprobación:**

- [ ] ✅ Verificar que la nómina esté en estado "borrador"
- [ ] ✅ Revisar que todos los empleados tengan datos bancarios
- [ ] ✅ Confirmar envío

### **Al Aprobar desde Presupuesto:**

- [ ] ✅ Verificar disponibilidad presupuestaria
- [ ] ✅ Revisar que el monto sea correcto
- [ ] ✅ Ingresar comentarios si es necesario
- [ ] ✅ Aprobar o rechazar según corresponda

### **Al Confirmar:**

- [ ] ✅ Verificar que está aprobada por Presupuesto
- [ ] ✅ Confirmar que se generó el asiento contable
- [ ] ✅ Verificar que se registró en presupuesto

---

## 💡 Consejos y Mejores Prácticas

### **1. Verificar Datos Antes de Generar**

```
✅ Verificar que todos los empleados tengan:
   - Salario base > 0
   - Conceptos asignados
   - Datos bancarios completos
```

### **2. Revisar Cálculos**

```
✅ Después de generar, revisar:
   - Totales por empleado
   - Totales generales
   - Que los cálculos sean correctos
```

### **3. Validar Presupuesto Antes de Enviar**

```
✅ Verificar disponibilidad presupuestaria:
   - Disponible mensual
   - Disponible anual
   - Si hay suficiente, enviar
   - Si no hay suficiente, esperar o solicitar modificación
```

### **4. Documentar Aprobaciones**

```
✅ Siempre ingresar comentarios al aprobar:
   - Razón de la aprobación
   - Observaciones especiales
   - Referencias si aplica
```

### **5. Seguir el Flujo en Orden**

```
✅ No saltarse pasos:
   1. Generar → 2. Enviar → 3. Aprobar → 4. Confirmar → 5. Pagar
```

---

## ❓ Preguntas Frecuentes

### **¿Puedo generar nómina sin tener conceptos asignados?**

❌ **No recomendado.** El sistema calculará, pero si un empleado no tiene conceptos, solo se pagará el salario base.

**Solución:** Asignar conceptos antes de generar.

---

### **¿Qué pasa si olvido asignar datos bancarios a un empleado?**

⚠️ **No podrás generar órdenes de pago automáticamente.**

**Solución:** Completar datos bancarios antes de generar órdenes.

---

### **¿Puedo confirmar nómina sin aprobación presupuestaria?**

❌ **NO.** El sistema bloquea la confirmación si no está aprobada.

**Mensaje:** "La nómina debe estar aprobada por Presupuesto antes de confirmar"

---

### **¿Qué pasa si Presupuesto rechaza la nómina?**

✅ **La nómina vuelve a estado "borrador".**

**RRHH puede:**
- Ver el motivo del rechazo
- Corregir lo necesario
- Volver a enviar a aprobación

---

### **¿Puedo editar una nómina después de generarla?**

⚠️ **Solo si está en estado "borrador".**

Una vez enviada a aprobación, no se puede editar directamente.

---

### **¿Cómo sé si el presupuesto es suficiente?**

✅ **El sistema valida automáticamente al aprobar.**

Si no hay suficiente, muestra:
```
"SALDO INSUFICIENTE EN EL MES. 
Disponible: Bs. 25,000.00, 
Requerido: Bs. 30,000.00"
```

---

## 📚 Documentación Relacionada

- `docs/EJEMPLOS_GESTION_NOMINA.md` - Ejemplos prácticos completos
- `docs/GUIA_RAPIDA_NOMINA.md` - Guía rápida de referencia
- `docs/IMPLEMENTACION_APROBACION_NOMINAS.md` - Detalles técnicos

---

**Última actualización:** Guía completa de registro
**Versión:** 1.0

