import { test, expect } from '@playwright/test';

test.describe('Ciclo Unificado de Solicitudes Internas con Insumos Variados en MySQL', () => {

  test('Recorrido Continuo Selección de Productos Distintos, Aprobar, Rechazar y Anular', async ({ page }) => {
    const timestamp = Date.now().toString().slice(-5);

    const motivo1 = `Solicitud #1 (Papelería y Oficina) - ${timestamp}`;
    const motivo2 = `Solicitud #2 (Tóner y Consumibles) - ${timestamp}`;
    const motivo3 = `Solicitud #3 (Materiales Diversos) - ${timestamp}`;

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 1: Inicio de Sesión Único
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 2: Solicitud #1 ➔ Producto #1 del Catálogo ➔ Enviar ➔ Retractar ➔ Aprobar
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/inventario/solicitudes-internas');
    await page.waitForTimeout(1000);

    const newBtn = page.locator('button').filter({ hasText: /Nueva Solicitud|\+ Solicitud/i });
    await expect(newBtn.first()).toBeVisible();
    await newBtn.first().click();
    await page.waitForTimeout(1000);

    // Departamento
    const deptSelect = page.locator('div[role="dialog"] select').first();
    if (await deptSelect.count() > 0) {
      const options = await deptSelect.locator('option').all();
      if (options.length > 1) await deptSelect.selectOption(await options[1].getAttribute('value') || '');
    }

    // Motivo Solicitud #1
    await page.locator('textarea[placeholder*="Describa brevemente"]').first().fill(motivo1);
    await page.waitForTimeout(300);

    // Seleccionar Insumo #1 del Catálogo
    const buscarInsumoInput = page.locator('input[placeholder*="Buscar insumo por código"]').first();
    await buscarInsumoInput.click();
    await page.waitForTimeout(800);

    const catOptions1 = page.locator('div[role="dialog"] div.z-50 button');
    if (await catOptions1.count() > 0) {
      await catOptions1.first().click();
      await page.waitForTimeout(400);
    }

    // Cantidad
    await page.locator('div[role="dialog"] input[type="number"]').first().fill('5');
    const plusBtn = page.locator('div[role="dialog"] button:has(svg.lucide-plus)');
    if (await plusBtn.count() > 0) await plusBtn.first().click();
    await page.waitForTimeout(600);

    // Guardar Borrador
    await page.locator('div[role="dialog"] button').filter({ hasText: /Guardar Borrador/i }).click();
    await page.waitForTimeout(3000);

    // Abrir detalle Solicitud #1
    const row1 = page.locator('table tbody tr').filter({ hasText: motivo1 });
    await expect(row1.first()).toBeVisible();
    await row1.first().click();
    await page.waitForTimeout(1500);

    // Enviar a Aprobación
    const sendBtn1 = page.locator('button').filter({ hasText: /Enviar a Aprobación/i });
    if (await sendBtn1.count() > 0) {
      await sendBtn1.first().click();
      await page.waitForTimeout(600);
      await page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Enviar/i }).click();
      await page.waitForTimeout(2500);
    }

    // Retractar a Borrador
    const retractBtn = page.locator('button').filter({ hasText: /Retractar a Borrador/i });
    if (await retractBtn.count() > 0) {
      await retractBtn.first().click();
      await page.waitForTimeout(600);
      await page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Retractar/i }).click();
      await page.waitForTimeout(2500);
    }

    // Volver a Enviar y APROBAR
    if (await sendBtn1.count() > 0) {
      await sendBtn1.first().click();
      await page.waitForTimeout(600);
      await page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Enviar/i }).click();
      await page.waitForTimeout(2500);
    }

    const approveBtn = page.locator('button').filter({ hasText: /Aprobar \/ Procesar/i });
    await expect(approveBtn.first()).toBeVisible();
    await approveBtn.first().click();
    await page.waitForTimeout(1000);

    const obsApprove = page.locator('div[role="dialog"] textarea').first();
    if (await obsApprove.count() > 0) await obsApprove.fill('Aprobación cuantitativa confirmada exitosamente.');
    await page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar Aprobación/i }).click();
    await page.waitForTimeout(3000);

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 3: Solicitud #2 ➔ Producto #2 del Catálogo ➔ Rechazar
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/inventario/solicitudes-internas');
    await page.waitForTimeout(1000);

    await newBtn.first().click();
    await page.waitForTimeout(1000);

    if (await deptSelect.count() > 0) {
      const options = await deptSelect.locator('option').all();
      if (options.length > 1) await deptSelect.selectOption(await options[1].getAttribute('value') || '');
    }

    await page.locator('textarea[placeholder*="Describa brevemente"]').first().fill(motivo2);
    await page.waitForTimeout(300);

    // Seleccionar Insumo #2 del Catálogo (segundo producto)
    await buscarInsumoInput.click();
    await page.waitForTimeout(800);

    const catOptions2 = page.locator('div[role="dialog"] div.z-50 button');
    const totalCat2 = await catOptions2.count();
    if (totalCat2 > 1) {
      await catOptions2.nth(1).click();
    } else if (totalCat2 > 0) {
      await catOptions2.first().click();
    }
    await page.waitForTimeout(400);

    await page.locator('div[role="dialog"] input[type="number"]').first().fill('3');
    if (await plusBtn.count() > 0) await plusBtn.first().click();
    await page.waitForTimeout(600);

    // Enviar a Aprobación directamente
    await page.locator('div[role="dialog"] button').filter({ hasText: /Enviar a Aprobación/i }).click();
    await page.waitForTimeout(3000);

    // Abrir detalle de Solicitud #2
    const row2 = page.locator('table tbody tr').filter({ hasText: motivo2 });
    await expect(row2.first()).toBeVisible();
    await row2.first().click();
    await page.waitForTimeout(1500);

    // Rechazar
    const rejectBtn = page.locator('button').filter({ hasText: /Rechazar/i });
    await expect(rejectBtn.first()).toBeVisible();
    await rejectBtn.first().click();
    await page.waitForTimeout(1000);

    const motivoRechazoArea = page.locator('div[role="dialog"] textarea[placeholder*="razones presupuestarias"]').first();
    await expect(motivoRechazoArea).toBeVisible();
    await motivoRechazoArea.fill('Solicitud rechazada por la Dirección debido a revisión de prioridades presupuestarias.');
    await page.waitForTimeout(400);

    const confirmRejectBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar Rechazo/i });
    await expect(confirmRejectBtn.first()).toBeEnabled();
    await confirmRejectBtn.first().click();
    await page.waitForTimeout(3000);

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 4: Solicitud #3 ➔ Producto #3 del Catálogo ➔ Anular
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/inventario/solicitudes-internas');
    await page.waitForTimeout(1000);

    await newBtn.first().click();
    await page.waitForTimeout(1000);

    if (await deptSelect.count() > 0) {
      const options = await deptSelect.locator('option').all();
      if (options.length > 1) await deptSelect.selectOption(await options[1].getAttribute('value') || '');
    }

    await page.locator('textarea[placeholder*="Describa brevemente"]').first().fill(motivo3);
    await page.waitForTimeout(300);

    // Seleccionar Insumo #3 del Catálogo (tercer producto si existe)
    await buscarInsumoInput.click();
    await page.waitForTimeout(800);

    const catOptions3 = page.locator('div[role="dialog"] div.z-50 button');
    const totalCat3 = await catOptions3.count();
    if (totalCat3 > 2) {
      await catOptions3.nth(2).click();
    } else if (totalCat3 > 0) {
      await catOptions3.last().click();
    }
    await page.waitForTimeout(400);

    await page.locator('div[role="dialog"] input[type="number"]').first().fill('2');
    if (await plusBtn.count() > 0) await plusBtn.first().click();
    await page.waitForTimeout(600);

    // Guardar Borrador
    await page.locator('div[role="dialog"] button').filter({ hasText: /Guardar Borrador/i }).click();
    await page.waitForTimeout(3000);

    // Abrir detalle de Solicitud #3
    const row3 = page.locator('table tbody tr').filter({ hasText: motivo3 });
    await expect(row3.first()).toBeVisible();
    await row3.first().click();
    await page.waitForTimeout(1500);

    // Anular
    const annulBtn = page.locator('button').filter({ hasText: /Anular/i });
    await expect(annulBtn.first()).toBeVisible();
    await annulBtn.first().click();
    await page.waitForTimeout(1000);

    const motivoAnulacionArea = page.locator('div[role="dialog"] textarea[placeholder*="motivo legal u operativo"]').first();
    await expect(motivoAnulacionArea).toBeVisible();
    await motivoAnulacionArea.fill('Anulación formal del expediente por normas de auditoría e inspección técnica.');
    await page.waitForTimeout(400);

    const confirmAnnulBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar Anulación/i });
    await expect(confirmAnnulBtn.first()).toBeEnabled();
    await confirmAnnulBtn.first().click();
    await page.waitForTimeout(3000);
  });

});
