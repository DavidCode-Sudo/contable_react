# Formato de Recibo de Pago

## 📄 Terminología Correcta

El sistema ahora usa la terminología estándar para recibos de pago:

- ✅ **REMUNERACIONES** (en lugar de "percepciones")
- ✅ **DEDUCCIONES** (ya estaba correcto)

---

## 🖨️ Ejemplo de Recibo Generado

Así se verá el recibo de pago que genera el sistema para cada empleado:

```
┌─────────────────────────────────────────────────────────────────────┐
│ [LOGO EMPRESA]       RECIBO DE PAGO                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ EMPLEADO: Juan Pérez (EMP-001)          FECHA: 15/11/2025          │
│ IDENTIFICACIÓN: 12345678                 RECIBO #: REC-2025-000001  │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│ DETALLE                                                             │
├─────────────────────────────────────────────────────────────────────┤
│ CONCEPTO                         REMUNERACIONES      DEDUCCIONES    │
├─────────────────────────────────────────────────────────────────────┤
│ Sueldo Básico                         5,000.00                     │
│ Bono de Alimentación                    500.00                     │
│ Prima de Antigüedad                     250.00                     │
│ IVSS (4%)                                               200.00      │
│ FAOV (1%)                                                50.00      │
│ Préstamos Caja de Ahorros                              100.00      │
├─────────────────────────────────────────────────────────────────────┤
│ Salario base                           5,000.00                     │
│ Total remuneraciones                     750.00                     │
│ Total deducciones                                      350.00      │
│ NETO A PAGAR                           5,400.00                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📊 Estructura del Recibo

### **1. Encabezado**
```
- Nombre de la empresa
- Título: "RECIBO DE PAGO"
- Datos del empleado:
  * Nombre completo
  * Código de empleado
  * Identificación (cédula)
  * Número de recibo
  * Fecha de emisión
```

### **2. Detalle de Conceptos**
```
Tabla con 3 columnas:
┌─────────────────────────────────────────┐
│ CONCEPTO  │  REMUNERACIONES │ DEDUCCIONES │
├─────────────────────────────────────────┤
│ (lista de conceptos con sus montos)      │
└─────────────────────────────────────────┘
```

### **3. Totales**
```
- Salario base
- Total remuneraciones (suma de bonos, primas, etc.)
- Total deducciones (suma de IVSS, FAOV, préstamos, etc.)
- NETO A PAGAR (resultado final)
```

---

## 💰 Ejemplo Completo con Múltiples Conceptos

### **Empleado: María González**
**Salario Base:** Bs. 6,000.00  
**Período:** ENE-2025-Q1 (Primera Quincena de Enero 2025)

```
┌─────────────────────────────────────────────────────────────────────┐
│                     ALCALDÍA / EMPRESA                              │
│                     RECIBO DE PAGO                                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Empleado:       María González (EMP-002)                            │
│ Identificación: 98765432                                            │
│ Fecha:          15/01/2025                                          │
│ Recibo #:       REC-2025-000002                                     │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│                         DETALLE                                     │
├─────────────────────────────────────────────────────────────────────┤
│ CONCEPTO                         REMUNERACIONES      DEDUCCIONES    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ REMUNERACIONES:                                                      │
│ Bono de Alimentación                    500.00                      │
│ Prima de Profesionalización             300.00                      │
│ Prima por Antigüedad                    180.00                      │
│ Horas Extras                           250.00                      │
│ Bono Productividad                      200.00                      │
│                                                                      │
│ DEDUCCIONES:                                                        │
│ I.S.S.O. (IVSS)                                        240.00      │
│ Paro Forzoso                                            60.00      │
│ FAOV (Ahorro Habitacional)                              60.00      │
│ Retención Caja de Ahorros                              120.00      │
│ Préstamos Caja de Ahorros                              150.00      │
│ Préstamos Fundación                                     80.00      │
│ Otros descuentos                                        50.00      │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│ RESUMEN                                                             │
├─────────────────────────────────────────────────────────────────────┤
│ Salario base                                          6,000.00      │
│ Total remuneraciones                                  1,430.00      │
│ Total deducciones                                       760.00      │
│ ─────────────────────────────────────────────────────────────────── │
│ NETO A PAGAR                                          6,670.00      │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘

Este documento es un comprobante de pago generado por el sistema.

Firma: _______________________
```

---

## 🎯 Características del Recibo

### ✅ **Columnas Separadas**
- **REMUNERACIONES:** Columna derecha para montos que suman (bonos, primas, horas extras)
- **DEDUCCIONES:** Columna derecha para montos que restan (IVSS, ahorro, préstamos)

### ✅ **Cálculo Automático**
```
NETO = Salario Base + Total Remuneraciones - Total Deducciones

Ejemplo:
6,000.00 + 1,430.00 - 760.00 = 6,670.00
```

### ✅ **Todos los Conceptos Asignados**
- El sistema lista TODOS los conceptos asignados al empleado
- Cada concepto muestra su monto calculado
- Se agrupan visualmente por tipo

---

## 📝 Comparación con Recibo Real

### **Recibo de la Alcaldía de Caracas (Tu imagen):**
```
REMUNERACIONES:
- Sueldo Básico
- Prima de Profesionalización
- Prima por Antigüedad
- Pirma por Hijo
- Otros Complementos
- Complemento Servicios
- Bono Vacacional
- Conciertos de cámara
- Aguinaldos

DEDUCCIONES:
- Retenciones
- I.S.S.O.
- Paro Forzoso
- FAOV
- Retención Caja de Ahorros
- Préstamos Caja de Ahorros
- Ret. SIPRES
- Préstamos Fundación
- Dieta Comité
- Monte Pio
- Otros descuentos (Caja Clap)
- Otros descuentos
```

### **Recibo del Sistema (Igual):**
```
REMUNERACIONES:
- (Lista de conceptos tipo "percepcion")
  * Bono Alimentación
  * Prima Antigüedad
  * Horas Extras
  * etc.

DEDUCCIONES:
- (Lista de conceptos tipo "deduccion")
  * IVSS
  * FAOV
  * Préstamos
  * Descuentos
  * etc.
```

---

## 🔄 Flujo de Generación

```
1. Sistema genera nómina
   ↓
2. Para cada empleado:
   - Calcula salario base
   - Calcula remuneraciones (suma conceptos tipo "percepcion")
   - Calcula deducciones (suma conceptos tipo "deduccion")
   - Calcula neto = base + remuneraciones - deducciones
   ↓
3. Genera recibo HTML con formato estándar
   ↓
4. Guarda en base de datos
   ↓
5. Empleado puede imprimirlo
```

---

## 💡 Conceptos Comunes a Configurar

### **REMUNERACIONES (Percepciones):**
| Código | Nombre | Tipo | Método | Parámetro |
|--------|--------|------|--------|-----------|
| `BONO_ALIM` | Bono de Alimentación | percepcion | fijo | 500.00 |
| `PRIMA_PROF` | Prima de Profesionalización | percepcion | % salario | 5.00 |
| `PRIMA_ANT` | Prima por Antigüedad | percepcion | % salario | 3.00 |
| `HORAS_EXT` | Horas Extras | percepcion | personalizado | 0.00 |
| `BONO_PROD` | Bono Productividad | percepcion | fijo | 200.00 |
| `AEM` | Aporte Empleador Ahorro | percepcion | % salario | 2.00 |

### **DEDUCCIONES:**
| Código | Nombre | Tipo | Método | Parámetro |
|--------|--------|------|--------|-----------|
| `IVSS` | I.S.S.O. / IVSS | deduccion | % salario | 4.00 |
| `PARO_FORZ` | Paro Forzoso | deduccion | % salario | 1.00 |
| `FAOV` | FAOV (Ahorro Habitacional) | deduccion | % salario | 1.00 |
| `RET_CAJA` | Retención Caja de Ahorros | deduccion | % salario | 2.00 |
| `PREST_CAJA` | Préstamos Caja de Ahorros | deduccion | fijo | 150.00 |
| `PREST_FUND` | Préstamos Fundación | deduccion | fijo | 80.00 |
| `DESC_VAR` | Otros Descuentos | deduccion | fijo | 50.00 |

---

## ✅ Resumen de Cambios

| Antes | Después |
|-------|---------|
| ❌ Percepciones | ✅ **REMUNERACIONES** |
| ✅ Deducciones | ✅ **DEDUCCIONES** (sin cambios) |

**El recibo ahora usa la terminología correcta y oficial.** ✅

---

## 🖨️ Cómo Imprimir el Recibo

1. Ir a: `modulos/nominas/ver_nomina.php`
2. Ver detalle de una nómina
3. En la lista de empleados, hacer clic en el botón de "Imprimir" (ícono de impresora)
4. Se abre el recibo en una nueva ventana
5. Usar Ctrl+P para imprimir o guardar como PDF

---

**El sistema ahora genera recibos con el formato correcto y profesional** similar al de la Alcaldía de Caracas. 📄✨

