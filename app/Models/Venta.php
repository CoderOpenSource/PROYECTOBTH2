<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'fecha',
        'total',
        'cliente_id', // Cambiado a cliente_id para reflejar la nueva relación
    ];

    /**
     * Relación con DetalleVenta
     * Una venta tiene muchos detalles de venta.
     */
    public function detalles()
    {
        return $this->hasMany(DetalleVenta::class);
    }

    /**
     * Relación con Pago
     * Una venta tiene un único pago.
     */
    public function pago()
    {
        return $this->hasOne(Pago::class);
    }

    /**
     * Relación con Cliente (Usuario)
     * Una venta pertenece a un cliente.
     */
    public function cliente()
    {
        return $this->belongsTo(Usuario::class, 'cliente_id');
    }

    /**
     * Relación con Factura
     * Una venta tiene una única factura.
     */
    public function factura()
    {
        return $this->hasOne(Factura::class);
    }
}
