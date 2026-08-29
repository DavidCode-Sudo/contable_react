# ¿Para qué sirve el Factor en las Asignaciones de Nómina?

## 📊 ¿Qué es el Factor?

El **Factor** es un **multiplicador** que se aplica al resultado del cálculo del concepto. Te permite ajustar el monto final sin modificar el parámetro base.

---

## 🔢 Cómo Funciona el Cálculo

### **Fórmula General:**
```
Resultado Final = (Cálculo del Concepto) × Factor
```

### **Según el Método de Cálculo:**

#### **1. Método: Fijo**
```
Cálculo = Parámetro × Factor

Ejemplo:
Parámetro: 500.00
Factor: 1.00
Resultado: 500.00 × 1.00 = 500.00

Si cambias Factor a 1.5:
Resultado: 500.00 × 1.5 = 750.00
```

#### **2. Método: % del Salario**
```
Cálculo = (Salario × Porcentaje/100) × Factor

Ejemplo:
Salario: 5,000.00
Parámetro (Porcentaje): 4.00
Factor: 1.00
Cálculo: (5,000 × 4/100) × 1.00 = 200.00 × 1.00 = 200.00

Si cambias Factor a 0.5:
Resultado: 200.00 × 0.5 = 100.00 (la mitad)
```

#### **3. Método: Personalizado**
```
El factor NO se aplica en este método
Se usa directamente el valor del parámetro
```

---

## 💡 Casos de Uso Prácticos

### **Caso 1: Factor 1.00 (Normal - 100%)**

**Uso más común:**
```
✅ Factor: 1.00 = Se aplica el 100% del cálculo
✅ Es el valor por defecto
✅ En el 99% de casos, usarás 1.00
```

**Ejemplo:**
```
IVSS con Factor 1.00:
- Salario: 5,000.00
- Porcentaje: 4%
- Factor: 1.00
- Resultado: (5,000 × 4%) × 1.00 = 200.00
```

---

### **Caso 2: Factor 0.5 (Mitad - 50%)**

**Uso:**
```
✅ Aplicar solo la mitad del concepto
✅ Útil para empleados a tiempo parcial
✅ Útil para conceptos que se aplican parcialmente
```

**Ejemplo:**
```
Bono Alimentación con Factor 0.5:
- Parámetro: 500.00
- Factor: 0.5
- Resultado: 500.00 × 0.5 = 250.00
- Se aplica solo la mitad del bono
```

---

### **Caso 3: Factor 1.5 (150%)**

**Uso:**
```
✅ Aplicar 1.5 veces el concepto
✅ Útil para bonos especiales
✅ Útil para conceptos con incremento
```

**Ejemplo:**
```
Prima Antigüedad con Factor 1.5:
- Salario: 5,000.00
- Porcentaje: 3%
- Factor: 1.5
- Cálculo normal: (5,000 × 3%) = 150.00
- Con factor: 150.00 × 1.5 = 225.00
- Se aplica 1.5 veces la prima
```

---

### **Caso 4: Factor 2.0 (Doble - 200%)**

**Uso:**
```
✅ Aplicar el doble del concepto
✅ Útil para bonos especiales
✅ Útil para conceptos con doble pago
```

**Ejemplo:**
```
Bono Vacacional con Factor 2.0:
- Parámetro: 500.00
- Factor: 2.0
- Resultado: 500.00 × 2.0 = 1,000.00
- Se paga el doble del bono
```

---

### **Caso 5: Factor 0.75 (75%)**

**Uso:**
```
✅ Aplicar el 75% del concepto
✅ Útil para empleados con reducción de horas
✅ Útil para conceptos parciales
```

**Ejemplo:**
```
Bono Alimentación con Factor 0.75:
- Parámetro: 500.00
- Factor: 0.75
- Resultado: 500.00 × 0.75 = 375.00
- Se aplica el 75% del bono
```

---

## 🎯 Ejemplos Reales en Nómina

### **Ejemplo 1: Empleado a Tiempo Completo**

```
Concepto: IVSS
Parámetro: 4.00 (%)
Factor: 1.00 (normal)
Salario: 5,000.00

Resultado: (5,000 × 4%) × 1.00 = 200.00
```

---

### **Ejemplo 2: Empleado a Tiempo Parcial (50%)**

```
Concepto: IVSS
Parámetro: 4.00 (%)
Factor: 0.50 (mitad)
Salario: 5,000.00

Resultado: (5,000 × 4%) × 0.50 = 100.00
```

**Nota:** En este caso, el empleado a tiempo parcial gana menos, pero el IVSS se calcula sobre el salario completo y luego se reduce con el factor.

---

### **Ejemplo 3: Bono Especial (Doble)**

```
Concepto: Bono Productividad
Parámetro: 500.00 (fijo)
Factor: 2.00 (doble)

Resultado: 500.00 × 2.00 = 1,000.00
```

---

### **Ejemplo 4: Prima con Incremento**

```
Concepto: Prima Antigüedad
Parámetro: 3.00 (%)
Factor: 1.5 (150%)
Salario: 5,000.00

Cálculo normal: (5,000 × 3%) = 150.00
Con factor: 150.00 × 1.5 = 225.00
```

---

## ⚠️ Cuándo Usar el Factor

### **✅ Usar Factor cuando:**
- Necesitas aplicar un porcentaje del concepto (ej: 50%, 75%, 150%)
- Un empleado tiene condiciones especiales (tiempo parcial, bonos especiales)
- Quieres ajustar el monto sin cambiar el parámetro base

### **❌ NO usar Factor cuando:**
- El concepto debe aplicarse al 100% (usar 1.00)
- El parámetro ya tiene el valor correcto
- Es un concepto personalizado (no se aplica el factor)

---

## 📋 Resumen de Factores Comunes

| Factor | Porcentaje | Uso |
|--------|------------|-----|
| `0.5` | 50% | Tiempo parcial, mitad del concepto |
| `0.75` | 75% | Tres cuartos del concepto |
| `1.0` | 100% | Normal (valor por defecto) |
| `1.5` | 150% | Incremento del 50% |
| `2.0` | 200% | Doble del concepto |
| `2.5` | 250% | Dos veces y media |

---

## 💡 Recomendación

**En el 99% de casos:**
- ✅ Dejar el factor en **1.00** (normal)
- ✅ Solo cambiarlo si hay una razón específica

**Cuándo cambiar:**
- ✅ Empleado a tiempo parcial → Factor 0.5
- ✅ Bonos especiales → Factor 1.5 o 2.0
- ✅ Conceptos parciales → Factor 0.75

---

**En resumen:** El factor es un multiplicador que te permite ajustar el monto final del concepto sin modificar el parámetro base. Es útil para casos especiales, pero en la mayoría de casos se deja en 1.00. 🎯

