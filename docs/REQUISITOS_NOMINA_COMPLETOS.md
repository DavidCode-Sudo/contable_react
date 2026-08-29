# Requisitos Completos del Módulo de Nóminas

## 📋 Análisis de Requisitos vs Implementación Actual

### ✅ **LO QUE YA ESTÁ IMPLEMENTADO**

#### 1. Base de Datos de Empleados ✅
**Estado:** COMPLETO

La tabla `empleados` tiene todos los campos requeridos:
```sql
✅ identificacion, codigo, nombres, apellidos
✅ fecha_ingreso, direccion, departamento_id
✅ tipo_contrato (fijo/temporal/honorarios)
✅ salario_base
✅ periodo_pago (semanal/quincenal/mensual)
✅ banco, tipo_cuenta, numero_cuenta
✅ estado (activo/inactivo)
✅ fecha_nacimiento, sexo (para Banesco)
```

**Módulo:** `modulos/rrhh/gestion_empleados.php` - ✅ Existe y está completo

---

#### 2. Gestión de Períodos ✅
**Estado:** COMPLETO

- Crear/Editar períodos de nómina
- Tipos: semanal, quincenal, mensual
- Estados: abierto/cerrado/anulado
- Control de fechas

**Módulo:** `modulos/nominas/gestion_periodos.php` - ✅ Funcional

---

#### 3. Generación de Nóminas Básica ✅
**Estado:** PARCIALMENTE COMPLETO

- Generación masiva por período
- Cálculo de conceptos por empleado
- Generación de recibos HTML
- Numeración secuencial

**Módulo:** `modulos/nominas/gestion_nominas.php` - ✅ Funcional básico

---

### ❌ **LO QUE FALTA IMPLEMENTAR (Según Requisitos del Audio)**

## 🚨 **PRIORIDAD CRÍTICA**

### 1. **Integración con Presupuesto** ❌ **CRÍTICO**

**Requisito:** 
- "El presupuesto debe tener partidas dedicadas a estos gastos (ej. 401)"
- "La información de nómina debe impactar el presupuesto en tiempo real"
- "Ser visible para el área de Presupuesto para verificar la disponibilidad de fondos"

**Estado Actual:**
- ✅ Las partidas 401 existen en el sistema de cuentas
- ❌ NO se valida disponibilidad presupuestaria al generar nómina
- ❌ NO se registra en presupuesto al confirmar nómina
- ❌ NO hay impacto en tiempo real

**Qué Implementar:**

#### A. Validación de Presupuesto al Generar Nómina

```php
// En generarNominaMasiva(), ANTES de crear la nómina:

function validarPresupuestoDisponible($total_estimado, $mes, $anio) {
    // 1. Buscar presupuesto de partida 401 (Gastos de Personal)
    // 2. Verificar saldo disponible: credito_vigente - comprometido - pagado
    // 3. Si no hay suficiente, lanzar excepción
    // 4. Retornar presupuesto_id para uso posterior
}

// Ubicación sugerida: includes/util_nomina.php
```

#### B. Registro en Presupuesto al Confirmar Nómina

```php
// En confirmarNomina(), DESPUÉS de generar asiento:

function registrarNominaEnPresupuesto($nomina_id, $presupuesto_id, $monto) {
    // 1. Buscar presupuesto de partida 401
    // 2. Registrar como CAUSADO (no pagado aún)
    // 3. Actualizar ejecución presupuestaria
    // 4. Guardar relación: nominas.presupuesto_id, nominas.monto_presupuestado
}

// Ubicación: includes/util_nomina.php
```

#### C. Vista de Disponibilidad para Presupuesto

```php
// Nuevo módulo: modulos/presupuestos/impacto_nominas.php

// Muestra:
// - Nóminas comprometidas/causadas por período
// - Saldo disponible en partida 401
// - Alertas de disponibilidad
```

**Archivos a Modificar:**
- `includes/util_nomina.php` - Agregar funciones de presupuesto
- `modulos/nominas/gestion_nominas.php` - Agregar validación al generar
- `modulos/nominas/ver_nomina.php` - Mostrar info de presupuesto
- **NUEVO:** `modulos/presupuestos/impacto_nominas.php`

---

### 2. **Cálculo Automático de Prestaciones** ❌ **CRÍTICO**

**Requisito:**
- "Cálculo automático de prestaciones"
- "Vacaciones y descomiso" como prestaciones a considerar
- Para HP: "definir el monto a pagar y el período determinado"

**Estado Actual:**
- ❌ NO hay cálculo automático de prestaciones
- ❌ Las prestaciones deben configurarse manualmente como conceptos
- ❌ No hay lógica especial para Honorarios Profesionales

**Qué Implementar:**

#### A. Sistema de Cálculo de Prestaciones

```php
// Nuevo archivo: includes/util_prestaciones.php

function calcularPrestacionesEmpleado($empleado_id, $periodo_desde, $periodo_hasta) {
    // 1. Obtener datos del empleado (fecha_ingreso, salario_base, tipo_contrato)
    // 2. Calcular días trabajados en el período
    // 3. Calcular prestaciones según tipo de contrato:
    
    // - Fijo/Temporal:
    //   * Vacaciones: días acumulados según antigüedad
    //   * Aguinaldo: proporcional al período
    //   * Bono Vacacional: según fecha de ingreso
    //   * Prestaciones sociales: acumulado mensual
    
    // - Honorarios:
    //   * Monto fijo según contrato
    //   * Sin prestaciones (o según configuración especial)
    
    // 4. Retornar array con desglose
}

function calcularDescomiso($empleado_id, $periodo_id) {
    // Calcular días de descuento por faltas injustificadas
    // Aplicar proporción al salario
}
```

#### B. Integración en Generación de Nómina

```php
// Modificar: includes/util_nomina.php -> generarNominaMasiva()

// Dentro del loop de empleados, ANTES de calcular conceptos:

// 1. Calcular prestaciones automáticas
$prestaciones = calcularPrestacionesEmpleado(
    $emp['id'],
    $periodo['fecha_inicio'],
    $periodo['fecha_fin']
);

// 2. Si hay vacaciones/aguinaldo, agregar como percepciones
if ($prestaciones['vacaciones'] > 0) {
    // Crear concepto temporal o usar concepto predefinido
}

// 3. Si hay descomiso, agregar como deducción
if ($prestaciones['descomiso'] > 0) {
    // Aplicar descuento
}

// 4. Para HP: validar monto y período del contrato
if ($emp['tipo_contrato'] === 'honorarios') {
    // Validar que el período esté dentro del contrato
    // Aplicar monto según configuración específica
}
```

#### C. Configuración de Reglas de Prestaciones

```php
// Nueva tabla: reglas_prestaciones
// Campos: tipo_contrato, tipo_prestacion, formula_calculo, porcentaje, etc.

// Nuevo módulo: modulos/rrhh/gestion_reglas_prestaciones.php
// Para configurar cómo se calculan prestaciones por tipo de empleado
```

**Archivos a Crear:**
- **NUEVO:** `includes/util_prestaciones.php`
- **NUEVO:** `modulos/rrhh/gestion_reglas_prestaciones.php`
- **NUEVO:** Tabla `reglas_prestaciones` en base de datos

**Archivos a Modificar:**
- `includes/util_nomina.php` - Integrar cálculo de prestaciones

---

### 3. **Manejo Especial para Honorarios Profesionales (HP)** ❌ **IMPORTANTE**

**Requisito:**
- "Para HP, el sistema debe permitir definir el monto a pagar y el período determinado de contratación"
- "Cálculo automático con reglas y métodos de cálculo (fijo o porcentaje para HP)"

**Estado Actual:**
- ✅ Campo `tipo_contrato` incluye 'honorarios'
- ❌ NO hay lógica especial para HP
- ❌ NO se valida período de contratación
- ❌ NO hay interfaz para definir monto/período de HP

**Qué Implementar:**

#### A. Tabla de Contratos HP

```sql
CREATE TABLE contratos_honorarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    empleado_id INT NOT NULL,
    monto_total DECIMAL(14,2) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    metodo_pago ENUM('fijo', 'porcentaje') DEFAULT 'fijo',
    porcentaje DECIMAL(5,2) DEFAULT NULL,
    estado ENUM('activo', 'finalizado', 'cancelado') DEFAULT 'activo',
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### B. Validación en Generación de Nómina

```php
// En generarNominaMasiva(), para empleados HP:

if ($emp['tipo_contrato'] === 'honorarios') {
    // 1. Buscar contrato activo
    $contrato = obtenerContratoActivoHP($emp['id'], $periodo);
    
    if (!$contrato) {
        throw new Exception("Empleado {$emp['codigo']} no tiene contrato HP activo para este período");
    }
    
    // 2. Validar que el período esté dentro del contrato
    if ($periodo['fecha_inicio'] < $contrato['fecha_inicio'] || 
        $periodo['fecha_fin'] > $contrato['fecha_fin']) {
        throw new Exception("Período fuera del rango del contrato HP");
    }
    
    // 3. Calcular monto según método
    if ($contrato['metodo_pago'] === 'fijo') {
        $salario_base = $contrato['monto_total'];
    } else {
        // Porcentaje de algo (definir regla)
        $salario_base = calcularPorcentajeHP($contrato, $periodo);
    }
    
    // 4. HP generalmente NO tiene prestaciones
    $aplicar_prestaciones = false;
}
```

#### C. Módulo de Gestión de Contratos HP

```php
// NUEVO: modulos/rrhh/gestion_contratos_hp.php

// CRUD de contratos de honorarios profesionales
// Validación de fechas
// Asociación con empleados
// Visualización de contratos activos
```

**Archivos a Crear:**
- **NUEVO:** Tabla `contratos_honorarios`
- **NUEVO:** `modulos/rrhh/gestion_contratos_hp.php`
- **NUEVO:** Funciones en `includes/util_nomina.php`

---

## 🔶 **PRIORIDAD ALTA**

### 4. **Cuentas Contables Específicas para Nómina** ⚠️ **IMPORTANTE**

**Requisito:**
- "Manejo de cuentas específicas para 'gasto de personal' y 'aporte personal'"
- "El presupuesto debe tener partidas dedicadas a estos gastos (ej. 401)"

**Estado Actual:**
- ✅ Partidas 401 existen (Gastos de Personal)
- ⚠️ Confirmación de nómina busca cuentas genéricamente
- ❌ NO hay mapeo específico de cuentas por tipo de concepto

**Qué Implementar:**

```php
// Modificar: includes/util_nomina.php -> confirmarNomina()

// 1. Mapeo específico de cuentas:
$cuentas_nomina = [
    'gasto_personal' => obtenerCuentaPorCodigo('401010101'), // Sueldos básicos
    'aporte_personal' => obtenerCuentaPorCodigo('401060100'), // Aportes patronales
    'sueldos_pagar' => obtenerCuentaPorCodigo('210010000'),  // Sueldos por pagar
];

// 2. Desglosar asiento por tipo de concepto:
// - Salarios base → Gasto Personal
// - Aportes patronales → Aporte Personal
// - Total → Sueldos por Pagar

// 3. Asiento más detallado:
$detalles = [
    ['cuenta_id' => $cuentas_nomina['gasto_personal'], 'descripcion' => 'Sueldos básicos', 'debe' => $total_salarios, 'haber' => 0],
    ['cuenta_id' => $cuentas_nomina['aporte_personal'], 'descripcion' => 'Aportes patronales', 'debe' => $total_aportes, 'haber' => 0],
    ['cuenta_id' => $cuentas_nomina['sueldos_pagar'], 'descripcion' => 'Sueldos por pagar', 'debe' => 0, 'haber' => $total_neto],
];
```

---

### 5. **Descarga de Nómina Completa por Período** ❌ **IMPORTANTE**

**Requisito:**
- "Capacidad de descargar la nómina por período"
- "Generación de nóminas completas por período"

**Estado Actual:**
- ✅ Se puede ver detalle de nómina
- ✅ Se puede exportar PDF Banesco (solo ahorro habitacional)
- ❌ NO hay descarga de nómina completa en Excel/PDF
- ❌ NO hay formato institucional completo

**Qué Implementar:**

```php
// NUEVO: modulos/nominas/exportar_nomina_completa.php

// Opciones:
// 1. PDF Completo (todos los empleados con detalles)
// 2. Excel (formato tabular)
// 3. Formato institucional (si existe plantilla)

// Incluir:
// - Encabezado con datos del período
// - Totales generales
// - Detalle por empleado
// - Desglose de conceptos
// - Firmas y aprobaciones
```

**Archivos a Crear:**
- **NUEVO:** `modulos/nominas/exportar_nomina_completa.php`

---

### 6. **Formatos Actualizados para Sueldos y Primas** ⚠️ **MEJORA**

**Requisito:**
- "Formatos actualizados para sueldos y primas"

**Estado Actual:**
- ✅ Recibos HTML básicos generados
- ⚠️ Formato simple, no específico por tipo

**Qué Implementar:**

```php
// Mejorar: includes/util_nomina.php -> generarReciboHTML()

// Opciones:
// 1. Plantillas diferentes por tipo de empleado
// 2. Formato institucional oficial
// 3. Inclusión de información adicional:
//    - Desglose detallado de prestaciones
//    - Aportes patronales visibles
//    - Información fiscal completa
```

---

### 7. **Generación Automática de Pagos** ❌ **IMPORTANTE**

**Requisito:**
- "El sistema debe generar pagos y recibos de forma automatizada"

**Estado Actual:**
- ✅ Recibos generados automáticamente al crear nómina
- ❌ NO se genera orden de pago automáticamente
- ❌ NO se marca como pagado automáticamente

**Qué Implementar:**

```php
// NUEVO: Función para generar órdenes de pago desde nómina confirmada

function generarOrdenesPagoDesdeNomina($nomina_id) {
    // 1. Obtener empleados de la nómina
    // 2. Para cada empleado:
    //    - Crear orden de pago individual
    //    - Asociar a presupuesto de partida 401
    //    - Usar datos bancarios del empleado
    //    - Monto = total_neto del empleado
    // 3. Agrupar por banco (opcional)
    // 4. Generar archivo de transferencia bancaria
}

// Integrar en confirmarNomina() o crear acción separada
```

**Archivos a Crear:**
- **NUEVO:** `includes/util_pagos_nomina.php`
- **NUEVO:** `modulos/nominas/generar_pagos.php`

---

## 🔵 **PRIORIDAD MEDIA**

### 8. **Aportes para IC (Instituto de Capacitación)** ⚠️ **FUTURO**

**Requisito:**
- "Inclusión de cálculos y aportes para el 'IC' (Instituto de Capacitación) una vez implementado"

**Estado Actual:**
- ❌ No mencionado en código actual

**Implementación Futura:**
- Similar a ahorro habitacional
- Concepto de deducción específico
- Configuración por empleado
- Exportación si aplica

---

### 9. **Eliminación de Caja de Ahorro** ⚠️ **VERIFICAR**

**Requisito:**
- "La 'Caja de ahorro' podría eliminarse si ya no es relevante"

**Acción:**
- Verificar si existe concepto "Caja de Ahorro" en sistema
- Si existe y no se usa, marcarlo como inactivo o eliminar

---

## 📊 **FLUJO COMPLETO QUE REQUIEREN**

### **Flujo Deseado (Según Requisitos):**

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. CONFIGURACIÓN INICIAL                                         │
│    - Crear empleados con todos sus datos                        │
│    - Configurar conceptos de nómina                            │
│    - Asignar conceptos por empleado                             │
│    - Configurar reglas de prestaciones                          │
│    - Para HP: Crear contratos con montos/períodos               │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. CREAR PERÍODO DE NÓMINA                                       │
│    - Definir período (quincenal/mensual)                        │
│    - Fechas de inicio y fin                                      │
│    - Estado: Abierto                                             │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. GENERAR NÓMINA                                                │
│    A. Validar presupuesto disponible (partida 401)             │
│       - Verificar saldo disponible                              │
│       - Mostrar alerta si no hay suficiente                     │
│    B. Para cada empleado activo:                                │
│       - Calcular salario base                                   │
│       - Calcular prestaciones automáticas (vacaciones, etc.)   │
│       - Aplicar conceptos configurados                         │
│       - Para HP: Validar contrato y aplicar monto/período        │
│       - Calcular descomiso si aplica                            │
│       - Calcular total neto                                      │
│    C. Generar recibos HTML                                      │
│    D. Guardar nómina en estado "borrador"                      │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. REVISIÓN Y APROBACIÓN                                         │
│    - RRHH/Administración revisa nómina                          │
│    - Puede editar antes de confirmar                            │
│    - Verifica totales y disponibilidad presupuestaria            │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. CONFIRMAR NÓMINA                                              │
│    A. Validar presupuesto nuevamente                            │
│    B. Registrar como CAUSADO en presupuesto (partida 401)       │
│       - Impacto en tiempo real                                  │
│       - Visible para área de Presupuesto                         │
│    C. Generar asiento contable:                                 │
│       - Debe: Gasto Personal (401.01.01.01)                    │
│       - Debe: Aporte Personal (401.06.xx.xx)                    │
│       - Haber: Sueldos por Pagar (210.01.00.00)                 │
│    D. Cambiar estado a "confirmada"                              │
│    E. Registrar auditoría                                       │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. GENERAR PAGOS                                                 │
│    A. Generar órdenes de pago automáticas                       │
│       - Una por empleado o agrupadas                            │
│       - Usar datos bancarios del empleado                       │
│       - Asociar a presupuesto                                    │
│    B. Generar archivo de transferencia bancaria                │
│    C. Marcar recibos como "pagado" al ejecutar pago             │
│    D. Actualizar presupuesto: CAUSADO → PAGADO                  │
└─────────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. REPORTES Y DOCUMENTOS                                         │
│    - Descargar nómina completa por período (PDF/Excel)        │
│    - Imprimir recibos individuales                              │
│    - Exportar formatos especiales (Banesco, etc.)               │
│    - Consultar impacto presupuestario                           │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📝 **RESUMEN: QUÉ IMPLEMENTAR (Priorizado)**

### **🔴 CRÍTICO (Debe estar antes de usar en producción):**

1. **Integración con Presupuesto**
   - Validación al generar
   - Registro como causado al confirmar
   - Vista para área de Presupuesto

2. **Cálculo Automático de Prestaciones**
   - Vacaciones, aguinaldo, bono vacacional
   - Prestaciones sociales
   - Descomiso

3. **Manejo de Honorarios Profesionales**
   - Contratos HP
   - Validación de períodos
   - Cálculo según método

### **🟡 ALTO (Funcionalidad importante):**

4. **Cuentas Contables Específicas**
   - Mapeo de partidas 401
   - Asientos detallados por tipo

5. **Descarga de Nómina Completa**
   - PDF/Excel formato institucional

6. **Generación Automática de Pagos**
   - Órdenes de pago desde nómina
   - Archivos de transferencia

7. **Formatos Mejorados**
   - Recibos más completos
   - Información fiscal

### **🟢 MEDIO (Mejoras y optimizaciones):**

8. **IC (Instituto de Capacitación)** - Futuro
9. **Eliminación Caja de Ahorro** - Verificar si aplica

---

## 🔗 **SEPARACIÓN DE REQUISICIONES**

**Requisito importante:**
> "La nómina se gestiona separadamente de las requisiciones de compra"

**Estado:** ✅ Ya está separado
- Nóminas: `modulos/nominas/`
- Requisiciones: `modulos/requisiciones/`
- Presupuestos independientes
- Flujos de aprobación separados

---

## 👥 **GESTIÓN POR RRHH/ADMINISTRACIÓN**

**Requisito:**
> "El departamento de Recursos Humanos/Administración es el principal gestor"

**Estado Actual:**
- ✅ Permisos configurados para módulo 'nominas'
- ✅ Permisos configurados para módulo 'rrhh'
- ⚠️ Verificar que usuarios RRHH tengan permisos necesarios

**Acción:**
- Verificar configuración de permisos en base de datos
- Asignar permisos: `nominas:generar`, `nominas:confirmar`, `rrhh:ver`, etc.

---

## ✅ **CHECKLIST DE IMPLEMENTACIÓN**

### Fase 1: Crítico
- [ ] Implementar validación de presupuesto al generar nómina
- [ ] Implementar registro en presupuesto al confirmar
- [ ] Crear vista de impacto presupuestario
- [ ] Implementar cálculo automático de prestaciones
- [ ] Crear sistema de contratos HP
- [ ] Validar períodos de contratos HP

### Fase 2: Alto
- [ ] Mapear cuentas contables específicas (401)
- [ ] Crear exportación completa de nómina
- [ ] Implementar generación automática de pagos
- [ ] Mejorar formatos de recibos

### Fase 3: Medio
- [ ] Implementar aportes IC (futuro)
- [ ] Verificar/Eliminar caja de ahorro si aplica
- [ ] Reportes adicionales

---

**Última Actualización:** Basado en requisitos del audio resumido

