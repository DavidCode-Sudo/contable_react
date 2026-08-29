# Implementación de Distribución Presupuestaria de Nóminas - COMPLETADA

## ✅ Lo que se implementó

### 1. Tabla de Mapeo Creada
- ✅ Tabla `conceptos_nomina_presupuestos` creada
- Permite mapear cada concepto de deducción a su presupuesto correspondiente

### 2. Funciones PHP Agregadas

#### `obtenerDistribucionDeduccionesNomina($nomina_id)`
- Obtiene todas las deducciones de una nómina agrupadas por concepto
- Busca el presupuesto asociado a cada concepto desde `conceptos_nomina_presupuestos`
- Retorna array con información completa de distribución

#### `registrarDeduccionEnPresupuesto($presupuesto_id, $monto, $descripcion)`
- Registra cada deducción en su presupuesto correspondiente
- Actualiza el campo `causado` y `por_pagar` del presupuesto

### 3. Función `confirmarNomina()` Modificada

**Cambios principales:**

1. **Cálculo de total bruto**: Ahora calcula el total bruto (salarios + percepciones) además del neto

2. **Asiento contable distribuido**:
   - **DEBE**: Gasto de Nómina (total bruto) → Presupuesto principal
   - **HABER**: Sueldos por Pagar (total neto)
   - **HABER**: Cada deducción en su cuenta correspondiente (IVSS, FAOV, ISLR, etc.)

3. **Registro en presupuestos**:
   - Presupuesto principal: se registra el **total bruto** (gasto completo)
   - Cada presupuesto de deducción: se registra su monto correspondiente

## 📋 Próximos pasos (Configuración)

### Paso 1: Verificar campo `presupuesto_id` en tabla `nominas`

Ejecuta esto para verificar:

```sql
SHOW COLUMNS FROM nominas LIKE 'presupuesto_id';
```

Si no existe, ejecuta:

```sql
ALTER TABLE `nominas` 
ADD COLUMN `presupuesto_id` INT(11) NULL DEFAULT NULL AFTER `periodo_id`,
ADD INDEX `idx_nominas_presupuesto` (`presupuesto_id`);
```

### Paso 2: Configurar mapeo de conceptos a presupuestos

Para cada concepto de deducción (IVSS, FAOV, ISLR, etc.), debes:

1. **Identificar el presupuesto correspondiente**:
   ```sql
   -- Ejemplo: Buscar presupuesto para IVSS (partida 2.1.1.01)
   SELECT p.id, c.codigo, c.nombre 
   FROM presupuestos p
   INNER JOIN cuentas c ON p.cuenta_id = c.id
   WHERE c.codigo LIKE '2.1.1.01%' 
   AND c.es_partida_presupuestaria = 1;
   ```

2. **Identificar la cuenta contable del pasivo**:
   ```sql
   -- Ejemplo: Buscar cuenta "IVSS por Pagar"
   SELECT id, codigo, nombre 
   FROM cuentas 
   WHERE (nombre LIKE '%IVSS%' OR nombre LIKE '%Seguro Social%') 
   AND tipo = 'pasivo';
   ```

3. **Insertar en `conceptos_nomina_presupuestos`**:
   ```sql
   INSERT INTO conceptos_nomina_presupuestos 
   (concepto_id, presupuesto_id, cuenta_id, tipo_distribucion, descripcion, estado)
   VALUES 
   (
       (SELECT id FROM conceptos_nomina WHERE codigo = 'IVSS'),
       [ID_DEL_PRESUPUESTO],
       [ID_DE_LA_CUENTA],
       'deduccion_pasivo',
       'Aporte IVSS del empleado',
       'activo'
   );
   ```

### Paso 3: Ejemplo completo de configuración

```sql
-- Configurar IVSS
SET @concepto_ivss = (SELECT id FROM conceptos_nomina WHERE codigo = 'IVSS' LIMIT 1);
SET @presupuesto_ivss = (SELECT p.id FROM presupuestos p 
                         INNER JOIN cuentas c ON p.cuenta_id = c.id 
                         WHERE c.codigo LIKE '2.1.1.01%' LIMIT 1);
SET @cuenta_ivss = (SELECT id FROM cuentas 
                    WHERE nombre LIKE '%IVSS%' AND tipo = 'pasivo' LIMIT 1);

INSERT INTO conceptos_nomina_presupuestos 
(concepto_id, presupuesto_id, cuenta_id, tipo_distribucion, descripcion, estado)
VALUES (@concepto_ivss, @presupuesto_ivss, @cuenta_ivss, 'deduccion_pasivo', 'Aporte IVSS', 'activo')
ON DUPLICATE KEY UPDATE 
    presupuesto_id = @presupuesto_ivss,
    cuenta_id = @cuenta_ivss,
    estado = 'activo';

-- Configurar FAOV
SET @concepto_faov = (SELECT id FROM conceptos_nomina WHERE codigo = 'FAOV' LIMIT 1);
SET @presupuesto_faov = (SELECT p.id FROM presupuestos p 
                         INNER JOIN cuentas c ON p.cuenta_id = c.id 
                         WHERE c.codigo LIKE '2.1.1.02%' LIMIT 1);
SET @cuenta_faov = (SELECT id FROM cuentas 
                    WHERE nombre LIKE '%FAOV%' AND tipo = 'pasivo' LIMIT 1);

INSERT INTO conceptos_nomina_presupuestos 
(concepto_id, presupuesto_id, cuenta_id, tipo_distribucion, descripcion, estado)
VALUES (@concepto_faov, @presupuesto_faov, @cuenta_faov, 'deduccion_pasivo', 'Aporte Ahorro Habitacional', 'activo')
ON DUPLICATE KEY UPDATE 
    presupuesto_id = @presupuesto_faov,
    cuenta_id = @cuenta_faov,
    estado = 'activo';
```

## 📊 Ejemplo de Asiento Contable Generado

**Antes (simple)**:
```
DEBE:  Gasto de Nómina        150,000.00
HABER: Sueldos por Pagar      150,000.00
```

**Ahora (distribuido)**:
```
DEBE:  Gasto de Nómina        150,000.00
HABER: Sueldos por Pagar      120,000.00
HABER: IVSS por Pagar          18,000.00
HABER: FAOV por Pagar           4,500.00
HABER: ISLR por Pagar           7,500.00
```

## 🔍 Verificación

Para verificar que funciona:

1. **Ver distribuciones configuradas**:
   ```sql
   SELECT cnp.*, c.codigo AS concepto_codigo, c.nombre AS concepto_nombre,
          p.id AS presupuesto_id, cu.codigo AS cuenta_codigo, cu.nombre AS cuenta_nombre
   FROM conceptos_nomina_presupuestos cnp
   INNER JOIN conceptos_nomina c ON cnp.concepto_id = c.id
   INNER JOIN presupuestos p ON cnp.presupuesto_id = p.id
   LEFT JOIN cuentas cu ON cnp.cuenta_id = cu.id
   WHERE cnp.estado = 'activo';
   ```

2. **Ver distribuciones de una nómina específica** (desde PHP):
   ```php
   $distribuciones = obtenerDistribucionDeduccionesNomina($nomina_id);
   print_r($distribuciones);
   ```

## ✅ Estado Final

- ✅ Tabla creada
- ✅ Funciones PHP implementadas
- ✅ Lógica de distribución integrada
- ⚠️ **Falta**: Configurar mapeo de conceptos a presupuestos (manual según tu estructura contable)

Una vez configurado el mapeo, al confirmar una nómina se distribuirán automáticamente las deducciones a sus presupuestos correspondientes.

