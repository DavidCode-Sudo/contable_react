# Cómo Configurar una Nueva Asignación de Concepto a Empleado

## 📋 ¿Qué es una Asignación?

Una **Asignación** es vincular una **Regla de Nómina** (concepto) a un **Empleado** específico y configurar cómo se calculará para ese empleado.

---

## 🎯 ¿Para qué sirve?

Cuando asignas un concepto a un empleado, le estás diciendo al sistema:
- ✅ **Qué concepto** se le aplicará (ej: IVSS, Bono de Alimentación, etc.)
- ✅ **Cómo se calculará** (monto fijo, porcentaje, personalizado)
- ✅ **Cuánto será** (el parámetro específico)
- ✅ **Si está activo** o inactivo

---

## 📝 Campos del Modal "Nueva Asignación"

### **1. Empleado * (Obligatorio)**

**¿Qué es?** Seleccionas el empleado al que le vas a asignar el concepto.

**Ejemplo:**
```
EMP-001 - Juan Pérez
EMP-002 - María González
EMP-003 - Carlos Rodríguez
```

**Nota:** Si ya tienes un empleado seleccionado en la página, aparecerá preseleccionado.

---

### **2. Regla * (Obligatorio)**

**¿Qué es?** Seleccionas la regla de nómina que quieres asignar al empleado.

**Ejemplos de reglas:**
```
PRIMA_PROF - Prima de Profesionalización
IVSS - S.S.O. (Seguro Social Obligatorio)
FAOV - FAOV (Fondo de Ahorro Obligatorio para la Vivienda)
BONO_ALIM - Bono de Alimentación
PREST_CAJA_AHORROS - Préstamos Caja de Ahorros
```

**Funcionalidad automática:**
- ✅ Al seleccionar una regla, el sistema **automáticamente**:
  - Carga el método de cálculo de la regla
  - Carga el parámetro predeterminado de la regla
  - Puedes modificarlos después si es necesario

---

### **3. Método de Cálculo**

**¿Qué es?** Cómo se calculará el concepto para este empleado.

**Opciones:**

#### **A. Fijo**
- **Uso:** Monto fijo que no depende del salario
- **Ejemplo:** Bono de Alimentación = Bs. 500.00 siempre
- **Parámetro:** El monto fijo en Bs. (ej: `500.00`)

#### **B. % del Salario**
- **Uso:** Porcentaje del salario base del empleado
- **Ejemplo:** IVSS = 4% del salario
- **Parámetro:** El porcentaje sin el símbolo % (ej: `4.00` para 4%)

#### **C. Personalizado**
- **Uso:** Valor ya calculado o especial
- **Ejemplo:** ISLR según tabla, Horas Extras según tarifa
- **Parámetro:** El monto ya calculado en Bs.

**Nota:** Si seleccionas una regla que ya tiene un método configurado, se carga automáticamente, pero puedes cambiarlo.

---

### **4. Parámetro**

**¿Qué es?** El valor numérico que se usa para calcular el concepto.

**Según el Método de Cálculo:**

| Método | ¿Qué poner? | Ejemplo | Significado |
|--------|-------------|---------|-------------|
| **Fijo** | Monto fijo en Bs. | `500.00` | Bs. 500.00 siempre |
| **% del Salario** | Porcentaje (sin %) | `4.00` | 4% del salario |
| **Personalizado** | Monto ya calculado | `250.00` | Bs. 250.00 directamente |

**Ejemplos prácticos:**

**Ejemplo 1: IVSS (Porcentaje)**
```
Regla: IVSS
Método: % del Salario
Parámetro: 4.00
Factor: 1.00

Resultado: Si el empleado gana Bs. 5,000
→ Se calcula: 5,000 × 4% × 1 = Bs. 200.00
```

**Ejemplo 2: Bono Alimentación (Fijo)**
```
Regla: BONO_ALIM
Método: Fijo
Parámetro: 500.00
Factor: 1.00

Resultado: Siempre se suma Bs. 500.00
→ No importa el salario
```

**Ejemplo 3: Préstamo (Personalizado)**
```
Regla: PREST_CAJA_AHORROS
Método: Personalizado
Parámetro: 150.00
Factor: 1.00

Resultado: Se resta Bs. 150.00 (cuota del préstamo)
```

**Funcionalidad automática:**
- ✅ Al seleccionar una regla, el sistema carga el parámetro predeterminado
- ✅ Puedes modificarlo si este empleado tiene un valor diferente

---

### **5. Factor**

**¿Qué es?** Un multiplicador que se aplica al cálculo.

**Uso común:**
- **Factor 1.00:** Normal (se aplica el 100% del cálculo)
- **Factor 1.5:** Se aplica 1.5 veces el cálculo (150%)
- **Factor 2.0:** Se aplica 2 veces el cálculo (200%)
- **Factor 0.5:** Se aplica la mitad del cálculo (50%)

**Ejemplo:**
```
Parámetro: 500.00
Factor: 1.5

Cálculo: 500.00 × 1.5 = 750.00
```

**En la mayoría de casos:**
- ✅ Dejar en **1.00** (normal)
- ✅ Solo cambiar si necesitas aplicar un multiplicador especial

---

### **6. Estado**

**¿Qué es?** Si la asignación está activa o inactiva.

**Opciones:**
- **Activo:** El concepto se aplica en las nóminas
- **Inactivo:** El concepto NO se aplica (pero se mantiene guardado)

**Recomendación:** Dejar en **Activo** al crear.

---

## 💡 Ejemplos Completos de Configuración

### **Ejemplo 1: Asignar IVSS a un Empleado**

```
Empleado: EMP-001 - Juan Pérez
Regla: SSO - S.S.O. (Seguro Social Obligatorio)
Método: % del Salario (se carga automáticamente)
Parámetro: 4.00 (se carga automáticamente de la regla)
Factor: 1.00
Estado: Activo

Resultado: 
- Se calculará el 4% del salario de Juan Pérez
- Si gana Bs. 5,000 → IVSS = Bs. 200.00
```

---

### **Ejemplo 2: Asignar Bono de Alimentación**

```
Empleado: EMP-002 - María González
Regla: BONO_ALIM - Bono de Alimentación
Método: Fijo (o el que tenga configurado la regla)
Parámetro: 500.00
Factor: 1.00
Estado: Activo

Resultado:
- Siempre se suma Bs. 500.00
- No importa cuánto gane
```

---

### **Ejemplo 3: Asignar Préstamo de Caja de Ahorros**

```
Empleado: EMP-003 - Carlos Rodríguez
Regla: PREST_CAJA_AHORROS - Préstamos Caja de Ahorros
Método: Personalizado
Parámetro: 150.00 (cuota mensual del préstamo)
Factor: 1.00
Estado: Activo

Resultado:
- Se resta Bs. 150.00 cada mes
- Hasta que se pague el préstamo
```

---

### **Ejemplo 4: Asignar Prima de Antigüedad**

```
Empleado: EMP-001 - Juan Pérez
Regla: PRIMA_ANT - Prima por Antigüedad
Método: % del Salario (se carga automáticamente)
Parámetro: 3.00 (se carga automáticamente de la regla)
Factor: 1.00
Estado: Activo

Resultado:
- Se calcula el 3% del salario
- Si gana Bs. 5,000 → Prima = Bs. 150.00
```

---

## 🔄 Flujo de Trabajo

### **Paso 1: Seleccionar Empleado**
```
1. Ir a: modulos/rrhh/gestion_empleado_conceptos.php
2. Hacer clic en "Elegir Empleado"
3. Seleccionar el empleado de la lista
```

### **Paso 2: Crear Nueva Asignación**
```
1. Hacer clic en "Nueva Asignación"
2. El empleado ya está seleccionado (si lo elegiste antes)
3. Seleccionar la Regla que quieres asignar
4. El sistema carga automáticamente:
   - Método de Cálculo
   - Parámetro
5. Ajustar si es necesario (ej: cambiar parámetro)
6. Revisar Factor (generalmente 1.00)
7. Estado: Activo
8. Guardar
```

### **Paso 3: Verificar**
```
1. La asignación aparece en la tabla
2. Verificar que los valores sean correctos
3. Al generar nómina, el concepto se aplicará automáticamente
```

---

## ⚠️ Puntos Importantes

### **1. No se puede duplicar**
```
❌ No puedes asignar la misma regla dos veces al mismo empleado
✅ Si ya existe, debes editar la asignación existente
```

### **2. El parámetro puede sobreescribir la regla**
```
✅ Si asignas IVSS con parámetro 4.00, se aplicará 4%
✅ Pero puedes cambiar el parámetro a 3.50 si este empleado tiene un caso especial
✅ El parámetro de la asignación tiene prioridad sobre el de la regla
```

### **3. El factor es opcional**
```
✅ En el 99% de casos, dejar en 1.00
✅ Solo cambiar si necesitas un multiplicador especial
```

### **4. Estado Inactivo**
```
✅ Si pones "Inactivo", el concepto NO se aplicará en las nóminas
✅ Pero se mantiene guardado (útil para reactivar después)
```

---

## ✅ Resumen

**En "Nueva Asignación" configuras:**

1. ✅ **A quién:** Empleado específico
2. ✅ **Qué concepto:** Regla de nómina
3. ✅ **Cómo se calcula:** Método de cálculo
4. ✅ **Cuánto es:** Parámetro
5. ✅ **Multiplicador:** Factor (generalmente 1.00)
6. ✅ **Si está activo:** Estado

**El sistema automáticamente:**
- ✅ Carga el método de cálculo de la regla
- ✅ Carga el parámetro predeterminado de la regla
- ✅ Puedes modificar estos valores si es necesario para este empleado específico

---

## 🎯 Casos de Uso Comunes

### **Caso 1: Asignar conceptos obligatorios a todos**
```
✅ IVSS (4%) - A todos
✅ FAOV (1%) - A todos
✅ Paro Forzoso (0.5%) - A todos
```

### **Caso 2: Asignar bonos específicos**
```
✅ Bono Alimentación (Bs. 500) - Solo a empleados que lo reciben
✅ Prima Antigüedad (3%) - Solo a empleados con más de X años
```

### **Caso 3: Asignar préstamos**
```
✅ Préstamo Caja (Bs. 150) - Solo a empleados con préstamo activo
✅ Préstamo Fundación (Bs. 80) - Solo a empleados con préstamo
```

---

**En resumen:** "Nueva Asignación" es donde vinculas una regla de nómina a un empleado específico y defines cómo se calculará para ese empleado. 🎉

