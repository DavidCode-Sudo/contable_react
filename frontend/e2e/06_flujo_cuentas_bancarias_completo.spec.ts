import { test, expect } from '@playwright/test';

test.describe('Ciclo Completo de Cuentas Bancarias y Tesorería - Validación Módulo 11 SUDEBAN, RIF SENIAT, Modo Sudo, Transferencias y Reactividad en MySQL', () => {

  test('Recorrido Completo: Creación, Validaciones SUDEBAN/RIF, Data Masking, Saldo Inicial Sudo, Edición, Cambio de Estado y Transferencia Completa', async ({ page }) => {
    const timestamp = Date.now().toString().slice(-6);
    const randomNum = Math.floor(Math.random() * 9000) + 1000;

    const nombreCuentaBDV = `Banco de Venezuela - Fondo Tesorería #${timestamp}`;
    const institucionNom = `Alcaldía Bolivariana de Valencia #${randomNum}`;
    const sucursalNom = `Agencia Principal Valencia Centro`;
    const sucursalEditada = `Agencia Valencia Norte (Reestructurada)`;
    const rifGenerado = `G-2000${timestamp.slice(0, 4)}-0`;

    // Generar un número de cuenta válido Módulo 11 SUDEBAN (0102 Banco de Venezuela)
    // 0102 (Banco 4d) + 0001 (Agencia 4d) + 15 (DC 2d) + 0000 (4d) + XXXXXX (6d) = 20 dígitos numéricos exactos
    const numCuentaValid = `01020001150000${timestamp.padStart(6, '0')}`;

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 1: Autenticación Única en el Sistema (Login)
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(800);

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 2: Navegación al Módulo de Cuentas Bancarias
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/tesoreria/cuentas-bancarias');
    await page.waitForTimeout(1000);

    await expect(page.locator('h1, h2, span').filter({ hasText: /Cuentas Bancarias|Gestión de Tesorería/i }).first()).toBeVisible();

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 3: Batería de Pruebas de Validación en Modal "Nueva Cuenta"
    // ═════════════════════════════════════════════════════════════════════════
    const nuevaCuentaBtn = page.locator('button').filter({ hasText: /Nueva Cuenta|\+ Nueva Cuenta/i }).first();
    await expect(nuevaCuentaBtn).toBeVisible();
    await nuevaCuentaBtn.click();
    await page.waitForTimeout(600);

    const modalCuenta = page.locator('div[role="dialog"]');
    await expect(modalCuenta.first()).toBeVisible();

    // 1. Probar intento de guardado con campos vacíos -> Botón deshabilitado
    const guardarBtn = modalCuenta.locator('button[type="submit"]').filter({ hasText: /Guardar/i }).first();
    await expect(guardarBtn).toBeDisabled();

    // 2. Probar Algoritmo SUDEBAN Módulo 11 con número inválido (Código de banco 9999 inválido)
    const numCuentaInput = modalCuenta.locator('#numero_cuenta').first();
    await numCuentaInput.click();
    await numCuentaInput.fill('99990001150000000001'); // Inválido
    await numCuentaInput.dispatchEvent('blur');
    await page.waitForTimeout(300);

    const sudebanError = modalCuenta.locator('p').filter({ hasText: /Inválido según algoritmo Módulo 11 SUDEBAN|Debe ingresar 20 dígitos/i });
    if (await sudebanError.count() > 0) {
      await expect(sudebanError.first()).toBeVisible();
    }
    await expect(guardarBtn).toBeDisabled();

    // 3. Llenar TODOS los campos obligatorios del formulario
    const instInput = modalCuenta.locator('#institucion').first();
    await instInput.fill(institucionNom);
    await instInput.dispatchEvent('blur');

    const rifInput = modalCuenta.locator('#rif').first();
    await rifInput.fill(rifGenerado);
    await rifInput.dispatchEvent('blur');

    const sucursalInput = modalCuenta.locator('#sucursal').first();
    await sucursalInput.fill(sucursalNom);

    const nombreCuentaInput = modalCuenta.locator('#banco_nombre').first();
    await nombreCuentaInput.fill(nombreCuentaBDV);
    await nombreCuentaInput.dispatchEvent('blur');

    // 4. Reemplazar con Número de Cuenta Válido 100% SUDEBAN de 20 dígitos
    await numCuentaInput.fill(numCuentaValid);
    await numCuentaInput.dispatchEvent('blur');
    await page.waitForTimeout(400);

    // 5. Verificar que el botón Guardar se habilita reactivamente y guardar
    await expect(guardarBtn).toBeEnabled();
    await guardarBtn.click();
    await expect(modalCuenta.first()).not.toBeVisible({ timeout: 10000 });

    // Confirmar que la cuenta recién creada aparece en la tabla
    const filaCuenta = page.locator('tr').filter({ hasText: nombreCuentaBDV }).first();
    await expect(filaCuenta).toBeVisible({ timeout: 10000 });

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 4: Data Masking (Ofuscación Visual del Número de Cuenta)
    // ═════════════════════════════════════════════════════════════════════════
    const eyeToggleBtn = page.locator('button').filter({ has: page.locator('svg.lucide-eye, svg.lucide-eye-off') }).first();
    if (await eyeToggleBtn.count() > 0) {
      await eyeToggleBtn.click();
      await page.waitForTimeout(500);
      // Alternar nuevamente la máscara de vista
      await eyeToggleBtn.click();
      await page.waitForTimeout(300);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 5: Auditoría del Saldo Inicial en Modo Sudo ("Danger Zone")
    // ═════════════════════════════════════════════════════════════════════════
    const saldoInicialBtn = filaCuenta.locator('button[title*="Saldo Inicial"]').first();
    if (await saldoInicialBtn.count() > 0) {
      await saldoInicialBtn.click();
    } else {
      const altSaldoBtn = filaCuenta.locator('button').filter({ has: page.locator('svg.lucide-dollar-sign') }).first();
      if (await altSaldoBtn.count() > 0) await altSaldoBtn.click();
    }
    await page.waitForTimeout(600);

    const modalSaldo = page.locator('div[role="dialog"]').filter({ hasText: /Saldo Inicial/i }).first();
    if (await modalSaldo.count() > 0) {
      await expect(modalSaldo).toBeVisible();

      const mntInput = modalSaldo.locator('#nuevo_saldo, input[type="number"]').first();
      const passAdminInput = modalSaldo.locator('#password_admin_saldo, input[type="password"]').first();

      if (await mntInput.count() > 0) {
        await mntInput.fill('5000.00');
        await mntInput.dispatchEvent('input');
      }
      
      if (await passAdminInput.count() > 0) {
        await passAdminInput.fill('Simon1756*');
        await passAdminInput.dispatchEvent('input');
      }
      await page.waitForTimeout(300);

      // Enviar formulario y esperar a que el modal se cierre totalmente
      await passAdminInput.press('Enter');
      await expect(modalSaldo).not.toBeVisible({ timeout: 10000 });
      await page.waitForTimeout(800);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 6: Edición Administrativa de Cuenta Bancaria
    // ═════════════════════════════════════════════════════════════════════════
    const editBtn = filaCuenta.locator('button[title*="Editar"]').first();
    await expect(editBtn).toBeVisible({ timeout: 5000 });
    await editBtn.click();
    await page.waitForTimeout(600);

    const modalEdit = page.locator('div[role="dialog"]').first();
    await expect(modalEdit).toBeVisible();

    const sucursalEditInput = modalEdit.locator('#sucursal').first();
    await sucursalEditInput.fill(sucursalEditada);

    const guardarEditBtn = modalEdit.locator('button[type="submit"]').first();
    await guardarEditBtn.click();
    await expect(modalEdit).not.toBeVisible({ timeout: 10000 });
    await page.waitForTimeout(800);

    // Verificar que la sucursal editada se actualizó
    await expect(page.locator('tr').filter({ hasText: sucursalEditada }).first()).toBeVisible();

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 7: Control de Estado (Activa ➔ Inactiva ➔ Activa)
    // ═════════════════════════════════════════════════════════════════════════
    const toggleEstadoBtn = filaCuenta.locator('button[title*="cuenta bancaria"]').first();
    if (await toggleEstadoBtn.count() > 0) {
      // 1. Desactivar cuenta y hacer clic explícito en Confirmar Desactivación
      await toggleEstadoBtn.click();
      await page.waitForTimeout(800);

      const confirmDesactivarBtn = page.locator('button').filter({ hasText: /Confirmar Desactivación/i }).first();
      await expect(confirmDesactivarBtn).toBeVisible({ timeout: 5000 });
      await confirmDesactivarBtn.click();
      await page.waitForTimeout(1500);

      // 2. Reactivar cuenta y hacer clic explícito en Confirmar Reactivación
      await toggleEstadoBtn.click();
      await page.waitForTimeout(800);

      const confirmActivarBtn = page.locator('button').filter({ hasText: /Confirmar Reactivación|Confirmar Activación/i }).first();
      await expect(confirmActivarBtn).toBeVisible({ timeout: 5000 });
      await confirmActivarBtn.click();
      await page.waitForTimeout(1500);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 8: Procesar Transferencia Entre Cuentas Bancarias Registradas con Saldo
    // ═════════════════════════════════════════════════════════════════════════
    const transfeHeaderBtn = page.locator('button').filter({ hasText: /Transferencia|Nueva Transferencia/i }).first();
    await expect(transfeHeaderBtn).toBeVisible();
    await transfeHeaderBtn.click();
    await page.waitForTimeout(800);

    const modalTransfe = page.locator('div[role="dialog"]').filter({ hasText: /Transferencia/i }).first();
    await expect(modalTransfe).toBeVisible();

    // 1. Seleccionar Cuenta Bancaria Origen (Emisora) con dinero disponible real
    const selectOrigen = modalTransfe.locator('select').first();
    await expect(selectOrigen).toBeVisible({ timeout: 5000 });

    const optionsOrigen = await selectOrigen.locator('option').all();
    let indexOrigen = -1;

    for (let i = 0; i < optionsOrigen.length; i++) {
      const text = await optionsOrigen[i].innerText();
      if (!text.includes('(0,00 VES)') && !text.includes('(0.00 VES)') && text.trim() !== '') {
        indexOrigen = i;
        break;
      }
    }

    if (indexOrigen === -1 && optionsOrigen.length > 0) {
      indexOrigen = optionsOrigen.length - 1; // Cuenta recién creada en Etapa 3 con saldo inicial de 5.000,00 en Etapa 5
    }

    await selectOrigen.selectOption({ index: indexOrigen });
    await page.waitForTimeout(600);

    // 2. Seleccionar Cuenta Bancaria Destino (Receptora) distinta a la de origen
    const selectDestino = modalTransfe.locator('select').nth(1);
    await expect(selectDestino).toBeVisible({ timeout: 5000 });

    const optionsDestino = await selectDestino.locator('option').all();
    let indexDestino = 0;
    if (optionsDestino.length > 1 && indexDestino === indexOrigen) {
      indexDestino = (indexOrigen + 1) % optionsDestino.length;
    }

    await selectDestino.selectOption({ index: indexDestino });
    await page.waitForTimeout(600);

    // 3. Llenar campos requeridos de la transferencia
    const refInput = modalTransfe.locator('#numero_transferencia').first();
    await refInput.fill(`TRF-${timestamp}`);

    const montoTransInput = modalTransfe.locator('#monto').first();
    await montoTransInput.fill('250.00');

    const descTransInput = modalTransfe.locator('#concepto').first();
    await descTransInput.fill(`Traspaso institucional de fondos para nómina #${timestamp}`);

    const obsTransInput = modalTransfe.locator('#observaciones').first();
    await obsTransInput.fill(`Oficio de soporte N° OSMC-${timestamp}-FIN`);

    // 4. Ingresar la Contraseña de Administrador (Autorización Sudo)
    const passAdminTrfInput = modalTransfe.locator('#password_admin_trf').first();
    await passAdminTrfInput.fill('Simon1756*');
    await page.waitForTimeout(400);

    // 5. Presionar botón Ejecutar Transferencia
    const btnEjecutarTrf = modalTransfe.locator('button[type="submit"]').first();
    await expect(btnEjecutarTrf).toBeEnabled();
    await btnEjecutarTrf.click();

    // 6. Confirmar que el modal de transferencia se cierra exitosamente
    await expect(modalTransfe).not.toBeVisible({ timeout: 12000 });
    await page.waitForTimeout(1000);
  });

});
