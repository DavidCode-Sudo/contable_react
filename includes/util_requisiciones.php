<?php
// Utilidades para Requisiciones de Compra
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database/database.php';

if (!function_exists('generarNumeroRequisicion')) {
    function generarNumeroRequisicion(PDO $conn, string $prefijo = 'REQ'): string {
        $anio = date('Y');
        $base = $prefijo . '-' . $anio . '-';
        $stmt = $conn->prepare("SELECT numero FROM requisiciones WHERE numero LIKE :p ORDER BY numero DESC LIMIT 1");
        $stmt->execute([':p' => $base . '%']);
        $ultimo = $stmt->fetchColumn();
        $corr = 0;
        if ($ultimo && preg_match('/^' . preg_quote($base,'/') . '(\d{5})$/', $ultimo, $m)) {
            $corr = (int)$m[1];
        }
        do {
            $corr++;
            $nuevo = $base . str_pad((string)$corr, 5, '0', STR_PAD_LEFT);
            $chk = $conn->prepare("SELECT 1 FROM requisiciones WHERE numero=:n LIMIT 1");
            $chk->execute([':n'=>$nuevo]);
        } while ($chk->fetchColumn());
        return $nuevo;
    }
}

if (!function_exists('calcularTotalesItems')) {
    function calcularTotalesItems(array $items): array {
        $subtotal = 0.0; $impuestos = 0.0; $total = 0.0;
        foreach ($items as $it) {
            $cant = (float)($it['cantidad'] ?? 0);
            // El precio ya está en bolívares en el campo 'precio'
            $precio_bs = (float)($it['precio'] ?? 0);
            $imp = (float)($it['impuesto'] ?? 0);
            
            // Calcular línea subtotal
            $linea = $cant * $precio_bs;
            $subtotal += $linea;
            
            // Calcular impuestos sobre la línea
            if ($imp > 0) {
                $impuestos += ($linea * ($imp / 100.0));
            }
        }
        $total = $subtotal + $impuestos;
        return [
            'subtotal' => $subtotal,
            'impuestos' => $impuestos,
            'total' => $total
        ];
    }
}
