# Flujos Completos del Sistema Contable
## Descripción Natural y Humanizada para Presentación

---

## PARTE I: FLUJO COMPLETO DE REQUISICIONES

### El Viaje de una Requisición: Desde la Necesidad hasta el Pago

Imagina que necesitas comprar materiales de oficina para tu departamento. El sistema te guía paso a paso desde que identificas la necesidad hasta que el proveedor recibe su pago y todo queda registrado contablemente.

#### **PASO 1: Crear la Requisición (El Inicio del Viaje)**

Todo comienza cuando un usuario del sistema identifica una necesidad. Por ejemplo, el departamento de administración necesita comprar papel, tinta para impresoras y carpetas.

El usuario accede al módulo de Requisiciones y crea una nueva requisición. En este momento, el sistema le pide:

- **¿Qué necesitas?** Aquí describe los productos o servicios requeridos
- **¿Cuándo lo necesitas?** Define la fecha requerida
- **¿Cuánto cuesta?** Ingresa los ítems con sus cantidades y precios
- **¿De qué partida presupuestaria saldrá el dinero?** Selecciona la partida subespecífica correspondiente (por ejemplo, 401.02.01.01 - Materiales de Oficina)

En este punto, el sistema calcula automáticamente:
- El subtotal de todos los ítems
- Los impuestos (IVA) si aplican
- El total final en Bolívares

La requisición se guarda con estado **"borrador"**. Esto significa que aún puede ser editada, modificada o incluso eliminada si es necesario. El sistema no ha comprometido ningún presupuesto todavía.

**¿Qué pasa con el presupuesto en este momento?** Nada. El presupuesto permanece intacto. La requisición es solo una solicitud pendiente de aprobación.

---

#### **PASO 2: Enviar para Aprobación (La Primera Validación)**

Una vez que el usuario completa la requisición y está satisfecho con los datos, la envía para aprobación. El sistema cambia el estado de **"borrador"** a **"pendiente"**.

Ahora la requisición aparece en la lista de pendientes de aprobación para los supervisores o jefes de departamento que tienen permisos para aprobar requisiciones.

**¿Qué pasa con el presupuesto?** Todavía nada. El presupuesto sigue sin verse afectado porque la requisición solo está esperando aprobación administrativa.

---

#### **PASO 3: Aprobación Administrativa (Doble Llave de Aprobación)**

El sistema implementa un mecanismo de seguridad llamado "doble llave de aprobación". Esto significa que la requisición debe ser aprobada por dos personas diferentes, garantizando un control adecuado sobre los gastos.

**Primera Aprobación (Nivel 1):**
Un supervisor o jefe de departamento revisa la requisición. Verifica que:
- Los productos o servicios solicitados sean necesarios
- Los precios sean razonables
- La justificación sea válida

Si aprueba, el sistema registra esta primera aprobación y la requisición queda lista para la segunda validación.

**Segunda Aprobación (Nivel 2 - Validación Presupuestaria):**
Ahora viene la parte más importante: la validación presupuestaria. Un usuario del área de presupuesto (o alguien con permisos especiales) revisa la requisición, pero esta vez el sistema hace algo muy inteligente:

**El sistema verifica automáticamente:**
- ¿Existe suficiente presupuesto disponible en la partida seleccionada?
- ¿El monto de la requisición no excede el crédito vigente menos lo ya comprometido y causado?
- ¿Hay disponibilidad en el mes correspondiente?

Si hay suficiente presupuesto, el sistema aprueba la requisición y cambia su estado a **"aprobada"**. Si no hay suficiente presupuesto, el sistema **bloquea la aprobación** y muestra un mensaje claro indicando cuánto dinero falta.

**¿Qué pasa con el presupuesto ahora?** Todavía no se compromete nada. El presupuesto sigue intacto, pero el sistema ya sabe que hay una requisición aprobada que eventualmente consumirá ese presupuesto.

---

#### **PASO 4: Generar Compromiso Presupuestario (El Dinero se Reserva)**

Una vez que la requisición está aprobada, el siguiente paso es generar un **compromiso presupuestario**. Este es un concepto muy importante en administración pública.

**¿Qué es un compromiso?** Es la reserva del dinero en el presupuesto. Es como cuando vas a un restaurante y haces una reserva: el restaurante guarda una mesa para ti, pero aún no has comido. De la misma manera, el compromiso "reserva" el dinero del presupuesto para esta requisición específica.

Cuando el usuario genera el compromiso desde la requisición aprobada, el sistema:

1. **Crea un registro de compromiso** con un número único (por ejemplo: CP-2025-00001)
2. **Actualiza el presupuesto** incrementando el campo "comprometido"
3. **Calcula la nueva disponibilidad** restando lo comprometido del crédito vigente

**Ahora sí, el presupuesto se ve afectado:**
- **Crédito Vigente:** Se mantiene igual (es el presupuesto total asignado)
- **Comprometido:** Aumenta con el monto de la requisición
- **Disponible:** Disminuye porque ahora hay dinero "reservado" para esta requisición

**Ejemplo práctico:**
Si la partida 401.02.01.01 tenía:
- Crédito Vigente: Bs. 100.000,00
- Comprometido: Bs. 20.000,00
- Disponible: Bs. 80.000,00

Y generas un compromiso de Bs. 15.000,00, ahora tendrá:
- Crédito Vigente: Bs. 100.000,00 (no cambia)
- Comprometido: Bs. 35.000,00 (aumentó en 15.000)
- Disponible: Bs. 65.000,00 (disminuyó en 15.000)

El compromiso tiene estado **"vigente"**, lo que significa que el dinero está reservado y listo para ser usado cuando se ejecute la compra.

---

#### **PASO 5: Recibir los Productos o Servicios (El Devengado o Causado)**

Cuando los productos llegan o el servicio se presta, el sistema registra la recepción. Esto es lo que en contabilidad pública se llama **"causado"** o **"devengado"**.

**¿Qué significa causado?** Significa que la obligación ya se cumplió. El proveedor entregó los productos o prestó el servicio, por lo tanto, la institución tiene una deuda con el proveedor que debe ser pagada.

Cuando se registra la recepción, el sistema:

1. **Crea un registro de causado** vinculado al compromiso
2. **Actualiza el presupuesto** incrementando el campo "causado"
3. **Actualiza el estado del compromiso** a reflejar que ya se causó
4. **Cambia el estado de la requisición** a **"recibida"**

**El presupuesto ahora muestra:**
- **Causado:** Aumenta con el monto (indica que ya se recibió el bien o servicio)
- **Por Pagar:** Aumenta (indica que hay una deuda pendiente de pago)
- **Disponible:** Sigue disminuida porque el dinero está comprometido y causado

**Ejemplo:**
Continuando con el ejemplo anterior, después del causado:
- Crédito Vigente: Bs. 100.000,00
- Comprometido: Bs. 35.000,00
- **Causado: Bs. 15.000,00** (nuevo)
- **Por Pagar: Bs. 15.000,00** (nuevo)
- Disponible: Bs. 65.000,00

---

#### **PASO 6: Crear Orden de Pago (La Autorización para Pagar)**

Ahora que los productos están recibidos y el causado está registrado, es momento de autorizar el pago. El sistema crea una **Orden de Pago**.

La orden de pago es como un cheque o una autorización bancaria. Contiene toda la información necesaria para que el banco pueda hacer la transferencia:

- **Beneficiario:** El nombre del proveedor
- **Monto:** El monto a pagar (puede incluir retenciones)
- **Datos bancarios:** Banco, número de cuenta, tipo de cuenta
- **Concepto:** Descripción del pago
- **Partida presupuestaria:** La misma partida de la requisición

Cuando se crea la orden de pago, el sistema:

1. **Genera un número único** de orden (por ejemplo: OP-2025-00001)
2. **Vincula la orden con la requisición** y el compromiso
3. **Mantiene el presupuesto igual** (ya estaba comprometido y causado)

La orden se crea con estado **"emitida"**, lo que significa que está lista para ser procesada por el banco, pero aún no se ha pagado.

**¿Qué pasa si hay retenciones?** Si el proveedor es una persona jurídica, el sistema calcula automáticamente las retenciones (ISLR 1x1000 e IVA 1x25) y genera recibos de retención separados. El monto que se paga al proveedor es el monto neto (después de descontar las retenciones).

---

#### **PASO 7: Ejecutar el Pago (El Dinero Sale del Banco)**

Cuando el banco ejecuta la transferencia y confirma el pago, el usuario marca la orden de pago como **"pagada"** en el sistema. En este momento, el usuario ingresa:

- **Fecha de pago:** La fecha real en que se ejecutó la transferencia
- **Referencia bancaria:** El número de referencia que el banco proporciona

**Aquí es donde la magia contable sucede.** El sistema automáticamente:

1. **Registra el pago en la tabla de pagos presupuestarios** con un número único (PG-2025-00001)
2. **Actualiza el presupuesto** incrementando el campo "pagado"
3. **Actualiza el estado del causado** a "pagado"
4. **Genera el asiento contable automáticamente**

**El asiento contable que se genera es:**

```
DEBE:  [Partida Presupuestaria - Ej: 401.02.01.01 Materiales de Oficina]  → Bs. 15.000,00
HABER: [Cuenta Bancaria - Ej: 1.1.1.01.00 Bancos]                        → Bs. 15.000,00
```

Este asiento refleja que:
- Se ejecutó un gasto en la partida presupuestaria (DEBE)
- El dinero salió del banco (HABER)

**El presupuesto final muestra:**
- Crédito Vigente: Bs. 100.000,00
- Comprometido: Bs. 35.000,00
- Causado: Bs. 15.000,00
- **Pagado: Bs. 15.000,00** (nuevo - indica que ya se pagó)
- Disponible: Bs. 65.000,00

**¿Qué pasa con las retenciones?** Si hubo retenciones, el sistema también genera asientos contables para ellas:

```
DEBE:  [Partida Presupuestaria]                    → Bs. 15.000,00 (monto bruto)
HABER: [Cuenta Bancaria]                           → Bs. 14.500,00 (monto neto pagado)
HABER: [Retención ISLR 1x1000]                     → Bs. 15,00
HABER: [Retención IVA 1x25]                        → Bs. 485,00
```

Esto refleja que el proveedor recibió el monto neto, pero la institución retuvo y debe pagar a la administración tributaria las retenciones correspondientes.

---

#### **PASO 8: Actualización Automática de Ejecución Financiera**

El sistema tiene un mecanismo muy inteligente: cuando se registra un pago, automáticamente se actualiza la tabla de **ejecución financiera mensual**. Esta tabla es la base para todos los reportes presupuestarios.

**¿Qué es la ejecución financiera?** Es un registro detallado mes a mes de cómo se está ejecutando el presupuesto. Para cada partida presupuestaria, el sistema guarda:

- Cuánto se presupuestó (crédito inicial)
- Cuánto se ha comprometido
- Cuánto se ha causado
- Cuánto se ha pagado
- Cuánto queda disponible

Todo esto se actualiza automáticamente mediante **triggers** en la base de datos. Cuando insertas un registro en la tabla de pagos presupuestarios, el sistema automáticamente recalcula y actualiza la ejecución financiera del mes correspondiente.

---

#### **Resumen del Flujo de Requisiciones:**

```
1. CREAR REQUISICIÓN (borrador)
   → Presupuesto: Sin cambios

2. ENVIAR PARA APROBACIÓN (pendiente)
   → Presupuesto: Sin cambios

3. APROBAR (aprobada)
   → Presupuesto: Sin cambios (solo validación)

4. GENERAR COMPROMISO (vigente)
   → Presupuesto: Comprometido aumenta, Disponible disminuye

5. RECIBIR PRODUCTOS (recibida - causado)
   → Presupuesto: Causado aumenta, Por Pagar aumenta

6. CREAR ORDEN DE PAGO (emitida)
   → Presupuesto: Sin cambios (ya estaba comprometido y causado)

7. MARCAR COMO PAGADA (pagada)
   → Presupuesto: Pagado aumenta
   → Asiento Contable: DEBE Partida / HABER Banco

8. ACTUALIZACIÓN AUTOMÁTICA
   → Ejecución Financiera Mensual se actualiza automáticamente
```

---

## PARTE II: FLUJO COMPLETO DE NÓMINAS

### El Viaje de una Nómina: Desde la Configuración hasta el Pago de los Empleados

El sistema de nóminas es el proceso más complejo y crítico del sistema, ya que involucra el pago de las personas que trabajan en la institución. Te explico paso a paso cómo funciona.

#### **PASO 1: Configuración Inicial (La Base de Todo)**

Antes de generar cualquier nómina, el sistema debe estar configurado correctamente. Esta configuración se hace una vez y luego se reutiliza.

**1.1. Configurar Conceptos de Nómina**

Los conceptos son los diferentes tipos de percepciones (lo que se paga) y deducciones (lo que se descuenta) que pueden aplicarse a los empleados.

**Ejemplos de percepciones:**
- Sueldo Base
- Bono Alimentación
- Prima de Antigüedad
- Horas Extras
- Bono de Productividad

**Ejemplos de deducciones:**
- IVSS (Instituto Venezolano de Seguros Sociales)
- FAOV (Fondo de Ahorro Obligatorio para la Vivienda)
- ISLR (Impuesto Sobre la Renta)
- Descuentos varios

Cada concepto tiene:
- Un código único (ej: "SUELDO", "IVSS", "FAOV")
- Un nombre descriptivo
- Un tipo (percepción o deducción)
- Una base de cálculo (fijo, porcentaje del salario, o personalizado)
- Un valor o porcentaje

**1.2. Configurar Empleados**

Cada empleado debe estar registrado en el sistema con:
- Datos personales (nombres, apellidos, cédula)
- Salario base
- Datos bancarios (banco, número de cuenta, tipo de cuenta)
- Estado (activo o inactivo)
- Fecha de ingreso
- Otros datos relevantes

**1.3. Asignar Conceptos a Empleados**

No todos los empleados tienen los mismos conceptos. Por ejemplo:
- Un empleado puede tener 10% de descuento en Ahorro Habitacional
- Otro puede tener 5%
- Algunos pueden tener bonos especiales
- Otros pueden tener descuentos por préstamos

El sistema permite asignar conceptos específicos a cada empleado con valores personalizados.

**1.4. Configurar Presupuesto para Nóminas**

El sistema debe tener configurada una partida presupuestaria específica para nóminas. Típicamente es la partida **401.01.01.01 - Sueldos básicos personal fijo a tiempo completo** o similar.

Esta partida debe tener un presupuesto asignado con distribución mensual (cuánto dinero hay disponible cada mes del año).

---

#### **PASO 2: Crear Período de Nómina (Definir el Marco Temporal)**

Antes de generar una nómina, debes crear un **período de nómina**. Un período define el marco temporal para el cual se pagará a los empleados.

**Ejemplos de períodos:**
- Enero 2025 (del 1 al 31 de enero)
- Primera Quincena de Enero 2025 (del 1 al 15 de enero)
- Diciembre 2024 (del 1 al 31 de diciembre)

El período tiene:
- Un código único (ej: "ENE-2025", "Q1-ENE-2025")
- Una descripción
- Fecha de inicio y fecha de fin
- Periodicidad (semanal, quincenal, mensual)
- Estado (abierto o cerrado)

Solo se pueden generar nóminas para períodos que estén en estado **"abierto"**.

---

#### **PASO 3: Generar la Nómina (El Cálculo Automático)**

Una vez que tienes el período creado y los empleados configurados, puedes generar la nómina. El sistema hace todo el trabajo pesado automáticamente.

**3.1. Selección de Empleados**

El sistema te permite:
- Seleccionar todos los empleados activos automáticamente
- O seleccionar empleados específicos manualmente

**3.2. Cálculo Automático por Empleado**

Para cada empleado seleccionado, el sistema:

1. **Obtiene el salario base** del empleado
2. **Obtiene todos los conceptos asignados** a ese empleado
3. **Calcula cada concepto:**
   - Si es "fijo": usa el valor directamente
   - Si es "porcentaje del salario": multiplica el salario por el porcentaje
   - Si es "personalizado": aplica la fórmula específica
4. **Suma todas las percepciones** (salario base + bonos + otros)
5. **Suma todas las deducciones** (IVSS + FAOV + ISLR + otros)
6. **Calcula el neto a pagar:** Percepciones - Deducciones

**Ejemplo de cálculo:**
```
Empleado: Juan Pérez
Salario Base: Bs. 5.000,00

PERCEPCIONES:
- Sueldo Base: Bs. 5.000,00
- Bono Alimentación: Bs. 500,00
Total Percepciones: Bs. 5.500,00

DEDUCCIONES:
- IVSS (4%): Bs. 200,00
- FAOV (1%): Bs. 50,00
- ISLR: Bs. 100,00
Total Deducciones: Bs. 350,00

NETO A PAGAR: Bs. 5.150,00
```

**3.3. Generación de Recibos**

Para cada empleado, el sistema genera automáticamente un **recibo de pago** (baunche) en formato HTML. Este recibo contiene:
- Datos del empleado
- Período de pago
- Desglose de percepciones
- Desglose de deducciones
- Neto a pagar
- Información de la institución

**3.4. Validación Presupuestaria Inicial**

Antes de crear la nómina, el sistema valida que haya suficiente presupuesto disponible. Esta es una validación **estimada** que te advierte si no hay suficiente dinero, pero no bloquea la creación.

**3.5. Creación del Registro de Nómina**

El sistema crea un registro principal de nómina con:
- Un número único (ej: NOM-2025-00001)
- El período asociado
- Fecha de generación
- Estado: **"borrador"**
- Totales: bruto, deducciones, neto

Y para cada empleado, crea un registro en la tabla de empleados de nómina con:
- El empleado
- Su recibo número
- Sus totales individuales
- Estado: **"pendiente"**

**¿Qué pasa con el presupuesto en este momento?** Nada todavía. La nómina está en borrador y puede ser editada o eliminada.

---

#### **PASO 4: Enviar a Aprobación Presupuestaria (La Validación Formal)**

Una vez que revisas la nómina generada y estás satisfecho con los cálculos, la envías para aprobación presupuestaria. El sistema cambia el estado de **"borrador"** a **"pendiente_validacion_presupuesto"**.

Ahora la nómina aparece en el módulo de Presupuestos, en la sección de "Nóminas Pendientes de Aprobación".

---

#### **PASO 5: Aprobación Presupuestaria (La Validación Estricta)**

Un usuario del área de presupuesto (o alguien con permisos especiales) revisa la nómina. El sistema realiza una **validación estricta** del presupuesto:

**El sistema verifica:**
- ¿Hay suficiente presupuesto disponible en la partida seleccionada?
- ¿El monto de la nómina no excede el crédito vigente?
- ¿Hay disponibilidad en el mes correspondiente al período de la nómina?

**Validación Mensual:**
El sistema es muy inteligente. No solo verifica el presupuesto anual, sino que verifica específicamente el mes del período de nómina. Por ejemplo:
- Si la nómina es de enero, verifica el presupuesto de enero
- Si la nómina es de febrero, verifica el presupuesto de febrero

**Si hay suficiente presupuesto:**
El sistema aprueba la nómina y cambia su estado a **"aprobada_presupuesto"**. Además, guarda un "snapshot" (fotografía) completa del estado del presupuesto en ese momento, incluyendo:
- Crédito vigente
- Comprometido
- Causado
- Pagado
- Disponible

Este snapshot es muy importante para auditoría, porque permite saber exactamente cómo estaba el presupuesto cuando se aprobó la nómina.

**Si NO hay suficiente presupuesto:**
El sistema **bloquea la aprobación** y muestra un mensaje claro indicando:
- Cuánto presupuesto hay disponible
- Cuánto se necesita
- Cuánto falta

**¿Qué pasa con el presupuesto?** Todavía no se compromete nada. El presupuesto sigue intacto, pero el sistema ya sabe que hay una nómina aprobada que consumirá ese presupuesto.

---

#### **PASO 6: Confirmar la Nómina (El Registro Contable y el Causado)**

Una vez que la nómina está aprobada presupuestariamente, el siguiente paso es **confirmarla**. Esta es una acción muy importante porque aquí es donde:

1. Se registra como **causado** en el presupuesto
2. Se genera el **asiento contable** de causación
3. La nómina queda lista para ser pagada

**6.1. Validación de Aprobación**

El sistema verifica que la nómina esté en estado **"aprobada_presupuesto"**. Si no lo está, no permite confirmarla.

**6.2. Búsqueda de Cuentas Contables**

El sistema busca automáticamente las cuentas contables necesarias:
- **Cuenta de Gasto de Nómina:** Busca una cuenta que contenga "nómina", "nomina" o "sueld" en el nombre
- **Cuenta de Sueldos por Pagar:** Busca una cuenta que contenga "sueldos por pagar" o "por pagar"

**6.3. Generación del Asiento Contable de Causación**

El sistema genera automáticamente un asiento contable con esta estructura:

```
DEBE:  [Partida Presupuestaria - Ej: 401.01.01.01 Sueldos]  → Bs. 176.894,00
HABER: [Sueldos por Pagar - Pasivo]                         → Bs. 176.894,00
```

Este asiento refleja que:
- Se registró un gasto en la partida presupuestaria (DEBE)
- Se creó una obligación de pago (HABER - pasivo)

**¿Por qué se usa "Sueldos por Pagar"?** Porque en el momento de confirmar la nómina, aún no se ha pagado a los empleados. La institución tiene una deuda con los empleados que debe ser pagada. Esto es contabilidad por el principio de causación: se registra el gasto cuando se causa (cuando se genera la nómina), no cuando se paga.

**6.4. Registro en el Presupuesto como Causado**

El sistema actualiza el presupuesto:
- **Causado:** Aumenta con el monto total de la nómina
- **Por Pagar:** Aumenta (indica que hay una deuda pendiente)
- **Disponible:** Disminuye porque el dinero está comprometido y causado

**Ejemplo:**
Si la partida 401.01.01.01 tenía:
- Crédito Vigente: Bs. 500.000,00
- Comprometido: Bs. 0,00
- Causado: Bs. 0,00
- Disponible: Bs. 500.000,00

Y confirmas una nómina de Bs. 176.894,00, ahora tendrá:
- Crédito Vigente: Bs. 500.000,00 (no cambia)
- Comprometido: Bs. 0,00 (las nóminas no pasan por compromiso)
- **Causado: Bs. 176.894,00** (aumentó)
- **Por Pagar: Bs. 176.894,00** (aumentó)
- **Disponible: Bs. 323.106,00** (disminuyó)

**6.5. Registro de Deducciones en Presupuestos Específicos**

El sistema es muy inteligente. Si las deducciones (como IVSS o FAOV) tienen partidas presupuestarias específicas asignadas, el sistema también registra esas deducciones en sus respectivos presupuestos.

Por ejemplo:
- Si el IVSS tiene asignada la partida 411.01.01.01 (Disminución de Pasivos - IVSS)
- El sistema registra el monto total de IVSS retenido en esa partida

**6.6. Cambio de Estado**

El sistema cambia el estado de la nómina a **"confirmada"** y el estado de cada empleado a **"pendiente"** (listo para ser pagado).

**¿Por qué las nóminas no pasan por "compromiso"?** Porque las nóminas son gastos recurrentes y predecibles. No necesitas "reservar" el dinero porque ya sabes que debes pagarlo. Directamente se registra como causado cuando se confirma.

---

#### **PASO 7: Preparar el Pago (Generar Archivo para el Banco)**

Una vez que la nómina está confirmada, el sistema permite generar un archivo de texto con la información de los empleados para cargar en el sistema bancario (anteriormente PATRIA, ahora cualquier sistema bancario).

Este archivo contiene:
- Cédula del empleado
- Nombre (solo primer nombre)
- Apellido (solo primer apellido)
- Número de cuenta bancaria

El formato es: `Cédula|Nombre|Apellido|Cuenta Bancaria`

Este archivo se descarga y se carga en el sistema bancario para que el banco ejecute las transferencias masivas a todos los empleados.

---

#### **PASO 8: Marcar como Pagada (El Pago Real y el Asiento Final)**

Cuando el banco ejecuta las transferencias y confirma los pagos, el usuario marca la nómina como **"pagada"** en el sistema. En este momento, el usuario ingresa:

- **Fecha de pago:** La fecha real en que se ejecutaron las transferencias
- **Referencia bancaria:** El número de referencia que el banco proporciona (muy importante para conciliación)

**Aquí es donde sucede la magia contable final.** El sistema automáticamente:

**8.1. Actualiza el Estado de los Empleados**

Cada empleado en la nómina cambia su estado de **"pendiente"** a **"pagado"**.

**8.2. Actualiza el Presupuesto**

El sistema actualiza el presupuesto:
- **Pagado:** Aumenta con el monto total de la nómina
- **Por Pagar:** Disminuye (porque ya se pagó)

**8.3. Genera el Asiento Contable del Pago**

El sistema genera automáticamente un asiento contable con esta estructura:

```
DEBE:  [Partida Presupuestaria - Ej: 401.01.01.01 Sueldos]  → Bs. 176.894,00
HABER: [Cuenta Bancaria - Ej: 1.1.1.01.00 Bancos]           → Bs. 176.894,00
```

Este asiento refleja que:
- Se ejecutó el gasto en la partida presupuestaria (DEBE)
- El dinero salió del banco (HABER)

**¿Por qué se debita la partida presupuestaria directamente y no "Sueldos por Pagar"?** Porque en administración pública venezolana, el sistema debe reflejar directamente el gasto en la partida presupuestaria. El asiento de causación (Sueldos por Pagar) es para control interno, pero el asiento de pago debe afectar directamente la partida.

**8.4. Registra el Pago Presupuestario**

El sistema crea un registro en la tabla de **pagos presupuestarios** con:
- El presupuesto afectado
- El monto pagado
- La fecha de pago
- La referencia bancaria
- El asiento contable generado

Este registro es muy importante porque:
- Se usa para los reportes de ejecución presupuestaria
- Se usa para la relación de gastos
- Se usa para la conciliación bancaria

**8.5. Actualización Automática de Ejecución Financiera**

Al igual que con las requisiciones, cuando se registra el pago de una nómina, el sistema automáticamente actualiza la tabla de **ejecución financiera mensual** mediante triggers.

**El presupuesto final muestra:**
- Crédito Vigente: Bs. 500.000,00
- Comprometido: Bs. 0,00
- Causado: Bs. 176.894,00
- **Pagado: Bs. 176.894,00** (aumentó - indica que ya se pagó)
- Por Pagar: Bs. 0,00 (disminuyó porque ya se pagó)
- Disponible: Bs. 323.106,00

---

#### **Resumen del Flujo de Nóminas:**

```
1. CONFIGURACIÓN INICIAL
   → Conceptos, Empleados, Asignaciones, Presupuesto
   → Presupuesto: Sin cambios

2. CREAR PERÍODO
   → Define el marco temporal
   → Presupuesto: Sin cambios

3. GENERAR NÓMINA (borrador)
   → Cálculo automático por empleado
   → Generación de recibos
   → Validación presupuestaria estimada
   → Presupuesto: Sin cambios

4. ENVIAR A APROBACIÓN (pendiente_validacion_presupuesto)
   → Presupuesto: Sin cambios

5. APROBAR PRESUPUESTO (aprobada_presupuesto)
   → Validación estricta mensual
   → Snapshot del presupuesto
   → Presupuesto: Sin cambios (solo validación)

6. CONFIRMAR NÓMINA (confirmada)
   → Asiento Contable: DEBE Partida / HABER Sueldos por Pagar
   → Presupuesto: Causado aumenta, Por Pagar aumenta, Disponible disminuye

7. PREPARAR PAGO
   → Generar archivo TXT para banco
   → Presupuesto: Sin cambios

8. MARCAR COMO PAGADA (pagada)
   → Asiento Contable: DEBE Partida / HABER Banco
   → Presupuesto: Pagado aumenta, Por Pagar disminuye
   → Ejecución Financiera se actualiza automáticamente
```

---

## PARTE III: CÓMO TODO SE INTEGRA EN LOS REPORTES

### La Trazabilidad Completa: Desde la Transacción hasta el Reporte

Uno de los aspectos más importantes del sistema es que **todo está conectado**. Cada movimiento, cada pago, cada nómina, se refleja automáticamente en todos los reportes del sistema.

#### **Reportes que se Actualizan Automáticamente:**

**1. Relación de Gastos**
Este reporte muestra todos los gastos ejecutados, incluyendo:
- Requisiciones pagadas (con su número de orden de pago y referencia bancaria)
- Nóminas pagadas (con su número de nómina y referencia bancaria)
- Fecha de pago
- Monto
- Partida presupuestaria
- Beneficiario

**2. Estado de Ejecución del Presupuesto**
Este reporte muestra mes a mes:
- Cuánto se presupuestó
- Cuánto se ha comprometido
- Cuánto se ha causado
- Cuánto se ha pagado
- Cuánto queda disponible
- Porcentaje de ejecución

**3. Estado de Ejecución Financiera**
Este reporte es el más completo. Muestra:
- Ejecución por partida presupuestaria
- Ejecución por mes
- Ejecución acumulada del año
- Comparación con el presupuesto asignado

**4. Estados Financieros**
Los asientos contables generados automáticamente alimentan:
- Balance General
- Estado de Resultados
- Balance de Comprobación

**5. Dashboard Presupuestario**
Muestra un resumen ejecutivo de:
- Presupuesto total
- Ejecutado hasta la fecha
- Disponible
- Porcentaje de ejecución
- Alertas de presupuesto bajo

---

## CONCLUSIÓN: UN SISTEMA INTEGRADO Y AUTOMÁTICO

El sistema está diseñado para que **todo fluya automáticamente**. Una vez que creas una requisición o generas una nómina, el sistema:

1. **Valida el presupuesto** en cada paso crítico
2. **Registra los movimientos** en las tablas correspondientes
3. **Genera los asientos contables** automáticamente
4. **Actualiza los presupuestos** en tiempo real
5. **Refleja todo en los reportes** sin intervención manual

No necesitas hacer cálculos manuales, no necesitas crear asientos contables manualmente, no necesitas actualizar reportes. El sistema lo hace todo por ti, garantizando:

- **Precisión:** Los cálculos son automáticos y consistentes
- **Trazabilidad:** Cada movimiento tiene un registro completo
- **Control:** El presupuesto se valida en cada paso
- **Transparencia:** Todo está documentado y auditado
- **Cumplimiento:** Sigue las normas de administración pública venezolana

Este es un sistema diseñado para la administración pública, donde la transparencia, el control y la trazabilidad son fundamentales.

