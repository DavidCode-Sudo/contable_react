# Distribución Presupuestaria de Nóminas

## Resumen Ejecutivo

Actualmente, el sistema tiene parcialmente implementada la asignación de presupuesto a nóminas, pero **NO distribuye las deducciones (IVSS, FAOV, etc.) a diferentes partidas presupuestarias**. Solo registra el total neto en una sola partida.

## Estado Actual

### ✅ Lo que SÍ tenemos:

1. **Asignación de presupuesto a nómina**: 
   - El código intenta insertar `presupuesto_id` en la tabla `nominas`
   - Se valida disponibilidad presupuestaria antes de confirmar
   - Se registra el total neto de la nómina en el presupuesto como "causado"

2. **Conceptos de nómina**:
   - Tabla `conceptos_nomina` con tipos (percepción/deducción)
   - Tabla `empleados_conceptos` para asignar conceptos a empleados
   - Tabla `nomina_detalle` que guarda el detalle de cada concepto

### ❌ Lo que NO tenemos:

1. **Campo `presupuesto_id` en tabla `nominas`**:
   - La tabla `nominas` NO tiene el campo `presupuesto_id` en su definición
   - El código intenta usarlo pero fallará en la inserción

2. **Distribución de deducciones a diferentes partidas**:
   - Las deducciones (IVSS, FAOV, ISLR, etc.) NO se distribuyen a sus respectivas partidas presupuestarias
   - El asiento contable actual solo registra:
     - **DEBE**: Gasto de Nómina (total neto) → Partida 401 (Gastos de Personal)
     - **HABER**: Sueldos por Pagar (total neto)
   - **Falta**: Distribuir cada deducción a su cuenta/presupuesto correspondiente

3. **Mapeo de conceptos a presupuestos**:
   - No existe una tabla que asocie cada concepto de deducción con su presupuesto correspondiente
   - Ejemplo: IVSS debería ir a partida específica, FAOV a otra, etc.

## Lo que se necesita implementar

### 1. Agregar campo `presupuesto_id` a tabla `nominas`

```sql
ALTER TABLE `nominas` 
ADD COLUMN `presupuesto_id` INT(11) NULL DEFAULT NULL AFTER `periodo_id`,
ADD COLUMN `asiento_id` INT(11) NULL DEFAULT NULL AFTER `estado`,
ADD INDEX `idx_nominas_presupuesto` (`presupuesto_id`),
ADD INDEX `idx_nominas_asiento` (`asiento_id`);
```

### 2. Crear tabla de mapeo: Conceptos → Presupuestos

```sql
CREATE TABLE `conceptos_nomina_presupuestos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `concepto_id` INT(11) NOT NULL COMMENT 'ID del concepto de nómina',
  `presupuesto_id` INT(11) NOT NULL COMMENT 'ID del presupuesto donde se registra esta deducción',
  `cuenta_id` INT(11) NULL DEFAULT NULL COMMENT 'ID de la cuenta contable asociada',
  `tipo_distribucion` ENUM('deduccion_pasivo', 'aporte_empleador', 'retencion') NOT NULL DEFAULT 'deduccion_pasivo',
  `descripcion` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
  `creado_en` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_concepto_presupuesto` (`concepto_id`, `presupuesto_id`),
  KEY `idx_concepto` (`concepto_id`),
  KEY `idx_presupuesto` (`presupuesto_id`),
  KEY `idx_cuenta` (`cuenta_id`),
  CONSTRAINT `fk_cnp_concepto` FOREIGN KEY (`concepto_id`) REFERENCES `conceptos_nomina` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cnp_presupuesto` FOREIGN KEY (`presupuesto_id`) REFERENCES `presupuestos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cnp_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `cuentas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. Modificar el asiento contable para distribuir deducciones

**Asiento actual (simple)**:
```
DEBE:  Gasto de Nómina (total neto)        → Presupuesto 401
HABER: Sueldos por Pagar (total neto)
```

**Asiento propuesto (distribuido)**:
```
DEBE:  Gasto de Nómina (total bruto)        → Presupuesto 401
DEBE:  IVSS por Pagar (total IVSS)         → Presupuesto IVSS
DEBE:  FAOV por Pagar (total FAOV)         → Presupuesto FAOV
DEBE:  ISLR por Pagar (total ISLR)         → Presupuesto ISLR
HABER: Sueldos por Pagar (total neto)
HABER: IVSS por Pagar (total IVSS)
HABER: FAOV por Pagar (total FAOV)
HABER: ISLR por Pagar (total ISLR)
```

### 4. Actualizar presupuestos correspondientes

Al confirmar la nómina, se debe:
- Registrar el **total bruto** en el presupuesto de Gastos de Personal (401)
- Registrar cada **deducción** en su presupuesto correspondiente como "causado"

## Ejemplo de flujo completo

### 1. Configuración inicial (una vez):

```sql
-- Asignar presupuesto a IVSS
INSERT INTO conceptos_nomina_presupuestos 
(concepto_id, presupuesto_id, cuenta_id, tipo_distribucion, descripcion)
VALUES 
((SELECT id FROM conceptos_nomina WHERE codigo = 'IVSS'), 
 (SELECT id FROM presupuestos WHERE cuenta_id = (SELECT id FROM cuentas WHERE codigo LIKE '2.1.1.01%' LIMIT 1)),
 (SELECT id FROM cuentas WHERE nombre LIKE '%IVSS%' OR codigo LIKE '2.1.1.01%' LIMIT 1),
 'deduccion_pasivo',
 'Aporte IVSS del empleado');

-- Asignar presupuesto a FAOV
INSERT INTO conceptos_nomina_presupuestos 
(concepto_id, presupuesto_id, cuenta_id, tipo_distribucion, descripcion)
VALUES 
((SELECT id FROM conceptos_nomina WHERE codigo = 'FAOV'), 
 (SELECT id FROM presupuestos WHERE cuenta_id = (SELECT id FROM cuentas WHERE codigo LIKE '2.1.1.02%' LIMIT 1)),
 (SELECT id FROM cuentas WHERE nombre LIKE '%FAOV%' OR codigo LIKE '2.1.1.02%' LIMIT 1),
 'deduccion_pasivo',
 'Aporte Ahorro Habitacional del empleado');
```

### 2. Al generar nómina:

- Se selecciona el presupuesto principal (Gastos de Personal - 401)
- Se calculan todas las deducciones por empleado
- Se agrupan por concepto

### 3. Al confirmar nómina:

- Se genera asiento contable distribuido
- Se actualiza el presupuesto principal con el total bruto
- Se actualiza cada presupuesto de deducción con su monto correspondiente

## Beneficios de la implementación

1. **Trazabilidad completa**: Cada deducción queda registrada en su partida presupuestaria correspondiente
2. **Control presupuestario**: Se puede controlar el presupuesto de cada tipo de aporte por separado
3. **Asientos contables correctos**: Los pasivos se registran en las cuentas correctas
4. **Reportes detallados**: Se pueden generar reportes por tipo de deducción y presupuesto

## Próximos pasos

1. ✅ Crear script SQL para agregar campo `presupuesto_id` a `nominas`
2. ✅ Crear tabla `conceptos_nomina_presupuestos`
3. ✅ Modificar función `confirmarNomina()` para generar asiento distribuido
4. ✅ Crear interfaz para configurar mapeo de conceptos a presupuestos
5. ✅ Actualizar función de registro en presupuesto para distribuir deducciones

