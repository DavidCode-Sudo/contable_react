import { test, expect, Page } from '@playwright/test';

test.describe('Ciclo Completo de Órdenes de Entrega (ODE) - Vincular Solicitud Aprobada, Despacho Definitivo, Liberar Reserva y Anular en MySQL', () => {

  test('Recorrido Fluido Continuo: Solicitud Interna (Stock Disponible) ➔ Aprobar ➔ Generar ODE ➔ Despachar, Liberar Reserva y Anular', async ({ page }) => {
    const timestamp = Date.now().toString().slice(-5);
    const randomNum = Math.floor(Math.random() * 900) + 100;

    const motivoSolicitudODE = `Solicitud Interna con Stock Disponible para Generar ODE #${timestamp}`;
    const justificacionLiberar = `Despacho directo para prueba de liberación de reserva #${randomNum}`;
    const justificacionAnular = `Despacho borrador para prueba de anulación #${timestamp}-${randomNum}`;

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 1: Inicio de Sesión Único
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(800);

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 2: FASE INTEGRAL: Solicitud Interna ➔ Aprobar / Procesar ➔ ODE Vinculada ➔ Despacho
    // ═════════════════════════════════════════════════════════════════════════
    await page.goto('/inventario/solicitudes-internas');
    await page.waitForTimeout(1000);

    // 1. Crear Solicitud Interna Seleccionando Producto del Catálogo con Stock
    const newSolBtn = page.locator('button').filter({ hasText: /Nueva Solicitud|\+ Solicitud/i });
    await expect(newSolBtn.first()).toBeVisible();
    await newSolBtn.first().click();
    await page.waitForTimeout(1000);

    const modalSol = page.locator('div[role="dialog"]');
    await expect(modalSol.first()).toBeVisible();

    const deptSelect = modalSol.locator('select').first();
    if (await deptSelect.count() > 0) {
      const options = await deptSelect.locator('option').all();
      if (options.length > 1) await deptSelect.selectOption(await options[1].getAttribute('value') || '');
    }

    await modalSol.locator('textarea[placeholder*="Describa brevemente"]').first().fill(motivoSolicitudODE);
    await page.waitForTimeout(300);

    // Seleccionar Insumo del Catálogo
    const buscarInsumoInput = modalSol.locator('input[placeholder*="Buscar insumo por código"]').first();
    await buscarInsumoInput.click();
    await page.waitForTimeout(800);

    const catOption = modalSol.locator('div.z-50 button').first();
    if (await catOption.count() > 0) await catOption.click();

    await modalSol.locator('input[type="number"]').first().fill('2');
    const plusBtn = modalSol.locator('button:has(svg.lucide-plus)');
    if (await plusBtn.count() > 0) await plusBtn.first().click();
    await page.waitForTimeout(600);

    // 2. Enviar a Aprobación
    await modalSol.locator('button').filter({ hasText: /Enviar a Aprobación/i }).click();
    await page.waitForTimeout(3000);

    // 3. LEER CÓDIGO Y ENTRAR DIRECTAMENTE AL DETALLE (Haciendo clic en la celda del correlativo td.first(), NUNCA en los tres puntos)
    const firstRowSolCell = page.locator('table tbody tr').first().locator('td').first();
    await expect(firstRowSolCell).toBeVisible();
    const codigoSolicitud = (await firstRowSolCell.innerText()).trim();
    console.log(`📌 Solicitud Interna creada para ODE: ${codigoSolicitud}`);

    await firstRowSolCell.click();
    await page.waitForTimeout(2000);

    // 4. GARANTIZAR APROBACIÓN: Esperar visibilidad de "Aprobar / Procesar" y Confirmar
    const approveBtn = page.locator('button').filter({ hasText: /Aprobar \/ Procesar/i });
    await expect(approveBtn.first()).toBeVisible({ timeout: 15000 });
    await approveBtn.first().click();
    await page.waitForTimeout(1000);

    const modalAprob = page.locator('div[role="dialog"]');
    await expect(modalAprob.first()).toBeVisible();

    const obsTextarea = modalAprob.locator('textarea').first();
    if (await obsTextarea.count() > 0) {
      await obsTextarea.fill('Solicitud aprobada formalmente con existencias disponibles en almacén.');
      await page.waitForTimeout(300);
    }

    const confirmApproveBtn = modalAprob.locator('button').filter({ hasText: /Confirmar Aprobación/i });
    await expect(confirmApproveBtn.first()).toBeVisible();
    await confirmApproveBtn.first().click();
    await page.waitForTimeout(4000);

    // 5. Ir al Detalle de la ODE Vinculada (Haciendo clic en "Ver ODE →")
    const verOdeBtn = page.locator('button, a').filter({ hasText: /Ver ODE/i });
    if (await verOdeBtn.count() > 0) {
      await verOdeBtn.first().click();
      await page.waitForTimeout(2000);
    } else {
      await page.goto('/inventario/ordenes-entrega');
      await page.waitForTimeout(1200);
      await page.locator('table tbody tr').first().locator('td').first().click();
      await page.waitForTimeout(2000);
    }

    // 6. Esperar Carga API del Detalle de la ODE Vinculada y Leer su Correlativo
    const titleH1 = page.locator('h1').filter({ hasText: /Orden de Entrega/i });
    await expect(titleH1.first()).toBeVisible({ timeout: 15000 });
    const textoH1 = await titleH1.first().innerText();
    console.log(`📌 Orden de Entrega vinculada abierta en detalle: ${textoH1}`);

    // ── ACCIÓN 1: Generar Acta Oficial (PDF) ──
    const pdfBtn = page.locator('button').filter({ hasText: /Generar Acta|PDF|Imprimir/i });
    await expect(pdfBtn.first()).toBeVisible({ timeout: 15000 });

    // ── ACCIÓN 2: Editar Orden Vinculada ──
    const editOdeBtn = page.locator('button').filter({ hasText: /Editar Orden/i });
    if (await editOdeBtn.count() > 0) {
      await editOdeBtn.first().click();
      await page.waitForTimeout(1000);

      const justModalArea = page.locator('div[role="dialog"] textarea[placeholder*="Describa detalladamente"]').first();
      if (await justModalArea.count() > 0) {
        await justModalArea.fill(`Justificación actualizada en ODE vinculada #${timestamp}`);
        await page.waitForTimeout(300);
      }

      const saveEditBtn = page.locator('div[role="dialog"] button[type="submit"]').filter({ hasText: /Guardar Cambios/i });
      if (await saveEditBtn.count() > 0) {
        await saveEditBtn.first().click();
        await page.waitForTimeout(2500);
      }
    }

    // ── ACCIÓN 3: Confirmar y Despachar de Almacén (Descuenta inventario en MySQL) ──
    const despacharBtn = page.locator('button').filter({ hasText: /Confirmar y Despachar/i });
    if (await despacharBtn.count() > 0) {
      await despacharBtn.first().click();
      await page.waitForTimeout(1000);

      const confirmDespachoBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Procesar/i });
      if (await confirmDespachoBtn.count() > 0) {
        await confirmDespachoBtn.first().click();
        await page.waitForTimeout(3500);
      }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ETAPA 3: FASE REGISTRO DIRECTO: Probar Liberar Reserva y Anular Ordenes
    // ═════════════════════════════════════════════════════════════════════════
    async function crearODEDirectaHelper(
      justificacionText: string,
      estadoVal: 'borrador' | 'aprobada',
      observacionesText: string
    ): Promise<string> {
      await page.goto('/inventario/ordenes-entrega');
      await page.waitForTimeout(1000);

      const newBtn = page.locator('button').filter({ hasText: /Nueva Orden|Crear Orden|\+ Orden/i });
      await expect(newBtn.first()).toBeVisible();
      await newBtn.first().click();
      await page.waitForTimeout(1000);

      const modal = page.locator('div[role="dialog"]');
      await expect(modal.first()).toBeVisible();

      const tipoDestSelect = modal.locator('select').nth(0);
      if (await tipoDestSelect.count() > 0) {
        await tipoDestSelect.selectOption('departamento');
        await page.waitForTimeout(300);
      }

      const deptOdeSelect = modal.locator('select').nth(1);
      if (await deptOdeSelect.count() > 0) {
        const options = await deptOdeSelect.locator('option').all();
        for (let i = 0; i < options.length; i++) {
          const val = await options[i].getAttribute('value');
          if (val && val !== '') {
            await deptOdeSelect.selectOption(val);
            break;
          }
        }
        await page.waitForTimeout(300);
      }

      const justArea = modal.locator('textarea[placeholder*="Describa detalladamente"]').first();
      await expect(justArea).toBeVisible();
      await justArea.fill(justificacionText);
      await page.waitForTimeout(300);

      const select2Trigger = modal.locator('div.cursor-pointer').filter({ hasText: /Buscar por código|-- Buscar/i }).first();
      if (await select2Trigger.count() > 0) {
        await select2Trigger.click();
        await page.waitForTimeout(600);

        const prodOpt = page.locator('div.absolute.z-50 div.cursor-pointer').first();
        if (await prodOpt.count() > 0) {
          await prodOpt.click();
          await page.waitForTimeout(400);
        }
      }

      const cantInput = modal.locator('input[type="number"]').first();
      if (await cantInput.count() > 0) {
        await cantInput.clear();
        await cantInput.fill('2');
        await page.waitForTimeout(300);
      }

      const addOdeBtn = modal.locator('button').filter({ hasText: /Añadir|\+/i }).first();
      await expect(addOdeBtn).toBeVisible();
      await addOdeBtn.click();
      await page.waitForTimeout(800);

      const estadoSelect = modal.locator('select').nth(2);
      if (await estadoSelect.count() > 0) {
        await estadoSelect.selectOption(estadoVal);
        await page.waitForTimeout(300);
      }

      const obsInput = modal.locator('input[placeholder*="Observaciones de entrega"]').first();
      if (await obsInput.count() > 0) {
        await obsInput.fill(observacionesText);
        await page.waitForTimeout(300);
      }

      const saveOdeBtn = modal.locator('button[type="submit"]').filter({ hasText: /Crear Orden|Guardar/i });
      await expect(saveOdeBtn.first()).toBeEnabled();
      await saveOdeBtn.first().click();
      await page.waitForTimeout(3500);

      const firstRowCell = page.locator('table tbody tr').first().locator('td').first();
      await expect(firstRowCell).toBeVisible();
      const codigoGenerado = (await firstRowCell.innerText()).trim();
      console.log(`📌 Correlativo de Orden Directa leído de la tabla: ${codigoGenerado}`);

      return codigoGenerado;
    }

    // ── ACCIÓN 4: Orden Directa #2 (Aprobada) ➔ Liberar Reserva de Stock ──
    const codigoOrden2 = await crearODEDirectaHelper(
      justificacionLiberar,
      'aprobada',
      'Orden creada para probar la liberación formal de reserva.'
    );

    const linkCodigo2 = page.locator('table tbody tr').first().locator('td').first();
    await expect(linkCodigo2).toBeVisible();
    await linkCodigo2.click();
    await page.waitForTimeout(2000);

    const titleH1_2 = page.locator('h1').filter({ hasText: codigoOrden2 });
    await expect(titleH1_2.first()).toBeVisible({ timeout: 15000 });

    const releaseResBtn = page.locator('button').filter({ hasText: /Liberar Reserva/i });
    if (await releaseResBtn.count() > 0) {
      await releaseResBtn.first().click();
      await page.waitForTimeout(800);

      const confirmReleaseBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Liberar/i });
      if (await confirmReleaseBtn.count() > 0) {
        await confirmReleaseBtn.first().click();
        await page.waitForTimeout(2500);
      }
    }

    // ── ACCIÓN 5: Orden Directa #3 (Borrador) ➔ Anular Orden ──
    const codigoOrden3 = await crearODEDirectaHelper(
      justificacionAnular,
      'borrador',
      'Orden preliminar creada para prueba de anulación.'
    );

    const linkCodigo3 = page.locator('table tbody tr').first().locator('td').first();
    await expect(linkCodigo3).toBeVisible();
    await linkCodigo3.click();
    await page.waitForTimeout(2000);

    const titleH1_3 = page.locator('h1').filter({ hasText: codigoOrden3 });
    await expect(titleH1_3.first()).toBeVisible({ timeout: 15000 });

    const annulOdeBtn = page.locator('button').filter({ hasText: /Anular Orden|Anular/i });
    if (await annulOdeBtn.count() > 0) {
      await annulOdeBtn.first().click();
      await page.waitForTimeout(1000);

      const confirmAnnulBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Confirmar|Anular/i });
      if (await confirmAnnulBtn.count() > 0) {
        await confirmAnnulBtn.first().click();
        await page.waitForTimeout(3000);
      }
    }
  });

});
