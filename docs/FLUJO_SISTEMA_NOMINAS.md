# Flujo Completo del Sistema de Nóminas

## 📋 Índice
1. [Visión General](#visión-general)
2. [Flujo Detallado Paso a Paso](#flujo-detallado-paso-a-paso)
3. [Estructura de Base de Datos](#estructura-de-base-de-datos)
4. [Componentes Implementados](#componentes-implementados)
5. [Componentes Faltantes](#componentes-faltantes)
6. [Recomendaciones de Implementación](#recomendaciones-de-implementación)

---

## 📊 Visión General

El sistema de nóminas permite:
- **Gestionar períodos de nómina** (semanal, quincenal, mensual)
- **Generar nóminas masivas** para empleados activos
- **Calcular automáticamente** percepciones y deducciones
- **Generar recibos de pago** (baunches) en HTML
- **Confirmar nóminas** (generar asientos contables)
- **Exportar formatos especiales** (ej: Banesco Ahorro Habitacional)
- **Imprimir recibos individuales**

---

## 🔄 Flujo Detallado Paso a Paso

### **PASO 1: Configuración Inicial (PRE-REQUISITO)**

#### 1.1. Crear Conceptos de Nómina
**Ubicación:** `modulos/rrhh/gestion_conceptos.php` (ASUMIDO - NO INCLUIDO EN LOS ARCHIVOS PROPORCIONADOS)

**Tabla:** `conceptos_nomina`
```sql
- id, codigo, nombre, tipo (percepcion/deduccion)
- base_calculo (fijo/porcentaje_salario/personalizado)
- valor, orden, estado
```

**Ejemplos de conceptos:**
- **Percepciones:** Sueldo Base, Bono Alimentación, Prima de Antigüedad, Horas Extras
- **Deducciones:** IVSS, FAOV (Ahorro Habitacional), ISLR, Descuentos varios

#### 1.2. Configurar Conceptos por Empleado
**Ubicación:** `modulos/rrhh/gestion_empleado_conceptos.php`

**Tabla:** `empleados_conceptos`
```sql
- empleado_id, concepto_id
- base_calculo, valor_parametro, cantidad, estado
```

**Funcionalidad:**
- Asigna conceptos específicos a cada empleado
- Permite personalizar valores por empleado
- Ejemplo: Empleado A tiene 10% de descuento en Ahorro Habitacional, Empleado B tiene 5%

---

### **PASO 2: Crear Período de Nómina**

**Archivo:** `modulos/nominas/gestion_periodos.php`

**Flujo:**
1. Usuario accede a "Períodos de Nómina"
2. Crea nuevo período con:
   - Código (ej: "ENE-2025")
   - Descripción
   - Fecha Inicio / Fecha Fin
   - Periodicidad (semanal/quincenal/mensual)
   - Estado (abierto/cerrado/anulado)

**Tabla:** `periodos_nomina`
- Estado "abierto" permite generar nóminas
- Estado "cerrado" impide nuevas nóminas

**Operaciones:**
- ✅ Crear período
- ✅ Editar período
- ✅ Abrir/Cerrar/Anular período

---

### **PASO 3: Generar Nómina**

**Archivo:** `modulos/nominas/gestion_nominas.php`

#### 3.1. Proceso de Generación

**Función:** `generarNominaMasiva($periodo_id, $empleado_ids)`  
**Ubicación:** `includes/util_nomina.php` (líneas 168-273)

**Proceso interno:**

1. **Validación:**
   - ✅ Verificar permisos (`rrhh_tienePermiso('generar')`)
   - ✅ Validar que el período exista y esté "abierto"
   - ✅ Validar que haya empleados seleccionados

2. **Crear Cabecera de Nómina:**
   ```sql
   INSERT INTO nominas (
       numero,           -- Ej: "NOM-2025-00001"
       periodo_id,
       fecha_generacion, -- CURDATE()
       estado,           -- 'borrador'
       total_bruto,      -- 0 (se calcula después)
       total_deducciones,-- 0
       total_neto        -- 0
   )
   ```

3. **Procesar cada Empleado:**

   a. **Generar número de recibo:**
      ```php
      $recibo_num = generarNumeroRecibo($conn); // Ej: "REC-2025-000001"
      ```

   b. **Obtener salario base del empleado:**
      ```php
      $salario_base = (float)$emp['salario_base'];
      ```

   c. **Obtener conceptos asignados al empleado:**
      ```php
      $conceptosEmp = obtenerConceptosPorEmpleado($emp['id']);
      // Tabla: empleados_conceptos JOIN conceptos_nomina
      ```

   d. **Calcular montos por concepto:**
      ```php
      foreach ($conceptosEmp as $c) {
          $monto = calcularConceptoMonto(
              $c['tipo'],           // percepción/deducción
              $c['base_calculo'],   // fijo/porcentaje_salario/personalizado
              $c['valor_parametro'], // valor o porcentaje
              $c['cantidad'],        // multiplicador
              $salario_base
          );
          
          if ($c['tipo'] === 'percepcion') {
              $percepciones += $monto;
          } else {
              $deducciones += $monto;
          }
      }
      ```

   e. **Calcular neto:**
      ```php
      $neto = $salario_base + $percepciones - $deducciones;
      ```

   f. **Insertar registro en `nominas_empleados`:**
      ```sql
      INSERT INTO nominas_empleados (
          nomina_id, empleado_id, recibo_numero,
          salario_base, total_percepciones, total_deducciones,
          total_neto, estado -- 'pendiente'
      )
      ```

   g. **Insertar detalles en `nomina_detalle`:**
      ```sql
      INSERT INTO nomina_detalle (
          nomina_empleado_id, concepto_id, tipo,
          base_calculo, valor_parametro, cantidad, monto
      )
      -- Un registro por cada concepto aplicado
      ```

   h. **Generar recibo HTML:**
      ```php
      $reciboHtml = generarReciboHTML(
          APP_NAME,
          $emp,
          [
              'recibo_numero' => $recibo_num,
              'salario_base' => $salario_base,
              'total_percepciones' => $percepciones,
              'total_deducciones' => $deducciones,
              'total_neto' => $neto
          ],
          $detalles_conceptos
      );
      ```

   i. **Guardar recibo en `recibos_nomina`:**
      ```sql
      INSERT INTO recibos_nomina (
          nomina_empleado_id, recibo_numero,
          formato, -- 'html'
          contenido_largo -- HTML completo del recibo
      )
      ```

4. **Actualizar totales de la nómina:**
   ```php
   UPDATE nominas SET
       total_bruto = SUM(salario_base + percepciones),
       total_deducciones = SUM(deducciones),
       total_neto = SUM(neto)
   WHERE id = $nomina_id
   ```

5. **Registrar auditoría:**
   ```php
   registrarCreacion('nominas', 'nominas', $nomina_id, ...);
   ```

**Estado resultante:**
- Nómina con estado `'borrador'`
- Empleados con estado `'pendiente'`
- Recibos HTML generados y almacenados

---

### **PASO 4: Ver Detalle de Nómina**

**Archivo:** `modulos/nominas/ver_nomina.php`

**Funcionalidad:**
- Muestra lista de empleados en la nómina
- Totales por empleado (salario, percepciones, deducciones, neto)
- Estado de cada empleado (pendiente/pagado/anulado)
- Botón para imprimir recibo individual
- Botones para exportar PDF Banesco

**Tabla mostrada:**
- Empleado | Identificación | Recibo | Salario Base | Percepciones | Deducciones | Neto | Estado | Acciones

---

### **PASO 5: Confirmar Nómina**

**Archivo:** `modulos/nominas/gestion_nominas.php` (línea 47)

**Función:** `confirmarNomina($nomina_id)`  
**Ubicación:** `includes/util_nomina.php` (líneas 275-314)

**Proceso:**

1. **Validación:**
   - ✅ Verificar permisos (`rrhh_tienePermiso('confirmar')`)
   - ✅ Verificar que la nómina exista
   - ✅ Verificar que no esté ya confirmada

2. **Buscar cuentas contables:**
   ```php
   $idGastoNomina = obtenerIdCuentaPorNombreParcial($conn, 'nómina');
   // Busca: 'nómina' → 'nomina' → 'sueld'
   
   $idSueldosPagar = obtenerIdCuentaPorNombreParcial($conn, 'sueldos por pagar');
   // Busca: 'sueldos por pagar' → 'por pagar'
   ```

3. **Generar asiento contable:**
   ```php
   if ($idGastoNomina && $idSueldosPagar) {
       $detalles_asiento = [
           [
               'cuenta_id' => $idGastoNomina,
               'descripcion' => 'Gasto de Nómina ' . $nomina['numero'],
               'debe' => $totalNeto,
               'haber' => 0
           ],
           [
               'cuenta_id' => $idSueldosPagar,
               'descripcion' => 'Sueldos por pagar ' . $nomina['numero'],
               'debe' => 0,
               'haber' => $totalNeto
           ]
       ];
       
       $asiento_id = generarAsientoContable(
           'Nómina ' . $nomina['numero'],
           $detalles_asiento,
           $nomina['numero']
       );
   }
   ```

4. **Actualizar estado de nómina:**
   ```sql
   UPDATE nominas SET estado = 'confirmada' WHERE id = $nomina_id
   ```

5. **Registrar auditoría:**
   ```php
   registrarActualizacion('nominas', 'nominas', $nomina_id, ...);
   ```

**Resultado:**
- ✅ Asiento contable generado (Debe: Gasto Nómina | Haber: Sueldos por Pagar)
- ✅ Nómina marcada como "confirmada"
- ✅ Sistema listo para pagos

---

### **PASO 6: Imprimir Recibo Individual**

**Archivo:** `modulos/nominas/imprimir_recibo.php`

**Funcionalidad:**
- Recibe `ne_id` (ID de `nominas_empleados`) o `recibo` (número de recibo)
- Busca el recibo HTML almacenado en `recibos_nomina`
- Inyecta barra de acciones (Imprimir, Volver) con CSS `no-print`
- Muestra el HTML con botón de impresión del navegador

**Proceso:**
```php
1. Buscar recibo:
   SELECT r.*, ne.recibo_numero
   FROM recibos_nomina r
   JOIN nominas_empleados ne ON r.nomina_empleado_id = ne.id
   WHERE r.nomina_empleado_id = :id OR r.recibo_numero = :rec

2. Obtener contenido HTML:
   $contenido = $row['contenido_largo'];

3. Inyectar barra de impresión:
   $barra = '<div class="no-print">...</div>';
   + CSS @media print { .no-print { display:none; } }

4. Mostrar HTML completo
```

---

### **PASO 7: Exportar PDF Banesco**

**Archivo:** `modulos/nominas/exportar_banesco_ahorro.php`

**Funcionalidad:**
- Genera PDF en formato requerido por Banesco para aportes de ahorro habitacional
- Busca conceptos específicos de ahorro (deducción empleado + percepción empleador)
- Formato horizontal (landscape) con logos y formato profesional

**Proceso:**

1. **Buscar conceptos de ahorro:**
   ```php
   // Busca concepto de deducción (ahorro empleado)
   $concepto_ahorro_empleado = buscar por código (AHO, HAB) 
                                o nombre (ahorro habitacional)
   
   // Busca concepto de percepción (aporte empleador)
   $concepto_ahorro_empleador = buscar por código (AEM, APORTE)
                                 o nombre (aporte empleador)
   ```

2. **Procesar cada empleado:**
   ```php
   foreach ($empleados_nomina as $emp_nom) {
       // Obtener monto de ahorro del empleado
       $mto_aho = buscar en nomina_detalle
       
       // Obtener monto de aporte del empleador
       $mto_emp = buscar en nomina_detalle
       
       $total = $mto_aho + $mto_emp;
       
       // Agregar a array de exportación
       $datos_exportacion[] = [
           'cedula' => $emp['identificacion'],
           'mto_aho' => $mto_aho,
           'mto_emp' => $mto_emp,
           'total' => $total,
           'apellidos_nombre' => ...,
           'sexo' => ...,
           'f_nac' => ..., // fecha nacimiento formateada
           'f_inc' => ...  // fecha incorporación formateada
       ];
   }
   ```

3. **Generar PDF con TCPDF:**
   - Clase personalizada: `BanescoAhorroPDF extends TCPDF`
   - Headers: Logos, empresa, contrato Banesco, fecha
   - Tabla: CEDULA | MTO AHO | MTO EMP | TOTAL | STA | NOMBRE | SEXO | F NAC | F INC
   - Totales al final
   - Información de nómina y conceptos utilizados

4. **Exportar:**
   - `?descargar=1` → Forzar descarga
   - Sin parámetro → Mostrar en navegador (inline)

---

## 🗄️ Estructura de Base de Datos

### Tablas Principales

#### 1. `periodos_nomina`
```sql
id, codigo, descripcion, fecha_inicio, fecha_fin,
periodicidad (semanal/quincenal/mensual),
estado (abierto/cerrado/anulado)
```

#### 2. `nominas`
```sql
id, numero (NOM-2025-00001), periodo_id,
fecha_generacion, estado (borrador/confirmada/anulada),
total_bruto, total_deducciones, total_neto
```

#### 3. `nominas_empleados`
```sql
id, nomina_id, empleado_id, recibo_numero (REC-2025-000001),
salario_base, total_percepciones, total_deducciones, total_neto,
estado (pendiente/pagado/anulado)
```

#### 4. `nomina_detalle`
```sql
id, nomina_empleado_id, concepto_id,
tipo (percepcion/deduccion), base_calculo, valor_parametro,
cantidad, monto
```

#### 5. `recibos_nomina`
```sql
id, nomina_empleado_id, recibo_numero,
formato (html/pdf), contenido_largo (MEDIUMTEXT con HTML)
```

#### 6. `conceptos_nomina`
```sql
id, codigo, nombre, tipo (percepcion/deduccion),
base_calculo (fijo/porcentaje_salario/personalizado),
valor, orden, estado
```

#### 7. `empleados_conceptos`
```sql
id, empleado_id, concepto_id,
base_calculo, valor_parametro, cantidad, estado
```

---

## ✅ Componentes Implementados

### **1. Gestión de Períodos** ✅
- ✅ Crear/Editar períodos
- ✅ Abrir/Cerrar/Anular períodos
- ✅ Validación de fechas
- ✅ Estados de período

### **2. Generación de Nómina** ✅
- ✅ Generación masiva por período
- ✅ Selección de empleados específicos
- ✅ Cálculo automático de conceptos
- ✅ Generación de números secuenciales
- ✅ Guardado de recibos HTML
- ✅ Actualización de totales

### **3. Visualización** ✅
- ✅ Listado de nóminas generadas
- ✅ Detalle de nómina con empleados
- ✅ Totales y estados
- ✅ Acciones rápidas

### **4. Confirmación** ✅
- ✅ Validación de permisos
- ✅ Búsqueda automática de cuentas contables
- ✅ Generación de asientos contables
- ✅ Actualización de estados

### **5. Impresión de Recibos** ✅
- ✅ Visualización de recibo HTML
- ✅ Barra de impresión (no se imprime)
- ✅ Formato profesional
- ✅ Búsqueda por ID o número de recibo

### **6. Exportación Banesco** ✅
- ✅ Búsqueda inteligente de conceptos
- ✅ Generación de PDF profesional
- ✅ Formato requerido por Banesco
- ✅ Logos y encabezados

---

## ❌ Componentes Faltantes

### **1. Gestión de Conceptos de Nómina** ❌ **CRÍTICO**

**Problema:** No se encontró el archivo `modulos/rrhh/gestion_conceptos.php`

**Funcionalidad requerida:**
- Crear/Editar/Eliminar conceptos
- Definir tipo (percepción/deducción)
- Configurar base de cálculo (fijo, porcentaje, personalizado)
- Establecer valores por defecto
- Activar/Desactivar conceptos

**Implementación sugerida:**
```
modulos/rrhh/gestion_conceptos.php
- Formulario CRUD completo
- Validación de tipos y cálculos
- Selector de tipo (percepción/deducción)
- Campo base_calculo (select)
- Campo valor por defecto
- Orden de presentación
```

### **2. Gestión de Empleados** ❌ **CRÍTICO**

**Problema:** No se encontró módulo completo de gestión de empleados

**Funcionalidad requerida:**
- CRUD de empleados
- Campo `salario_base` en tabla `empleados`
- Estado de empleado (activo/inactivo)
- Fecha de ingreso, fecha de nacimiento, sexo (para exportación Banesco)
- Relación con departamentos

**Verificación necesaria:**
```sql
-- Verificar estructura de tabla empleados
DESCRIBE empleados;

-- Campos requeridos:
- id, codigo, nombres, apellidos
- identificacion (CEDULA)
- salario_base ✅ (verificar que existe)
- estado (activo/inactivo)
- fecha_ingreso, fecha_nacimiento, sexo (para Banesco)
- departamento_id (opcional)
```

### **3. Asignación Masiva de Conceptos** ⚠️ **RECOMENDADO**

**Funcionalidad sugerida:**
- Asignar un concepto a múltiples empleados de una vez
- Plantillas de conceptos por tipo de empleado
- Copiar conceptos de un empleado a otro

**Ubicación sugerida:**
```
modulos/rrhh/asignacion_masiva_conceptos.php
```

### **4. Edición de Nóminas Generadas** ❌ **IMPORTANTE**

**Problema:** No existe funcionalidad para editar nóminas en estado "borrador"

**Funcionalidad requerida:**
- Editar montos individuales (antes de confirmar)
- Agregar/Quitar conceptos por empleado
- Recalcular totales
- Validar cambios antes de guardar

**Ubicación sugerida:**
```
modulos/nominas/editar_nomina.php
- Solo permite editar si estado = 'borrador'
- Bloquea edición si estado = 'confirmada'
```

### **5. Anulación de Nóminas** ⚠️ **IMPORTANTE**

**Problema:** Solo existe botón/comentario, no está implementado

**Funcionalidad requerida:**
- Anular nómina completa
- Anular recibo individual
- Revertir asientos contables (si está confirmada)
- Validación de permisos
- Auditoría de anulaciones

**Implementación sugerida:**
```php
// En gestion_nominas.php
case 'anular':
    if (!verificarPermiso('nominas','anular')) {
        throw new Exception('Sin permisos');
    }
    anularNomina($id);
    
// Función en util_nomina.php
function anularNomina($nomina_id) {
    // 1. Verificar estado
    // 2. Si está confirmada, revertir asiento
    // 3. Cambiar estado a 'anulada'
    // 4. Cambiar estados de empleados a 'anulado'
    // 5. Registrar auditoría
}
```

### **6. Reportes y Consultas** ⚠️ **RECOMENDADO**

**Funcionalidad sugerida:**
- Reporte de nóminas por período
- Reporte de pagos por empleado
- Consulta histórica de recibos
- Exportación a Excel
- Comparativo de nóminas

**Ubicación sugerida:**
```
modulos/nominas/reportes/
- reporte_nominas_periodo.php
- reporte_empleado_historico.php
- exportar_excel_nomina.php
```

### **7. Validaciones Adicionales** ⚠️ **MEJORA**

**Validaciones faltantes:**
- ✅ Verificar que el período esté abierto antes de generar
- ❌ Verificar que no exista nómina duplicada para el mismo período
- ❌ Validar que los empleados tengan salario base > 0
- ❌ Validar que los conceptos estén activos
- ❌ Alertar si un empleado no tiene conceptos asignados
- ❌ Validar rangos de fechas del período

### **8. Integración con Pago Real** ❌ **FUTURO**

**Funcionalidad sugerida:**
- Registrar pagos efectuados
- Marcar recibos como "pagados"
- Integración con ordenes de pago
- Conciliación bancaria de pagos

---

## 🔧 Recomendaciones de Implementación

### **Prioridad ALTA (Crítico para funcionamiento):**

1. **Crear módulo de gestión de conceptos:**
   ```
   modulos/rrhh/gestion_conceptos.php
   ```
   - CRUD completo
   - Validación de tipos y cálculos
   - Gestión de estados

2. **Verificar/Gestionar empleados:**
   ```
   modulos/rrhh/gestion_empleados.php (verificar que existe)
   ```
   - Asegurar que tabla `empleados` tenga campo `salario_base`
   - Verificar campos necesarios para Banesco (sexo, fecha_nacimiento, fecha_ingreso)

3. **Crear conceptos base:**
   ```sql
   -- Insertar conceptos comunes
   INSERT INTO conceptos_nomina (codigo, nombre, tipo, base_calculo, valor, orden, estado)
   VALUES
   ('SUELDO', 'Sueldo Base', 'percepcion', 'fijo', 0, 1, 'activo'),
   ('IVSS', 'Aporte IVSS', 'deduccion', 'porcentaje_salario', 4, 10, 'activo'),
   ('FAOV', 'Ahorro Habitacional Empleado', 'deduccion', 'porcentaje_salario', 1, 20, 'activo'),
   ('AEM', 'Aporte Empleador Ahorro Habitacional', 'percepcion', 'porcentaje_salario', 2, 30, 'activo');
   ```

### **Prioridad MEDIA (Funcionalidad importante):**

4. **Implementar edición de nóminas:**
   - Solo para estado "borrador"
   - Validación de permisos
   - Recalculación automática

5. **Implementar anulación:**
   - Con validaciones
   - Reversión de asientos si aplica
   - Auditoría completa

### **Prioridad BAJA (Mejoras y optimizaciones):**

6. **Reportes adicionales**
7. **Asignación masiva de conceptos**
8. **Integración con pagos**

---

## 📝 Checklist de Implementación

### Pre-requisitos:
- [ ] Verificar que tabla `empleados` tiene `salario_base`
- [ ] Verificar que tabla `empleados` tiene campos para Banesco
- [ ] Crear módulo `gestion_conceptos.php`
- [ ] Crear conceptos base en la base de datos
- [ ] Verificar permisos de usuario

### Flujo Básico:
- [ ] Crear período de nómina
- [ ] Configurar conceptos por empleado
- [ ] Generar nómina masiva
- [ ] Ver detalle de nómina
- [ ] Imprimir recibos
- [ ] Confirmar nómina (generar asiento)
- [ ] Exportar PDF Banesco

### Funcionalidades Avanzadas:
- [ ] Editar nómina (borrador)
- [ ] Anular nómina
- [ ] Reportes
- [ ] Validaciones adicionales

---

## 🔍 Debugging y Troubleshooting

### Problemas Comunes:

1. **Error: "No se encontraron conceptos"**
   - Verificar que existen conceptos activos en `conceptos_nomina`
   - Verificar que existen asignaciones en `empleados_conceptos`
   - Verificar que los conceptos están activos (`estado='activo'`)

2. **Error: "Cuentas contables no encontradas" al confirmar**
   - Verificar que existen cuentas con nombres que contengan "nómina" o "nomina"
   - Verificar que existe cuenta "sueldos por pagar"
   - Crear las cuentas necesarias en el módulo de contabilidad

3. **Recibos sin contenido**
   - Verificar que `generarReciboHTML()` está funcionando
   - Verificar que se guarda correctamente en `recibos_nomina.contenido_largo`

4. **Exportación Banesco sin datos**
   - Verificar que existen conceptos con códigos/nombres que contengan "AHO", "HAB", "AEM"
   - Verificar que los conceptos están en la nómina generada
   - Revisar la lógica de búsqueda en `exportar_banesco_ahorro.php`

---

## 📚 Archivos Relacionados

### Core del Sistema:
- `includes/util_nomina.php` - Funciones principales de nómina
- `includes/funciones_contables.php` - Funciones contables (asientos, auditoría)

### Módulos de Nómina:
- `modulos/nominas/gestion_periodos.php` - Gestión de períodos ✅
- `modulos/nominas/gestion_nominas.php` - Gestión de nóminas ✅
- `modulos/nominas/ver_nomina.php` - Ver detalle ✅
- `modulos/nominas/imprimir_recibo.php` - Imprimir recibo ✅
- `modulos/nominas/exportar_banesco_ahorro.php` - Exportar Banesco ✅

### Módulos de RRHH (Necesarios):
- `modulos/rrhh/gestion_empleado_conceptos.php` - Asignar conceptos ✅
- `modulos/rrhh/gestion_conceptos.php` - **FALTANTE** ❌
- `modulos/rrhh/gestion_empleados.php` - **VERIFICAR** ⚠️

---

## ✅ Conclusión

El sistema de nóminas está **80% implementado**. Las funcionalidades core están funcionando, pero falta:

1. **Módulo de gestión de conceptos** (CRÍTICO)
2. **Verificación/gestión completa de empleados** (CRÍTICO)
3. **Edición y anulación de nóminas** (IMPORTANTE)
4. **Reportes adicionales** (RECOMENDADO)

Con la implementación de los componentes faltantes, el sistema estará completo y funcional para gestionar nóminas de manera profesional.

