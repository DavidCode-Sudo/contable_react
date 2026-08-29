<?php
declare(strict_types=1);

// Iniciar buffer de salida desde el comienzo para capturar cualquier salida accidental
if (ob_get_level() === 0) {
    ob_start();
}

// Configurar para PDF - suprimir warnings de libpng / GD
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

set_error_handler(function($errno, $errstr) {
    if (strpos($errstr, 'libpng warning') !== false || 
        strpos($errstr, 'iCCP') !== false ||
        strpos($errstr, 'sRGB profile') !== false) {
        return true;
    }
    return false;
});

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database/database.php';
require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

// Liberar la sesión para evitar bloqueos concurrentes en la app
session_write_close();

$conn = \getConnection();
$identifier = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if (empty($identifier)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Identificador de requisición no válido']);
    exit;
}

// 1. Obtener datos de la Requisición
$stmt = $conn->prepare("
    SELECT r.*,
           COALESCE(u.nombre_completo, 'Solicitante') AS solicitante,
           prov.nombre AS prov_nombre,
           prov.ruc AS prov_ruc
    FROM requisiciones r
    LEFT JOIN usuarios u ON u.id = r.solicitante_id
    LEFT JOIN proveedores prov ON r.proveedor_id = prov.id AND r.proveedor_id > 0
    WHERE r.id = ? OR r.numero = ?
    LIMIT 1
");
$stmt->execute([$identifier, $identifier]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => "Requisición #{$identifier} no encontrada"]);
    exit;
}

$reqId = (int) $req['id'];

// 2. Obtener ítems de la Requisición
$stmtItems = $conn->prepare("SELECT * FROM requisicion_items WHERE requisicion_id = ? ORDER BY id ASC");
$stmtItems->execute([$reqId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Normalizar campos de la requisición con fallbacks
$req['numero'] = !empty($req['numero']) ? $req['numero'] : sprintf("REQ-%d-%05d", (int)date('Y'), $reqId);
$req['proveedor_nombre'] = !empty($req['proveedor_nombre']) ? $req['proveedor_nombre'] : (!empty($req['prov_nombre']) ? $req['prov_nombre'] : 'CONSULTORES TÉCNICOS C.A.');
$req['proveedor_rif'] = !empty($req['proveedor_rif']) ? $req['proveedor_rif'] : (!empty($req['prov_ruc']) ? $req['prov_ruc'] : 'J-00000000-0');
$req['concepto_texto'] = !empty($req['observaciones']) ? strtoupper($req['observaciones']) : (!empty($req['concepto']) ? strtoupper($req['concepto']) : 'REQUISICIÓN DE BIENES Y SERVICIOS');

// Función interna de conversión de número a letras
function numeroALetrasRequisicion(float $numero): string {
    if ($numero <= 0) return 'CERO BOLÍVARES';
    
    $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
    $decenas = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
    $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
    
    $partes = explode('.', number_format($numero, 2, '.', ''));
    $entero = (int)$partes[0];
    $decimales = isset($partes[1]) ? (int)$partes[1] : 0;
    
    $convertirGrupo = function($num) use ($unidades, $decenas, $centenas, $especiales) {
        if ($num == 0) return '';
        $resultado = '';
        if ($num >= 100) {
            $cen = intval($num / 100);
            if ($cen == 1 && $num == 100) {
                $resultado = 'CIEN';
            } else {
                $resultado = $centenas[$cen];
            }
            $num = $num % 100;
        }
        if ($num >= 20) {
            $dec = intval($num / 10);
            $uni = $num % 10;
            if (!empty($resultado)) $resultado .= ' ';
            if ($uni == 0) {
                $resultado .= $decenas[$dec];
            } else if ($dec == 2) {
                $resultado .= 'VEINTI' . strtolower($unidades[$uni]);
            } else {
                $resultado .= $decenas[$dec] . ' Y ' . $unidades[$uni];
            }
        } else if ($num >= 10) {
            if (!empty($resultado)) $resultado .= ' ';
            $resultado .= $especiales[$num - 10];
        } else if ($num > 0) {
            if (!empty($resultado)) $resultado .= ' ';
            $resultado .= $unidades[$num];
        }
        return $resultado;
    };
    
    $letras = '';
    if ($entero >= 1000000) {
        $millones = intval($entero / 1000000);
        $letras = ($millones == 1) ? 'UN MILLÓN' : $convertirGrupo($millones) . ' MILLONES';
        $entero = $entero % 1000000;
    }
    if ($entero >= 1000) {
        $miles = intval($entero / 1000);
        if (!empty($letras)) $letras .= ' ';
        $letras .= ($miles == 1) ? 'MIL' : $convertirGrupo($miles) . ' MIL';
        $entero = $entero % 1000;
    }
    if ($entero > 0) {
        if (!empty($letras)) $letras .= ' ';
        $letras .= $convertirGrupo($entero);
    }
    if ($decimales > 0) {
        $letras .= ' CON ' . str_pad((string)$decimales, 2, '0', STR_PAD_LEFT) . '/100';
    }
    
    return $letras . ' BOLÍVARES';
}

// Limpieza de PNG para evitar incompatibilidades de perfil sRGB
function cleanPngImageRequisicion(string $imagePath): string {
    static $processed = [];
    if (isset($processed[$imagePath])) return $processed[$imagePath];
    if (!file_exists($imagePath) || !function_exists('imagecreatefrompng')) return $imagePath;

    $cacheDir = __DIR__ . '/../../uploads/pdf_cache/clean_images';
    $hash = md5($imagePath . ':' . filemtime($imagePath));
    $cachedPath = $cacheDir . '/' . $hash . '.png';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    if (!file_exists($cachedPath)) {
        try {
            $image = @imagecreatefrompng($imagePath);
            if ($image === false) return $imagePath;

            $width = imagesx($image);
            $height = imagesy($image);
            $cleanImage = imagecreatetruecolor($width, $height);

            imagealphablending($cleanImage, false);
            imagesavealpha($cleanImage, true);
            $transparent = imagecolorallocatealpha($cleanImage, 255, 255, 255, 127);
            imagefill($cleanImage, 0, 0, $transparent);
            imagecopy($cleanImage, $image, 0, 0, 0, 0, $width, $height);
            imagepng($cleanImage, $cachedPath);

            imagedestroy($image);
            imagedestroy($cleanImage);
        } catch (\Throwable $e) {
            return $imagePath;
        }
    }

    $processed[$imagePath] = file_exists($cachedPath) ? $cachedPath : $imagePath;
    return $processed[$imagePath];
}

// Clase de Diseño Oficial "Alcaldía del Municipio Bolivariano Libertador"
class PDFAlcaldiaRequisicion extends TCPDF {
    public array $reqData = [];

    public function Header(): void {
        // Logo Alcaldía (Izquierda)
        $logo_path = __DIR__ . '/../../assets/logos/logo4.png';
        if (file_exists($logo_path)) {
            $clean_logo = cleanPngImageRequisicion($logo_path);
            $this->Image($clean_logo, 4, 9, 40, 18, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Encabezado Texto Oficial
        $this->SetFont('helvetica', 'B', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(41, 11);
        $textWidth = 120;
        $this->Cell($textWidth, 3.5, 'REPÚBLICA BOLIVARIANA DE VENEZUELA', 0, 1, 'L');
        $this->SetXY(41, 14.5);
        $this->Cell($textWidth, 3.5, 'ALCALDÍA DEL MUNICIPIO BOLIVARIANO LIBERTADOR', 0, 1, 'L');
        $this->SetXY(41, 18);
        $this->Cell($textWidth, 3.5, 'FUNDACIÓN ORQUESTA SINFONICA MUNICIPAL DEL MUNICIPIO LIBERTADOR', 0, 1, 'L');
        $this->SetXY(41, 21.5);
        $this->Cell($textWidth, 3.5, 'G-20008980 COORDINACIÓN DE COMPRAS', 0, 1, 'L');
        
        // Logo Orquesta (Derecha)
        $logo_crrs_path = __DIR__ . '/../../assets/logos/logo2.png';
        if (file_exists($logo_crrs_path)) {
            $clean_logo_crrs = cleanPngImageRequisicion($logo_crrs_path);
            $this->Image($clean_logo_crrs, 158, 11, 45, 9, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Cuadros Superiores Derechos (Número, Fecha, RIF)
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
        
        $startX = 150;
        $startY = 38;
        
        // Cuadro "Número"
        $this->Rect($startX, $startY, 22, 6);
        $this->SetFont('helvetica', '', 7);
        $this->SetXY($startX, $startY + 1.5);
        $this->Cell(22, 3, 'Número', 0, 0, 'C');
        $this->Rect($startX + 22, $startY, 28, 6);
        $this->SetXY($startX + 22, $startY + 1.5);
        $this->Cell(28, 3, $this->reqData['numero'], 0, 0, 'C');
        
        // Cuadro "Fecha"
        $this->Rect($startX, $startY + 6, 22, 6);
        $this->SetXY($startX, $startY + 7.5);
        $this->Cell(22, 3, 'Fecha', 0, 0, 'C');
        $this->Rect($startX + 22, $startY + 6, 28, 6);
        $this->SetXY($startX + 22, $startY + 7.5);
        $fechaReq = !empty($this->reqData['fecha_solicitud']) ? date('d/m/Y', strtotime($this->reqData['fecha_solicitud'])) : date('d/m/Y');
        $this->Cell(28, 3, $fechaReq, 0, 0, 'C');
        
        // Cuadro "R.I.F"
        $this->Rect($startX, $startY + 12, 22, 6);
        $this->SetXY($startX, $startY + 13.5);
        $this->Cell(22, 3, 'R.I.F', 0, 0, 'C');
        $this->Rect($startX + 22, $startY + 12, 28, 6);
        $this->SetXY($startX + 22, $startY + 13.5);
        $this->Cell(28, 3, $this->reqData['proveedor_rif'], 0, 0, 'C');
    }
    
    public function Footer(): void {
        $this->SetY(-18);
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);
        
        $this->SetXY(10, -15);
        $this->Cell(90, 3, 'Lugar de entrega de los Bienes o la Presentación de los', 0, 1, 'L');
        $this->SetXY(10, -12);
        $this->Cell(90, 3, 'servicios:', 0, 1, 'L');
        
        $this->SetXY(110, -15);
        $this->Cell(90, 3, 'Dirección: Esquina de Gradillas a Sociedad Edificio Humboldt.', 0, 1, 'L');
        $this->SetXY(110, -12);
        $this->Cell(90, 3, 'Segundo Nivel. Caracas.', 0, 1, 'L');
        $this->SetXY(110, -9);
        $this->Cell(90, 3, 'Teléfonos: 0426-5767776 (0212)5423080', 0, 1, 'L');
    }
    
    public function addWatermark(): void {
        if (($this->reqData['estado'] ?? '') === 'anulada') {
            $this->StartTransform();
            $this->SetAlpha(0.15);
            $this->SetTextColor(255, 0, 0);
            $this->SetFont('helvetica', 'B', 80);
            
            $x = $this->GetPageWidth() / 2;
            $y = $this->GetPageHeight() / 2;
            $this->Rotate(45, $x, $y);
            $this->SetXY($x - 60, $y - 15);
            $this->Cell(120, 40, 'ANULADO', 0, 0, 'C');
            $this->StopTransform();
            $this->SetAlpha(1);
            $this->SetTextColor(0, 0, 0);
        }
    }
}

// 3. Generación del Documento TCPDF
$pdf = new PDFAlcaldiaRequisicion('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->reqData = $req;

$pdf->SetCreator('Sistema Contable - Alcaldía de Caracas');
$pdf->SetAuthor('Alcaldía de Caracas');
$pdf->SetTitle('Requisición ' . $req['numero']);
$pdf->SetSubject('Requisición de Bienes y Servicios');

$pdf->SetMargins(10, 45, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(FALSE);

$pdf->AddPage();

// Título Central
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetY(60);
$pdf->Cell(0, 8, 'REQUISICIÓN', 0, 1, 'C');

// Sección Proveedor, Dependencia Emisora y Concepto
$currentY = 72;
$pdf->SetY($currentY);
$pdf->SetFont('helvetica', 'B', 9);
$labelProveedor = 'PROVEEDOR:';
$labelWidth = $pdf->GetStringWidth($labelProveedor . ' ') + 1;
$pdf->Cell($labelWidth, 6, $labelProveedor, 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(10 + $labelWidth, $currentY);
$pdf->Cell(0, 6, $req['proveedor_nombre'], 0, 1, 'L');

$currentY += 8;
$pdf->SetY($currentY);
$pdf->SetFont('helvetica', 'B', 9);
$labelDependencia = 'DEPENDENCIA EMISORA:';
$labelDependenciaWidth = $pdf->GetStringWidth($labelDependencia . ' ') + 1;
$pdf->Cell($labelDependenciaWidth, 6, $labelDependencia, 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(10 + $labelDependenciaWidth, $currentY);
$pdf->Cell(0, 6, 'FUNDACIÓN ORQUESTA SINFONICA MUNICIPAL DEL MUNICIPIO LIBERTADOR', 0, 1, 'L');

$currentY += 8;
$pdf->SetY($currentY);
$pdf->SetFont('helvetica', 'B', 9);
$labelConcepto = 'CONCEPTO:';
$labelConceptoWidth = $pdf->GetStringWidth($labelConcepto . ' ') + 1;
$pdf->Cell($labelConceptoWidth, 6, $labelConcepto, 0, 0, 'L');

$pdf->SetFont('helvetica', '', 9);
$posXConcepto = 10 + $labelConceptoWidth;
$pdf->SetXY($posXConcepto, $currentY);
$margins = $pdf->getMargins();
$anchoDisponible = $pdf->getPageWidth() - $margins['right'] - $posXConcepto;
if ($pdf->GetStringWidth($req['concepto_texto']) <= $anchoDisponible) {
    $pdf->Cell($anchoDisponible, 6, $req['concepto_texto'], 0, 1, 'L');
} else {
    $pdf->MultiCell($anchoDisponible, 6, $req['concepto_texto'], 0, 'L');
}

$conceptoEndY = $pdf->GetY();
$pdf->SetY($conceptoEndY + 3);

// Tabla de Renglones / Ítems
$currentY = $pdf->GetY();
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);
$pdf->SetFillColor(240, 240, 240);

$tipo_requisicion = $req['tipo_requisicion'] ?? 'compra';
$pdf->Rect(10, $currentY, 190, 10, 'DF');

if ($tipo_requisicion === 'compra') {
    $pdf->Line(30, $currentY, 30, $currentY + 10);
    $pdf->Line(55, $currentY, 55, $currentY + 10);
    $pdf->Line(120, $currentY, 120, $currentY + 10);
    $pdf->Line(155, $currentY, 155, $currentY + 10);
    
    $pdf->SetXY(10, $currentY + 2);
    $pdf->Cell(20, 6, 'CANTIDAD', 0, 0, 'C');
    $pdf->Cell(25, 6, 'UNIDAD', 0, 0, 'C');
    $pdf->Cell(65, 6, 'DESCRIPCIÓN', 0, 0, 'C');
    $pdf->Cell(35, 6, 'COSTO', 0, 0, 'C');
    $pdf->Cell(45, 6, 'COSTO TOTAL', 0, 0, 'C');
} else {
    $pdf->Line(140, $currentY, 140, $currentY + 10);
    $pdf->SetXY(10, $currentY + 2);
    $pdf->Cell(130, 6, 'DESCRIPCIÓN DEL SERVICIO', 0, 0, 'C');
    $pdf->Cell(60, 6, 'COSTO TOTAL', 0, 0, 'C');
}

$pdf->SetFont('helvetica', '', 9);
$currentY = $currentY + 10;
$subtotal_bs = 0.0;

foreach ($items as $item) {
    $cantidad = (float)$item['cantidad'];
    $unidad = $item['unidad'] ?? 'Unidad';
    $descripcion = strtoupper((string)$item['descripcion']);
    $precio_bs = (float)$item['precio'];
    
    $linea_sin_impuesto = $cantidad * $precio_bs;
    $impuesto_linea = $linea_sin_impuesto * ((float)($item['impuesto'] ?? 0) / 100.0);
    $total_linea_bs = (float)($item['total_linea'] ?? ($linea_sin_impuesto + $impuesto_linea));
    if ($total_linea_bs <= 0) $total_linea_bs = $linea_sin_impuesto + $impuesto_linea;
    $subtotal_bs += $linea_sin_impuesto;
    
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(10, $currentY, 190, 8, 'DF');
    
    if ($tipo_requisicion === 'compra') {
        $pdf->Line(30, $currentY, 30, $currentY + 8);
        $pdf->Line(55, $currentY, 55, $currentY + 8);
        $pdf->Line(120, $currentY, 120, $currentY + 8);
        $pdf->Line(155, $currentY, 155, $currentY + 8);
        
        $pdf->SetXY(10, $currentY + 2);
        $pdf->Cell(20, 4, number_format($cantidad, 0), 0, 0, 'C');
        $pdf->SetXY(30, $currentY + 2);
        $pdf->Cell(25, 4, substr($unidad, 0, 8), 0, 0, 'C');
        $pdf->SetXY(55, $currentY + 2);
        $pdf->Cell(65, 4, substr($descripcion, 0, 30), 0, 0, 'L');
        $pdf->SetXY(120, $currentY + 2);
        $pdf->Cell(35, 4, number_format($precio_bs, 2, ',', '.'), 0, 0, 'R');
        $pdf->SetXY(155, $currentY + 2);
        $pdf->Cell(45, 4, number_format($total_linea_bs, 2, ',', '.'), 0, 0, 'R');
    } else {
        $pdf->Line(140, $currentY, 140, $currentY + 8);
        $pdf->SetXY(10, $currentY + 2);
        $pdf->Cell(130, 4, substr($descripcion, 0, 60), 0, 0, 'L');
        $pdf->SetXY(140, $currentY + 2);
        $pdf->Cell(60, 4, number_format($total_linea_bs, 2, ',', '.'), 0, 0, 'R');
    }
    
    $currentY += 8;
}

// Fila Vacía Estética
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(10, $currentY, 190, 8, 'DF');

if ($tipo_requisicion === 'compra') {
    $pdf->Line(30, $currentY, 30, $currentY + 8);
    $pdf->Line(55, $currentY, 55, $currentY + 8);
    $pdf->Line(120, $currentY, 120, $currentY + 8);
    $pdf->Line(155, $currentY, 155, $currentY + 8);
} else {
    $pdf->Line(140, $currentY, 140, $currentY + 8);
}

$currentY += 8;

// Cuadro de Totales (Subtotal, IVA 16%, Total Final)
$subtotal_real = isset($req['subtotal']) ? (float)$req['subtotal'] : $subtotal_bs;
$iva_monto = isset($req['impuestos']) ? (float)$req['impuestos'] : ($subtotal_real * 0.16); 
$total_final = isset($req['monto_total']) ? (float)$req['monto_total'] : (isset($req['total']) ? (float)$req['total'] : ($subtotal_real + $iva_monto));

$pdf->SetFillColor(248, 248, 248);
$pdf->SetFont('helvetica', 'B', 10);

$pdf->SetXY(120, $currentY + 5);
$pdf->Cell(35, 6, 'SUB TOTAL', 1, 0, 'L', true);
$pdf->Cell(45, 6, 'Bs. ' . number_format($subtotal_real, 2, ',', '.'), 1, 1, 'R', true);

$pdf->SetXY(120, $currentY + 11);
$pdf->Cell(35, 6, 'IVA 16%', 1, 0, 'L', true);
$pdf->Cell(45, 6, 'Bs. ' . number_format($iva_monto, 2, ',', '.'), 1, 1, 'R', true);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetXY(120, $currentY + 17);
$pdf->Cell(35, 7, 'TOTAL', 1, 0, 'L', true);
$pdf->Cell(45, 7, 'Bs. ' . number_format($total_final, 2, ',', '.'), 1, 1, 'R', true);

// Información Presupuestaria y Monto en Letras
$infoPresupY = $currentY + 30;
$pdf->SetY($infoPresupY);
$pdf->SetFont('helvetica', '', 9);

$monto_en_letras = numeroALetrasRequisicion($total_final);

$pdf->Cell(0, 4, 'SEGÚN PRESUPUESTO/ CONTRATO NÚMERO: ________________', 0, 1, 'L');
$pdf->SetY($infoPresupY + 4);
$pdf->Cell(0, 4, 'DE FECHA: _______ POR UN MONTO DE: ' . $monto_en_letras, 0, 1, 'L');
$pdf->SetY($infoPresupY + 8);
$pdf->Cell(0, 4, 'REF. SOLICITUD: REQUISICIÓN N° ' . $req['numero'], 0, 1, 'L');
$pdf->SetY($infoPresupY + 12);
$pdf->Cell(0, 4, 'CHEQUE: _______ ENTREGAR ANTES DE: _______', 0, 1, 'L');

// Sección Oficial de Firmas
$firmasY = $infoPresupY + 20;
$pdf->SetY($firmasY);
$pdf->SetFont('helvetica', 'B', 9);

$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);

$pdf->Rect(10, $firmasY, 190, 55);
$pdf->Line(73, $firmasY, 73, $firmasY + 55);
$pdf->Line(136, $firmasY, 136, $firmasY + 55);
$pdf->Line(10, $firmasY + 15, 200, $firmasY + 15);

$pdf->SetXY(10, $firmasY + 2);
$pdf->Cell(63, 5, 'REALIZADO POR:', 0, 0, 'C');
$pdf->SetXY(73, $firmasY + 2);
$pdf->Cell(63, 5, 'AUTORIZADO POR:', 0, 0, 'C');
$pdf->SetXY(136, $firmasY + 2);
$pdf->Cell(64, 5, 'PROVEEDOR', 0, 0, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(10, $firmasY + 8);
$pdf->Cell(63, 4, 'FIRMA Y SELLO', 0, 0, 'C');
$pdf->SetXY(73, $firmasY + 8);
$pdf->Cell(63, 4, 'FIRMA Y SELLO', 0, 0, 'C');

$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY(10, $firmasY + 47);
$pdf->Cell(63, 4, 'COMPRAS Y SERVICIOS', 0, 0, 'C');
$pdf->SetXY(73, $firmasY + 47);
$pdf->Cell(63, 4, 'DIRECCIÓN EJECUTIVA', 0, 0, 'C');
$pdf->SetXY(136, $firmasY + 47);
$pdf->Cell(64, 4, 'FIRMA/SELLO Y FECHA', 0, 0, 'C');

$pdf->addWatermark();

// Limpiar buffer e imprimir PDF
while (ob_get_level()) {
    ob_end_clean();
}

$filename = 'Requisicion_' . str_replace(['/', '-', ' '], '_', $req['numero']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output($filename, 'I');
exit();
