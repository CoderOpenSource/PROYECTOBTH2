<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class VentaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Obtener todas las ventas con sus detalles, pagos y clientes
        $ventas = Venta::with(['detalles.producto', 'pago', 'cliente'])->get();
        $productos = Producto::all(); // Obtener productos disponibles para el modal
        $clientes = Usuario::where('rol', 'cliente')->get(); // Obtener todos los clientes

        // Retornar la vista y pasarle las ventas, productos y clientes
        return view('ventas.index', compact('ventas', 'productos', 'clientes'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (session('rol') !== 'administrador' && session('rol') !== 'empleado') {
            return redirect('/login')->withErrors(['No tienes acceso a esta área.']);
        }

        $request->validate([
            'cliente_id' => 'required|exists:usuarios,id',
            'total' => 'required|numeric',
            'productos' => 'required|array',
            'productos.*.producto_id' => 'required|exists:productos,id',
            'productos.*.peso_vendido' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string',
            'imagen_pago' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        ]);

        DB::transaction(function () use ($request) {
            $venta = Venta::create([
                'total' => 0, // Inicialmente en 0, lo actualizaremos después
                'cliente_id' => $request->cliente_id,
                'fecha' => now(),
            ]);

            $totalVenta = 0;

            foreach ($request->productos as $productoData) {
                $productoInfo = Producto::find($productoData['producto_id']);
                $pesoVendido = $productoData['peso_vendido'];

                if ($productoInfo->peso_disponible < $pesoVendido) {
                    throw new \Exception("Stock insuficiente para el producto {$productoInfo->nombre}");
                }

                $precioVenta = $productoInfo->precio_por_unidad * $pesoVendido;
                $ganancia = ($productoInfo->precio_por_unidad - $productoInfo->precio_compra) * $pesoVendido;

                $productoInfo->peso_disponible -= $pesoVendido;
                $productoInfo->save();

                DetalleVenta::create([
                    'venta_id' => $venta->id,
                    'producto_id' => $productoInfo->id,
                    'peso_vendido' => $pesoVendido,
                    'precio_venta' => $precioVenta,
                    'ganancia' => $ganancia,
                ]);

                $totalVenta += $precioVenta;
            }

            $venta->update(['total' => $totalVenta]);

            $imagenPagoUrl = null;
            if ($request->metodo_pago === 'QR' && $request->hasFile('imagen_pago')) {
                $uploadedFileUrl = Cloudinary::upload($request->file('imagen_pago')->getRealPath())->getSecurePath();
                $imagenPagoUrl = $uploadedFileUrl;
            }

            Pago::create([
                'venta_id' => $venta->id,
                'metodo_pago' => $request->metodo_pago,
                'imagen_pago' => $imagenPagoUrl,
                'monto' => $totalVenta,
            ]);
        });

        return redirect()->route('ventas.index')->with('success', 'Venta creada exitosamente.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Venta $venta)
    {
        $venta->load(['detalles', 'pago']);
        $productos = Producto::all();
        $clientes = Usuario::where('rol', 'cliente')->get();

        return view('ventas.edit', compact('venta', 'productos', 'clientes'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Venta $venta)
    {
        $request->validate([
            'cliente_id' => 'required|exists:usuarios,id',
            'total' => 'required|numeric',
            'productos' => 'required|array',
            'productos.*.producto_id' => 'required|exists:productos,id',
            'productos.*.peso_vendido' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string',
            'imagen_pago' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
        ]);

        DB::transaction(function () use ($request, $venta) {
            $venta->update(['total' => 0, 'cliente_id' => $request->cliente_id]);

            $nuevosProductosIds = collect($request->productos)->pluck('producto_id')->toArray();
            DetalleVenta::where('venta_id', $venta->id)
                ->whereNotIn('producto_id', $nuevosProductosIds)
                ->delete();

            $totalVenta = 0;

            foreach ($request->productos as $productoData) {
                $productoInfo = Producto::find($productoData['producto_id']);
                $pesoVendido = $productoData['peso_vendido'];

                $detalle = DetalleVenta::where('venta_id', $venta->id)
                    ->where('producto_id', $productoInfo->id)
                    ->first();

                if ($detalle) {
                    $productoInfo->peso_disponible += $detalle->peso_vendido;
                    $productoInfo->save();

                    $precioVenta = $productoInfo->precio_por_unidad * $pesoVendido;
                    $ganancia = ($productoInfo->precio_por_unidad - $productoInfo->precio_compra) * $pesoVendido;

                    $detalle->update([
                        'peso_vendido' => $pesoVendido,
                        'precio_venta' => $precioVenta,
                        'ganancia' => $ganancia,
                    ]);
                } else {
                    if ($productoInfo->peso_disponible < $pesoVendido) {
                        throw new \Exception("Stock insuficiente para el producto {$productoInfo->nombre}");
                    }

                    $precioVenta = $productoInfo->precio_por_unidad * $pesoVendido;
                    $ganancia = ($productoInfo->precio_por_unidad - $productoInfo->precio_compra) * $pesoVendido;

                    DetalleVenta::create([
                        'venta_id' => $venta->id,
                        'producto_id' => $productoInfo->id,
                        'peso_vendido' => $pesoVendido,
                        'precio_venta' => $precioVenta,
                        'ganancia' => $ganancia,
                    ]);
                }

                $productoInfo->peso_disponible -= $pesoVendido;
                $productoInfo->save();

                $totalVenta += $precioVenta;
            }

            $venta->update(['total' => $totalVenta]);

            $imagenPagoUrl = $venta->pago->imagen_pago;
            if ($request->metodo_pago === 'QR' && $request->hasFile('imagen_pago')) {
                $uploadedFileUrl = Cloudinary::upload($request->file('imagen_pago')->getRealPath())->getSecurePath();
                $imagenPagoUrl = $uploadedFileUrl;
            }

            $venta->pago->update([
                'metodo_pago' => $request->metodo_pago,
                'monto' => $totalVenta,
                'imagen_pago' => $imagenPagoUrl,
            ]);
        });

        return redirect()->route('ventas.index')->with('success', 'Venta actualizada exitosamente.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Venta $venta)
    {
        $venta->detalles()->delete();
        $venta->pago()->delete();
        $venta->delete();

        return redirect()->route('ventas.index')->with('success', 'Venta eliminada exitosamente.');
    }
}
