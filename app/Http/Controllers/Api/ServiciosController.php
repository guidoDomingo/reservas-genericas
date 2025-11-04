<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ServiciosController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('servicios')
                ->select([
                    'servicios.id',
                    'servicios.negocio_id',
                    'servicios.nombre',
                    'servicios.descripcion',
                    'servicios.duracion',
                    'servicios.precio',
                    'servicios.activo',
                    'servicios.created_at',
                    'servicios.updated_at',
                    'negocios.nombre as negocio_nombre'
                ])
                ->leftJoin('negocios', 'servicios.negocio_id', '=', 'negocios.id');

            // Filtros opcionales
            if ($request->has('negocio_id')) {
                $query->where('servicios.negocio_id', $request->negocio_id);
            }

            if ($request->has('activo')) {
                $query->where('servicios.activo', $request->boolean('activo'));
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('servicios.nombre', 'like', "%{$search}%")
                      ->orWhere('servicios.descripcion', 'like', "%{$search}%");
                });
            }

            // Ordenamiento
            $sortBy = $request->get('sort_by', 'nombre');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy("servicios.{$sortBy}", $sortOrder);

            // Paginación
            $perPage = $request->get('per_page', 15);
            $servicios = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $servicios,
                'message' => 'Servicios obtenidos exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los servicios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|integer|exists:negocios,id',
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
                'duracion' => 'required|integer|min:1',
                'precio' => 'required|numeric|min:0',
                'activo' => 'boolean'
            ], [
                'negocio_id.required' => 'El ID del negocio es obligatorio',
                'negocio_id.exists' => 'El negocio especificado no existe',
                'nombre.required' => 'El nombre del servicio es obligatorio',
                'duracion.required' => 'La duración del servicio es obligatoria',
                'duracion.min' => 'La duración debe ser mayor a 0 minutos',
                'precio.required' => 'El precio del servicio es obligatorio',
                'precio.min' => 'El precio no puede ser negativo'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no exista un servicio con el mismo nombre en el negocio
            $existingService = DB::table('servicios')
                ->where('negocio_id', $request->negocio_id)
                ->where('nombre', $request->nombre)
                ->first();

            if ($existingService) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un servicio con ese nombre en este negocio'
                ], 409);
            }

            $servicioData = [
                'negocio_id' => $request->negocio_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'duracion' => $request->duracion,
                'precio' => $request->precio,
                'activo' => $request->get('activo', true),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ];

            $servicioId = DB::table('servicios')->insertGetId($servicioData);

            // Obtener el servicio creado con información del negocio
            $servicio = DB::table('servicios')
                ->select([
                    'servicios.*',
                    'negocios.nombre as negocio_nombre'
                ])
                ->leftJoin('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('servicios.id', $servicioId)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $servicio,
                'message' => 'Servicio creado exitosamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el servicio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $servicio = DB::table('servicios')
                ->select([
                    'servicios.*',
                    'negocios.nombre as negocio_nombre',
                    'negocios.direccion as negocio_direccion'
                ])
                ->leftJoin('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('servicios.id', $id)
                ->first();

            if (!$servicio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }


            return response()->json([
                'success' => true,
                'data' => $servicio,
                'message' => 'Servicio obtenido exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el servicio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            // Verificar que el servicio existe
            $existingService = DB::table('servicios')->where('id', $id)->first();
            
            if (!$existingService) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'negocio_id' => 'sometimes|integer|exists:negocios,id',
                'nombre' => 'sometimes|string|max:255',
                'descripcion' => 'nullable|string',
                'duracion' => 'sometimes|integer|min:1',
                'precio' => 'sometimes|numeric|min:0',
                'activo' => 'sometimes|boolean'
            ], [
                'negocio_id.exists' => 'El negocio especificado no existe',
                'duracion.min' => 'La duración debe ser mayor a 0 minutos',
                'precio.min' => 'El precio no puede ser negativo'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar nombre único en el negocio si se está actualizando
            if ($request->has('nombre') || $request->has('negocio_id')) {
                $nombre = $request->get('nombre', $existingService->nombre);
                $negocioId = $request->get('negocio_id', $existingService->negocio_id);
                
                $duplicateService = DB::table('servicios')
                    ->where('negocio_id', $negocioId)
                    ->where('nombre', $nombre)
                    ->where('id', '!=', $id)
                    ->first();

                if ($duplicateService) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ya existe un servicio con ese nombre en este negocio'
                    ], 409);
                }
            }

            $updateData = [];
            
            if ($request->has('negocio_id')) {
                $updateData['negocio_id'] = $request->negocio_id;
            }
            if ($request->has('nombre')) {
                $updateData['nombre'] = $request->nombre;
            }
            if ($request->has('descripcion')) {
                $updateData['descripcion'] = $request->descripcion;
            }
            if ($request->has('duracion')) {
                $updateData['duracion'] = $request->duracion;
            }
            if ($request->has('precio')) {
                $updateData['precio'] = $request->precio;
            }
            if ($request->has('activo')) {
                $updateData['activo'] = $request->activo;
            }

            $updateData['updated_at'] = Carbon::now();

            DB::table('servicios')
                ->where('id', $id)
                ->update($updateData);

            // Obtener el servicio actualizado con información del negocio
            $servicio = DB::table('servicios')
                ->select([
                    'servicios.*',
                    'negocios.nombre as negocio_nombre'
                ])
                ->leftJoin('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('servicios.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $servicio,
                'message' => 'Servicio actualizado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el servicio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            // Verificar que el servicio existe
            $servicio = DB::table('servicios')->where('id', $id)->first();
            
            if (!$servicio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            // Verificar si hay reservas asociadas
            // $reservasCount = DB::table('reservas')
            //     ->where('servicio_id', $id)
            //     ->count();

            // if ($reservasCount > 0) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'No se puede eliminar el servicio porque tiene reservas asociadas',
            //         'reservas_count' => $reservasCount
            //     ], 409);
            // }

            // Eliminar agendas asociadas primero
            // DB::table('agenda')->where('servicio_id', $id)->delete();

            // Eliminar el servicio
            DB::table('servicios')->where('id', $id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Servicio eliminado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el servicio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get services by business
     */
    public function getByNegocio(string $negocioId)
    {
        try {
            $servicios = DB::table('servicios')
                ->select([
                    'servicios.*',
                    'negocios.nombre as negocio_nombre'
                ])
                ->leftJoin('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('servicios.negocio_id', $negocioId)
                ->where('servicios.activo', true)
                ->orderBy('servicios.nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $servicios,
                'message' => 'Servicios del negocio obtenidos exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los servicios del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle service status
     */
    public function toggleStatus(string $id)
    {
        try {
            $servicio = DB::table('servicios')->where('id', $id)->first();
            
            if (!$servicio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio no encontrado'
                ], 404);
            }

            $newStatus = !$servicio->activo;
            
            DB::table('servicios')
                ->where('id', $id)
                ->update([
                    'activo' => $newStatus,
                    'updated_at' => Carbon::now()
                ]);

            return response()->json([
                'success' => true,
                'data' => ['activo' => $newStatus],
                'message' => $newStatus ? 'Servicio activado exitosamente' : 'Servicio desactivado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado del servicio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
