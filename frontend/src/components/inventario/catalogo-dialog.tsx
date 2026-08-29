import React, { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { toast } from 'sonner';
import { Loader2, Calculator, FileSpreadsheet, CheckCircle2, XCircle } from 'lucide-react';
import { 
  catalogoCuentasService, 
  obtenerNaturalezaSugerida, 
  type CuentaContable, 
  type SaveCuentaPayload, 
  type TipoCuenta,
  type NaturalezaCuenta
} from '@/services/catalogoCuentas';

interface CatalogoDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  cuentaEdit?: CuentaContable;
  cuentasPadre: CuentaContable[];
}

export const CatalogoDialog: React.FC<CatalogoDialogProps> = ({ open, onOpenChange, cuentaEdit, cuentasPadre }) => {
  const queryClient = useQueryClient();
  const isEditing = !!cuentaEdit;

  // Form Fields State
  const [codigo, setCodigo] = useState('');
  const [nombre, setNombre] = useState('');
  const [tipo, setTipo] = useState<TipoCuenta>('activo');
  const [naturaleza, setNaturaleza] = useState<NaturalezaCuenta>('deudora');
  const [categoria, setCategoria] = useState('');
  const [cuentaPadreId, setCuentaPadreId] = useState<number | null>(null);
  const [estado, setEstado] = useState<'activa' | 'inactiva'>('activa');
  const [esPartida, setEsPartida] = useState(false);
  const [numeroPartida, setNumeroPartida] = useState('');
  const [generica, setGenerica] = useState('01');
  const [especifica, setEspecifica] = useState('01');
  const [subespecifica, setSubespecifica] = useState('00');

  // Errors & Async Validation State
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [validandoCodigo, setValidandoCodigo] = useState(false);
  const [codigoValido, setCodigoValido] = useState<boolean | null>(null);
  const [mensajeCodigo, setMensajeCodigo] = useState('');

  // Auto-suggest nature for patrimonial accounts
  useEffect(() => {
    if (!isEditing && tipo && !esPartida) {
      setNaturaleza(obtenerNaturalezaSugerida(tipo));
    }
  }, [tipo, isEditing, esPartida]);

  // Real-time ONAPRE code assembly
  useEffect(() => {
    if (esPartida && numeroPartida) {
      const g = (generica || '00').padStart(2, '0');
      const e = (especifica || '00').padStart(2, '0');
      const s = (subespecifica || '00').padStart(2, '0');
      setCodigo(`${numeroPartida}.${g}.${e}.${s}`);
    }
  }, [esPartida, numeroPartida, generica, especifica, subespecifica]);

  // Load editing values or reset on open
  useEffect(() => {
    if (open) {
      setErrors({});
      if (cuentaEdit) {
        setCodigo(cuentaEdit.codigo);
        setNombre(cuentaEdit.nombre);
        setTipo(cuentaEdit.tipo);
        setNaturaleza(cuentaEdit.naturaleza);
        setCategoria(cuentaEdit.categoria || '');
        setCuentaPadreId(cuentaEdit.cuenta_padre_id || null);
        setEstado(cuentaEdit.estado);
        setEsPartida(cuentaEdit.es_partida_presupuestaria);
        setNumeroPartida(cuentaEdit.numero_partida || '');
        setGenerica(cuentaEdit.generica || '00');
        setEspecifica(cuentaEdit.especifica || '00');
        setSubespecifica(cuentaEdit.subespecifica || '00');
        setCodigoValido(true);
        setMensajeCodigo('Código actual');
      } else {
        setCodigo('');
        setNombre('');
        setTipo('activo');
        setNaturaleza('deudora');
        setCategoria('');
        setCuentaPadreId(null);
        setEstado('activa');
        setEsPartida(false);
        setNumeroPartida('');
        setGenerica('01');
        setEspecifica('01');
        setSubespecifica('00');
        setCodigoValido(null);
        setMensajeCodigo('');
      }
    }
  }, [open, cuentaEdit]);

  // Debounced Async Validation (500ms)
  useEffect(() => {
    if (!open) return;
    const handler = setTimeout(async () => {
      if (!codigo || codigo.length < 2) return;
      if (isEditing && codigo === cuentaEdit?.codigo) {
        setCodigoValido(true);
        setMensajeCodigo('Código actual');
        return;
      }

      setValidandoCodigo(true);
      try {
        if (esPartida && numeroPartida) {
          const res = await catalogoCuentasService.validarCodigoPartida({
            numero_partida: numeroPartida,
            generica,
            especifica,
            subespecifica,
            omitir_id: cuentaEdit?.id
          });
          setCodigoValido(res.valido);
          setMensajeCodigo(res.mensaje);
        } else if (!esPartida) {
          const res = await catalogoCuentasService.validarCampo('codigo', codigo, cuentaEdit?.id);
          setCodigoValido(res.valido);
          setMensajeCodigo(res.mensaje);
        }
      } catch {
        setCodigoValido(false);
        setMensajeCodigo('Error de conexión');
      } finally {
        setValidandoCodigo(false);
      }
    }, 500);

    return () => clearTimeout(handler);
  }, [codigo, esPartida, numeroPartida, generica, especifica, subespecifica, isEditing, cuentaEdit, open]);

  const mutation = useMutation({
    mutationFn: (payload: SaveCuentaPayload) => 
      isEditing && cuentaEdit ? catalogoCuentasService.update(cuentaEdit.id, payload) : catalogoCuentasService.create(payload),
    onSuccess: () => {
      toast.success(isEditing ? 'Cuenta actualizada correctamente' : 'Cuenta registrada con éxito');
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
      onOpenChange(false);
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al guardar la cuenta');
    }
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const newErrors: Record<string, string> = {};

    if (!codigo.trim()) newErrors.codigo = 'El código es obligatorio';
    if (!nombre.trim()) newErrors.nombre = 'El nombre es obligatorio';
    else if (nombre.trim().length < 3) newErrors.nombre = 'Mínimo 3 caracteres';

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    if (codigoValido === false) {
      toast.error('Corrija el código antes de guardar.');
      return;
    }

    const payload: SaveCuentaPayload = {
      codigo: codigo.trim(),
      nombre: nombre.trim(),
      tipo,
      naturaleza,
      categoria,
      cuenta_padre_id: cuentaPadreId,
      estado,
      es_partida_presupuestaria: esPartida,
      numero_partida: esPartida ? numeroPartida.trim() : undefined,
      generica: esPartida ? generica.trim() : undefined,
      especifica: esPartida ? especifica.trim() : undefined,
      subespecifica: esPartida ? subespecifica.trim() : undefined,
    };

    mutation.mutate(payload);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto border-border shadow-lg">
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-primary/10 text-primary flex items-center justify-center">
              {esPartida ? <FileSpreadsheet className="size-4" /> : <Calculator className="size-4" />}
            </div>
            {isEditing ? 'Editar Registro Contable' : 'Nuevo Registro Contable'}
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground">
            Complete los datos para integrar esta cuenta al plan contable de la institución.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-5 py-2">
          {/* Fila 1: Modo Operativo */}
          <div className="bg-muted/40 p-3.5 rounded-lg border border-border/60 flex items-center justify-between">
            <div>
              <Label className="text-xs font-semibold text-foreground">Clasificador ONAPRE (Presupuestario)</Label>
              <p className="text-[11px] text-muted-foreground">Active esto si el registro corresponde a una partida de presupuesto.</p>
            </div>
            <div className="flex items-center gap-3">
              <span className={`text-xs font-bold ${esPartida ? 'text-primary' : 'text-muted-foreground'}`}>
                {esPartida ? 'ONAPRE' : 'PATRIMONIAL'}
              </span>
              <Switch 
                checked={esPartida} 
                onCheckedChange={(val) => setEsPartida(val)}
              />
            </div>
          </div>

          {/* DYNAMIC VIEW: Campos ONAPRE vs Contables */}
          {esPartida ? (
            <div className="border border-primary/20 bg-primary/5 rounded-lg p-3.5 space-y-3">
              <h4 className="text-[11px] font-bold text-primary uppercase tracking-wider">Estructura Presupuestaria ONAPRE</h4>
              <div className="grid grid-cols-4 gap-2.5">
                <div className="space-y-1">
                  <Label className="text-[11px] font-semibold text-foreground">Partida (PART) *</Label>
                  <Input value={numeroPartida} onChange={(e) => setNumeroPartida(e.target.value)} placeholder="Ej: 4.01" className="h-8 text-xs font-mono bg-background" />
                </div>
                <div className="space-y-1">
                  <Label className="text-[11px] font-semibold text-foreground">Genérica (GEN)</Label>
                  <Input value={generica} onChange={(e) => setGenerica(e.target.value)} placeholder="01" maxLength={2} className="h-8 text-xs font-mono bg-background" />
                </div>
                <div className="space-y-1">
                  <Label className="text-[11px] font-semibold text-foreground">Específica (ESP)</Label>
                  <Input value={especifica} onChange={(e) => setEspecifica(e.target.value)} placeholder="01" maxLength={2} className="h-8 text-xs font-mono bg-background" />
                </div>
                <div className="space-y-1">
                  <Label className="text-[11px] font-semibold text-foreground">Sub-Esp (SUB)</Label>
                  <Input value={subespecifica} onChange={(e) => setSubespecifica(e.target.value)} placeholder="00" maxLength={2} className="h-8 text-xs font-mono bg-background" />
                </div>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold text-foreground">Tipo de Cuenta Contable</Label>
                <Select value={tipo} onValueChange={(val) => setTipo(val as TipoCuenta)}>
                  <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Seleccione..." /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="activo" className="text-xs">Activo</SelectItem>
                    <SelectItem value="pasivo" className="text-xs">Pasivo</SelectItem>
                    <SelectItem value="patrimonio" className="text-xs">Patrimonio</SelectItem>
                    <SelectItem value="ingreso" className="text-xs">Ingresos</SelectItem>
                    <SelectItem value="gasto" className="text-xs">Gastos</SelectItem>
                    <SelectItem value="orden" className="text-xs">Cuentas de Orden</SelectItem>
                    <SelectItem value="cierre" className="text-xs">Cuentas de Cierre</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold text-foreground">Naturaleza Patrimonial</Label>
                <Select value={naturaleza} onValueChange={(val) => setNaturaleza(val as NaturalezaCuenta)}>
                  <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Seleccione..." /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="deudora" className="text-xs">Deudora (Aumenta por Debe)</SelectItem>
                    <SelectItem value="acreedora" className="text-xs">Acreedora (Aumenta por Haber)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}

          {/* Fila 2: Código Principal y Nombre */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-1.5 relative">
              <Label className="text-xs font-semibold text-foreground">Código Principal <span className="text-destructive">*</span></Label>
              <Input 
                value={codigo}
                onChange={(e) => setCodigo(e.target.value)}
                readOnly={esPartida}
                className={`h-9 text-xs ${codigoValido === false ? 'border-destructive focus-visible:ring-destructive' : codigoValido === true ? 'border-emerald-500' : ''} ${esPartida ? 'bg-muted/50 font-mono font-bold text-primary' : 'font-mono'}`} 
              />
              <div className="absolute right-3 top-8">
                {validandoCodigo ? <Loader2 className="size-3.5 animate-spin text-muted-foreground" /> :
                 codigoValido === true ? <CheckCircle2 className="size-3.5 text-emerald-500" /> :
                 codigoValido === false ? <XCircle className="size-3.5 text-destructive" /> : null}
              </div>
              {mensajeCodigo && (
                <p className={`text-[11px] font-semibold mt-1 ${codigoValido === true ? 'text-emerald-600' : codigoValido === false ? 'text-destructive' : 'text-muted-foreground'}`}>{mensajeCodigo}</p>
              )}
              {errors.codigo && <p className="text-[11px] text-destructive">{errors.codigo}</p>}
            </div>
            
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold text-foreground">Denominación / Nombre <span className="text-destructive">*</span></Label>
              <Input value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Ej: Banco Moneda Nacional" className="h-9 text-xs" />
              {errors.nombre && <p className="text-[11px] text-destructive">{errors.nombre}</p>}
            </div>
          </div>

          {/* Fila 3: Cuenta Padre y Estado */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold text-foreground">Cuenta / Partida Superior (Padre)</Label>
              <Select 
                value={cuentaPadreId?.toString() || "none"} 
                onValueChange={(val) => setCuentaPadreId(val === "none" ? null : parseInt(val))}
              >
                <SelectTrigger className="h-9 font-mono text-xs"><SelectValue placeholder="-- Raíz (Sin Padre) --" /></SelectTrigger>
                <SelectContent className="max-h-56">
                  <SelectItem value="none" className="font-mono text-xs">-- Raíz (Sin Padre) --</SelectItem>
                  {cuentasPadre.map(padre => {
                    const nombreCorto = padre.nombre.length > 45 ? `${padre.nombre.substring(0, 45)}...` : padre.nombre;
                    return (
                      <SelectItem key={padre.id} value={padre.id.toString()} className="font-mono text-xs">
                        {padre.codigo} - {nombreCorto}
                      </SelectItem>
                    );
                  })}
                </SelectContent>
              </Select>
            </div>
            
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold text-foreground">Estado Operativo</Label>
              <Select value={estado} onValueChange={(val) => setEstado(val as 'activa' | 'inactiva')}>
                <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="activa" className="text-xs">Activa</SelectItem>
                  <SelectItem value="inactiva" className="text-xs">Inactiva (Bloqueada)</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <DialogFooter className="border-t border-border/60 pt-4 mt-6">
            <Button variant="outline" type="button" size="sm" onClick={() => onOpenChange(false)} className="h-9 text-xs">
              Cancelar
            </Button>
            <Button type="submit" size="sm" disabled={mutation.isPending || codigoValido === false} className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold h-9 text-xs px-4">
              {mutation.isPending ? <Loader2 className="size-3.5 animate-spin mr-1.5" /> : null}
              {isEditing ? 'Guardar Cambios' : 'Registrar Cuenta'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
