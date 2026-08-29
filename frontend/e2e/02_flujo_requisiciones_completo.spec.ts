import { test, expect, Page } from '@playwright/test';

test.describe('Ciclo de Vida Completo de Requisiciones - Base de Datos MySQL Real XAMPP', () => {

  // ═════════════════════════════════════════════════════════════════════════
  // FUNCIONES AUXILIARES PARA COMPLETAR FORMULARIO RIGUROSAMENTE
  // ═════════════════════════════════════════════════════════════════════════

  async function completarPaso2ProveedorCompleto(page: Page) {
    const provSearchInput = page.locator('input[placeholder*="Buscar por RIF"], input[placeholder*="Villamizar"]').first();
    if (await provSearchInput.count() > 0) {
      await provSearchInput.click();
      await page.waitForTimeout(600);

      const provOption = page.locator('.max-h-60 div[class*="cursor-pointer"]').first();
      if (await provOption.count() > 0) {
        await provOption.click();
        await page.waitForTimeout(400);
      }
    }

    const nombreInput = page.locator('input[placeholder="Nombre de la empresa o proveedor"]').first();
    if (await nombreInput.count() > 0 && !(await nombreInput.inputValue())) {
      await nombreInput.fill('SUMINISTROS CORPORATIVOS Y ASOCIADOS C.A.');
    }

    const rifInput = page.locator('input[placeholder="J-12345678-9"]').first();
    if (await rifInput.count() > 0 && !(await rifInput.inputValue())) {
      await rifInput.fill('J-30495812-4');
    }

    const telfInput = page.locator('input[placeholder="0414-1234567"]').first();
    if (await telfInput.count() > 0 && !(await telfInput.inputValue())) {
      await telfInput.fill('0212-5551234');
    }

    const emailInput = page.locator('input[placeholder="proveedor@email.com"]').first();
    if (await emailInput.count() > 0 && !(await emailInput.inputValue())) {
      await emailInput.fill('ventas@suministroscorp.com.ve');
    }

    const dirTextarea = page.locator('textarea[placeholder*="Dirección completa"]').first();
    if (await dirTextarea.count() > 0 && !(await dirTextarea.inputValue())) {
      await dirTextarea.fill('Av. Francisco de Miranda, Edificio Centro Empresarial, Piso 4, Oficina 4B, Chacao, Caracas');
    }
  }

  async function completarPaso3ItemsCompleto(page: Page, descripcion: string, cantidad: string, precio: string) {
    const descInput = page.locator('input[placeholder*="busque en catálogo"], input[placeholder*="Papel Resma"]').first();
    if (await descInput.count() > 0) {
      await descInput.fill(descripcion);
      await page.waitForTimeout(400);
    }

    const numberInputs = page.locator('input[type="number"]');
    if (await numberInputs.count() >= 3) {
      await numberInputs.nth(0).clear();
      await numberInputs.nth(0).fill(cantidad);

      await numberInputs.nth(1).clear();
      await numberInputs.nth(1).fill(precio);

      await numberInputs.nth(2).clear();
      await numberInputs.nth(2).fill('16');
    }
  }

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 1: Crear Requisición Real ➔ Ver Detalle ➔ Anular en Base de Datos
  // ═════════════════════════════════════════════════════════════════════════
  test('1. Crear Requisición en Borrador, Ver su Detalle y Anular en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/requisiciones/nueva');
    await page.waitForTimeout(600);

    // PASO 1: Presupuesto
    const budgetInput = page.locator('input[placeholder*="Buscar presupuesto"]');
    if (await budgetInput.count() > 0) {
      await budgetInput.first().click();
      await page.waitForTimeout(600);

      const dropdownOption = page.locator('.max-h-56 div[class*="cursor-pointer"]').first();
      if (await dropdownOption.count() > 0) {
        await dropdownOption.click();
        await page.waitForTimeout(400);
      }
    }

    const dateInputs = page.locator('input[type="date"]');
    if (await dateInputs.count() >= 2) {
      await dateInputs.nth(0).fill('2026-08-25');
      await dateInputs.nth(1).fill('2026-09-15');
    }

    await page.locator('textarea').first().fill('Requisición institucional de papelería e insumos de oficina para el departamento contable.');

    const nextButton = page.locator('button').filter({ hasText: /^Siguiente/i });
    await nextButton.first().click();
    await page.waitForTimeout(800);

    // PASO 2: Proveedor
    await completarPaso2ProveedorCompleto(page);

    await nextButton.first().click();
    await page.waitForTimeout(800);

    // PASO 3: Ítems
    await completarPaso3ItemsCompleto(page, 'Resmas de Papel Carta 75g (Cajas de 5 unidades)', '20', '140.00');

    await nextButton.first().click();
    await page.waitForTimeout(1000);

    // Guardar Borrador
    const saveButton = page.locator('button').filter({ hasText: /Guardar Borrador|Enviar a Aprobación/i });
    await saveButton.first().click();
    await page.waitForTimeout(1500);

    // Anular Requisición
    const cancelDetailButton = page.locator('button').filter({ hasText: /Anular/i });
    if (await cancelDetailButton.count() > 0) {
      await cancelDetailButton.first().click();
      await page.waitForTimeout(600);

      const modalTextarea = page.locator('textarea').first();
      await modalTextarea.fill('Anulación de prueba ejecutada directamente en la base de datos MySQL.');
      await page.waitForTimeout(400);

      const confirmButton = page.locator('button').filter({ hasText: /Confirmar Acción/i });
      if (await confirmButton.count() > 0) {
        await confirmButton.first().click();
        await page.waitForTimeout(1500);
      }
    }

    const estadoBadge = page.locator('span, div').filter({ hasText: /ANULADA/i });
    await expect(estadoBadge.first()).toBeVisible();
  });

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 2: Crear Requisición Real ➔ Editar Requisición en MySQL
  // ═════════════════════════════════════════════════════════════════════════
  test('2. Crear Requisición Real en Borrador y Probar Editarla en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/requisiciones/nueva');
    await page.waitForTimeout(600);

    const budgetInput = page.locator('input[placeholder*="Buscar presupuesto"]');
    if (await budgetInput.count() > 0) {
      await budgetInput.first().click();
      await page.waitForTimeout(600);
      const dropdownOption = page.locator('.max-h-56 div[class*="cursor-pointer"]').first();
      if (await dropdownOption.count() > 0) await dropdownOption.click();
    }

    const dateInputs = page.locator('input[type="date"]');
    if (await dateInputs.count() >= 2) {
      await dateInputs.nth(0).fill('2026-08-25');
      await dateInputs.nth(1).fill('2026-09-15');
    }

    await page.locator('textarea').first().fill('Requisición de consumibles de impresión para el departamento contable.');

    const nextButton = page.locator('button').filter({ hasText: /^Siguiente/i });
    await nextButton.first().click();
    await page.waitForTimeout(800);

    await completarPaso2ProveedorCompleto(page);

    await nextButton.first().click();
    await page.waitForTimeout(800);

    await completarPaso3ItemsCompleto(page, 'Toner HP LaserJet Enterprise 89A', '5', '480.00');

    await nextButton.first().click();
    await page.waitForTimeout(1000);

    const saveButton = page.locator('button').filter({ hasText: /Guardar Borrador/i });
    await saveButton.first().click();
    await page.waitForTimeout(1500);

    const editButton = page.locator('a, button').filter({ hasText: /Editar Requisición|Editar/i });
    if (await editButton.count() > 0) {
      await editButton.first().click();
      await page.waitForTimeout(800);

      const obsTextarea = page.locator('textarea').first();
      await obsTextarea.clear();
      await obsTextarea.fill('Requisición de consumibles de impresión EDITADA Y ACTUALIZADA DIRECTAMENTE EN MYSQL.');

      await nextButton.first().click();
      await page.waitForTimeout(400);
      await nextButton.first().click();
      await page.waitForTimeout(400);
      await nextButton.first().click();
      await page.waitForTimeout(800);

      const saveEditButton = page.locator('button').filter({ hasText: /Guardar Borrador/i });
      await saveEditButton.first().click();
      await page.waitForTimeout(1500);
    }
  });

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 3: Crear Requisición ➔ Enviar a Dirección Ejecutiva ➔ Rechazar en MySQL
  // ═════════════════════════════════════════════════════════════════════════
  test('3. Crear Requisición Real, Enviar a Dirección Ejecutiva y Rechazar en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/requisiciones/nueva');
    await page.waitForTimeout(600);

    const budgetInput = page.locator('input[placeholder*="Buscar presupuesto"]');
    if (await budgetInput.count() > 0) {
      await budgetInput.first().click();
      await page.waitForTimeout(600);
      const dropdownOption = page.locator('.max-h-56 div[class*="cursor-pointer"]').first();
      if (await dropdownOption.count() > 0) await dropdownOption.click();
    }

    const dateInputs = page.locator('input[type="date"]');
    if (await dateInputs.count() >= 2) {
      await dateInputs.nth(0).fill('2026-08-25');
      await dateInputs.nth(1).fill('2026-09-15');
    }

    await page.locator('textarea').first().fill('Requisición de mantenimiento preventivo para servidores e impresoras.');

    const nextButton = page.locator('button').filter({ hasText: /^Siguiente/i });
    await nextButton.first().click();
    await page.waitForTimeout(800);

    await completarPaso2ProveedorCompleto(page);

    await nextButton.first().click();
    await page.waitForTimeout(800);

    await completarPaso3ItemsCompleto(page, 'Servicio de Mantenimiento y Reparación de Equipos', '2', '850.00');

    await nextButton.first().click();
    await page.waitForTimeout(1000);

    const saveButton = page.locator('button').filter({ hasText: /Guardar Borrador/i });
    await saveButton.first().click();
    await page.waitForTimeout(1500);

    const sendDirButton = page.locator('button').filter({ hasText: /Enviar a Dirección Ejecutiva/i });
    if (await sendDirButton.count() > 0) {
      await sendDirButton.first().click();
      await page.waitForTimeout(600);

      const modalTextarea = page.locator('textarea').first();
      await modalTextarea.fill('Envío real a Dirección Ejecutiva para su revisión.');

      const confirmButton = page.locator('button').filter({ hasText: /Confirmar Acción/i });
      if (await confirmButton.count() > 0) {
        await confirmButton.first().click();
        await page.waitForTimeout(1500);
      }
    }

    const rejectButton = page.locator('button').filter({ hasText: /Rechazar/i });
    if (await rejectButton.count() > 0) {
      await rejectButton.first().click();
      await page.waitForTimeout(600);

      const modalTextarea = page.locator('textarea').first();
      await modalTextarea.fill('Rechazado por Dirección Ejecutiva en la base de datos MySQL.');

      const confirmButton = page.locator('button').filter({ hasText: /Confirmar Acción/i });
      if (await confirmButton.count() > 0) {
        await confirmButton.first().click();
        await page.waitForTimeout(1500);
      }
    }

    const rechazadaBadge = page.locator('span, div').filter({ hasText: /RECHAZADA/i });
    await expect(rechazadaBadge.first()).toBeVisible();
  });

});
