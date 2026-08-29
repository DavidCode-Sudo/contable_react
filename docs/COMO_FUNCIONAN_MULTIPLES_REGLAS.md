# ¿Cómo Funcionan Múltiples Reglas en la Nómina?

## ✅ Respuesta Corta

**SÍ, todas las reglas asignadas a un empleado se calculan y se aplican automáticamente.**

El sistema:
- ✅ **Suma TODAS las percepciones** (bonos, primas, etc.)
- ✅ **Resta TODAS las deducciones** (IVSS, ahorro, descuentos, etc.)
- ✅ Calcula el neto a pagar automáticamente

---

## 📊 Ejemplo Práctico Completo

### **Empleado: Juan Pérez**
**Salario Base:** Bs. 5,000.00

### **Reglas Asignadas al Empleado:**

| # | Concepto | Tipo | Método | Parámetro | Cálculo | Resultado |
|---|----------|------|--------|-----------|---------|-----------|
| 1 | Bono Alimentación | **Percepción** | Fijo | 500.00 | 500.00 × 1 | **+500.00** |
| 2 | Prima Antigüedad | **Percepción** | % Salario | 5.00 | (5,000 × 5%) × 1 | **+250.00** |
| 3 | Horas Extras | **Percepción** | Personalizado | 0.00 | (manual) | **+300.00** |
| 4 | IVSS | **Deducción** | % Salario | 4.00 | (5,000 × 4%) × 1 | **-200.00** |
| 5 | FAOV (Ahorro) | **Deducción** | % Salario | 1.00 | (5,000 × 1%) × 1 | **-50.00** |
| 6 | ISLR | **Deducción** | Personalizado | 0.00 | (según tabla) | **-150.00** |
| 7 | Descuento Varios | **Deducción** | Fijo | 100.00 | 100.00 × 1 | **-100.00** |

---

## 💰 Cálculo Final Automático:

```
┌─────────────────────────────────────────────────────────┐
│ CÁLCULO DE NÓMINA - JUAN PÉREZ                          │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ Salario Base:                   5,000.00                │
│                                                          │
│ PERCEPCIONES (se SUMAN):                                 │
│   + Bono Alimentación:           500.00                 │
│   + Prima Antigüedad:            250.00                 │
│   + Horas Extras:                300.00                 │
│   ─────────────────────────────────────                 │
│   Total Percepciones:           1,050.00                │
│                                                          │
│ DEDUCCIONES (se RESTAN):                                 │
│   - IVSS (4%):                   200.00                  │
│   - FAOV (1%):                    50.00                  │
│   - ISLR:                        150.00                  │
│   - Descuento Varios:            100.00                  │
│   ─────────────────────────────────────                 │
│   Total Deducciones:              500.00                 │
│                                                          │
│ NETO A PAGAR:                                           │
│   Salario Base:       5,000.00                           │
│   + Percepciones:     1,050.00                           │
│   - Deducciones:       500.00                            │
│   ─────────────────────────────────────                 │
│   = NETO A PAGAR:     5,550.00                          │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## 🔄 Cómo Funciona el Sistema

### **Paso 1: Obtener Todas las Reglas**
```
El sistema busca TODAS las reglas asignadas al empleado
```

### **Paso 2: Calcular Cada Regla**
```
Para cada regla:
  - Si es PERCEPCIÓN → Calcula y SUMA al total de percepciones
  - Si es DEDUCCIÓN → Calcula y SUMA al total de deducciones
```

### **Paso 3: Calcular Neto**
```
Neto = Salario Base + Total Percepciones - Total Deducciones
```

---

## 📋 Ejemplo en el Recibo de Nómina

El empleado verá algo así en su recibo:

```
┌─────────────────────────────────────────────────────────┐
│ RECIBO DE NÓMINA                                        │
│ Empleado: Juan Pérez                                    │
│ Período: ENE-2025-Q1                                    │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ SALARIO BASE:                   5,000.00                │
│                                                          │
│ PERCEPCIONES:                                           │
│   Bono Alimentación:              500.00                │
│   Prima Antigüedad:               250.00                │
│   Horas Extras:                   300.00                │
│   ─────────────────────────────────────                 │
│   Total Percepciones:           1,050.00                │
│                                                          │
│ DEDUCCIONES:                                           │
│   IVSS (4%):                     200.00                 │
│   FAOV (1%):                      50.00                 │
│   ISLR:                          150.00                 │
│   Descuento Varios:               100.00                 │
│   ─────────────────────────────────────                 │
│   Total Deducciones:              500.00                 │
│                                                          │
│ NETO A PAGAR:                   5,550.00                │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## ⚠️ Puntos Importantes

### **1. Todas las Reglas se Calculan**
```
✅ Si asignas 10 reglas al empleado, las 10 se calculan
✅ No hay límite de reglas
✅ El sistema procesa todas automáticamente
```

### **2. El Orden No Afecta el Cálculo Final**
```
El campo "Orden" solo afecta cómo se muestran en el recibo,
pero NO afecta el cálculo del total.

Ejemplo:
- Orden 10: IVSS
- Orden 20: FAOV
- Orden 30: ISLR

El resultado será el mismo que si fuera:
- Orden 30: IVSS
- Orden 10: FAOV
- Orden 20: ISLR
```

### **3. Percepciones Siempre Suman**
```
✅ Todas las percepciones se suman al salario base
✅ No importa si son fijas, porcentajes o personalizadas
✅ Todas se suman al total
```

### **4. Deducciones Siempre Restan**
```
✅ Todas las deducciones se restan del salario
✅ No importa si son fijas, porcentajes o personalizadas
✅ Todas se restan del total
```

---

## 🎯 Casos de Uso

### **Caso 1: Empleado con Múltiples Bonos**
```
Reglas Asignadas:
- Bono Alimentación (Bs. 500)
- Bono Transporte (Bs. 300)
- Bono Productividad (Bs. 200)

Resultado:
Salario Base: 5,000.00
+ Percepciones: 1,000.00 (500 + 300 + 200)
= Neto: 6,000.00
```

### **Caso 2: Empleado con Múltiples Deducciones**
```
Reglas Asignadas:
- IVSS (4%)
- FAOV (1%)
- Descuento Préstamo (Bs. 250)

Resultado:
Salario Base: 5,000.00
- Deducciones: 500.00 (200 + 50 + 250)
= Neto: 4,500.00
```

### **Caso 3: Empleado con Todo (Múltiples Percepciones y Deducciones)**
```
Reglas Asignadas:
- Bono Alimentación: +500
- Prima Antigüedad: +250 (5%)
- IVSS: -200 (4%)
- FAOV: -50 (1%)
- Descuento Varios: -100

Resultado:
Salario Base: 5,000.00
+ Percepciones: 750.00
- Deducciones: 350.00
= Neto: 5,400.00
```

---

## ✅ Resumen

| Pregunta | Respuesta |
|----------|----------|
| ¿Se calculan todas las reglas? | **SÍ, todas** |
| ¿Hay límite de reglas? | **NO** |
| ¿El orden afecta el cálculo? | **NO, solo la visualización** |
| ¿Las percepciones se suman? | **SÍ, todas se suman** |
| ¿Las deducciones se restan? | **SÍ, todas se restan** |
| ¿Se calcula automáticamente? | **SÍ, todo es automático** |

---

## 💡 Consejos

1. **Asigna todas las reglas necesarias** al empleado
2. **No te preocupes por el orden** (a menos que quieras organizar visualmente)
3. **El sistema calcula todo automáticamente** al generar la nómina
4. **Revisa el recibo** para verificar que todas las reglas se aplicaron correctamente

---

**En resumen:** El sistema calcula **TODAS** las reglas asignadas al empleado, suma todas las percepciones y resta todas las deducciones automáticamente. No hay límite y todo es automático. 🎉

