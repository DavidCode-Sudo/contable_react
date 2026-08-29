# 📊 Diagrama de Flujo: Proceso de Nóminas

## 🎯 Flujo Completo del Sistema de Nóminas

Este diagrama muestra el proceso completo de generación y pago de nóminas paso a paso.

---

## 📋 DIAGRAMA PRINCIPAL

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    FLUJO DE NÓMINAS - VISTA GENERAL                      │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│  FASE 1: CONFIGURACIÓN INICIAL (Solo Primera Vez o cuando cambie)        │
└─────────────────────────────────────────────────────────────────────────┘

    ┌─────────────────┐
    │  RRHH: Crear    │
    │  Empleados      │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  RRHH: Crear    │
    │  Conceptos      │
    │  (IVSS, Ahorro) │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  RRHH: Asignar  │
    │  Conceptos a    │
    │  Empleados      │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  ✅ Configuración│
    │     Completa    │
    └────────┬────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  FASE 2: PROCESO MENSUAL/QUINCENAL (Se repite cada período)              │
└─────────────────────────────────────────────────────────────────────────┘

    ┌─────────────────┐
    │  RRHH: Crear    │
    │  Período        │
    │  (ENE-2025-Q1)  │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  RRHH: Generar  │
    │  Nómina         │
    │  • Selecciona   │
    │    período      │
    │  • Selecciona   │
    │    empleados    │
    │  • Sistema      │
    │    calcula      │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐      ┌──────────────────┐
    │  Estado:        │──────▶│  BORRADOR        │
    │  borrador       │      │  (Revisable)     │
    └─────────────────┘      └──────────────────┘
             │
             │ Usuario revisa y está conforme
             ▼
    ┌─────────────────┐
    │  RRHH: Enviar a │
    │  Aprobación     │
    │  Presupuestaria │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐      ┌──────────────────┐
    │  Estado:        │──────▶│  PENDIENTE       │
    │  pendiente_     │      │  VALIDACIÓN      │
    │  validacion_    │      │  PRESUPUESTO     │
    │  presupuesto    │      │  ⏳ Esperando     │
    └─────────────────┘      └──────────────────┘
             │
             │ Presupuesto revisa
             ▼
    ┌─────────────────┐
    │  Presupuesto:   │
    │  Validar        │
    │  • Verifica     │
    │    saldo        │
    │  • Aprobar o    │
    │    Rechazar     │
    └────────┬────────┘
             │
        ┌────┴────┐
        │         │
        ▼         ▼
┌──────────┐  ┌──────────┐
│ RECHAZAR │  │ APROBAR  │
└────┬─────┘  └────┬─────┘
     │            │
     │            ▼
     │    ┌─────────────────┐      ┌──────────────────┐
     │    │  Estado:        │──────▶│  APROBADA        │
     │    │  aprobada_      │      │  PRESUPUESTO     │
     │    │  presupuesto    │      │  ✅ Lista para   │
     │    └─────────────────┘      │     confirmar    │
     │                              └──────────────────┘
     │                                        │
     │                                        │ RRHH confirma
     │                                        ▼
     │                              ┌─────────────────┐
     │                              │  RRHH:          │
     │                              │  Confirmar      │
     │                              │  Nómina         │
     │                              └────────┬────────┘
     │                                       │
     │                                       ▼
     │                              ┌─────────────────┐      ┌──────────────────┐
     │                              │  Estado:        │──────▶│  CONFIRMADA      │
     │                              │  confirmada     │      │  ✅ Asiento      │
     │                              └─────────────────┘      │     generado     │
     │                                       │                │  ✅ Causado en   │
     │                                       │                │     presupuesto  │
     │                                       │                └──────────────────┘
     │                                       │
     │                                       │ Generar órdenes
     │                                       ▼
     │                              ┌─────────────────┐
     │                              │  RRHH: Generar  │
     │                              │  Órdenes de     │
     │                              │  Pago           │
     │                              │  (Una por       │
     │                              │   empleado)     │
     │                              └────────┬────────┘
     │                                       │
     │                                       ▼
     │                              ┌─────────────────┐      ┌──────────────────┐
     │                              │  Órdenes:        │──────▶│  EMITIDAS        │
     │                              │  emitidas        │      │  📄 Listas para  │
     │                              └─────────────────┘      │     pagar         │
     │                                       │                └──────────────────┘
     │                                       │
     │                                       │ Ejecutar pagos
     │                                       ▼
     │                              ┌─────────────────┐
     │                              │  Presupuesto:   │
     │                              │  Marcar Órdenes │
     │                              │  como Pagadas   │
     │                              │  (Banco ejecuta)│
     │                              └────────┬────────┘
     │                                       │
     │                                       ▼
     │                              ┌─────────────────┐      ┌──────────────────┐
     │                              │  Órdenes:        │──────▶│  PAGADAS          │
     │                              │  pagadas         │      │  ✅ Recibos       │
     │                              └─────────────────┘      │     pagados       │
     │                                                         │  ✅ Presupuesto   │
     │                                                         │     actualizado   │
     │                                                         └──────────────────┘
     │
     ▼
┌─────────────────┐
│  Estado vuelve  │
│  a: BORRADOR    │
│  (Correcciones)│
└─────────────────┘
```

---

## 🔄 FLUJO DETALLADO POR ESTADO

### **ESTADO 1: BORRADOR** 📝

```
┌─────────────────────────────────────────┐
│  NÓMINA EN ESTADO: BORRADOR            │
├─────────────────────────────────────────┤
│                                         │
│  ✅ Puede:                              │
│  • Ver detalle de empleados            │
│  • Imprimir recibos (preview)          │
│  • Enviar a aprobación presupuestaria  │
│                                         │
│  ❌ No puede:                           │
│  • Confirmar (requiere aprobación)     │
│  • Generar órdenes de pago             │
│                                         │
│  👤 Usuario: RRHH                       │
│  🔐 Permiso: nominas:generar           │
└─────────────────────────────────────────┘
```

### **ESTADO 2: PENDIENTE VALIDACIÓN PRESUPUESTO** ⏳

```
┌─────────────────────────────────────────┐
│  NÓMINA EN ESTADO:                      │
│  PENDIENTE_VALIDACION_PRESUPUESTO      │
├─────────────────────────────────────────┤
│                                         │
│  ⚠️ Esperando:                          │
│  • Aprobación del área de Presupuesto  │
│                                         │
│  ✅ Puede:                              │
│  • Ver detalle                          │
│  • Ver recibos                          │
│                                         │
│  ❌ No puede:                           │
│  • Editar (ya enviada)                  │
│  • Confirmar (sin aprobación)           │
│                                         │
│  👤 Usuario: Presupuesto                │
│  🔐 Permiso: nominas:aprobar_presupuesto│
│                                         │
│  📋 Acción:                             │
│  • Validar presupuesto disponible      │
│  • Aprobar o Rechazar                   │
└─────────────────────────────────────────┘
```

### **ESTADO 3: APROBADA PRESUPUESTO** ✅

```
┌─────────────────────────────────────────┐
│  NÓMINA EN ESTADO:                      │
│  APROBADA_PRESUPUESTO                   │
├─────────────────────────────────────────┤
│                                         │
│  ✅ Aprobada por Presupuesto            │
│  ✅ Validación presupuestaria OK        │
│                                         │
│  ✅ Puede:                              │
│  • Confirmar nómina (RRHH)             │
│  • Ver detalle completo                 │
│                                         │
│  ❌ No puede:                           │
│  • Generar órdenes (sin confirmar)     │
│                                         │
│  👤 Usuario: RRHH                       │
│  🔐 Permiso: nominas:confirmar          │
│                                         │
│  📋 Acción:                             │
│  • Confirmar nómina                    │
│  • Se genera asiento contable          │
│  • Se registra como CAUSADO            │
└─────────────────────────────────────────┘
```

### **ESTADO 4: CONFIRMADA** ✅✅

```
┌─────────────────────────────────────────┐
│  NÓMINA EN ESTADO: CONFIRMADA           │
├─────────────────────────────────────────┤
│                                         │
│  ✅ Confirmada por RRHH                 │
│  ✅ Asiento contable generado           │
│  ✅ Registrada como CAUSADO             │
│                                         │
│  ✅ Puede:                              │
│  • Generar órdenes de pago              │
│  • Ver detalle completo                 │
│  • Exportar PDF Banesco                 │
│                                         │
│  👤 Usuario: RRHH                       │
│  🔐 Permiso: nominas:generar            │
│                                         │
│  📋 Acción:                             │
│  • Generar órdenes de pago              │
│  • Una orden por empleado               │
│  • Órdenes con estado: EMITIDA          │
└─────────────────────────────────────────┘
```

---

## 💰 FLUJO DE ÓRDENES DE PAGO

```
┌─────────────────────────────────────────────────────────┐
│  GENERACIÓN Y PAGO DE ÓRDENES                            │
└─────────────────────────────────────────────────────────┘

    ┌─────────────────┐
    │  Nómina:         │
    │  CONFIRMADA      │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  RRHH: Generar  │
    │  Órdenes de Pago│
    │  (Automático)    │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  Sistema crea:   │
    │  • 1 orden por   │
    │    empleado      │
    │  • Datos bancarios│
    │    automáticos   │
    │  • Estado:       │
    │    EMITIDA       │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  Presupuesto:   │
    │  Exportar       │
    │  Constancias    │
    │  Bancarias      │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  Banco: Ejecuta │
    │  Transferencias │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  Presupuesto:   │
    │  Marcar Orden   │
    │  como PAGADA    │
    │  • Ingresa      │
    │    referencia   │
    │  • Fecha pago   │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────┐
    │  Sistema:        │
    │  Actualiza:      │
    │  • Estado orden: │
    │    PAGADA        │
    │  • Estado recibo:│
    │    PAGADO        │
    │  • Presupuesto:  │
    │    PAGADO +=     │
    │  • Asiento:      │
    │    Generado      │
    └─────────────────┘
```

---

## 👥 ROLES Y PERMISOS

### **RRHH (Recursos Humanos)**

```
┌─────────────────────────────────────────┐
│  ROL: RRHH                              │
├─────────────────────────────────────────┤
│                                         │
│  ✅ Puede hacer:                         │
│  • Crear períodos de nómina             │
│  • Generar nóminas                       │
│  • Enviar a aprobación presupuestaria    │
│  • Confirmar nóminas (si aprobadas)     │
│  • Generar órdenes de pago              │
│  • Ver recibos e imprimir                │
│  • Exportar PDF Banesco                  │
│                                         │
│  ❌ No puede:                            │
│  • Aprobar desde presupuesto             │
│  • Marcar órdenes como pagadas           │
└─────────────────────────────────────────┘
```

### **PRESUPUESTO**

```
┌─────────────────────────────────────────┐
│  ROL: PRESUPUESTO                       │
├─────────────────────────────────────────┤
│                                         │
│  ✅ Puede hacer:                         │
│  • Aprobar nóminas desde presupuesto     │
│  • Rechazar nóminas                      │
│  • Validar disponibilidad presupuestaria │
│  • Marcar órdenes de pago como pagadas   │
│  • Generar constancias bancarias         │
│                                         │
│  ❌ No puede:                            │
│  • Generar nóminas                       │
│  • Confirmar nóminas                     │
│  • Generar órdenes de pago               │
└─────────────────────────────────────────┘
```

---

## 📊 ESTADOS Y TRANSICIONES

```
┌──────────────┐
│  BORRADOR    │
└──────┬───────┘
       │
       │ [Enviar a Aprobación]
       ▼
┌──────────────────────────────┐
│  PENDIENTE_VALIDACION_       │
│  PRESUPUESTO                 │
└──────┬───────────────────────┘
       │
       ├───[Rechazar]───▶ BORRADOR
       │
       │ [Aprobar]
       ▼
┌──────────────────────────────┐
│  APROBADA_PRESUPUESTO        │
└──────┬───────────────────────┘
       │
       │ [Confirmar]
       ▼
┌──────────────┐
│  CONFIRMADA  │
└──────┬───────┘
       │
       │ [Generar Órdenes]
       ▼
┌──────────────┐
│  ÓRDENES     │
│  EMITIDAS    │
└──────┬───────┘
       │
       │ [Marcar como Pagada]
       ▼
┌──────────────┐
│  ÓRDENES     │
│  PAGADAS     │
└──────────────┘
```

---

## ⚠️ PUNTOS DE DECISIÓN

### **DECISIÓN 1: ¿Presupuesto suficiente?**

```
┌─────────────────────────────────────────┐
│  Validación Presupuestaria              │
└─────────────────────────────────────────┘
                    │
                    ▼
        ┌───────────────────────┐
        │  ¿Hay presupuesto     │
        │  suficiente?          │
        └───────┬───────────────┘
                │
        ┌───────┴───────┐
        │               │
        ▼               ▼
   ┌────────┐      ┌────────┐
   │  SÍ    │      │  NO    │
   └───┬────┘      └───┬────┘
       │               │
       ▼               ▼
┌──────────┐      ┌──────────┐
│  APRUEBA │      │  RECHAZA │
│  ✅      │      │  ❌      │
└──────────┘      └──────────┘
```

### **DECISIÓN 2: ¿Todos los recibos pagados?**

```
┌─────────────────────────────────────────┐
│  Verificación de Pagos                 │
└─────────────────────────────────────────┘
                    │
                    ▼
        ┌───────────────────────┐
        │  ¿Todos los recibos    │
        │  están pagados?        │
        └───────┬───────────────┘
                │
        ┌───────┴───────┐
        │               │
        ▼               ▼
   ┌────────┐      ┌────────┐
   │  SÍ    │      │  NO    │
   └───┬────┘      └───┬────┘
       │               │
       ▼               ▼
┌──────────┐      ┌──────────┐
│  Nómina  │      │  Algunos │
│  Completa│      │  pendientes│
│  ✅      │      │  ⏳      │
└──────────┘      └──────────┘
```

---

## 📝 CHECKLIST POR USUARIO

### **Para RRHH:**

```
☐ Crear período de nómina
☐ Generar nómina (seleccionar empleados)
☐ Revisar cálculos y totales
☐ Enviar a aprobación presupuestaria
☐ Esperar aprobación de Presupuesto
☐ Confirmar nómina (si aprobada)
☐ Generar órdenes de pago
☐ Verificar que órdenes se generaron correctamente
```

### **Para Presupuesto:**

```
☐ Ver nóminas pendientes de aprobación
☐ Validar disponibilidad presupuestaria
☐ Aprobar o Rechazar nómina
☐ Exportar constancias bancarias
☐ Cuando banco ejecute pagos:
  ☐ Marcar órdenes como pagadas
  ☐ Ingresar referencia bancaria
  ☐ Verificar que recibos se actualizaron
```

---

## 🎯 RESUMEN VISUAL

```
CONFIGURACIÓN (1 vez)
    ↓
PERÍODO DE NÓMINA
    ↓
GENERAR NÓMINA → BORRADOR
    ↓
ENVIAR A APROBACIÓN → PENDIENTE
    ↓
PRESUPUESTO APROBA → APROBADA
    ↓
RRHH CONFIRMA → CONFIRMADA
    ↓
GENERAR ÓRDENES → EMITIDAS
    ↓
BANCO PAGA → PAGADAS ✅
```

---

## 📚 LEGENDA

- ✅ = Completado / Permitido
- ❌ = No permitido / Bloqueado
- ⏳ = En espera / Pendiente
- 📝 = Estado inicial / Editable
- 🔐 = Requiere permiso específico
- 👤 = Rol de usuario
- 📋 = Acción disponible

---

**Última actualización:** Diagrama de flujo para usuarios
**Versión:** 1.0

