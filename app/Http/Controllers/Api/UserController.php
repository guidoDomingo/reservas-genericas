<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Método store para crear un nuevo usuario
    public function store(Request $request)
    {
        try {
            // 1. Validar los datos recibidos
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'telefono' => 'nullable|string|max:20',
                'direccion' => 'nullable|string|max:500',
                'fecha_nacimiento' => 'nullable|date',
                'genero' => 'nullable|in:masculino,femenino,otro',
                'activo' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 2. Crear el usuario
            $userData = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
                'direccion' => $request->direccion,
                'fecha_nacimiento' => $request->fecha_nacimiento,
                'genero' => $request->genero,
                'activo' => $request->activo ?? true,
                'email_verified_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $userId = \DB::table('users')->insertGetId($userData);

            // 3. Obtener el usuario creado (sin password)
            $user = \DB::table('users')
                ->select('id', 'name', 'email', 'telefono', 'direccion', 'fecha_nacimiento', 'genero', 'activo', 'email_verified_at', 'created_at', 'updated_at')
                ->where('id', $userId)
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario creado exitosamente',
                'data' => $user
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método index para listar todos los usuarios
    public function index(Request $request)
    {
        try {
            $query = \DB::table('users')
                ->select('id', 'name', 'email', 'telefono', 'direccion', 'fecha_nacimiento', 'genero', 'activo', 'email_verified_at', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc');

            // Filtros opcionales
            if ($request->has('activo')) {
                $query->where('activo', $request->activo);
            }

            if ($request->has('genero')) {
                $query->where('genero', $request->genero);
            }

            if ($request->has('email_verificado')) {
                if ($request->email_verificado == '1') {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            }

            if ($request->has('buscar')) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('name', 'like', '%' . $buscar . '%')
                      ->orWhere('email', 'like', '%' . $buscar . '%');
                });
            }

            $usuarios = $query->get();

            return response()->json([
                'status' => 'success',
                'data' => $usuarios,
                'total' => $usuarios->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los usuarios: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método show para mostrar un usuario específico
    public function show($id)
    {
        try {
            $user = \DB::table('users')
                ->select('id', 'name', 'email', 'telefono', 'direccion', 'fecha_nacimiento', 'genero', 'activo', 'email_verified_at', 'created_at', 'updated_at')
                ->where('id', $id)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Obtener estadísticas del usuario
            $estadisticas = [
                'total_reservas' => \DB::table('reservas')->where('usuario_id', $id)->count(),
                'reservas_pendientes' => \DB::table('reservas')->where('usuario_id', $id)->where('estado', 'pendiente')->count(),
                'reservas_confirmadas' => \DB::table('reservas')->where('usuario_id', $id)->where('estado', 'confirmada')->count(),
                'reservas_completadas' => \DB::table('reservas')->where('usuario_id', $id)->where('estado', 'completada')->count(),
                'reservas_canceladas' => \DB::table('reservas')->where('usuario_id', $id)->where('estado', 'cancelada')->count(),
            ];

            $user->estadisticas = $estadisticas;

            return response()->json([
                'status' => 'success',
                'data' => $user
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método update para actualizar un usuario
    public function update(Request $request, $id)
    {
        try {
            // Verificar que el usuario existe
            $userExistente = \DB::table('users')->where('id', $id)->first();
            if (!$userExistente) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Validar los datos recibidos
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
                'password' => 'sometimes|string|min:8',
                'telefono' => 'nullable|string|max:20',
                'direccion' => 'nullable|string|max:500',
                'fecha_nacimiento' => 'nullable|date',
                'genero' => 'nullable|in:masculino,femenino,otro',
                'activo' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Preparar datos para actualizar
            $updateData = [];
            
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('password')) $updateData['password'] = Hash::make($request->password);
            if ($request->has('telefono')) $updateData['telefono'] = $request->telefono;
            if ($request->has('direccion')) $updateData['direccion'] = $request->direccion;
            if ($request->has('fecha_nacimiento')) $updateData['fecha_nacimiento'] = $request->fecha_nacimiento;
            if ($request->has('genero')) $updateData['genero'] = $request->genero;
            if ($request->has('activo')) $updateData['activo'] = $request->activo;

            $updateData['updated_at'] = now();

            // Actualizar el usuario
            \DB::table('users')->where('id', $id)->update($updateData);

            // Obtener el usuario actualizado (sin password)
            $user = \DB::table('users')
                ->select('id', 'name', 'email', 'telefono', 'direccion', 'fecha_nacimiento', 'genero', 'activo', 'email_verified_at', 'created_at', 'updated_at')
                ->where('id', $id)
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario actualizado exitosamente',
                'data' => $user
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método destroy para desactivar un usuario (soft delete)
    public function destroy($id)
    {
        try {
            $user = \DB::table('users')->where('id', $id)->first();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            // Verificar si tiene reservas activas
            $reservasActivas = \DB::table('reservas')
                ->where('usuario_id', $id)
                ->whereIn('estado', ['pendiente', 'confirmada'])
                ->count();

            if ($reservasActivas > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede desactivar el usuario porque tiene reservas pendientes o confirmadas'
                ], 400);
            }

            // Marcar como inactivo
            \DB::table('users')
                ->where('id', $id)
                ->update([
                    'activo' => false,
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario desactivado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al desactivar el usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para reactivar un usuario
    public function activate($id)
    {
        try {
            $user = \DB::table('users')->where('id', $id)->first();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            \DB::table('users')
                ->where('id', $id)
                ->update([
                    'activo' => true,
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario reactivado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al reactivar el usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para verificar email
    public function verificarEmail($id)
    {
        try {
            $user = \DB::table('users')->where('id', $id)->first();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            \DB::table('users')
                ->where('id', $id)
                ->update([
                    'email_verified_at' => now(),
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verificado exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al verificar el email: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para cambiar contraseña
    public function cambiarPassword(Request $request, $id)
    {
        try {
            $user = \DB::table('users')->where('id', $id)->first();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'password_actual' => 'required|string',
                'password_nuevo' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar contraseña actual
            if (!Hash::check($request->password_actual, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La contraseña actual no es correcta'
                ], 400);
            }

            // Actualizar contraseña
            \DB::table('users')
                ->where('id', $id)
                ->update([
                    'password' => Hash::make($request->password_nuevo),
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña actualizada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cambiar la contraseña: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para obtener historial de reservas del usuario
    public function historialReservas($id)
    {
        try {
            $user = \DB::table('users')->where('id', $id)->first();
            
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $reservas = \DB::table('reservas')
                ->select([
                    'reservas.*',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                    'negocios.nombre as negocio_nombre'
                ])
                ->join('servicios', 'reservas.servicio_id', '=', 'servicios.id')
                ->join('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('reservas.usuario_id', $id)
                ->orderBy('reservas.fecha_reserva', 'desc')
                ->orderBy('reservas.hora_inicio', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'usuario' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'reservas' => $reservas
                ],
                'total_reservas' => $reservas->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el historial de reservas: ' . $e->getMessage()
            ], 500);
        }
    }
}