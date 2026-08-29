# Guía: ¿Qué Poner en Cada Campo del Formulario de Conceptos de Nómina?

## 📝 Nombres de los Campos (Están Correctos ✅)

Los títulos de los campos son correctos y están bien etiquetados en español. Aquí te explico qué poner en cada uno:

---

## 📋 Ejemplos Prácticos por Tipo de Concepto

### **EJEMPLO 1: IVSS (Deducción por Porcentaje)**

```
┌─────────────────────────────────────────────────────────┐
│ Código *:           IVSS                                │
│ Regla *:            Aporte IVSS (4% del Salario)        │
│ Tipo:               Deducción                           │
│ Método de Cálculo:  % del Salario                       │
│ Parámetro *:        4.00                                │
│ Orden:              20                                  │
│ Estado:             Activo                               │
└─────────────────────────────────────────────────────────┘
```

**Explicación:**
- **Código:** Identificador corto único (sin espacios, solo letras/números)
- **Regla:** Descripción completa y clara
- **Tipo:** Deducción (porque se descuenta del salario)
- **Método:** Porcentaje (porque es 4% del salario)
- **Parámetro:** 4.00 (el porcentaje, sin el símbolo %)
- **Orden:** 20 (se aplica después de otras deducciones)

---

### **EJEMPLO 2: Bono de Alimentación (Percepción Fija)**

```
┌─────────────────────────────────────────────────────────┐
│ Código *:           BONO_ALIM                           │
│ Regla *:            Bono de Alimentación                │
│ Tipo:               Percepción                          │
│ Método de Cálculo:  Fijo                                │
│ Parámetro *:        500.00                              │
│ Orden:              10                                  │
│ Estado:             Activo                               │
└─────────────────────────────────────────────────────────┘
```

**Explicación:**
- **Código:** BONO_ALIM (identificador único)
- **Regla:** Nombre completo del concepto
- **Tipo:** Percepción (porque suma al salario)
- **Método:** Fijo (porque siempre es el mismo monto)
- **Parámetro:** 500.00 (el monto fijo en Bs.)
- **Orden:** 10 (se aplica antes de otras percepciones)

---

### **EJEMPLO 3: Ahorro Habitacional (Deducción por Porcentaje)**

```
┌─────────────────────────────────────────────────────────┐
│ Código *:           FAOV                                │
│ Regla *:            Fondo de Ahorro Obligatorio         │
│ Tipo:               Deducción                           │
│ Método de Cálculo:  % del Salario                       │
│ Parámetro *:        1.00                                │
│ Orden:              25                                  │
│ Estado:             Activo                               │
└─────────────────────────────────────────────────────────┘
```

---

### **EJEMPLO 4: Prima de Antigüedad (Percepción por Porcentaje)**

```
┌─────────────────────────────────────────────────────────┐
│ Código *:           PRIMA_ANT                           │
│ Regla *:            Prima de Antigüedad (5% del Salario)│
│ Tipo:               Percepción                          │
│ Método de Cálculo:  % del Salario                       │
│ Parámetro *:        5.00                                │
│ Orden:              15                                  │
│ Estado:             Activo                               │
└─────────────────────────────────────────────────────────┘
```

---

### **EJEMPLO 5: Descuento Varios (Deducción Fija)**

```
┌─────────────────────────────────────────────────────────┐
│ Código *:           DESC_VAR                            │
│ Regla *:            Descuentos Varios                   │
│ Tipo:               Deducción                           │
│ Método de Cálculo:  Fijo                                │
│ Parámetro *:        100.00                              │
│ Orden:              30                                  │
│ Estado:             Activo                               │
└─────────────────────────────────────────────────────────┘
```

---

## 📚 Tabla de Referencia Completa

| Concepto | Código | Regla | Tipo | Método | Parámetro | Orden |
|----------|--------|-------|------|--------|-----------|-------|
| **IVSS** | `IVSS` | Aporte IVSS (4% del Salario) | Deducción | % del Salario | `4.00` | 20 |
| **FAOV** | `FAOV` | Fondo de Ahorro Obligatorio | Deducción | % del Salario | `1.00` | 25 |
| **ISLR** | `ISLR` | Impuesto sobre la Renta | Deducción | Personalizado | `0.00` | 30 |
| **Bono Alimentación** | `BONO_ALIM` | Bono de Alimentación | Percepción | Fijo | `500.00` | 10 |
| **Prima Antigüedad** | `PRIMA_ANT` | Prima de Antigüedad (5%) | Percepción | % del Salario | `5.00` | 15 |
| **Horas Extras** | `HORAS_EXT` | Horas Extras | Percepción | Personalizado | `0.00` | 12 |
| **AEM** | `AEM` | Aporte Empleador Ahorro (2%) | Percepción | % del Salario | `2.00` | 11 |
| **Descuentos Varios** | `DESC_VAR` | Descuentos Varios | Deducción | Fijo | `100.00` | 30 |

---

## 🔍 Explicación Detallada de Cada Campo

### **1. Código * (Obligatorio)**

**¿Qué es?** Identificador único corto del concepto.

**Reglas:**
- ✅ 2-20 caracteres
- ✅ Solo letras, números, punto (.), guion (-), guion bajo (_)
- ✅ Sin espacios
- ✅ Único en el sistema

**Ejemplos correctos:**
- ✅ `IVSS`
- ✅ `BONO_ALIM`
- ✅ `PRIMA_ANT`
- ✅ `FAOV`
- ✅ `DESC_VAR`

**Ejemplos incorrectos:**
- ❌ `IVSS 4%` (tiene espacio)
- ❌ `bono-alimentacion` (guion, mejor usar guion bajo)
- ❌ `A` (muy corto, mínimo 2 caracteres)

---

### **2. Regla * (Obligatorio)**

**¿Qué es?** Nombre completo y descriptivo del concepto.

**Reglas:**
- ✅ 3-100 caracteres
- ✅ Descripción clara y completa
- ✅ Puede incluir porcentaje si aplica

**Ejemplos correctos:**
- ✅ `Aporte IVSS (4% del Salario)`
- ✅ `Bono de Alimentación`
- ✅ `Prima de Antigüedad (5% del Salario)`
- ✅ `Fondo de Ahorro Obligatorio`
- ✅ `Impuesto sobre la Renta`

**Ejemplos incorrectos:**
- ❌ `IVSS` (muy corto, mejor usar "Aporte IVSS")
- ❌ `Bono` (muy corto, mejor usar "Bono de Alimentación")

---

### **3. Tipo**

**¿Qué es?** Si el concepto suma o resta del salario.

**Opciones:**
- **Percepción:** Suma al salario (bonos, primas, horas extras)
- **Deducción:** Resta del salario (IVSS, ahorro, ISLR, descuentos)

**Ejemplos:**
- **Percepción:** Bono de Alimentación, Prima de Antigüedad, Horas Extras
- **Deducción:** IVSS, FAOV, ISLR, Descuentos Varios

---

### **4. Método de Cálculo**

**¿Qué es?** Cómo se calcula el monto del concepto.

**Opciones:**

#### **A. Fijo**
- **Uso:** Cuando el monto siempre es el mismo
- **Ejemplo:** Bono de Alimentación = Bs. 500.00 siempre
- **Parámetro:** El monto fijo en Bs. (ej: `500.00`)

#### **B. % del Salario**
- **Uso:** Cuando es un porcentaje del salario base
- **Ejemplo:** IVSS = 4% del salario
- **Parámetro:** El porcentaje SIN el símbolo % (ej: `4.00` para 4%)

**Cálculo automático:**
```
Si salario = 5,000.00 y parámetro = 4.00
Resultado = (5,000.00 × 4.00 / 100) = 200.00
```

#### **C. Personalizado**
- **Uso:** Cuando el cálculo es más complejo o se define manualmente
- **Ejemplo:** ISLR (según tabla de impuestos), Horas Extras (según tarifa)
- **Parámetro:** Generalmente `0.00` (se calcula manualmente o con fórmula especial)

---

### **5. Parámetro * (Obligatorio)**

**¿Qué es?** El valor numérico que usa el método de cálculo.

**Reglas:**
- ✅ Debe ser un número ≥ 0
- ✅ Puede tener decimales (ej: `4.00`, `1.50`, `500.00`)

**Según el Método:**

| Método | ¿Qué Poner? | Ejemplo | Significado |
|--------|-------------|---------|-------------|
| **Fijo** | Monto fijo en Bs. | `500.00` | Siempre se paga Bs. 500.00 |
| **% del Salario** | Porcentaje (sin %) | `4.00` | 4% del salario base |
| **Personalizado** | Generalmente `0.00` | `0.00` | Se calcula manualmente o con fórmula especial |

**Ejemplos:**
- Método **Fijo** + Parámetro `500.00` = Bono de Bs. 500.00
- Método **% del Salario** + Parámetro `4.00` = 4% del salario
- Método **% del Salario** + Parámetro `1.00` = 1% del salario
- Método **Personalizado** + Parámetro `0.00` = Se calcula manualmente

---

### **6. Orden (Opcional)**

**¿Qué es?** Orden en que se aplican los conceptos (de menor a mayor).

**Recomendación:**
- **Percepciones:** Orden 10-19 (se aplican primero)
  - Ejemplo: Bono = 10, Prima = 15
- **Deducciones:** Orden 20-39 (se aplican después)
  - Ejemplo: IVSS = 20, FAOV = 25, ISLR = 30

**No es obligatorio, pero ayuda a organizar el cálculo.**

---

### **7. Estado**

**¿Qué es?** Si el concepto está activo o inactivo.

**Opciones:**
- **Activo:** El concepto se usa en los cálculos
- **Inactivo:** El concepto NO se usa (pero se mantiene en el sistema)

**Recomendación:** Dejar en **Activo** al crear.

---

## ✅ Ejemplo Completo: IVSS

Basado en tu imagen, aquí está el formulario completo para IVSS:

```
┌─────────────────────────────────────────────────────────┐
│ NUEVA REGLA                                             │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ Código *:           IVSS                        ✅       │
│ (Ya tienes esto correcto)                                │
│                                                          │
│ Regla *:            Aporte IVSS (4% del Salario)        │
│ (Completa este campo)                                    │
│                                                          │
│ Tipo:               Deducción                            │
│ (Cambiar de "Percepción" a "Deducción")                  │
│                                                          │
│ Método de Cálculo:  % del Salario                        │
│ (Cambiar de "Fijo" a "% del Salario")                   │
│                                                          │
│ Parámetro *:        4.00                                 │
│ (Completa este campo con el porcentaje)                  │
│                                                          │
│ Orden:              20                                   │
│ (Opcional, pero recomendado)                             │
│                                                          │
│ Estado:             Activo                               │
│ (Dejar como está)                                        │
│                                                          │
│ [Cancelar]  [Guardar]                                    │
└─────────────────────────────────────────────────────────┘
```

---

## 🎯 Resumen Rápido

**Para IVSS (que estás creando):**

1. ✅ **Código:** `IVSS` (ya lo tienes)
2. ❌ **Regla:** `Aporte IVSS (4% del Salario)` (falta completar)
3. ❌ **Tipo:** Cambiar a `Deducción` (está en "Percepción")
4. ❌ **Método:** Cambiar a `% del Salario` (está en "Fijo")
5. ❌ **Parámetro:** `4.00` (falta completar)
6. ⚪ **Orden:** `20` (opcional)
7. ✅ **Estado:** `Activo` (está bien)

---

## 💡 Consejos Finales

1. **Código:** Usa mayúsculas para códigos estándar (IVSS, FAOV, ISLR)
2. **Regla:** Sé descriptivo, incluye el porcentaje si aplica
3. **Tipo:** Piensa: ¿Suma o resta del salario?
4. **Método:** 
   - Si siempre es el mismo monto → **Fijo**
   - Si es porcentaje del salario → **% del Salario**
   - Si es complejo → **Personalizado**
5. **Parámetro:** 
   - Fijo → monto en Bs.
   - Porcentaje → número sin símbolo %
   - Personalizado → generalmente 0.00

---

**¿Tienes dudas sobre algún concepto específico?** Puedo ayudarte a completarlo. 😊

