<?php
declare(strict_types=1);

// Iniciar buffer de salida desde el comienzo para capturar cualquier salida accidental
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database/database.php';
require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

// IMPORTANTE: Liberar el bloqueo de sesión para permitir peticiones simultáneas
session_write_close();

$conn = \getConnection();
$identifier = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if (empty($identifier)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Identificador de orden no válido']);
    exit;
}

$stmtHead = $conn->prepare("
    SELECT oe.*, 
           COALESCE(us.nombre_completo, 'Sistema') AS solicitante,
           COALESCE(d.nombre, 'Sin Departamento') AS departamento_destino,
           ua.nombre_completo AS autorizado_por_nombre,
           COALESCE(ue.nombre_completo, us.nombre_completo, 'Sistema') AS entregado_por_nombre,
           ur.nombre_completo AS recibido_por_nombre,
           COALESCE(oe.numero_orden, oe.numero) AS numero_final,
           COALESCE(oe.justificacion, oe.motivo) AS motivo_final
    FROM ordenes_entrega oe 
    LEFT JOIN usuarios us ON us.id = COALESCE(oe.solicitante_id, oe.usuario_despacho_id, 1)
    LEFT JOIN departamentos d ON d.id = COALESCE(oe.departamento_id, oe.departamento_destino_id)
    LEFT JOIN usuarios ua ON ua.id = oe.autorizado_por
    LEFT JOIN usuarios ue ON ue.id = COALESCE(oe.usuario_despacho_id, oe.entregado_por, 1)
    LEFT JOIN usuarios ur ON ur.id = oe.recibido_por
    WHERE oe.id = ? OR oe.hash_verificacion LIKE ? OR oe.numero_orden = ?
");
$likeToken = is_numeric($identifier) ? $identifier : ($identifier . '%');
$stmtHead->execute([$identifier, $likeToken, $identifier]);
$orden = $stmtHead->fetch(PDO::FETCH_ASSOC);

if (!$orden) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Orden de entrega no encontrada']);
    exit;
}

$id = (int) $orden['id'];

// Asegurar token hash_verificacion inmutable
if (empty($orden['hash_verificacion'])) {
    $orden['hash_verificacion'] = hash('sha256', "OSMC-ODE-{$id}-" . ($orden['fecha_orden'] ?? date('Y-m-d')) . "-SECRET");
    try {
        $updHash = $conn->prepare("UPDATE ordenes_entrega SET hash_verificacion = ? WHERE id = ?");
        $updHash->execute([$orden['hash_verificacion'], $id]);
    } catch (\Throwable $e) {}
}

$stmtItems = $conn->prepare("
    SELECT oei.*, p.codigo, p.nombre, p.unidad_medida,
           COALESCE(oei.cantidad_despachada, oei.cantidad_entregada, 0) AS cantidad_entregada_final
    FROM orden_entrega_items oei
    INNER JOIN productos p ON p.id = oei.producto_id
    WHERE oei.orden_entrega_id = ?
    ORDER BY p.nombre
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Crear PDF con diseño tipo vale de entrega física interna
class OrdenEntregaPDF extends TCPDF {
    private $orden;

    public function __construct($orden) {
        parent::__construct();
        $this->orden = $orden;
    }

    public function Header() {
        // Logo (lado izquierdo)
        $logoPath = __DIR__ . '/../../assets/logos/logo2.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 13, 10, 70, 10, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }

        // Código QR con Token Corto Criptográfico Ciego (12 caracteres - Lectura ultrarrápida de cámara)
        $fullHash = (string) ($this->orden['hash_verificacion'] ?? '');
        $shortToken = !empty($fullHash) ? substr($fullHash, 0, 12) : (string) ($this->orden['id'] ?? 1);
        
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostOnly = parse_url("http://{$host}", PHP_URL_HOST);
        if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'])) {
            $lanIp = gethostbyname(gethostname());
            if (!empty($lanIp) && $lanIp !== '127.0.0.1') {
                $port = $_SERVER['SERVER_PORT'] ?? '80';
                $portSuffix = ($port !== '80' && $port !== '443') ? ":{$port}" : '';
                $host = $lanIp . $portSuffix;
            }
        }

        $verifyUrl = "http://{$host}/contable_react/v.php?t={$shortToken}";
        
        $styleQR = [
            'border' => 0, // Sin recuadro perimetral negro
            'vpadding' => 1,
            'hpadding' => 1,
            'forecolor' => [0, 0, 0], // Negro puro para alto contraste de cámara
            'backcolor' => [255, 255, 255], // Fondo blanco integrado
            'module_width' => 1,
            'module_height' => 1
        ];
        $this->write2DBarcode($verifyUrl, 'QRCODE,M', 178, 8, 16, 16, $styleQR, 'N');

        // Título ORDEN DE ENTREGA (grande y centrado)
        $this->SetFont('helvetica', 'B', 20);
        $this->SetTextColor(30, 30, 30);
        $this->SetXY(15, 35);
        $this->Cell(0, 10, 'ORDEN DE ENTREGA', 0, 1, 'C');
        
        // Restablecer colores
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 10, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$numeroOrden = $orden['numero_final'] ?? "ODE-{$id}";

$pdf = new OrdenEntregaPDF($orden);
$pdf->setPrintFooter(false);
$pdf->SetCreator('Sistema Contable OSMC');
$pdf->SetAuthor('Sistema Contable OSMC');
$pdf->SetTitle('Orden de Entrega ' . $numeroOrden);
$pdf->SetSubject('Orden de Entrega');

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

function truncateText($text, $maxLength = 48) {
    return mb_strlen($text, 'UTF-8') > $maxLength ? mb_substr($text, 0, $maxLength, 'UTF-8') . '...' : $text;
}

$contentStartY = 50;
$pdf->SetY($contentStartY);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(30, 30, 30);

// Columna izquierda
$leftX = 15; $colW = 85; $lineH = 5.5;
$pdf->SetXY($leftX, $contentStartY);
$pdf->Cell($colW, $lineH, 'SOLICITANTE / OPERADOR', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetX($leftX);
$pdf->Cell($colW, $lineH, truncateText($orden['solicitante'] ?? 'Sistema', 40), 0, 1, 'L');
$pdf->SetX($leftX);
$pdf->Cell($colW, $lineH, 'Departamento: ' . truncateText($orden['departamento_destino'] ?? 'Sin Departamento', 35), 0, 1, 'L');

// Columna derecha
$margins = $pdf->getMargins();
$rightMargin = isset($margins['right']) ? $margins['right'] : 15;
$rightColW = 70;
$rightBlockX = $pdf->getPageWidth() - $rightMargin - $rightColW;
$rightBlockY = $contentStartY;
$pdf->SetXY($rightBlockX, $rightBlockY);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell($rightColW, $lineH, 'N° DE ORDEN', 0, 1, 'R');
$pdf->SetXY($rightBlockX, $pdf->GetY());
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell($rightColW, $lineH, $numeroOrden, 0, 1, 'R');
$pdf->SetXY($rightBlockX, $pdf->GetY());
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell($rightColW, $lineH, 'Fecha: ' . date('d/m/Y', strtotime($orden['fecha_orden'])), 0, 1, 'R');
$pdf->SetXY($rightBlockX, $pdf->GetY());
$pdf->Cell($rightColW, $lineH, 'Estado: ' . strtoupper($orden['estado']), 0, 1, 'R');

$pdf->SetY(max($pdf->GetY(), $rightBlockY + (4 * $lineH)) + 4);
$pdf->Ln(4);

// Motivo / Justificación de la entrega
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 6, 'JUSTIFICACIÓN / MOTIVO DE LA ENTREGA:', 0, 1, 'L');
$pdf->SetDrawColor(200, 200, 200);
$pageWidth = $pdf->getPageWidth();
$margins = $pdf->getMargins();
$left = isset($margins['left']) ? $margins['left'] : 15;
$right = isset($margins['right']) ? $margins['right'] : 15;
$pdf->Line($left, $pdf->GetY(), $pageWidth - $right, $pdf->GetY());
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);
$pdf->SetFont('helvetica', '', 9);
$pdf->MultiCell(0, 5, $orden['motivo_final'] ?? 'Despacho institucional de suministros', 0, 'L');
$pdf->Ln(4);

// TABLA FÍSICA INTERNA (SIN COLUMNAS FINANCIERAS)
// Columnas: [ N° (8%) | CÓDIGO (18%) | DESCRIPCIÓN DEL INSUMO (46%) | UNIDAD (12%) | CANT. SOL. (8%) | ENTREGADO (8%) ]
// Anchos para 170mm: 14mm + 30mm + 78mm + 20mm + 14mm + 14mm = 170mm

$pdf->SetFont('helvetica', 'B', 7.5);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetDrawColor(100, 100, 100);
$pdf->SetTextColor(30, 30, 30);

$tableWidth = 170;
$availableWidth = $pageWidth - $left - $right;
$tableStartX = $left + (($availableWidth - $tableWidth) / 2);

$pdf->SetX($tableStartX);
$pdf->Cell(10, 7, 'N°', 1, 0, 'C', true);
$pdf->Cell(24, 7, 'CÓDIGO', 1, 0, 'C', true);
$pdf->Cell(80, 7, 'DESCRIPCIÓN DEL INSUMO', 1, 0, 'C', true);
$pdf->Cell(18, 7, 'UNIDAD', 1, 0, 'C', true);
$pdf->Cell(19, 7, 'CANT. SOL.', 1, 0, 'C', true);
$pdf->Cell(19, 7, 'ENTREGADO', 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 8.5);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetTextColor(0, 0, 0);

$rowNum = 1;

foreach ($items as $item) {
    $cantSol = (float) $item['cantidad_solicitada'];
    $cantEnt = (float) $item['cantidad_entregada_final'];

    $pdf->SetX($tableStartX);
    $pdf->Cell(10, 6.5, (string) $rowNum, 1, 0, 'C');
    $pdf->Cell(24, 6.5, $item['codigo'], 1, 0, 'C');
    $pdf->Cell(80, 6.5, truncateText($item['nombre'], 50), 1, 0, 'L');
    $pdf->Cell(18, 6.5, $item['unidad_medida'], 1, 0, 'C');
    $pdf->Cell(19, 6.5, number_format($cantSol, 2, ',', '.'), 1, 0, 'R');
    $pdf->Cell(19, 6.5, number_format($cantEnt, 2, ',', '.'), 1, 1, 'R');

    $rowNum++;
}

$pdf->Ln(4);

if (!empty($orden['observaciones'])) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->Cell(0, 6, 'OBSERVACIONES DE EMPAQUE / ENTREGA:', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell(0, 4, $orden['observaciones'], 0, 'L');
    $pdf->Ln(4);
}

// Sello Digital SHA-256 (si existe)
if (!empty($orden['hash_verificacion'])) {
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, 'Sello Digital SHA-256: ' . $orden['hash_verificacion'], 0, 1, 'L');
    $pdf->Ln(2);
}

$pageHeight = $pdf->getPageHeight();
$bottomMargin = 25;
$signatureHeight = 45;
$targetY = $pageHeight - $bottomMargin - $signatureHeight;

if ($pdf->GetY() < $targetY - 10) {
    $pdf->SetY($targetY);
} else {
    $pdf->Ln(10);
}

// SECCIÓN DE FIRMAS Y SELLOS FÍSICOS
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$firmaWidth = ($availableWidth - 20) / 2;
$firmaStartY = $pdf->GetY();

// FIRMA IZQUIERDA: Entregado por (Almacén)
$firmaLeftX = $left;
$pdf->SetXY($firmaLeftX, $firmaStartY);
$pdf->SetDrawColor(100, 100, 100);
$lineWidth = 65;
$lineStartX = $firmaLeftX + (($firmaWidth - $lineWidth) / 2);
$pdf->Line($lineStartX, $pdf->GetY(), $lineStartX + $lineWidth, $pdf->GetY());
$pdf->Ln(3);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetX($firmaLeftX);
$pdf->Cell($firmaWidth, 6, 'ENTREGADO POR (ALMACÉN)', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetX($firmaLeftX);
$pdf->Cell($firmaWidth, 5, truncateText($orden['entregado_por_nombre'] ?? 'Simón Figueroa', 35), 0, 1, 'C');

// FIRMA DERECHA: Recibido Conforme (Unidad Receptora + Sello Húmedo)
$firmaRightX = $left + $firmaWidth + 20;
$pdf->SetXY($firmaRightX, $firmaStartY);
$lineStartXRight = $firmaRightX + (($firmaWidth - $lineWidth) / 2);
$pdf->Line($lineStartXRight, $firmaStartY, $lineStartXRight + $lineWidth, $firmaStartY);
$pdf->SetXY($firmaRightX, $firmaStartY + 3);

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetX($firmaRightX);
$pdf->Cell($firmaWidth, 6, 'RECIBIDO CONFORME Y SELLO', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetX($firmaRightX);
$pdf->Cell($firmaWidth, 5, truncateText($orden['departamento_destino'] ?? 'Unidad Receptora', 35), 0, 1, 'C');

$maxFirmaY = max($pdf->GetY(), $firmaStartY + 25);
$pdf->SetY($maxFirmaY);

// Pie Institucional de la Fundación
$pdf->Ln(4);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 4, 'FUNDACIÓN ORQUESTA SINFÓNICA MUNICIPAL DEL MUNICIPIO LIBERTADOR', 0, 1, 'C');
$pdf->Cell(0, 4, 'RIF: G200162805', 0, 1, 'C');
$pdf->Cell(0, 4, 'AV LECUNA EDIF TORRE OESTE PISO 13 OF ALA ESTE URB PARQUE CENTRAL CARACAS DISTRITO CAPITAL', 0, 1, 'C');

$filename = 'Orden_Entrega_' . $numeroOrden . '.pdf';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output($filename, 'I');
exit;
