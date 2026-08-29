# Sistema de Aprobación Presupuestaria para Nóminas

## 📋 Análisis del Estado Actual

### **Situación Actual de Nóminas:**

Actualmente, las nóminas tienen un flujo **simplificado**:

```
┌─────────────────────────────────────────────────────────┐
│ ESTADO ACTUAL DE NÓMINAS                                │
├─────────────────────────────────────────────────────────┤
│ 1. Generar Nómina                                       │
│    → Estado: "borrador"                                 │
│                                                          │
│ 2. Confirmar Nómina                                     │
│    → Validación automática de presupuesto               │
│    → Si hay disponibilidad → Se confirma directamente   │
│    → Estado: "confirmada"                               │
│    → Genera asiento contable                            │
│    → Registra como causado                              │
└─────────────────────────────────────────────────────────┘
```

**Problema:** No hay **aprobación explícita** del área de Presupuesto. El sistema solo valida automáticamente, pero no hay un paso de aprobación manual.

---

### **Comparación con Requisiciones:**

Las requisiciones tienen un sistema de **doble aprobación**:

```
┌─────────────────────────────────────────────────────────┐
│ FLUJO DE REQUISICIONES (CON APROBACIÓN)                │
├─────────────────────────────────────────────────────────┤
│ 1. Crear Requisición                                    │
│    → Estado: "enviada"                                  │
│                                                          │
│ 2. Aprobación Nivel 1 (Registro y Control)             │
│    → Permiso: requisiciones:aprobar_nivel_1              │
│    → Estado: "pendiente_nivel_2"                        │
│    → Aprobación: aprobacion_nivel_1 = 'aprobada'         │
│                                                          │
│ 3. Aprobación Nivel 2 (Presupuesto)                     │
│    → Permiso: requisiciones:aprobar_nivel_2              │
│    → VALIDA PRESUPUESTO ANTES DE APROBAR                │
│    → Si no hay suficiente → BLOQUEA aprobación          │
│    → Estado: "aprobada"                                  │
│    → Aprobación: aprobacion_nivel_2 = 'aprobada'        │
│    → Genera compromiso automáticamente                  │
└─────────────────────────────────────────────────────────┘
```

---

## ✅ Propuesta: Implementar Aprobación Presupuestaria para Nóminas

### **Flujo Propuesto:**

```
┌─────────────────────────────────────────────────────────┐
│ NUEVO FLUJO DE NÓMINAS CON APROBACIÓN                  │
├─────────────────────────────────────────────────────────┤
│ 1. Generar Nómina                                       │
│    → Estado: "borrador"                                 │
│                                                          │
│ 2. Enviar a Aprobación Presupuestaria                  │
│    → Estado: "pendiente_aprobacion_presupuesto"          │
│    → Solo usuarios con permiso pueden enviar           │
│                                                          │
│ 3. Aprobación Presupuestaria                            │
│    → Permiso: nominas:aprobar_presupuesto                │
│    → VALIDA PRESUPUESTO ANTES DE APROBAR                │
│    → Si no hay suficiente → RECHAZA o BLOQUEA          │
│    → Si hay suficiente → APRUEBA                        │
│    → Estado: "aprobada_presupuesto"                      │
│    → Aprobación: aprobacion_presupuesto = 'aprobada'     │
│                                                          │
│ 4. Confirmar Nómina (RRHH)                              │
│    → Solo si está aprobada por presupuesto              │
│    → Estado: "confirmada"                                │
│    → Genera asiento contable                            │
│    → Registra como causado                              │
└─────────────────────────────────────────────────────────┘
```

---

## 🔧 Cambios Necesarios en Base de Datos

### **1. Modificar Tabla `nominas`**

```sql
-- Agregar campos de aprobación presupuestaria
ALTER TABLE `nominas` 
ADD COLUMN `aprobacion_presupuesto` ENUM('pendiente', 'aprobada', 'rechazada') 
    DEFAULT 'pendiente' AFTER `estado`,
ADD COLUMN `usuario_aprobacion_presupuesto` INT(11) NULL DEFAULT NULL 
    AFTER `aprobacion_presupuesto`,
ADD COLUMN `fecha_aprobacion_presupuesto` DATETIME NULL DEFAULT NULL 
    AFTER `usuario_aprobacion_presupuesto`,
ADD COLUMN `comentario_aprobacion_presupuesto` TEXT NULL DEFAULT NULL 
    AFTER `fecha_aprobacion_presupuesto`,
ADD COLUMN `validacion_presupuestaria` ENUM('pendiente', 'aprobada', 'rechazada') 
    DEFAULT 'pendiente' AFTER `comentario_aprobacion_presupuesto`;

-- Modificar enum de estado para incluir nuevo estado
ALTER TABLE `nominas` 
MODIFY COLUMN `estado` ENUM('borrador', 'pendiente_aprobacion_presupuesto', 'aprobada_presupuesto', 'confirmada', 'anulada') 
    NOT NULL DEFAULT 'borrador';

-- Agregar índices
CREATE INDEX `idx_aprobacion_presupuesto` ON `nominas` (`aprobacion_presupuesto`, `estado`);
CREATE INDEX `idx_usuario_aprobacion` ON `nominas` (`usuario_aprobacion_presupuesto`);
```

---

## 📝 Archivos a Crear/Modificar

### **1. Nuevo Archivo: `modulos/nominas/aprobar_presupuesto.php`**

Similar a `modulos/requisiciones/aprobar_doble_llave.php`, pero adaptado para nóminas.

```php
<?php
require_once __DIR__ . '/../../includes/verificar_sesion.php';
require_once __DIR__ . '/../../includes/funciones_contables.php';
require_once __DIR__ . '/../../includes/util_nomina.php';
require_once __DIR__ . '/../../config/database/database.php';

// Función para responder con JSON
function responderJSON($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Verificar permisos
if (!verificarPermiso('nominas', 'aprobar_presupuesto')) {
    responderJSON(false, 'No tiene permisos para aprobar nóminas desde presupuesto');
}

$conn = getConnection();
$usuario_id = $_SESSION['usuario_id'];

// Procesar aprobación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomina_id = (int)$_POST['nomina_id'];
    $accion = $_POST['accion']; // 'aprobar' o 'rechazar'
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    try {
        // Obtener datos de la nómina
        $nomina = fetchOne("SELECT * FROM nominas WHERE id = :id", [':id' => $nomina_id]);
        
        if (!$nomina) {
            throw new Exception('Nómina no encontrada');
        }
        
        // Validar estado: debe estar pendiente de aprobación
        if ($nomina['estado'] !== 'pendiente_aprobacion_presupuesto') {
            throw new Exception("La nómina debe estar en estado 'pendiente_aprobacion_presupuesto' para aprobación presupuestaria. Estado actual: '{$nomina['estado']}'");
        }
        
        $conn->beginTransaction();
        
        $estado_aprobacion = ($accion === 'aprobar') ? 'aprobada' : 'rechazada';
        
        if ($accion === 'aprobar') {
            // VALIDAR SALDO PRESUPUESTARIO ANTES DE APROBAR
            $totalNeto = (float)$nomina['total_neto'];
            
            // Obtener período contable activo
            $periodo_contable_id = obtenerPeriodoActivo();
            
            // Validar presupuesto
            $validacion_presupuesto = validarPresupuestoNomina($totalNeto, $periodo_contable_id);
            
            if (!$validacion_presupuesto['valido']) {
                throw new Exception('No se puede aprobar la nómina: ' . $validacion_presupuesto['mensaje']);
            }
            
            // Actualizar estado de la nómina
            $sql = "UPDATE nominas SET 
                    aprobacion_presupuesto = 'aprobada', 
                    usuario_aprobacion_presupuesto = ?, 
                    fecha_aprobacion_presupuesto = NOW(),
                    estado = 'aprobada_presupuesto',
                    validacion_presupuestaria = 'aprobada',
                    comentario_aprobacion_presupuesto = ?,
                    presupuesto_id = ?
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $usuario_id, 
                $observaciones ?: 'Aprobación presupuestaria',
                $validacion_presupuesto['presupuesto_id'],
                $nomina_id
            ]);
            
            // Registrar auditoría
            try {
                registrarActualizacion(
                    'nominas',
                    'nominas',
                    $nomina_id,
                    [
                        'estado' => $nomina['estado'],
                        'aprobacion_presupuesto' => 'pendiente'
                    ],
                    [
                        'estado' => 'aprobada_presupuesto',
                        'aprobacion_presupuesto' => 'aprobada',
                        'presupuesto_id' => $validacion_presupuesto['presupuesto_id']
                    ],
                    'Nómina aprobada por Presupuesto. ' . $observaciones
                );
            } catch (Exception $e) {
                error_log("Error en auditoría: " . $e->getMessage());
            }
            
            $mensaje = 'Nómina aprobada por Presupuesto. Disponible para confirmación.';
            
        } else {
            // Rechazar
            $sql = "UPDATE nominas SET 
                    aprobacion_presupuesto = 'rechazada', 
                    usuario_aprobacion_presupuesto = ?, 
                    fecha_aprobacion_presupuesto = NOW(),
                    estado = 'borrador',
                    comentario_aprobacion_presupuesto = ?
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $usuario_id,
                $observaciones ?: 'Rechazada por Presupuesto',
                $nomina_id
            ]);
            
            // Registrar auditoría
            try {
                registrarActualizacion(
                    'nominas',
                    'nominas',
                    $nomina_id,
                    [
                        'estado' => $nomina['estado'],
                        'aprobacion_presupuesto' => 'pendiente'
                    ],
                    [
                        'estado' => 'borrador',
                        'aprobacion_presupuesto' => 'rechazada'
                    ],
                    'Nómina rechazada por Presupuesto. ' . $observaciones
                );
            } catch (Exception $e) {
                error_log("Error en auditoría: " . $e->getMessage());
            }
            
            $mensaje = 'Nómina rechazada por Presupuesto.';
        }
        
        $conn->commit();
        responderJSON(true, $mensaje, ['nomina_id' => $nomina_id]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        responderJSON(false, $e->getMessage());
    }
}

responderJSON(false, 'Método no permitido');
```

---

### **2. Modificar `includes/util_nomina.php`**

#### **A. Agregar función para enviar a aprobación:**

```php
/**
 * Enviar nómina a aprobación presupuestaria
 */
function enviarNominaAprobacionPresupuesto($nomina_id) {
    if (!rrhh_tienePermiso('generar')) {
        throw new Exception('No tiene permisos para enviar nóminas a aprobación');
    }
    
    $conn = getConnection();
    $nomina = fetchOne("SELECT * FROM nominas WHERE id = :id", [':id' => $nomina_id]);
    
    if (!$nomina) {
        throw new Exception('Nómina no encontrada');
    }
    
    if ($nomina['estado'] !== 'borrador') {
        throw new Exception('Solo se pueden enviar nóminas en estado borrador');
    }
    
    // Actualizar estado
    executeQuery(
        "UPDATE nominas SET 
         estado = 'pendiente_aprobacion_presupuesto',
         aprobacion_presupuesto = 'pendiente'
         WHERE id = :id",
        [':id' => $nomina_id]
    );
    
    // Registrar auditoría
    try {
        registrarActualizacion(
            'nominas',
            'nominas',
            $nomina_id,
            ['estado' => 'borrador'],
            ['estado' => 'pendiente_aprobacion_presupuesto'],
            'Nómina enviada a aprobación presupuestaria'
        );
    } catch (Exception $e) {}
    
    return true;
}
```

#### **B. Modificar `confirmarNomina()` para requerir aprobación:**

```php
function confirmarNomina($nomina_id) {
    // ... código existente ...
    
    // VALIDAR QUE ESTÉ APROBADA POR PRESUPUESTO
    if ($nomina['aprobacion_presupuesto'] !== 'aprobada' || 
        $nomina['estado'] !== 'aprobada_presupuesto') {
        throw new Exception('La nómina debe estar aprobada por Presupuesto antes de confirmar');
    }
    
    // ... resto del código existente ...
}
```

---

### **3. Modificar `modulos/nominas/gestion_nominas.php`**

#### **A. Agregar botón "Enviar a Presupuesto":**

```php
<?php if ($n['estado']==='borrador' && verificarPermiso('nominas','generar')): ?>
  <button class="btn btn-outline-warning" 
          onclick="enviarAprobacionPresupuesto(<?php echo (int)$n['id']; ?>)"
          title="Enviar a Aprobación Presupuestaria">
    <i class="fas fa-paper-plane"></i> Enviar a Presupuesto
  </button>
<?php endif; ?>

<?php if ($n['estado']==='aprobada_presupuesto' && verificarPermiso('nominas','confirmar')): ?>
  <a href="?accion=confirmar&id=<?php echo (int)$n['id']; ?>" 
     class="btn btn-outline-success" 
     title="Confirmar Nómina">
    <i class="fas fa-check"></i> Confirmar
  </a>
<?php endif; ?>
```

#### **B. Agregar JavaScript:**

```javascript
async function enviarAprobacionPresupuesto(nominaId) {
    if (!confirm('¿Enviar esta nómina a aprobación presupuestaria?')) {
        return;
    }
    
    try {
        const response = await fetch('enviar_aprobacion_presupuesto.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                nomina_id: nominaId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message);
            location.reload();
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error al enviar: ' + error.message);
    }
}
```

---

### **4. Modificar `modulos/nominas/ver_nomina.php`**

#### **A. Mostrar información de aprobación:**

```php
<?php if ($nomina['estado'] === 'pendiente_aprobacion_presupuesto'): ?>
  <div class="alert alert-warning">
    <i class="fas fa-clock"></i> 
    <strong>Pendiente de Aprobación Presupuestaria</strong>
    <p>Esta nómina está esperando aprobación del área de Presupuesto.</p>
  </div>
  
  <?php if (verificarPermiso('nominas','aprobar_presupuesto')): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAprobarPresupuesto">
      <i class="fas fa-check"></i> Aprobar desde Presupuesto
    </button>
    <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalRechazarPresupuesto">
      <i class="fas fa-times"></i> Rechazar
    </button>
  <?php endif; ?>
<?php endif; ?>

<?php if ($nomina['aprobacion_presupuesto'] === 'aprobada'): ?>
  <div class="alert alert-success">
    <i class="fas fa-check-circle"></i> 
    <strong>Aprobada por Presupuesto</strong>
    <p>Aprobada por: <?php echo htmlspecialchars($nomina['aprobador_presupuesto'] ?? 'N/A'); ?></p>
    <p>Fecha: <?php echo $nomina['fecha_aprobacion_presupuesto'] ? date('d/m/Y H:i', strtotime($nomina['fecha_aprobacion_presupuesto'])) : 'N/A'; ?></p>
    <?php if ($nomina['comentario_aprobacion_presupuesto']): ?>
      <p>Comentario: <?php echo htmlspecialchars($nomina['comentario_aprobacion_presupuesto']); ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>
```

---

### **5. Nuevo Archivo: `modulos/nominas/enviar_aprobacion_presupuesto.php`**

```php
<?php
require_once __DIR__ . '/../../includes/verificar_sesion.php';
require_once __DIR__ . '/../../includes/util_nomina.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    if (!verificarPermiso('nominas','generar')) {
        throw new Exception('No tiene permisos para enviar nóminas a aprobación');
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $nomina_id = (int)($data['nomina_id'] ?? 0);
    
    if ($nomina_id <= 0) {
        throw new Exception('ID de nómina inválido');
    }
    
    enviarNominaAprobacionPresupuesto($nomina_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Nómina enviada a aprobación presupuestaria correctamente'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
```

---

## 🔐 Permisos Necesarios

### **Agregar nuevos permisos:**

```sql
-- Permiso para aprobar nóminas desde presupuesto
INSERT INTO permisos (modulo, accion, descripcion) 
VALUES ('nominas', 'aprobar_presupuesto', 'Aprobar nóminas desde área de Presupuesto');

-- Asignar permisos a usuarios del área de Presupuesto
-- (Ejemplo: usuario_id = 5 para usuario de Presupuesto)
INSERT INTO usuario_permisos (usuario_id, permiso_id) 
SELECT 5, id FROM permisos 
WHERE modulo = 'nominas' AND accion = 'aprobar_presupuesto';
```

---

## 📊 Estados del Nuevo Flujo

### **Estados de Nómina:**

```
borrador
  ↓ (enviar a aprobación)
pendiente_aprobacion_presupuesto
  ↓ (aprobar presupuesto)
aprobada_presupuesto
  ↓ (confirmar)
confirmada
```

### **Estados de Aprobación:**

```
aprobacion_presupuesto:
  - pendiente (recién enviada)
  - aprobada (aprobada por presupuesto)
  - rechazada (rechazada por presupuesto)
```

---

## ✅ Checklist de Implementación

### **Fase 1: Base de Datos**
- [ ] Modificar tabla `nominas` con campos de aprobación
- [ ] Agregar índices necesarios
- [ ] Crear permisos nuevos

### **Fase 2: Backend**
- [ ] Crear `aprobar_presupuesto.php`
- [ ] Crear `enviar_aprobacion_presupuesto.php`
- [ ] Modificar `confirmarNomina()` para requerir aprobación
- [ ] Agregar función `enviarNominaAprobacionPresupuesto()`

### **Fase 3: Frontend**
- [ ] Modificar `gestion_nominas.php` con botones de aprobación
- [ ] Modificar `ver_nomina.php` con información de aprobación
- [ ] Agregar modales de aprobación/rechazo
- [ ] Agregar JavaScript para funciones AJAX

### **Fase 4: Testing**
- [ ] Probar envío a aprobación
- [ ] Probar aprobación desde presupuesto
- [ ] Probar rechazo desde presupuesto
- [ ] Probar confirmación después de aprobación
- [ ] Validar que no se puede confirmar sin aprobación

---

## 🎯 Beneficios del Nuevo Sistema

1. ✅ **Control Presupuestario:** El área de Presupuesto tiene control explícito
2. ✅ **Trazabilidad:** Se registra quién aprobó y cuándo
3. ✅ **Consistencia:** Mismo flujo que requisiciones
4. ✅ **Seguridad:** Permisos específicos para aprobación
5. ✅ **Auditoría:** Historial completo de aprobaciones

---

## 📝 Notas Importantes

1. **Compatibilidad:** Las nóminas existentes en estado "confirmada" seguirán funcionando
2. **Migración:** Nóminas en "borrador" pueden enviarse a aprobación
3. **Permisos:** Asegurar que usuarios de Presupuesto tengan el permiso `nominas:aprobar_presupuesto`

---

**Última actualización:** Propuesta de implementación
**Versión:** 1.0

