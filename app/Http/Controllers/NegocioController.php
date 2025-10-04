<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class NegocioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('negocios')
                ->select([
                    'negocios.id',
                    'negocios.nombre',
                    'negocios.direccion',
                    'negocios.telefono',
                    'negocios.email',
                    'negocios.descripcion',
                    'negocios.activo',
                    'negocios.created_at',
                    'negocios.updated_at'
                ]);

            // Filtros opcionales
            if ($request->has('activo')) {
                $query->where('negocios.activo', $request->boolean('activo'));
            } else {
                $query->where('negocios.activo', true);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('negocios.nombre', 'like', "%{$search}%")
                      ->orWhere('negocios.direccion', 'like', "%{$search}%")
                      ->orWhere('negocios.email', 'like', "%{$search}%");
                });
            }

            // Ordenamiento
            $sortBy = $request->get('sort_by', 'nombre');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy("negocios.{$sortBy}", $sortOrder);

            // Paginación
            $perPage = $request->get('per_page', 10);
            $negocios = $query->paginate($perPage);


            return response()->json([
                'success' => true,
                'data' => $negocios,
                'message' => 'Negocios obtenidos exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los negocios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:255',
                'direccion' => 'required|string',
                'telefono' => 'required|string|max:20',
                'email' => 'required|email|unique:negocios,email',
                'descripcion' => 'nullable|string',
                'activo' => 'boolean'
            ], [
                'nombre.required' => 'El nombre del negocio es obligatorio',
                'direccion.required' => 'La dirección es obligatoria',
                'telefono.required' => 'El teléfono es obligatorio',
                'email.required' => 'El email es obligatorio',
                'email.email' => 'El email debe tener un formato válido',
                'email.unique' => 'Ya existe un negocio con este email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $negocioData = [
                'nombre' => $request->nombre,
                'direccion' => $request->direccion,
                'telefono' => $request->telefono,
                'email' => $request->email,
                'descripcion' => $request->descripcion,
                'activo' => $request->get('activo', true),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ];

            $negocioId = DB::table('negocios')->insertGetId($negocioData);

            // Obtener el negocio creado
            $negocio = DB::table('negocios')->where('id', $negocioId)->first();

            return response()->json([
                'success' => true,
                'data' => $negocio,
                'message' => 'Negocio creado exitosamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $negocio = DB::table('negocios')
                ->where('id', $id)
                ->first();

            if (!$negocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Negocio no encontrado'
                ], 404);
            }

     

            $negocioData = (array) $negocio;

            return response()->json([
                'success' => true,
                'data' => $negocioData,
                'message' => 'Negocio obtenido exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            // Verificar que el negocio existe
            $existingNegocio = DB::table('negocios')->where('id', $id)->first();
            
            if (!$existingNegocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Negocio no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nombre' => 'sometimes|string|max:255',
                'direccion' => 'sometimes|string',
                'telefono' => 'sometimes|string|max:20',
                'email' => 'sometimes|email|unique:negocios,email,' . $id,
                'descripcion' => 'nullable|string',
                'activo' => 'sometimes|boolean'
            ], [
                'email.email' => 'El email debe tener un formato válido',
                'email.unique' => 'Ya existe un negocio con este email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = [];
            
            if ($request->has('nombre')) {
                $updateData['nombre'] = $request->nombre;
            }
            if ($request->has('direccion')) {
                $updateData['direccion'] = $request->direccion;
            }
            if ($request->has('telefono')) {
                $updateData['telefono'] = $request->telefono;
            }
            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }
            if ($request->has('descripcion')) {
                $updateData['descripcion'] = $request->descripcion;
            }
            if ($request->has('activo')) {
                $updateData['activo'] = $request->activo;
            }

            $updateData['updated_at'] = Carbon::now();

            DB::table('negocios')
                ->where('id', $id)
                ->update($updateData);

            // Obtener el negocio actualizado
            $negocio = DB::table('negocios')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'data' => $negocio,
                'message' => 'Negocio actualizado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            // Verificar que el negocio existe
            $negocio = DB::table('negocios')->where('id', $id)->first();
            
            if (!$negocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Negocio no encontrado'
                ], 404);
            }

            // En lugar de eliminar físicamente, desactivamos el negocio
            DB::table('negocios')
                ->where('id', $id)
                ->update([
                    'activo' => false,
                    'updated_at' => Carbon::now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Negocio desactivado correctamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivate a business
     */
    public function reactivate(string $id): JsonResponse
    {
        try {
            $negocio = DB::table('negocios')->where('id', $id)->first();
            
            if (!$negocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Negocio no encontrado'
                ], 404);
            }

            DB::table('negocios')
                ->where('id', $id)
                ->update([
                    'activo' => true,
                    'updated_at' => Carbon::now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Negocio reactivado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reactivar el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
