# Sistema Híbrido de Reglas de Nómina

## Descripción

El sistema híbrido permite configurar reglas de nómina de dos formas:

1. **Reglas por cargo** (plantillas): Se definen una vez por cargo y se heredan automáticamente a todos los empleados con ese cargo
2. **Reglas específicas por empleado**: Se pueden agregar o sobrescribir reglas individuales para casos especiales

## Ventajas del Sistema Híbrido

✅ **Eficiencia**: Define reglas una vez por cargo y aplícalas a múltiples empleados
✅ **Consistencia**: Todos los empleados del mismo cargo tienen las mismas reglas base
✅ **Flexibilidad**: Permite excepciones y reglas adicionales por empleado
✅ **Mantenimiento**: Actualiza las reglas del cargo y se propagan a los empleados
✅ **Claridad**: Se visualiza el origen de cada regla (cargo o empleado)

## Estructura de Base de Datos

### Tablas Nuevas

#### `cargos_conceptos`
Almacena las reglas de nómina asignadas a cada cargo (plantilla).

```sql
- id: identificador único
- cargo_id: referencia al cargo
- concepto_id: referencia al concepto de nómina
- base_calculo: 'fijo', 'porcentaje_salario', 'personalizado'
- valor_parametro: valor del parámetro
- cantidad: factor multiplicador
- estado: 'activo', 'inactivo'
```

### Tablas Modificadas

#### `empleados_conceptos`
Ahora incluye campos para rastrear el origen de la regla:

- `origen`: enum('cargo','empleado') - indica si la regla viene del cargo o es específica del empleado
- `cargo_concepto_id`: referencia a la regla del cargo (si origen='cargo')

## Flujo de Trabajo

### 1. Configurar Reglas por Cargo

1. Ir a **RRHH > Configuración de Nómina por Cargo**
2. Elegir un cargo (ej: "Contador")
3. Asignar reglas de nómina al cargo:
   - Salario Base
   - Prima de Profesionalización
   - SSO (Seguro Social Obligatorio)
   - FAOV (Fondo de Ahorro Obligatorio para la Vivienda)
   - Paro Forzoso
   - Retenciones, etc.

### 2. Aplicar Reglas a Empleados

**Opción A: Aplicación Manual**
1. En el módulo de cargo, clic en "Aplicar a Empleados"
2. Elegir si sobrescribir reglas existentes o no
3. Las reglas del cargo se copian a todos los empleados con ese cargo

**Opción B: Aplicación Automática** (próximamente)
Cuando se asigna un cargo a un empleado, las reglas se heredan automáticamente

### 3. Personalizar por Empleado

En **RRHH > Configuración de Nómina por Empleado**:

- **Ver reglas heredadas**: marcadas como "Origen: Cargo"
- **Agregar reglas adicionales**: específicas del empleado
- **Sobrescribir reglas**: cambiar valores de reglas heredadas (convierte origen a 'empleado')

### 4. Sincronización (próximamente)

Cuando actualizas una regla en el cargo:
- Se puede optar por sincronizar con todos los empleados que la tienen heredada
- Las reglas modificadas manualmente por empleado no se sobrescriben

## Casos de Uso

### Caso 1: Configuración Estándar por Cargo

**Escenario**: Tienes 20 empleados con cargo "Administrativo" que deben tener las mismas reglas.

**Solución**:
1. Define las reglas en el cargo "Administrativo"
2. Aplica las reglas a todos los empleados administrativos
3. Futuras contrataciones heredarán automáticamente las reglas

### Caso 2: Empleado con Bonificación Especial

**Escenario**: Un empleado tiene una bonificación especial por antigüedad.

**Solución**:
1. El empleado hereda las reglas base del cargo
2. Agregas manualmente la regla "Bonificación Antigüedad" solo para ese empleado
3. El empleado tiene reglas del cargo + reglas específicas

### Caso 3: Ajuste Salarial Individual

**Escenario**: Un empleado negocia una reducción en la retención de FAOV.

**Solución**:
1. Editas la regla FAOV solo para ese empleado
2. La regla cambia de origen:'cargo' a origen:'empleado'
3. Futuras actualizaciones de FAOV en el cargo no afectan a este empleado

## Prioridad de Reglas

**Regla de Prioridad**: Empleado > Cargo

- Si existe una regla con origen='empleado', prevalece sobre la del cargo
- Si existe solo con origen='cargo', se usa la del cargo
- Si no existe ni en cargo ni en empleado, no se aplica

## Instalación

### Paso 1: Ejecutar Script SQL

```bash
# Ejecutar el script de instalación
mysql -u usuario -p nombre_bd < database/scripts/agregar_sistema_hibrido_nominas.sql
```

O desde phpMyAdmin:
1. Importar el archivo `database/scripts/agregar_sistema_hibrido_nominas.sql`

### Paso 2: Verificar Instalación

El script crea:
- Tabla `cargos_conceptos`
- Campos `origen` y `cargo_concepto_id` en `empleados_conceptos`
- Índices y foreign keys

### Paso 3: Configurar Primer Cargo

1. Ir a **Configuración de Nómina por Cargo**
2. Elegir un cargo de prueba
3. Asignar las reglas básicas
4. Aplicar a un empleado de prueba
5. Verificar que el empleado tenga las reglas heredadas

## Interfaz de Usuario

### Visualización en Tabla de Empleados

```
Regla              | Tipo    | Origen    | Valor
-------------------|---------|-----------|--------
Salario Base       | Percep. | Cargo     | 1000.00
Bonificación Esp.  | Percep. | Empleado  | 200.00  ← Específica
SSO                | Deducc. | Cargo     | 4%
```

### Indicadores Visuales

- 🏢 **Badge "Cargo"**: Regla heredada del cargo
- 👤 **Badge "Empleado"**: Regla específica del empleado
- 🔄 **Icono Sincronizar**: Permite actualizar reglas heredadas

## Mantenimiento

### Actualizar Reglas de un Cargo

1. Modificar reglas en "Configuración de Nómina por Cargo"
2. Clic en "Sincronizar con Empleados" (opcional)
3. Elegir si afecta solo reglas no modificadas o todas

### Auditoría

Todas las operaciones quedan registradas en `auditoria`:
- Creación de reglas por cargo
- Aplicación masiva a empleados
- Modificaciones individuales

## Mejores Prácticas

1. **Define primero los cargos**: Antes de asignar empleados, configura las reglas del cargo
2. **Usa herencia para reglas comunes**: No dupliques configuraciones repetitivas
3. **Documenta excepciones**: Deja comentarios cuando sobrescribas reglas
4. **Revisa periódicamente**: Verifica que las reglas heredadas sigan vigentes
5. **Sincroniza cambios importantes**: Cuando actualices legislación (ej: nuevo % de SSO), sincroniza con empleados

## Próximas Mejoras

- [ ] Herencia automática al asignar cargo a nuevo empleado
- [ ] Sincronización selectiva de reglas del cargo
- [ ] Historial de cambios en reglas heredadas
- [ ] Comparador de reglas entre cargo y empleado
- [ ] Exportación de configuración de cargo como plantilla

## Soporte

Para dudas o problemas, consultar:
- `GUIA_COMPLETA_REGISTRAR_NOMINA.md`
- `COMO_CONFIGURAR_ASIGNACION_EMPLEADO.md`
- `GUIA_REGLAS_NOMINA_ALCALDIA.md`

