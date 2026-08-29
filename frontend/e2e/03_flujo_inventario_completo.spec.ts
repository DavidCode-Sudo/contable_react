import { test, expect } from '@playwright/test';

test.describe('Ciclo Completo de Inventario - Categorías, Productos, Unidades, Ingreso Inicial y Acciones en MySQL', () => {

  const timestamp = Date.now().toString().slice(-5);
  const randomNum = Math.floor(Math.random() * 900) + 100;

  const categoriaNombreUnico = `Consumibles de Papelería #${timestamp}`;
  const productoNombreUnico = `Tóner HP LaserJet Pro #${randomNum}`;
  const facturaReferenciaUnica = `FACT-${timestamp}-${randomNum}`;

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 1: Registrar una Categoría Única en MySQL
  // ═════════════════════════════════════════════════════════════════════════
  test('1. Registrar una Nueva Categoría con Nombre Único Aleatorio en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/gestion');
    await page.waitForTimeout(1000);

    const catButton = page.locator('button').filter({ hasText: /Nueva Categoría|Categorías/i });
    if (await catButton.count() > 0) {
      await catButton.first().click();
      await page.waitForTimeout(800);

      // Nombre de Categoría
      const nombreInput = page.locator('input[placeholder*="Material de Limpieza"]').first();
      await expect(nombreInput).toBeVisible();
      await nombreInput.fill(categoriaNombreUnico);
      await page.waitForTimeout(300);

      // Descripción
      const descTextarea = page.locator('textarea[placeholder*="descripción del grupo"]').first();
      if (await descTextarea.count() > 0) {
        await descTextarea.fill(`Rubro institucional creado automáticamente en prueba E2E #${timestamp}.`);
        await page.waitForTimeout(300);
      }

      // Estado Activo
      const estadoSelect = page.locator('div[role="dialog"] select').first();
      if (await estadoSelect.count() > 0) {
        await estadoSelect.selectOption('activo');
        await page.waitForTimeout(300);
      }

      // Presionar Guardar Categoría
      const saveCatBtn = page.locator('button[type="submit"]').filter({ hasText: /Guardar Categoría/i });
      if (await saveCatBtn.count() > 0) {
        await saveCatBtn.first().click();
        await page.waitForTimeout(2000);
      }
    }
  });

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 2: Registrar Producto Único y Habilitar/Presionar el Botón Guardar
  // ═════════════════════════════════════════════════════════════════════════
  test('2. Registrar Nuevo Producto Único Probando Unidades, Ingreso Respaldado y Guardado en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/gestion');
    await page.waitForTimeout(1000);

    const newProdBtn = page.locator('button').filter({ hasText: /Nuevo Producto|\+ Producto/i });
    if (await newProdBtn.count() > 0) {
      await newProdBtn.first().click();
      await page.waitForTimeout(1000);

      // 1. Nombre del Producto Único
      const nomInput = page.locator('input[placeholder="Nombre descriptivo"]').first();
      await expect(nomInput).toBeVisible();
      await nomInput.fill(productoNombreUnico);
      await page.waitForTimeout(400);

      // 2. Descripción
      const descArea = page.locator('textarea[placeholder*="específica del producto"]').first();
      if (await descArea.count() > 0) {
        await descArea.fill(`Cartucho de tóner de alto rendimiento registrado en prueba E2E #${timestamp}.`);
        await page.waitForTimeout(400);
      }

      // 3. Categoría
      const catSelect = page.locator('div[role="dialog"] select').nth(0);
      if (await catSelect.count() > 0) {
        const catOptions = await catSelect.locator('option').all();
        if (catOptions.length > 1) {
          const val = await catOptions[catOptions.length - 1].getAttribute('value');
          if (val) await catSelect.selectOption(val);
        }
        await page.waitForTimeout(400);
      }

      // 4. Probar Unidades de Medida
      const unidadSelect = page.locator('div[role="dialog"] select').nth(1);
      if (await unidadSelect.count() > 0) {
        const unidadesAProbar = ['UNID', 'CAJA', 'KG', 'LT', 'MTR', 'PAQ', 'SERVIC'];
        for (const u of unidadesAProbar) {
          await unidadSelect.selectOption(u).catch(() => {});
          await page.waitForTimeout(150);
        }
        await unidadSelect.selectOption('UNID');
        await page.waitForTimeout(400);
      }

      // 5. Origen de Ingreso Inicial
      const tipoIngresoSelect = page.locator('div[role="dialog"] select').nth(2);
      if (await tipoIngresoSelect.count() > 0) {
        const tiposAProbar = ['Donación', 'Ingreso Interno', 'Compra Directa', 'Otro'];
        for (const t of tiposAProbar) {
          await tipoIngresoSelect.selectOption(t).catch(() => {});
          await page.waitForTimeout(150);
        }
        await tipoIngresoSelect.selectOption('Compra Directa');
        await page.waitForTimeout(400);
      }

      // 6. Cantidad Inicial
      const cantInicialInput = page.locator('input[placeholder="0"]').first();
      if (await cantInicialInput.count() > 0) {
        await cantInicialInput.clear();
        await cantInicialInput.fill('25');
        await page.waitForTimeout(400);
      }

      // 7. Costo Unitario
      const costoInicialInput = page.locator('input[placeholder="Bs. 0.00"]').first();
      if (await costoInicialInput.count() > 0) {
        await costoInicialInput.clear();
        await costoInicialInput.fill('450.00');
        await page.waitForTimeout(400);
      }

      // 8. Documento / Factura de Referencia Fiscal (OBLIGATORIO para habilitar botón Guardar)
      const docRefInput = page.locator('input[placeholder*="Factura N°"]').first();
      if (await docRefInput.count() > 0) {
        await docRefInput.fill(`Factura N° ${facturaReferenciaUnica}`);
        await page.waitForTimeout(400);
      }

      // 9. Observaciones del Ingreso
      const obsIngresoArea = page.locator('textarea[placeholder*="Detalles adicionales"]').first();
      if (await obsIngresoArea.count() > 0) {
        await obsIngresoArea.fill('Ingreso inicial de existencias respaldado con documento fiscal de compra.');
        await page.waitForTimeout(400);
      }

      // 10. Configuración Avanzada ERP
      const advBtn = page.locator('button').filter({ hasText: /Configuración Avanzada/i });
      if (await advBtn.count() > 0) {
        await advBtn.first().click();
        await page.waitForTimeout(600);

        const ubicacionInputs = page.locator('div[role="dialog"] input');
        for (let i = 0; i < await ubicacionInputs.count(); i++) {
          const ph = await ubicacionInputs.nth(i).getAttribute('placeholder');
          if (ph && ph.toLowerCase().includes('almacén')) {
            await ubicacionInputs.nth(i).fill('Almacén Principal - Estante B2 - Nivel 3');
            break;
          }
        }
      }

      // 11. Presionar el Botón Guardar (Texto exacto: "Guardar")
      const saveProdBtn = page.locator('div[role="dialog"] button[type="submit"]').filter({ hasText: /^Guardar$/i });
      await expect(saveProdBtn.first()).toBeEnabled();
      await saveProdBtn.first().click();
      await page.waitForTimeout(3000);
    }

    // 12. Verificar que el Producto Creado se Muestra en la Tabla
    const prodTableText = page.locator('table, td').filter({ hasText: new RegExp(productoNombreUnico, 'i') });
    await expect(prodTableText.first()).toBeVisible();
  });

  // ═════════════════════════════════════════════════════════════════════════
  // PRUEBA 3: Probar Entrada (+ Stock) y Salida (- Stock) con Documentos y Observaciones
  // ═════════════════════════════════════════════════════════════════════════
  test('3. Probar Entrada y Salida de Stock Llenando Documentos de Referencia y Observaciones en MySQL', async ({ page }) => {
    await page.goto('/login');
    await page.waitForTimeout(400);

    await page.locator('input[type="email"]').fill('admi.osmc@gmail.com');
    await page.locator('input[type="password"]').fill('Simon1756*');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL('**/dashboard', { timeout: 15000 });
    await page.waitForTimeout(600);

    await page.goto('/inventario/gestion');
    await page.waitForTimeout(1000);

    // ── ACCIÓN 1: ENTRADA DE STOCK CON DOCUMENTO Y OBSERVACIONES ──
    const ajustBtn = page.locator('button').filter({ hasText: /Ajustar Stock|Ajuste/i });
    if (await ajustBtn.count() > 0) {
      await ajustBtn.first().click();
      await page.waitForTimeout(1000);

      // Modal de Ajuste de Stock (Entrada)
      const cantInput = page.locator('div[role="dialog"] input[type="number"]').first();
      if (await cantInput.count() > 0) {
        await cantInput.clear();
        await cantInput.fill('10');
        await page.waitForTimeout(300);
      }

      // Documento / Acta de Referencia (Obligatorio en entradas)
      const docInput = page.locator('div[role="dialog"] input[placeholder*="Factura N°"]').first();
      if (await docInput.count() > 0) {
        await docInput.fill(`Acta-Entrada-${timestamp}`);
        await page.waitForTimeout(300);
      }

      // Observaciones del Movimiento
      const obsArea = page.locator('div[role="dialog"] textarea[placeholder*="Detalles adicionales"]').first();
      if (await obsArea.count() > 0) {
        await obsArea.fill('Entrada de existencias adicionales respaldada por Acta de Recepción.');
        await page.waitForTimeout(300);
      }

      // Presionar "Confirmar Ajuste"
      const confirmBtn = page.locator('div[role="dialog"] button[type="submit"]').filter({ hasText: /Confirmar Ajuste/i });
      await expect(confirmBtn.first()).toBeEnabled();
      await confirmBtn.first().click();
      await page.waitForTimeout(2500);
    }

    // ── ACCIÓN 2: SALIDA DE STOCK CON DEPARTAMENTO Y OBSERVACIONES ──
    if (await ajustBtn.count() > 0) {
      await ajustBtn.first().click();
      await page.waitForTimeout(1000);

      // Cambiar Tipo a Salida (- Stock)
      const salidaBtn = page.locator('div[role="dialog"] button').filter({ hasText: /Salida \(- Stock\)/i });
      if (await salidaBtn.count() > 0) {
        await salidaBtn.first().click();
        await page.waitForTimeout(400);
      }

      // Cantidad a Despachar
      const cantInput = page.locator('div[role="dialog"] input[type="number"]').first();
      if (await cantInput.count() > 0) {
        await cantInput.clear();
        await cantInput.fill('3');
        await page.waitForTimeout(300);
      }

      // Departamento Receptor
      const dptoInput = page.locator('div[role="dialog"] input[placeholder*="Dirección de Administración"]').first();
      if (await dptoInput.count() > 0) {
        await dptoInput.fill('Departamento de Contabilidad y Finanzas');
        await page.waitForTimeout(300);
      }

      // Documento / Acta de Referencia Salida
      const docInput = page.locator('div[role="dialog"] input[placeholder*="Factura N°"]').first();
      if (await docInput.count() > 0) {
        await docInput.fill(`Nota-Despacho-${timestamp}`);
        await page.waitForTimeout(300);
      }

      // Observaciones de Salida
      const obsArea = page.locator('div[role="dialog"] textarea[placeholder*="Detalles adicionales"]').first();
      if (await obsArea.count() > 0) {
        await obsArea.fill('Despacho interno de insumos para consumo del departamento contable.');
        await page.waitForTimeout(300);
      }

      // Presionar "Confirmar Ajuste"
      const confirmBtn = page.locator('div[role="dialog"] button[type="submit"]').filter({ hasText: /Confirmar Ajuste/i });
      await expect(confirmBtn.first()).toBeEnabled();
      await confirmBtn.first().click();
      await page.waitForTimeout(2500);
    }
  });

});
