import { useQuery, useMutation, useQueryClient, useInfiniteQuery } from '@tanstack/react-query';
import { asientosService } from '../services/asientos';
import type { AsientoFiltros, AsientoInput, Asiento } from '../types/asientos';

export const useAsientosInfinite = (filtros?: Omit<AsientoFiltros, 'last_fecha' | 'last_id'>) => {
  return useInfiniteQuery({
    queryKey: ['asientos', 'infinite', filtros],
    queryFn: async ({ pageParam }) => {
      const res = await asientosService.listar({
        ...filtros,
        last_fecha: pageParam?.last_fecha,
        last_id: pageParam?.last_id,
      });
      return res;
    },
    initialPageParam: undefined as { last_fecha?: string; last_id?: number } | undefined,
    getNextPageParam: (lastPage: any) => {
      return lastPage?.has_more ? lastPage?.next_cursor : undefined;
    },
    staleTime: 1000 * 60 * 5,
  });
};

export const useAsientos = (filtros?: AsientoFiltros) => {
  const queryClient = useQueryClient();

  const asientosQuery = useQuery({
    queryKey: ['asientos', filtros],
    queryFn: () => asientosService.listar(filtros),
    staleTime: 1000 * 60 * 5, // 5 minutos de cache inteligente
  });

  const crearMutation = useMutation({
    mutationFn: (input: AsientoInput) => asientosService.crear(input),
    onSuccess: () => {
      return queryClient.invalidateQueries({ queryKey: ['asientos'] });
    },
  });

  const actualizarMutation = useMutation({
    mutationFn: ({ id, input }: { id: number; input: Partial<AsientoInput> }) =>
      asientosService.actualizar(id, input),
    onSuccess: () => {
      return queryClient.invalidateQueries({ queryKey: ['asientos'] });
    },
  });

  const confirmarMutation = useMutation({
    mutationFn: (id: number) => asientosService.confirmar(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['asientos'] });
      await queryClient.invalidateQueries({ queryKey: ['libros'] });
    },
  });

  const anularMutation = useMutation({
    mutationFn: ({ id, fechaReversion }: { id: number; fechaReversion?: string }) =>
      asientosService.anular(id, fechaReversion),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['asientos'] });
      await queryClient.invalidateQueries({ queryKey: ['libros'] });
    },
  });

  const dataRaw = asientosQuery.data as any;
  const asientosList: Asiento[] = Array.isArray(dataRaw)
    ? dataRaw
    : Array.isArray(dataRaw?.items)
    ? dataRaw.items
    : Array.isArray(dataRaw?.data)
    ? dataRaw.data
    : [];

  return {
    asientos: asientosList,
    hasMore: dataRaw?.has_more ?? false,
    nextCursor: dataRaw?.next_cursor,
    isLoading: asientosQuery.isLoading,
    isError: asientosQuery.isError,
    error: asientosQuery.error,
    refetch: asientosQuery.refetch,
    crearAsiento: crearMutation.mutateAsync,
    actualizarAsiento: actualizarMutation.mutateAsync,
    confirmarAsiento: confirmarMutation.mutateAsync,
    anularAsiento: anularMutation.mutateAsync,
    isCreating: crearMutation.isPending,
    isUpdating: actualizarMutation.isPending,
    isConfirming: confirmarMutation.isPending,
    isAnulando: anularMutation.isPending,
  };
};

export const useLibroDiario = (desde?: string, hasta?: string) => {
  return useQuery({
    queryKey: ['libros', 'diario', desde, hasta],
    queryFn: () => asientosService.obtenerLibroDiario(desde, hasta),
    staleTime: 1000 * 60 * 5,
  });
};

export const useLibroMayor = (
  cuentaId: number = 0,
  ejercicio?: number,
  mes?: number,
  moneda: string = 'VES',
  desde?: string,
  hasta?: string
) => {
  return useQuery({
    queryKey: ['libros', 'mayor', cuentaId, ejercicio, mes, moneda, desde, hasta],
    queryFn: () => asientosService.obtenerLibroMayor(cuentaId, ejercicio, mes, moneda, desde, hasta),
    staleTime: 1000 * 60 * 5,
  });
};

export const useBalanceComprobacion = (
  ejercicio?: number,
  mes?: number,
  moneda: string = 'VES',
  desde?: string,
  hasta?: string
) => {
  return useQuery({
    queryKey: ['libros', 'balance-comprobacion', ejercicio, mes, moneda, desde, hasta],
    queryFn: () => asientosService.obtenerBalanceComprobacion(ejercicio, mes, moneda, desde, hasta),
    staleTime: 1000 * 60 * 5,
  });
};
