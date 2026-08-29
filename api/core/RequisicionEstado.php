<?php
declare(strict_types=1);

namespace Api\Core;

/**
 * Enum y Diccionario Centralizado de Estados de Requisiciones (PHP 8.1+)
 * Previene la duplicación de código en Controladores, Reportes, Auditoría Global u Órdenes de Compra.
 */
enum RequisicionEstado: string
{
    case BORRADOR = 'borrador';
    case ENVIADA = 'enviada';
    case PENDIENTE_DIRECCION = 'pendiente_direccion';
    case PENDIENTE_PRESUPUESTO = 'pendiente_presupuesto';
    case PENDIENTE_NIVEL_2 = 'pendiente_nivel_2';
    case APROBADA = 'aprobada';
    case RECHAZADA = 'rechazada';
    case ANULADA = 'anulada';
    case RECIBIDA = 'recibida';

    /**
     * Retorna la etiqueta amigable corporativa para el usuario final
     */
    public function label(): string
    {
        return match ($this) {
            self::BORRADOR => 'Borrador',
            self::ENVIADA, self::PENDIENTE_DIRECCION => 'Pendiente por Dirección Ejecutiva',
            self::PENDIENTE_PRESUPUESTO, self::PENDIENTE_NIVEL_2 => 'Pendiente por Presupuesto',
            self::APROBADA => 'Aprobada',
            self::RECHAZADA => 'Rechazada',
            self::ANULADA => 'Anulada',
            self::RECIBIDA => 'Recibida',
        };
    }

    /**
     * Helper estático para obtener la etiqueta legible directamente desde un string de estado
     */
    public static function getLabel(?string $estado): string
    {
        if (!$estado) {
            return 'Desconocido';
        }

        $estLower = strtolower(trim($estado));
        $enumCase = self::tryFrom($estLower);

        if ($enumCase) {
            return $enumCase->label();
        }

        return match ($estLower) {
            'pendiente' => 'Pendiente por Dirección Ejecutiva',
            default => ucfirst($estado),
        };
    }
}
