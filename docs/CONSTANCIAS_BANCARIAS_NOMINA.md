# 📄 Constancias Bancarias para Nómina - Dos Opciones

## 🎯 Resumen

El sistema ofrece **dos formas** de generar constancias bancarias para nóminas:

1. **Constancias Individuales** (una por empleado)
2. **Constancia Bancaria Masiva** (una sola hoja con todos los empleados) ⭐ **RECOMENDADO**

---

## 📋 OPCIÓN 1: Constancias Individuales

### **¿Qué es?**
Una constancia bancaria separada por cada empleado de la nómina.

### **¿Cuándo usar?**
- Cuando el banco requiere documentos individuales
- Cuando necesitas entregar constancias separadas a cada empleado
- Cuando hay pocos empleados (1-3)

### **¿Cómo generar?**
1. Generar órdenes de pago desde la nómina
2. Cada orden genera automáticamente una constancia individual
3. En `ver_nomina.php`, hacer clic en el ícono PDF de cada orden

### **Ventajas:**
- ✅ Documento individual por empleado
- ✅ Fácil de archivar por empleado
- ✅ Trazabilidad individual

### **Desventajas:**
- ❌ Muchos documentos si hay muchos empleados
- ❌ Más trabajo para el banco (múltiples documentos)
- ❌ Más tiempo de procesamiento

---

## 📋 OPCIÓN 2: Constancia Bancaria Masiva ⭐

### **¿Qué es?**
Una sola constancia bancaria que incluye **todos los empleados** de la nómina en formato tabla.

### **¿Cuándo usar?**
- ⭐ **RECOMENDADO para la mayoría de casos**
- Cuando hay varios empleados (4 o más)
- Cuando el banco acepta planillas masivas
- Cuando quieres un solo documento para llevar al banco
- Similar al formato Banesco Ahorro Habitacional

### **¿Cómo generar?**
1. Generar órdenes de pago desde la nómina (igual que antes)
2. En `ver_nomina.php`, hacer clic en **"Constancia Bancaria Masiva"**
3. Se genera un PDF con todos los empleados en una tabla

### **Ventajas:**
- ✅ **Un solo documento** para llevar al banco
- ✅ **Más rápido** para el banco procesar
- ✅ **Más práctico** para nóminas grandes
- ✅ **Formato profesional** tipo planilla
- ✅ Similar al formato Banesco ya usado

### **Desventajas:**
- ❌ No permite documentos individuales por empleado (pero se puede complementar)

---

## 📊 Comparación Visual

### **Constancias Individuales:**
```
┌─────────────────────┐
│ Constancia Empleado 1│
└─────────────────────┘
┌─────────────────────┐
│ Constancia Empleado 2│
└─────────────────────┘
┌─────────────────────┐
│ Constancia Empleado 3│
└─────────────────────┘
... (muchos documentos)
```

### **Constancia Masiva:**
```
┌─────────────────────────────────────────┐
│     CONSTANCIA BANCARIA MASIVA          │
├─────────────────────────────────────────┤
│  Información de Nómina                 │
│  Información Bancaria de Origen        │
│                                         │
│  ┌─────────────────────────────────┐   │
│  │ # │ Empleado │ Banco │ Monto │  │   │
│  ├───┼──────────┼───────┼───────┤  │   │
│  │ 1 │ Juan P.  │ Banesco│ 5000 │  │   │
│  │ 2 │ María G. │ Banesco│ 6000 │  │   │
│  │ 3 │ Carlos R.│ Banesco│ 5500 │  │   │
│  │...│ ...      │ ...   │ ...  │  │   │
│  └─────────────────────────────────┘   │
│                                         │
│  TOTAL: Bs. 25,000.00                   │
└─────────────────────────────────────────┘
```

---

## 🔄 Flujo Recomendado

### **Para Nóminas con 4+ Empleados:**

```
1. Generar Nómina
   ↓
2. Confirmar Nómina
   ↓
3. Generar Órdenes de Pago (una por empleado)
   ⚠️ Esto crea las órdenes para trazabilidad
   ↓
4. Generar Constancia Bancaria Masiva
   ⭐ Un solo PDF con todos los empleados
   ↓
5. Llevar al banco la constancia masiva
   ↓
6. Banco ejecuta las transferencias
   ↓
7. Marcar órdenes como pagadas (individualmente)
   ✅ Los recibos se actualizan automáticamente
```

### **Para Nóminas con 1-3 Empleados:**

```
1. Generar Nómina
   ↓
2. Confirmar Nómina
   ↓
3. Generar Órdenes de Pago
   ↓
4. Usar Constancias Individuales
   ✅ Más simple para pocos empleados
```

---

## 📝 Contenido de la Constancia Masiva

La constancia bancaria masiva incluye:

1. **Encabezado:**
   - Nombre de la institución
   - RIF
   - Título: "SOLICITUD DE TRANSFERENCIAS BANCARIAS - NÓMINA"

2. **Información de la Nómina:**
   - Número de nómina
   - Período
   - Fechas
   - Presupuesto
   - Total a pagar
   - Total de empleados

3. **Información Bancaria de Origen:**
   - Banco origen
   - Cuenta origen

4. **Tabla de Empleados:**
   - # (contador)
   - Nombre del empleado
   - Cédula
   - Banco destino
   - Número de cuenta
   - Monto a transferir

5. **Totales:**
   - Total general
   - Total de transferencias

6. **Información Adicional:**
   - Concepto
   - Fecha de solicitud
   - Total de transferencias

7. **Firma:**
   - Espacio para firma y sello

---

## 🎯 Recomendación Final

### **Usar Constancia Masiva cuando:**
- ✅ Nómina tiene 4 o más empleados
- ✅ Banco acepta planillas masivas
- ✅ Quieres simplificar el proceso

### **Usar Constancias Individuales cuando:**
- ✅ Nómina tiene 1-3 empleados
- ✅ Banco requiere documentos individuales
- ✅ Necesitas constancias separadas por empleado

---

## 📍 Ubicación de las Funcionalidades

### **Generar Constancia Masiva:**
- **Desde lista de nóminas:** `gestion_nominas.php`
  - Botón PDF azul junto al número de órdenes
- **Desde detalle de nómina:** `ver_nomina.php`
  - Botón "Constancia Bancaria Masiva" en la sección de órdenes

### **Generar Constancias Individuales:**
- **Desde detalle de nómina:** `ver_nomina.php`
  - Ícono PDF en cada orden individual

---

## 💡 Consejos

1. **Genera siempre las órdenes individuales primero:**
   - Esto mantiene la trazabilidad
   - Permite marcar pagos individuales después

2. **Usa la constancia masiva para el banco:**
   - Más fácil de procesar
   - Un solo documento

3. **Puedes usar ambas:**
   - Constancia masiva para el banco
   - Constancias individuales para archivo/empleados

---

**Última actualización:** Documentación de constancias bancarias
**Versión:** 1.0

