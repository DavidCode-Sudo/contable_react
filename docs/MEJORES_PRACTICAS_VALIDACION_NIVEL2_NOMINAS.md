# Mejores Prácticas: Validación Nivel 2 para Nóminas

## 📊 Análisis Comparativo

### **Sistema de Requisiciones (Referencia):**

```
┌─────────────────────────────────────────────────────────────┐
│ REQUISICIONES - DOBLE APROBACIÓN                            │
├─────────────────────────────────────────────────────────────┤
│ NIVEL 1: Registro y Control                                │
│ ─────────────────────────────────────────────────────────── │
│ Responsabilidades:                                          │
│   • Verificar completitud de documentos                    │
│   • Validar formato y estructura                           │
│   • Verificar que los datos sean correctos                  │
│   • Asegurar que cumple políticas internas                 │
│                                                              │
│ Permiso: requisiciones:aprobar_nivel_1                      │
│ Estado: enviada → pendiente_nivel_2                         │
│                                                              │
│ NIVEL 2: Presupuesto                                        │
│ ─────────────────────────────────────────────────────────── │
│ Responsabilidades:                                          │
│   • Validar disponibilidad presupuestaria                   │
│   • Verificar saldo mensual disponible                      │
│   • Aprobar o rechazar basado en presupuesto                │
│   • Generar compromiso automáticamente                      │
│                                                              │
│ Permiso: requisiciones:aprobar_nivel_2                       │
│ Estado: pendiente_nivel_2 → aprobada                        │
│ Validación: BLOQUEANTE si no hay presupuesto                │
└─────────────────────────────────────────────────────────────┘
```

---

## ✅ Mejor Práctica para Nóminas

### **Recomendación: SOLO NIVEL 2 (Presupuesto)**

**Razones:**

1. **RRHH ya valida al generar:**
   - Los datos de empleados están validados
   - Los conceptos están configurados previamente
   - Los cálculos son automáticos
   - No hay documentos externos que validar

2. **Nóminas son procesos estructurados:**
   - No hay variabilidad en el contenido
   - Los cálculos son determinísticos
   - No requieren validación de "completitud"

3. **El control crítico es presupuestario:**
   - El único riesgo es disponibilidad de fondos
   - La validación debe ser **bloqueante**
   - Debe ser explícita y auditable

---

## 🎯 Flujo Recomendado: Validación Nivel 2 Única

```
┌─────────────────────────────────────────────────────────────┐
│ MEJOR PRÁCTICA: NÓMINAS CON VALIDACIÓN NIVEL 2             │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ 1. GENERAR NÓMINA (RRHH)                                   │
│    ──────────────────────────────────────────────────────── │
│    • RRHH genera nómina con todos los empleados             │
│    • Sistema calcula automáticamente totales               │
│    • Estado: "borrador"                                     │
│    • Validaciones automáticas:                              │
│      - Empleados activos                                    │
│      - Conceptos configurados                               │
│      - Cálculos correctos                                   │
│                                                              │
│ 2. ENVIAR A VALIDACIÓN PRESUPUESTARIA                       │
│    ──────────────────────────────────────────────────────── │
│    • Acción: RRHH envía a Presupuesto                       │
│    • Estado: "pendiente_validacion_presupuesto"             │
│    • Notificación automática al área de Presupuesto          │
│                                                              │
│ 3. VALIDACIÓN NIVEL 2 - PRESUPUESTO                        │
│    ──────────────────────────────────────────────────────── │
│    • Responsable: Área de Presupuesto                       │
│    • Permiso: nominas:aprobar_presupuesto                    │
│    • Validaciones OBLIGATORIAS:                             │
│                                                              │
│      ✅ Validación 1: Disponibilidad Mensual                │
│         ─────────────────────────────────────────────────── │
│         - Verificar saldo disponible en el mes actual       │
│         - Fórmula: monto_mes - comprometido_mes - pagado_mes│
│         - Si disponible >= monto_nomina → CONTINUAR        │
│         - Si disponible < monto_nomina → BLOQUEAR          │
│                                                              │
│      ✅ Validación 2: Disponibilidad Anual                 │
│         ─────────────────────────────────────────────────── │
│         - Verificar saldo disponible anual                  │
│         - Fórmula: credito_vigente - comprometido - causado│
│         - Si disponible >= monto_nomina → CONTINUAR        │
│         - Si disponible < monto_nomina → BLOQUEAR          │
│                                                              │
│      ✅ Validación 3: Proyección de Ejecución               │
│         ─────────────────────────────────────────────────── │
│         - Verificar que no comprometa meses futuros        │
│         - Mostrar alerta si está cerca del límite          │
│                                                              │
│    • Decisiones posibles:                                  │
│      - APROBAR: Si hay suficiente presupuesto               │
│        → Estado: "aprobada_presupuesto"                     │
│        → Registra aprobación y usuario                       │
│                                                              │
│      - RECHAZAR: Si no hay suficiente presupuesto            │
│        → Estado: "borrador" (vuelve a RRHH)                 │
│        → Registra motivo de rechazo                         │
│                                                              │
│ 4. CONFIRMAR NÓMINA (RRHH)                                 │
│    ──────────────────────────────────────────────────────── │
│    • Solo si está aprobada por Presupuesto                 │
│    • Estado: "confirmada"                                    │
│    • Acciones automáticas:                                  │
│      - Genera asiento contable                              │
│      - Registra como causado en presupuesto                 │
│      - Actualiza disponibilidad presupuestaria              │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔐 Características de la Validación Nivel 2

### **1. Validación BLOQUEANTE**

```php
// La validación debe ser ESTRICTA y BLOQUEAR si no hay suficiente
function validarPresupuestoNominaNivel2($nomina_id, $usuario_id) {
    // Obtener datos de la nómina
    $nomina = obtenerNomina($nomina_id);
    $monto_nomina = $nomina['total_neto'];
    
    // Validación 1: Mes Actual (OBLIGATORIA)
    $disponibilidad_mes = calcularDisponibilidadMensual($presupuesto_id, $mes_actual);
    if ($disponibilidad_mes < $monto_nomina) {
        throw new Exception(
            "BLOQUEADO: Saldo insuficiente en el mes actual. " .
            "Disponible: " . formatearMoneda($disponibilidad_mes) . ", " .
            "Requerido: " . formatearMoneda($monto_nomina)
        );
    }
    
    // Validación 2: Anual (OBLIGATORIA)
    $disponibilidad_anual = calcularDisponibilidadAnual($presupuesto_id);
    if ($disponibilidad_anual < $monto_nomina) {
        throw new Exception(
            "BLOQUEADO: Saldo insuficiente en el presupuesto anual. " .
            "Disponible: " . formatearMoneda($disponibilidad_anual) . ", " .
            "Requerido: " . formatearMoneda($monto_nomina)
        );
    }
    
    // Validación 3: Proyección (ADVERTENCIA, no bloquea)
    $proyeccion = calcularProyeccionEjecucion($presupuesto_id, $monto_nomina);
    if ($proyeccion['porcentaje_ejecucion'] > 90) {
        // Advertencia pero permite aprobar
        registrarAdvertencia("Presupuesto cerca del límite: " . $proyeccion['porcentaje_ejecucion'] . "%");
    }
    
    // Si pasa todas las validaciones, aprobar
    return true;
}
```

### **2. Información Detallada para el Aprobador**

```php
// Mostrar información completa al aprobador
function obtenerInformacionValidacionPresupuesto($nomina_id) {
    return [
        'nomina' => [
            'numero' => $nomina['numero'],
            'total_neto' => $nomina['total_neto'],
            'periodo' => $nomina['periodo_codigo']
        ],
        'presupuesto' => [
            'credito_vigente' => $presupuesto['credito_vigente'],
            'comprometido' => $presupuesto['comprometido'],
            'causado' => $presupuesto['causado'],
            'pagado' => $presupuesto['pagado'],
            'disponibilidad_anual' => $disponibilidad_anual,
            'disponibilidad_mes' => $disponibilidad_mes,
            'porcentaje_ejecucion' => $porcentaje_ejecucion
        ],
        'validaciones' => [
            'mensual' => [
                'disponible' => $disponibilidad_mes,
                'requerido' => $nomina['total_neto'],
                'diferencia' => $disponibilidad_mes - $nomina['total_neto'],
                'valido' => $disponibilidad_mes >= $nomina['total_neto']
            ],
            'anual' => [
                'disponible' => $disponibilidad_anual,
                'requerido' => $nomina['total_neto'],
                'diferencia' => $disponibilidad_anual - $nomina['total_neto'],
                'valido' => $disponibilidad_anual >= $nomina['total_neto']
            ]
        ]
    ];
}
```

### **3. Auditoría Completa**

```php
// Registrar toda la información de la aprobación
function registrarAprobacionPresupuesto($nomina_id, $usuario_id, $accion, $observaciones) {
    $info_validacion = obtenerInformacionValidacionPresupuesto($nomina_id);
    
    // Guardar en base de datos
    $sql = "UPDATE nominas SET 
            aprobacion_presupuesto = ?,
            usuario_aprobacion_presupuesto = ?,
            fecha_aprobacion_presupuesto = NOW(),
            comentario_aprobacion_presupuesto = ?,
            validacion_presupuestaria = ?,
            datos_validacion_presupuesto = ?  -- JSON con toda la info
            WHERE id = ?";
    
    $datos_validacion = json_encode([
        'disponibilidad_mensual' => $info_validacion['validaciones']['mensual'],
        'disponibilidad_anual' => $info_validacion['validaciones']['anual'],
        'presupuesto_snapshot' => $info_validacion['presupuesto'],
        'fecha_validacion' => date('Y-m-d H:i:s')
    ]);
    
    // Registrar auditoría
    registrarActualizacion(
        'nominas',
        'nominas',
        $nomina_id,
        ['aprobacion_presupuesto' => 'pendiente'],
        [
            'aprobacion_presupuesto' => $accion,
            'usuario_aprobacion_presupuesto' => $usuario_id,
            'datos_validacion' => $datos_validacion
        ],
        "Aprobación Presupuestaria: " . $accion . ". " . $observaciones
    );
}
```

---

## 📋 Comparación: ¿Nivel 1 Necesario?

### **Opción A: Solo Nivel 2 (Recomendado) ✅**

**Ventajas:**
- ✅ Proceso más ágil
- ✅ RRHH ya valida al generar
- ✅ Menos pasos administrativos
- ✅ Enfoque en lo crítico (presupuesto)

**Cuándo usar:**
- Nóminas con cálculo automático
- RRHH confiable y validado
- Procesos estructurados

---

### **Opción B: Nivel 1 + Nivel 2 (Solo si es necesario)**

**Ventajas:**
- ✅ Doble control
- ✅ Separación de responsabilidades
- ✅ Más auditoría

**Desventajas:**
- ❌ Más pasos administrativos
- ❌ Puede ser redundante
- ❌ Más tiempo de procesamiento

**Cuándo usar:**
- Organizaciones muy grandes
- Requisitos de auditoría estrictos
- Nóminas con variaciones frecuentes

---

## ✅ Recomendación Final

### **Mejor Práctica: SOLO VALIDACIÓN NIVEL 2**

```
┌─────────────────────────────────────────────────────────────┐
│ FLUJO RECOMENDADO PARA NÓMINAS                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ 1. RRHH Genera Nómina                                       │
│    → Estado: borrador                                        │
│    → Validaciones automáticas internas                      │
│                                                              │
│ 2. RRHH Envía a Presupuesto                                 │
│    → Estado: pendiente_validacion_presupuesto                │
│    → Notificación automática                                 │
│                                                              │
│ 3. PRESUPUESTO - Validación Nivel 2                        │
│    → Validación BLOQUEANTE de disponibilidad                │
│    → Validación mensual OBLIGATORIA                         │
│    → Validación anual OBLIGATORIA                            │
│    → Proyección de ejecución (advertencia)                   │
│    → Aprobar o Rechazar                                      │
│    → Estado: aprobada_presupuesto o borrador (rechazada)     │
│                                                              │
│ 4. RRHH Confirma Nómina                                     │
│    → Solo si está aprobada por Presupuesto                  │
│    → Estado: confirmada                                      │
│    → Genera asiento y registra causado                      │
└─────────────────────────────────────────────────────────────┘
```

---

## 🎯 Características Clave de la Validación Nivel 2

### **1. Validaciones Obligatorias:**

- ✅ **Disponibilidad Mensual:** Verificar saldo del mes actual
- ✅ **Disponibilidad Anual:** Verificar saldo del presupuesto anual
- ✅ **Bloqueo Automático:** No permitir aprobar si no hay suficiente

### **2. Información para el Aprobador:**

- 📊 **Dashboard de Presupuesto:** Ver estado completo
- 📈 **Gráficos de Ejecución:** Visualizar tendencias
- ⚠️ **Alertas Preventivas:** Si está cerca del límite
- 📋 **Historial:** Ver nóminas anteriores aprobadas

### **3. Trazabilidad:**

- 👤 **Quién aprobó:** Registro de usuario
- 📅 **Cuándo aprobó:** Fecha y hora
- 💬 **Por qué aprobó:** Comentarios obligatorios
- 📊 **Datos de validación:** Snapshot del presupuesto al momento

### **4. Seguridad:**

- 🔐 **Permisos específicos:** `nominas:aprobar_presupuesto`
- 🔒 **No bypass:** No se puede confirmar sin aprobación
- 📝 **Auditoría completa:** Todo queda registrado

---

## 📊 Tabla Comparativa

| Aspecto | Solo Nivel 2 (Recomendado) | Nivel 1 + Nivel 2 |
|---------|----------------------------|-------------------|
| **Pasos** | 3 pasos | 4 pasos |
| **Tiempo** | Más rápido | Más lento |
| **Complejidad** | Menor | Mayor |
| **Control RRHH** | Automático al generar | Requiere validación manual |
| **Control Presupuesto** | ✅ Explícito y bloqueante | ✅ Explícito y bloqueante |
| **Auditoría** | ✅ Completa | ✅ Más completa |
| **Uso recomendado** | Organizaciones normales | Organizaciones muy grandes |

---

## 🚀 Implementación Recomendada

### **1. Base de Datos:**

```sql
-- Campos necesarios (mínimos)
ALTER TABLE `nominas` 
ADD COLUMN `aprobacion_presupuesto` ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
ADD COLUMN `usuario_aprobacion_presupuesto` INT(11) NULL,
ADD COLUMN `fecha_aprobacion_presupuesto` DATETIME NULL,
ADD COLUMN `comentario_aprobacion_presupuesto` TEXT NULL,
ADD COLUMN `validacion_presupuestaria` ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
ADD COLUMN `datos_validacion_presupuesto` JSON NULL; -- Snapshot de validación
```

### **2. Permisos:**

```sql
-- Solo un permiso necesario
INSERT INTO permisos (modulo, accion, descripcion) 
VALUES ('nominas', 'aprobar_presupuesto', 'Aprobar nóminas desde Presupuesto');
```

### **3. Validaciones:**

```php
// Validación estricta y bloqueante
function validarPresupuestoNivel2($nomina_id) {
    // 1. Validar mensual (OBLIGATORIA)
    // 2. Validar anual (OBLIGATORIA)
    // 3. Proyección (ADVERTENCIA)
    // Si falla cualquiera → BLOQUEAR
}
```

---

## ✅ Conclusión

**La mejor práctica para nóminas es:**

1. ✅ **SOLO Validación Nivel 2** (Presupuesto)
2. ✅ **Validación BLOQUEANTE** (no permite aprobar sin presupuesto)
3. ✅ **Validación MENSUAL y ANUAL** obligatorias
4. ✅ **Auditoría completa** de quién, cuándo y por qué
5. ✅ **Información detallada** para el aprobador

**No se recomienda Nivel 1** porque:
- RRHH ya valida al generar
- Los cálculos son automáticos
- No hay documentos externos que validar
- Agrega complejidad sin beneficio real

**El enfoque debe estar en:**
- Control presupuestario estricto
- Validación explícita y auditable
- Proceso ágil pero seguro

---

**Última actualización:** Mejores prácticas para validación nivel 2
**Versión:** 1.0

