<?php
declare(strict_types=1);

namespace Api\Core;

abstract class Controller
{
    protected function jsonResponse(mixed $data, int $statusCode = 200, string $message = ''): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => $statusCode >= 200 && $statusCode < 300,
        ];

        if ($message !== '') {
            $response['message'] = $message;
        }

        if (is_array($data) && isset($data['success'])) {
            $response = $data;
        } elseif ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function errorResponse(string $message, int $statusCode = 400, array $errors = []): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => false,
            'message' => $message,
        ];

        // Detección y estructuración de errores tridimensionales con ruta sugerida y permiso RBAC
        if (str_contains($message, '📌 DIAGNÓSTICO:')) {
            $diagnostico = '';
            $detalle = '';
            $accion = '';

            if (preg_match('/📌 DIAGNÓSTICO:\s*(.*?)(?=\s*💡 DETALLE:|\s*🔧 ACCIÓN REQUERIDA:|$)/su', $message, $m1)) {
                $diagnostico = trim($m1[1]);
            }
            if (preg_match('/💡 DETALLE:\s*(.*?)(?=\s*🔧 ACCIÓN REQUERIDA:|$)/su', $message, $m2)) {
                $detalle = trim($m2[1]);
            }
            if (preg_match('/🔧 ACCIÓN REQUERIDA:\s*(.*?)$/su', $message, $m3)) {
                $accion = trim($m3[1]);
            }

            // Inferencia de Ruta Sugerida y Permiso Requerido según el contexto del error
            $rutaSugerida = null;
            $permisoRequerido = null;

            if (str_contains($message, 'Períodos Contables') || str_contains($message, 'período contable') || str_contains($message, 'ejercicio fiscal')) {
                $rutaSugerida = '/contabilidad/periodos';
                $permisoRequerido = 'gestionar_periodos';
            } elseif (str_contains($message, 'Catálogo de Productos') || str_contains($message, '1.1.3.XX')) {
                $rutaSugerida = '/inventario/productos';
                $permisoRequerido = 'editar_productos';
            } elseif (str_contains($message, 'Catálogo de Cuentas') || str_contains($message, '5.1.2.XX')) {
                $rutaSugerida = '/contabilidad/cuentas';
                $permisoRequerido = 'gestionar_cuentas';
            }

            $response['error_type'] = 'VALIDACION_NEGOCIO';
            $response['payload'] = [
                'diagnostico' => $diagnostico !== '' ? $diagnostico : $message,
                'detalle' => $detalle,
                'accion' => $accion,
                'ruta_sugerida' => $rutaSugerida,
                'permiso_requerido' => $permisoRequerido,
            ];
        } else {
            // FALLBACK DEFENSIVO: Resiliencia ante errores de red o excepciones PDO simples no parseables
            $response['error_type'] = 'VALIDACION_SISTEMA';
            $response['payload'] = [
                'diagnostico' => 'Validación de Operación',
                'detalle' => $message,
                'accion' => 'Verifique los datos ingresados o contacte a soporte técnico si el problema persiste.',
                'ruta_sugerida' => null,
                'permiso_requerido' => null,
            ];
        }

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function getRequestData(): array
    {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    protected function jsonInput(): array
    {
        return $this->getRequestData();
    }

    protected function getJsonInput(): array
    {
        return $this->getRequestData();
    }

    protected function obtenerIpRealCliente(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Si la petición viene de Cloudflare (HTTP_CF_CONNECTING_IP) y la IP de origen proviene de un proxy confiable o CDN de Cloudflare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                if ($this->esIpProxyConfiable($remoteAddr)) {
                    return $cfIp;
                }
            }
        }

        // 2. Si viene tras proxy o balanceador inverso (X-Forwarded-For) desde una IP confiable
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && $this->esIpProxyConfiable($remoteAddr)) {
            $ips = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $clientIp = trim($ips[0]);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        return $remoteAddr;
    }

    protected function limpiarIntentosFallidosSudo(\PDO $db, int $usuarioId): void
    {
        $ip = $this->obtenerIpRealCliente();
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/api/tesoreria';
        $endpoint = parse_url($rawUri, PHP_URL_PATH) ?: '/api/tesoreria';
        $hashClave = hash('sha256', $ip . '_' . $usuarioId . '_' . $endpoint);

        try {
            $stmt = $db->prepare("DELETE FROM intentos_seguridad WHERE hash_clave = ?");
            $stmt->execute([$hashClave]);
        } catch (\Throwable $e) {
            // Ignorar silenciosamente si la tabla no existe
        }
    }

    private function esIpProxyConfiable(string $ip): bool
    {
        // 1. Comprobar si es privada o loopback (RFC 1918 / 127.0.0.1 / ::1 / Docker / K8s)
        $isPrivateOrLoopback = !filter_var(
            $ip, 
            FILTER_VALIDATE_IP, 
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPrivateOrLoopback) {
            return true;
        }

        // Rangos IPv4 oficiales de Cloudflare CDN / WAF
        $cloudflareCidrs = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];

        foreach ($cloudflareCidrs as $cidr) {
            if ($this->matchCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function matchCidr(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        [$subnet, $mask] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - (int)$mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
