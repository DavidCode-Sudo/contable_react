# Guía: Reglas de Nómina Configuradas

## 📋 Reglas Configuradas según Recibo de Alcaldía

He configurado todas las reglas que aparecen en el recibo de pago de la Alcaldía de Caracas.

---

## ✅ REMUNERACIONES (8 reglas)

### **1. Prima de Profesionalización**
- **Código:** `PRIMA_PROF`
- **Tipo:** Percepción
- **Método:** Porcentaje del Salario (20%)
- **Configuración:** Se calcula automáticamente como 20% del salario base
- **Uso:** Asignar a empleados profesionales según su categoría

### **2. Prima por Antigüedad**
- **Código:** `PRIMA_ANT`
- **Tipo:** Percepción
- **Método:** Porcentaje del Salario (3%)
- **Configuración:** Se calcula automáticamente como 3% del salario base
- **Uso:** Asignar a todos los empleados según años de servicio

### **3. Otros Complementos**
- **Código:** `COMPLEMENTOS`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para bonos o complementos especiales variables

### **4. Prima por Hijo**
- **Código:** `PRIMA_HIJO`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Asignar a empleados con hijos según política de la institución

### **5. Complemento Servicios**
- **Código:** `COMP_SERVICIOS`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para servicios adicionales prestados

### **6. Bono Vacacional**
- **Código:** `BONO_VACACIONAL`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para bonos de vacaciones (generalmente se calcula una vez al año)

### **7. Conciertos de cámara**
- **Código:** `CONCIERTOS_CAMARA`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Específico para músicos de orquesta sinfónica

### **8. Aguinaldos**
- **Código:** `AGUINALDOS`
- **Tipo:** Percepción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Aguinaldos (generalmente se calcula una vez al año)

---

## ✅ DEDUCCIONES (12 reglas)

### **9. Retardos**
- **Código:** `RETARDOS`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente según incidencias
- **Uso:** Para deducir por llegadas tardías

### **10. S.S.O. (Seguro Social Obligatorio / IVSS)**
- **Código:** `SSO`
- **Tipo:** Deducción
- **Método:** Porcentaje del Salario (4%)
- **Configuración:** Se calcula automáticamente como 4% del salario base
- **Uso:** Asignar a TODOS los empleados (obligatorio)

### **11. Paro Forzoso**
- **Código:** `PARO_FORZOSO`
- **Tipo:** Deducción
- **Método:** Porcentaje del Salario (0.5%)
- **Configuración:** Se calcula automáticamente como 0.5% del salario base
- **Uso:** Asignar a TODOS los empleados (obligatorio)

### **12. FAOV (Fondo de Ahorro Obligatorio para la Vivienda)**
- **Código:** `FAOV`
- **Tipo:** Deducción
- **Método:** Porcentaje del Salario (1%)
- **Configuración:** Se calcula automáticamente como 1% del salario base
- **Uso:** Asignar a TODOS los empleados (obligatorio)

### **13. Retención Caja de Ahorros**
- **Código:** `RET_CAJA_AHORROS`
- **Tipo:** Deducción
- **Método:** Porcentaje del Salario (2%)
- **Configuración:** Se calcula automáticamente como 2% del salario base
- **Uso:** Asignar a empleados que tienen retención voluntaria en caja de ahorros

### **14. Préstamos Caja de Ahorros**
- **Código:** `PREST_CAJA_AHORROS`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para deducir cuotas de préstamos de caja de ahorros

### **15. Ret. SIPRES**
- **Código:** `RET_SIPRES`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Retención del sistema de pensiones

### **16. Préstamos Fundación**
- **Código:** `PREST_FUNDACION`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para deducir cuotas de préstamos de la fundación

### **17. Dieta Comité**
- **Código:** `DIETA_COMITE`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para deducir dietas de comités

### **18. Monte Pío**
- **Código:** `MONTE_PIO`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para deducir aportes a monte pío

### **19. Otros descuentos (Caja Clap)**
- **Código:** `DESC_CAJA_CLAP`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para descuentos de caja CLAP

### **20. Otros descuentos**
- **Código:** `OTROS_DESCUENTOS`
- **Tipo:** Deducción
- **Método:** Personalizado
- **Configuración:** Se debe configurar manualmente por empleado
- **Uso:** Para cualquier otro descuento no contemplado

---

## 📝 Cómo Usar las Reglas

### **Paso 1: Ejecutar el Script SQL**

```sql
-- Ejecutar el script:
SOURCE database/scripts/agregar_reglas_nomina_alcaldia.sql;
```

O desde phpMyAdmin:
1. Abrir phpMyAdmin
2. Seleccionar la base de datos
3. Ir a la pestaña "SQL"
4. Copiar y pegar el contenido del archivo
5. Ejecutar

### **Paso 2: Verificar que se Crearon**

```sql
SELECT codigo, nombre, tipo, base_calculo, valor, orden 
FROM conceptos_nomina 
WHERE estado = 'activo' 
ORDER BY tipo, orden;
```

### **Paso 3: Asignar Reglas a Empleados**

1. Ir a: `modulos/rrhh/gestion_empleado_conceptos.php`
2. Seleccionar un empleado
3. Hacer clic en "Agregar Concepto"
4. Seleccionar la regla de la lista
5. Configurar según el tipo:

#### **Para Reglas Automáticas (Porcentaje):**
```
- Seleccionar concepto (ej: IVSS)
- Método: % del Salario (ya está configurado)
- Parámetro: 4.00 (ya está configurado)
- Cantidad: 1
- Estado: Activo
```

#### **Para Reglas Personalizadas:**
```
- Seleccionar concepto (ej: Bono Vacacional)
- Método: Fijo (si es un monto fijo)
- Parámetro: 500.00 (el monto)
- Cantidad: 1
- Estado: Activo

O si es personalizado:
- Método: Personalizado
- Parámetro: 0.00 (se calculará manualmente)
- Cantidad: 1
- Estado: Activo
```

---

## 🎯 Configuración Recomendada por Empleado

### **Reglas Obligatorias (Todos los Empleados):**

```
✅ S.S.O. (SSO) - 4% del salario
✅ Paro Forzoso - 0.5% del salario
✅ FAOV - 1% del salario
```

### **Reglas Opcionales (Según Empleado):**

```
⚪ Prima de Profesionalización - 20% (solo profesionales)
⚪ Prima por Antigüedad - 3% (todos los empleados)
⚪ Retención Caja de Ahorros - 2% (si tienen ahorro)
⚪ Préstamos Caja de Ahorros - monto fijo (si tienen préstamo)
⚪ Préstamos Fundación - monto fijo (si tienen préstamo)
⚪ Otros conceptos según necesidades
```

---

## 💡 Ajustes de Porcentajes

Los porcentajes configurados son estándar, pero puedes ajustarlos:

### **Para Cambiar un Porcentaje:**

1. Ir a: `modulos/rrhh/gestion_conceptos.php`
2. Buscar el concepto (ej: "Prima de Profesionalización")
3. Hacer clic en "Editar"
4. Cambiar el valor del parámetro:
   - **20.00** = 20%
   - **15.00** = 15%
   - **10.00** = 10%
   - etc.
5. Guardar

### **Ejemplo de Ajuste:**

Si la Prima de Profesionalización debe ser 15% en lugar de 20%:
```
1. Editar concepto PRIMA_PROF
2. Cambiar Parámetro de 20.00 a 15.00
3. Guardar
```

---

## 📊 Ejemplo de Asignación Completa

### **Empleado: Músico Principal**

**Remuneraciones:**
- ✅ Prima de Profesionalización (20% automático)
- ✅ Prima por Antigüedad (3% automático)
- ✅ Conciertos de cámara (personalizado, según conciertos)

**Deducciones:**
- ✅ S.S.O. (4% automático)
- ✅ Paro Forzoso (0.5% automático)
- ✅ FAOV (1% automático)
- ✅ Retención Caja de Ahorros (2% automático)
- ✅ Préstamos Fundación (Bs. 80.00 fijo)

---

## ⚠️ Notas Importantes

1. **Sueldo Básico:** NO se crea como regla porque es el `salario_base` del empleado, que ya está en la tabla `empleados`.

2. **Conceptos Personalizados:** Los conceptos con método "personalizado" y valor 0.0000 deben configurarse manualmente por empleado según sus necesidades específicas.

3. **Porcentajes Estándar:** Los porcentajes configurados (4% IVSS, 1% FAOV, etc.) son según normativa venezolana. Verifica si tu organización tiene porcentajes diferentes.

4. **Orden de Aplicación:** Las reglas se aplican en el orden definido (campo `orden`), pero esto solo afecta la visualización en el recibo, no el cálculo final.

5. **Conceptos Vacíos:** Los conceptos que aparecen en el recibo pero sin monto (ej: "Bono Vacacional") solo se mostrarán si tienen un valor asignado al empleado.

---

## ✅ Checklist de Configuración

- [ ] Ejecutar script SQL para crear reglas
- [ ] Verificar que todas las reglas se crearon correctamente
- [ ] Asignar reglas obligatorias a todos los empleados:
  - [ ] S.S.O. (4%)
  - [ ] Paro Forzoso (0.5%)
  - [ ] FAOV (1%)
- [ ] Asignar reglas opcionales según cada empleado
- [ ] Ajustar porcentajes si es necesario
- [ ] Probar generando una nómina de prueba

---

**¡Listo! Todas las reglas están configuradas y listas para usar.** 🎉

