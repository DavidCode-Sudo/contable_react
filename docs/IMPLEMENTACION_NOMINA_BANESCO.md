# Implementación Profesional de Nómina - Formato Banesco

## 📋 Descripción del Formato

El formato mostrado es un **archivo para aportes de ahorro habitacional** que debe enviarse a **Banesco** para procesar los aportes de los empleados y del empleador a los fondos de ahorro habitacional.

### Estructura del Formato

El formato incluye los siguientes campos:

| Campo | Descripción | Fuente en el Sistema |
|-------|-------------|----------------------|
| **CEDULA** | Número de identificación del empleado | `empleados.identificacion` |
| **MTO AHO** | Monto de ahorro aportado por el empleado | Concepto de nómina tipo deducción |
| **MTO EMP** | Monto aportado por el empleador | Concepto de nómina tipo percepción |
| **TOTAL** | Suma de MTO AHO + MTO EMP | Calculado automáticamente |
| **STA** | Estado del aporte (Activo) | Valor fijo: "A" |
| **APELLIDOS Y NOMBRE** | Nombre completo del empleado | `empleados.apellidos + nombres` |
| **SEXO** | Sexo del empleado (M/F/O) | `empleados.sexo` |
| **F NAC** | Fecha de nacimiento | `empleados.fecha_nacimiento` |
| **F INC** | Fecha de incorporación | `empleados.fecha_ingreso` |

## 🚀 Implementación Realizada

### 1. Actualización de Base de Datos

Se agregaron dos nuevos campos a la tabla `empleados`:

```sql
-- Ejecutar el script:
database/scripts/agregar_campos_empleados_nomina.sql
```

**Campos agregados:**
- `sexo`: ENUM('M','F','O') - Sexo del empleado
- `fecha_nacimiento`: DATE - Fecha de nacimiento

> **Nota:** El campo `fecha_ingreso` ya existe y se usa como fecha de incorporación (F INC).

### 2. Actualización del Formulario de Empleados

El formulario de gestión de empleados (`modulos/rrhh/gestion_empleados.php`) ahora incluye:

- **Campo Sexo**: Dropdown con opciones (Masculino, Femenino, Otro)
- **Campo Fecha de Nacimiento**: Input tipo fecha

Estos campos se pueden llenar al crear o editar un empleado.

### 3. Módulo de Exportación

Se creó el archivo `modulos/nominas/exportar_banesco_ahorro.php` que:

1. **Obtiene los datos de la nómina** seleccionada
2. **Busca los conceptos de aporte** de ahorro habitacional
3. **Genera un archivo CSV** en el formato exacto requerido por Banesco
4. **Incluye encabezados** con información del contrato y RIF

**Acceso:**
- Desde la vista de detalle de nómina (`ver_nomina.php`)
- Botón "Exportar Banesco" disponible para todas las nóminas

## ⚙️ Configuración de Conceptos de Nómina

Para que el sistema identifique correctamente los montos de aporte, debes crear **conceptos de nómina** con códigos específicos:

### Concepto 1: Ahorro del Empleado (MTO AHO)

1. Ir a **Conceptos de Nómina** (si existe el módulo) o crear directamente en la base de datos
2. Crear un concepto con:
   - **Tipo**: `deduccion` (se descuenta del salario)
   - **Código**: Contener "AHO" o similar (ej: "AHO-HAB", "AHORRO-EMPLEADO")
   - **Nombre**: "Ahorro Habitacional - Empleado"
   - **Base de cálculo**: `fijo`, `porcentaje_salario`, o `personalizado` según tu caso

### Concepto 2: Aporte del Empleador (MTO EMP)

1. Crear un segundo concepto con:
   - **Tipo**: `percepcion` (se agrega como beneficio)
   - **Código**: Contener "AEM" o similar (ej: "AEM-HAB", "APORTE-EMPLEADOR")
   - **Nombre**: "Aporte Habitacional - Empleador"
   - **Base de cálculo**: Normalmente `personalizado` o porcentaje del ahorro del empleado

### Ejemplo de Código SQL para Crear Conceptos:

```sql
-- Concepto de ahorro del empleado (deducción)
INSERT INTO conceptos_nomina (codigo, nombre, tipo, base_calculo, valor, orden, estado)
VALUES ('AHO-HAB', 'Ahorro Habitacional - Empleado', 'deduccion', 'porcentaje_salario', 5.00, 10, 'activo');

-- Concepto de aporte del empleador (percepción)
INSERT INTO conceptos_nomina (codigo, nombre, tipo, base_calculo, valor, orden, estado)
VALUES ('AEM-HAB', 'Aporte Habitacional - Empleador', 'percepcion', 'personalizado', 0.00, 11, 'activo');
```

### Asignar Conceptos a Empleados:

```sql
-- Asignar ahorro habitacional a un empleado (ejemplo: 5% del salario)
INSERT INTO empleados_conceptos (empleado_id, concepto_id, base_calculo, valor_parametro, cantidad, estado)
SELECT 
    e.id,
    (SELECT id FROM conceptos_nomina WHERE codigo = 'AHO-HAB' LIMIT 1),
    'porcentaje_salario',
    5.00, -- 5% del salario
    1.00,
    'activo'
FROM empleados e
WHERE e.codigo = 'EMP001'; -- Ajustar según el código del empleado

-- Asignar aporte del empleador (ejemplo: igual al ahorro del empleado)
-- Este se puede calcular automáticamente o configurarse por empleado
```

## 📊 Flujo de Trabajo

### 1. Configuración Inicial

1. **Ejecutar el script SQL** para agregar campos a empleados:
   ```bash
   # Desde phpMyAdmin o línea de comandos MySQL
   source database/scripts/agregar_campos_empleados_nomina.sql
   ```

2. **Actualizar datos de empleados existentes**:
   - Ir a **RRHH > Gestión de Empleados**
   - Editar cada empleado y llenar:
     - Sexo
     - Fecha de nacimiento

3. **Crear conceptos de ahorro habitacional**:
   - Crear conceptos en la base de datos (como se mostró arriba)
   - O usar la interfaz de gestión de conceptos si existe

4. **Asignar conceptos a empleados**:
   - Asignar el concepto de ahorro a cada empleado que participe
   - Configurar el monto o porcentaje según corresponda

### 2. Generación de Nómina

1. **Crear período de nómina** (si no existe)
2. **Generar nómina** para el período seleccionado
3. El sistema calculará automáticamente:
   - Ahorro del empleado (si está configurado como deducción)
   - Aporte del empleador (si está configurado como percepción)

### 3. Exportación para Banesco

1. Ir a **Nóminas > Ver Nómina**
2. Seleccionar la nómina que contiene los aportes
3. Click en **"Exportar Banesco"**
4. El sistema generará un archivo CSV con:
   - Encabezados según formato Banesco
   - Datos de todos los empleados con aportes
   - Formato de fechas: dd-mm-yy
   - Separador: punto y coma (;)

5. **Abrir el archivo** en Excel o enviarlo directamente a Banesco

## 🔍 Personalización

### Modificar Números de Contrato y RIF

Edita el archivo `modulos/nominas/exportar_banesco_ahorro.php`:

```php
// Línea ~30
$empresa_rif = 'J000155013'; // Cambiar por tu RIF
$contrato_banesco = '120002915'; // Cambiar por tu número de contrato
```

### Ajustar Búsqueda de Conceptos

El sistema busca conceptos por:
- Código que contenga "AHO" para ahorro del empleado
- Código que contenga "AEM" para aporte del empleador

Puedes ajustar estos criterios en las líneas ~50-60 del archivo `exportar_banesco_ahorro.php`.

### Filtros Adicionales

Si quieres exportar solo empleados que tienen aportes > 0, descomenta la línea:

```php
// if ($total == 0) continue;
```

## ⚠️ Notas Importantes

1. **Fechas**: El formato de fechas es `dd-mm-yy` (ej: 31-ago-25). El sistema convierte automáticamente.

2. **Montos**: Los montos se exportan sin separadores de miles y con punto decimal (ej: 1500.50).

3. **Estado (STA)**: Actualmente se exporta como "A" (Activo) para todos. Puedes personalizar esto según tu lógica de negocio.

4. **Conceptos**: Asegúrate de que los conceptos estén configurados correctamente para cada empleado antes de generar la nómina.

5. **Charset**: El archivo se genera con UTF-8 y BOM para compatibilidad con Excel.

## 🐛 Solución de Problemas

### No aparecen montos en la exportación

- **Verificar** que los conceptos de ahorro estén asignados a los empleados
- **Revisar** que los conceptos tengan los códigos correctos (con "AHO" o "AEM")
- **Confirmar** que la nómina se haya generado después de asignar los conceptos

### Fechas no se muestran correctamente

- **Verificar** que los empleados tengan fecha_nacimiento y fecha_ingreso en la base de datos
- **Formato esperado**: YYYY-MM-DD en la base de datos

### Error al exportar

- **Revisar permisos** del archivo y directorio
- **Verificar** que la nómina exista y tenga empleados asociados
- **Revisar logs** del sistema en `logs/error.log`

## 📝 Ejemplo de Salida

El archivo CSV generado tendrá este formato:

```
banesco - APORTE DE AHORRO HABITACIONAL DE LA FUNDACION OSMC DEL M.L.
CONTRATO:120002915;RIF:J000155013;FECHA:31-ago-25
CAUSA DE PAGO: P;APORTE DE AHORRISTAS

CEDULA;MTO AHO;MTO EMP;TOTAL;STA;APELLIDOS Y NOMBRE;SEXO;F NAC;F INC
12345678;150.00;150.00;300.00;A;PEREZ JUAN;M;15-03-85;01-01-20
87654321;200.00;200.00;400.00;A;GARCIA MARIA;F;20-05-90;15-06-22
```

---

**Última actualización**: 2025-01-31
**Versión**: 1.0

