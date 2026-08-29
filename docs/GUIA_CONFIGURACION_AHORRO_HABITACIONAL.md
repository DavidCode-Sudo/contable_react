# Guía: Configuración de Aportes de Ahorro Habitacional para Banesco

## 📋 Pasos para Implementar

### Paso 1: Crear los Conceptos de Nómina

**Opción A: Usando el Script SQL (Recomendado)**

1. Abre phpMyAdmin o tu cliente MySQL
2. Selecciona tu base de datos
3. Ejecuta el script: `database/scripts/crear_conceptos_ahorro_habitacional.sql`
4. Esto creará automáticamente:
   - **AHO_HAB**: Ahorro Habitacional - Empleado (Deducción)
   - **AEM_HAB**: Aporte Habitacional - Empleador (Percepción)

**Opción B: Manualmente desde el Sistema**

1. Ve a **RRHH > Reglas de Nómina** (o **Gestión de Conceptos**)
2. Clic en **"Nueva Regla"**

**Para el Ahorro del Empleado:**
- **Código**: `AHO_HAB`
- **Regla**: `Ahorro Habitacional - Empleado`
- **Tipo**: `Deducción`
- **Método de Cálculo**: `% del Salario` (o `Fijo` si prefieres)
- **Parámetro**: `5.00` (5% del salario - ajusta según tu política)
- **Orden**: `10`
- **Estado**: `Activo`

**Para el Aporte del Empleador:**
- **Código**: `AEM_HAB`
- **Regla**: `Aporte Habitacional - Empleador`
- **Tipo**: `Percepción`
- **Método de Cálculo**: `% del Salario` (o `Fijo`)
- **Parámetro**: `5.00` (5% del salario - ajusta según tu política)
- **Orden**: `11`
- **Estado**: `Activo`

### Paso 2: Asignar Conceptos a los Empleados

1. Ve a **RRHH > Configuración de Nómina por Empleado**
2. Selecciona el empleado al que quieres asignar los aportes
3. Clic en **"Nueva Asignación"**

**Asignación 1: Ahorro del Empleado**
- **Empleado**: [Seleccionar empleado]
- **Regla**: `AHO_HAB - Ahorro Habitacional - Empleado`
- **Método de Cálculo**: `% del Salario` (o el método que configuraste)
- **Parámetro**: `5.00` (o el porcentaje que desees)
- **Factor**: `1.00`
- **Estado**: `Activo`

**Asignación 2: Aporte del Empleador**
- **Empleado**: [El mismo empleado]
- **Regla**: `AEM_HAB - Aporte Habitacional - Empleador`
- **Método de Cálculo**: `% del Salario` (o el método que configuraste)
- **Parámetro**: `5.00` (o el porcentaje que desees)
- **Factor**: `1.00`
- **Estado**: `Activo`

**Nota:** Repite este proceso para cada empleado que debe tener aportes de ahorro habitacional.

### Paso 3: Generar la Nómina

1. Ve a **Nóminas > Gestión de Nóminas**
2. Clic en **"Generar Nómina"**
3. Selecciona el **Período** correspondiente
4. Selecciona los **Empleados** (o déjalo vacío para todos)
5. Clic en **"Generar"**

El sistema calculará automáticamente:
- **MTO AHO**: Monto de ahorro del empleado (basado en el concepto de deducción)
- **MTO EMP**: Monto de aporte del empleador (basado en el concepto de percepción)

### Paso 4: Exportar para Banesco

1. Ve a la nómina generada
2. Clic en **"Ver Detalle"**
3. Clic en **"Ver PDF Banesco"**
4. El PDF mostrará todos los empleados con sus aportes calculados

## 🔍 Verificación de Cálculos

En el PDF exportado, al final encontrarás una sección **"CONCEPTOS UTILIZADOS"** que muestra:

- ✅ **Conceptos encontrados**: Verás los códigos y nombres de los conceptos que se usaron
- ❌ **Conceptos no encontrados**: Si aparece en rojo, significa que faltan conceptos o asignaciones

## ⚙️ Configuración de Montos

### Métodos de Cálculo Disponibles:

1. **Fijo**: Monto fijo que se aplica a todos
   - Ejemplo: Parámetro = 100, Factor = 1 → Monto = 100.00

2. **% del Salario**: Porcentaje del salario base
   - Ejemplo: Parámetro = 5, Factor = 1, Salario = 1000 → Monto = 50.00 (5%)

3. **Personalizado**: Se calcula externamente
   - El sistema espera que el monto ya esté calculado

### Ejemplo de Configuración Común:

**Escenario:** Ahorro del 5% del salario del empleado, y aporte del 5% del salario del empleador

**Ahorro Empleado:**
- Método: `porcentaje_salario`
- Parámetro: `5.00`
- Factor: `1.00`
- Si el empleado gana 1000: Monto = 1000 × 5% × 1 = 50.00

**Aporte Empleador:**
- Método: `porcentaje_salario`
- Parámetro: `5.00`
- Factor: `1.00`
- Si el empleado gana 1000: Monto = 1000 × 5% × 1 = 50.00

**Total en PDF:** 50.00 + 50.00 = 100.00

## 🐛 Solución de Problemas

### Los montos aparecen en 0.00

1. **Verifica que los conceptos existan:**
   - Ve a RRHH > Reglas de Nómina
   - Busca `AHO_HAB` y `AEM_HAB`
   - Si no existen, créalos siguiendo el Paso 1

2. **Verifica que estén asignados al empleado:**
   - Ve a RRHH > Configuración de Nómina por Empleado
   - Selecciona el empleado
   - Verifica que ambos conceptos estén asignados y en estado "Activo"

3. **Verifica que la nómina se haya generado DESPUÉS de asignar los conceptos:**
   - Si generaste la nómina antes de asignar conceptos, debes regenerarla
   - Elimina la nómina anterior o crea una nueva

4. **Revisa el detalle de la nómina:**
   - Ve a Nóminas > Ver Detalle de Nómina
   - Verifica que los conceptos aparezcan en el detalle del empleado

### Los conceptos no se encuentran

El sistema busca conceptos con:
- **Códigos**: AHO, HAB, AEM, APORTE
- **Nombres**: "ahorro", "aporte empleador", "ahorro habitacional"

Si usas otros nombres, puedes:
1. Cambiar el código/nombre del concepto para que coincida
2. O editar el archivo `exportar_banesco_ahorro.php` para agregar más criterios de búsqueda

## 📝 Notas Importantes

- **El sueldo base NO aparece en el PDF de Banesco**: Este formato solo muestra los aportes de ahorro habitacional (MTO AHO y MTO EMP)
- **Los montos se calculan automáticamente** cuando generas la nómina, basándose en la configuración de cada empleado
- **Debes asignar los conceptos a cada empleado individualmente**, no se aplican automáticamente a todos
- **Si cambias la configuración de conceptos**, debes regenerar las nóminas afectadas

## 🎯 Checklist de Implementación

- [ ] Ejecutar script SQL o crear conceptos manualmente
- [ ] Asignar concepto de ahorro (AHO_HAB) a cada empleado que participa
- [ ] Asignar concepto de aporte (AEM_HAB) a cada empleado que participa
- [ ] Generar nómina para el período deseado
- [ ] Verificar que los montos aparezcan en el detalle de la nómina
- [ ] Exportar PDF de Banesco y verificar que los montos sean correctos
- [ ] Revisar la sección "CONCEPTOS UTILIZADOS" al final del PDF

---

**¿Necesitas ayuda?** Si después de seguir estos pasos los montos siguen en 0.00, revisa:
1. La sección "CONCEPTOS UTILIZADOS" en el PDF
2. Los logs del sistema en `logs/error.log`
3. El detalle de la nómina en el sistema

