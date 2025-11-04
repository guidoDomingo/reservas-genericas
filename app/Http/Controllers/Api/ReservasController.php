<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReservasController extends Controller
{
    // Método store para crear una nueva reserva
    public function store(Request $request)
    {
        try {
            // 1. Validar los datos recibidos
            $validator = Validator::make($request->all(), [
                'usuario_id' => 'required|exists:users,id',
                'servicio_id' => 'required|exists:servicios,id',
                'agenda_id' => 'required|exists:agenda,id',
                'fecha_reserva' => 'required|date|after_or_equal:today',
                'hora_inicio' => 'required',
                'hora_fin' => 'required|after:hora_inicio',
                'estado' => 'sometimes|in:pendiente,confirmada,cancelada,completada',
                'notas' => 'nullable|string',
                'precio_total' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 2. Verificar que la agenda esté activa
            $agenda = \DB::table('agenda')->where('id', $request->agenda_id)->where('activo', true)->first();
            if (!$agenda) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La agenda no está disponible'
                ], 404);
            }

            // 3. Verificar que la fecha esté dentro del rango de la agenda
            if ($request->fecha_reserva < $agenda->fecha_inicio || $request->fecha_reserva > $agenda->fecha_fin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La fecha de reserva está fuera del rango de la agenda'
                ], 400);
            }

            // 4. Verificar que sea un día activo
            $diasActivos = json_decode($agenda->dias_activos);
            $diaSemana = date('N', strtotime($request->fecha_reserva)); // 1=Lunes, 7=Domingo
            if (!in_array($diaSemana, $diasActivos)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El día seleccionado no está disponible en la agenda'
                ], 400);
            }

            // 5. Verificar que el horario esté dentro del horario de trabajo
            $horaInicio = \Carbon\Carbon::createFromFormat('H:i:s', $request->hora_inicio);
            $horaFin = \Carbon\Carbon::createFromFormat('H:i:s', $request->hora_fin);
            $trabajoInicio = \Carbon\Carbon::createFromFormat('H:i:s', $agenda->hora_inicio_trabajo);
            $trabajoFin = \Carbon\Carbon::createFromFormat('H:i:s', $agenda->hora_fin_trabajo);

            if ($horaInicio->lt($trabajoInicio) || $horaFin->gt($trabajoFin)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El horario está fuera del horario de trabajo'
                ], 400);
            }

            // 6. Verificar que no se solape con el horario de descanso
            if ($agenda->descanso_inicio && $agenda->descanso_fin) {
                $descansoInicio = \Carbon\Carbon::createFromFormat('H:i:s', $agenda->descanso_inicio);
                $descansoFin = \Carbon\Carbon::createFromFormat('H:i:s', $agenda->descanso_fin);
                
                if (($horaInicio->gte($descansoInicio) && $horaInicio->lt($descansoFin)) ||
                    ($horaFin->gt($descansoInicio) && $horaFin->lte($descansoFin)) ||
                    ($horaInicio->lt($descansoInicio) && $horaFin->gt($descansoFin))) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El horario se solapa con el período de descanso'
                    ], 400);
                }
            }

            // 7. Verificar que no exista conflicto con otras reservas
            $conflicto = \DB::table('reservas')
                ->where('agenda_id', $request->agenda_id)
                ->where('fecha_reserva', $request->fecha_reserva)
                ->where('estado', '!=', 'cancelada')
                ->where(function($query) use ($request) {
                    $query->whereBetween('hora_inicio', [$request->hora_inicio, $request->hora_fin])
                            ->orWhereBetween('hora_fin', [$request->hora_inicio, $request->hora_fin])
                            ->orWhere(function($q) use ($request) {
                                $q->where('hora_inicio', '<=', $request->hora_inicio)
                                ->where('hora_fin', '>=', $request->hora_fin);
                            });
                })
                ->exists();

            if ($conflicto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya existe una reserva en este horario'
                ], 409);
            }

            // 8. Crear la reserva
            $reservaData = [
                'usuario_id' => $request->usuario_id,
                'servicio_id' => $request->servicio_id,
                'agenda_id' => $request->agenda_id,
                'fecha_reserva' => $request->fecha_reserva,
                'hora_inicio' => $request->hora_inicio,
                'hora_fin' => $request->hora_fin,
                'estado' => $request->estado ?? 'pendiente',
                'notas' => $request->notas,
                'precio_total' => $request->precio_total,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $reservaId = \DB::table('reservas')->insertGetId($reservaData);

            // 9. Obtener la reserva completa con datos relacionados
            $reserva = $this->getReservaCompleta($reservaId);

            return response()->json([
                'status' => 'success',
                'message' => 'Reserva creada exitosamente',
                'data' => $reserva
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método index para listar todas las reservas
    public function index(Request $request)
    {
        try {
            $query = \DB::table('reservas')
                ->select([
                    'reservas.*',
                    'users.name as usuario_nombre',
                    'users.email as usuario_email',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                    'agenda.fecha_inicio as agenda_fecha_inicio',
                    'agenda.fecha_fin as agenda_fecha_fin',
                    'negocios.nombre as negocio_nombre'
                ])
                ->join('users', 'reservas.usuario_id', '=', 'users.id')
                ->join('servicios', 'reservas.servicio_id', '=', 'servicios.id')
                ->join('agenda', 'reservas.agenda_id', '=', 'agenda.id')
                ->join('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->orderBy('reservas.fecha_reserva', 'desc')
                ->orderBy('reservas.hora_inicio', 'desc');

            // Filtros opcionales
            if ($request->has('usuario_id')) {
                $query->where('reservas.usuario_id', $request->usuario_id);
            }

            if ($request->has('servicio_id')) {
                $query->where('reservas.servicio_id', $request->servicio_id);
            }

            if ($request->has('agenda_id')) {
                $query->where('reservas.agenda_id', $request->agenda_id);
            }

            if ($request->has('estado')) {
                $query->where('reservas.estado', $request->estado);
            }

            if ($request->has('fecha_desde')) {
                $query->where('reservas.fecha_reserva', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta')) {
                $query->where('reservas.fecha_reserva', '<=', $request->fecha_hasta);
            }

            $reservas = $query->get();

            return response()->json([
                'status' => 'success',
                'data' => $reservas,
                'total' => $reservas->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener las reservas: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método show para mostrar una reserva específica
    public function show($id)
    {
        try {
            $reserva = $this->getReservaCompleta($id);

            if (!$reserva) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reserva no encontrada'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $reserva
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método update para actualizar una reserva
    public function update(Request $request, $id)
    {
        try {
            // Verificar que la reserva existe
            $reservaExistente = \DB::table('reservas')->where('id', $id)->first();
            if (!$reservaExistente) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reserva no encontrada'
                ], 404);
            }

            // Validar los datos recibidos
            $validator = Validator::make($request->all(), [
                'usuario_id' => 'sometimes|exists:users,id',
                'servicio_id' => 'sometimes|exists:servicios,id',
                'agenda_id' => 'sometimes|exists:agenda,id',
                'fecha_reserva' => 'sometimes|date',
                'hora_inicio' => 'sometimes',
                'hora_fin' => 'sometimes',
                'estado' => 'sometimes|in:pendiente,confirmada,cancelada,completada',
                'notas' => 'nullable|string',
                'precio_total' => 'sometimes|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar conflictos si se actualizan datos críticos
            if ($request->has('fecha_reserva') || $request->has('hora_inicio') || $request->has('hora_fin') || $request->has('agenda_id')) {
                $agendaId = $request->agenda_id ?? $reservaExistente->agenda_id;
                $fechaReserva = $request->fecha_reserva ?? $reservaExistente->fecha_reserva;
                $horaInicio = $request->hora_inicio ?? $reservaExistente->hora_inicio;
                $horaFin = $request->hora_fin ?? $reservaExistente->hora_fin;

                $conflicto = \DB::table('reservas')
                    ->where('agenda_id', $agendaId)
                    ->where('fecha_reserva', $fechaReserva)
                    ->where('estado', '!=', 'cancelada')
                    ->where('id', '!=', $id)
                    ->where(function($query) use ($horaInicio, $horaFin) {
                        $query->whereBetween('hora_inicio', [$horaInicio, $horaFin])
                                ->orWhereBetween('hora_fin', [$horaInicio, $horaFin])
                                ->orWhere(function($q) use ($horaInicio, $horaFin) {
                                    $q->where('hora_inicio', '<=', $horaInicio)
                                    ->where('hora_fin', '>=', $horaFin);
                                });
                    })
                    ->exists();

                if ($conflicto) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ya existe una reserva en este horario'
                    ], 409);
                }
            }

            // Preparar datos para actualizar
            $updateData = [];
            
            if ($request->has('usuario_id')) $updateData['usuario_id'] = $request->usuario_id;
            if ($request->has('servicio_id')) $updateData['servicio_id'] = $request->servicio_id;
            if ($request->has('agenda_id')) $updateData['agenda_id'] = $request->agenda_id;
            if ($request->has('fecha_reserva')) $updateData['fecha_reserva'] = $request->fecha_reserva;
            if ($request->has('hora_inicio')) $updateData['hora_inicio'] = $request->hora_inicio;
            if ($request->has('hora_fin')) $updateData['hora_fin'] = $request->hora_fin;
            if ($request->has('estado')) $updateData['estado'] = $request->estado;
            if ($request->has('notas')) $updateData['notas'] = $request->notas;
            if ($request->has('precio_total')) $updateData['precio_total'] = $request->precio_total;

            $updateData['updated_at'] = now();

            // Actualizar la reserva
            \DB::table('reservas')->where('id', $id)->update($updateData);

            // Obtener la reserva actualizada
            $reserva = $this->getReservaCompleta($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Reserva actualizada exitosamente',
                'data' => $reserva
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método destroy para cancelar una reserva
    public function destroy($id)
    {
        try {
            $reserva = \DB::table('reservas')->where('id', $id)->first();
            
            if (!$reserva) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reserva no encontrada'
                ], 404);
            }

            // Cambiar estado a cancelada
            \DB::table('reservas')
                ->where('id', $id)
                ->update([
                    'estado' => 'cancelada',
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reserva cancelada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al cancelar la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para confirmar una reserva
    public function confirmar($id)
    {
        try {
            $reserva = \DB::table('reservas')->where('id', $id)->first();
            
            if (!$reserva) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reserva no encontrada'
                ], 404);
            }

            if ($reserva->estado === 'cancelada') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede confirmar una reserva cancelada'
                ], 400);
            }

            \DB::table('reservas')
                ->where('id', $id)
                ->update([
                    'estado' => 'confirmada',
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reserva confirmada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al confirmar la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para completar una reserva
    public function completar($id)
    {
        try {
            $reserva = \DB::table('reservas')->where('id', $id)->first();
            
            if (!$reserva) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reserva no encontrada'
                ], 404);
            }

            if ($reserva->estado === 'cancelada') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede completar una reserva cancelada'
                ], 400);
            }

            \DB::table('reservas')
                ->where('id', $id)
                ->update([
                    'estado' => 'completada',
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reserva completada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al completar la reserva: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para obtener reservas por usuario
    public function getByUsuario($usuarioId)
    {
        try {
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
                ->where('reservas.usuario_id', $usuarioId)
                ->orderBy('reservas.fecha_reserva', 'desc')
                ->orderBy('reservas.hora_inicio', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $reservas,
                'total' => $reservas->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener las reservas del usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para verificar disponibilidad de horarios
    public function verificarDisponibilidad(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'agenda_id' => 'required|exists:agenda,id',
                'fecha_reserva' => 'required|date',
                'hora_inicio' => 'required',
                'hora_fin' => 'required|after:hora_inicio',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $conflicto = \DB::table('reservas')
                ->where('agenda_id', $request->agenda_id)
                ->where('fecha_reserva', $request->fecha_reserva)
                ->where('estado', '!=', 'cancelada')
                ->where(function($query) use ($request) {
                    $query->whereBetween('hora_inicio', [$request->hora_inicio, $request->hora_fin])
                            ->orWhereBetween('hora_fin', [$request->hora_inicio, $request->hora_fin])
                            ->orWhere(function($q) use ($request) {
                                $q->where('hora_inicio', '<=', $request->hora_inicio)
                                ->where('hora_fin', '>=', $request->hora_fin);
                            });
                })
                ->exists();

            return response()->json([
                'status' => 'success',
                'disponible' => !$conflicto,
                'message' => $conflicto ? 'Horario no disponible' : 'Horario disponible'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al verificar disponibilidad: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método auxiliar para obtener reserva completa con datos relacionados
    private function getReservaCompleta($id)
    {
        return \DB::table('reservas')
            ->select([
                'reservas.*',
                'users.name as usuario_nombre',
                'users.email as usuario_email',
                'servicios.nombre as servicio_nombre',
                'servicios.descripcion as servicio_descripcion',
                'servicios.precio as servicio_precio',
                'servicios.duracion as servicio_duracion',
                'agenda.fecha_inicio as agenda_fecha_inicio',
                'agenda.fecha_fin as agenda_fecha_fin',
                'agenda.hora_inicio_trabajo',
                'agenda.hora_fin_trabajo',
                'negocios.nombre as negocio_nombre',
                'negocios.descripcion as negocio_descripcion'
            ])
            ->join('users', 'reservas.usuario_id', '=', 'users.id')
            ->join('servicios', 'reservas.servicio_id', '=', 'servicios.id')
            ->join('agenda', 'reservas.agenda_id', '=', 'agenda.id')
            ->join('negocios', 'servicios.negocio_id', '=', 'negocios.id')
            ->where('reservas.id', $id)
            ->first();
    }
}