<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgendaController extends Controller
{
    

    // Metodo store donde vamos a guardar la agenda
    public function store(Request $request)
    {

        try{

            // 1 paso validar los datos recibidos

            $validator = Validator::make($request->all(), [
                'servicio_id' => 'required|exists:servicios,id',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
                'hora_inicio_trabajo' => 'required',
                'hora_fin_trabajo' => 'required',
                'intervalo_minutos' => 'required|integer|min:1',
                'dias_activos' => 'required|array',
                'descanso_inicio' => 'nullable',
                'descanso_fin' => 'nullable',
                'activo' => 'boolean',
                'auto_generar_horarios' => 'boolean',
                'notas' => 'nullable|string',
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 2 paso verificar el servicio existe
            $servicio = \DB::table('servicios')->where('id', $request->servicio_id)->first();
            if (!$servicio) {
                return response()->json([
                    'status' => 'error',    
                    'message' => 'El servicio no existe'
                ], 404);
            }

            // 3 paso verificar que no exista una agenda para el servicio en las fechas dadas
            // 9:00 a 9:30 -> ya esta registrado en la agenda
            // 9:30 a 10:00 -> no esta registrado en la agenda
            $conflicto = \DB::table('agenda')
            ->where('servicio_id', $request->servicio_id)
            ->where('activo', true)
            ->where(function($query) use ($request) {
                $query->whereBetween('fecha_inicio', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhereBetween('fecha_fin', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhere(function($q) use ($request) {
                            $q->where('fecha_inicio', '<=', $request->fecha_inicio)
                            ->where('fecha_fin', '>=', $request->fecha_fin);
                        });
            })
            ->exists();


            if ($conflicto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya existe una agenda activa para este servicio en las fechas dadas'
                ], 409);
            }


            // 4 paso crear la agenda


            $agendaData = [
                'servicio_id' => $request->servicio_id,
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'hora_inicio_trabajo' => $request->hora_inicio_trabajo,
                'hora_fin_trabajo' => $request->hora_fin_trabajo,
                'intervalo_minutos' => $request->intervalo_minutos,
                'dias_activos' => json_encode($request->dias_activos),
                'descanso_inicio' => $request->descanso_inicio,
                'descanso_fin' => $request->descanso_fin,
                'activo' => $request->activo,
                'auto_generar_horarios' => $request->auto_generar_horarios,
                'notas' => $request->notas ?? null,
            ];

            //return $agendaData;

            $agendaId = \DB::table('agenda')->insertGetId($agendaData);

            $agenda  = \DB::table('agenda')
                      ->select(
                        [
                            'agenda.*',
                            'servicios.nombre as servicio_nombre',
                            'servicios.descripcion as servicio_descripcion',
                            'servicios.precio as servicio_precio',
                        ]
                      )
                      ->join('servicios', 'agenda.servicio_id', '=', 'servicios.id')
                      ->where('agenda.id', $agendaId)
                      ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Agenda creada exitosamente',
                'data' => $agenda
            ], 201);


        }catch(\Exception $e){
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear la agenda: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método index para listar todas las agendas
    public function index(Request $request)
    {
        try {
            $query = \DB::table('agenda')
                ->select([
                    'agenda.*',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                    'negocios.nombre as negocio_nombre'
                ])
                ->join('servicios', 'agenda.servicio_id', '=', 'servicios.id')
                ->join('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->orderBy('agenda.created_at', 'desc');

            // Filtros opcionales
            if ($request->has('servicio_id')) {
                $query->where('agenda.servicio_id', $request->servicio_id);
            }

            if ($request->has('activo')) {
                $query->where('agenda.activo', $request->activo);
            }

            if ($request->has('fecha_inicio')) {
                $query->where('agenda.fecha_inicio', '>=', $request->fecha_inicio);
            }

            if ($request->has('fecha_fin')) {
                $query->where('agenda.fecha_fin', '<=', $request->fecha_fin);
            }

            $agendas = $query->get();

            // Mapeo de días a nombres
            $diasNombres = [
                1 => 'Lunes',
                2 => 'Martes', 
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
                7 => 'Domingo'
            ];

            // Procesar cada agenda
            $agendas = $agendas->map(function($agenda) use ($diasNombres, $request) {
                // Decodificar dias_activos JSON y convertir a nombres
                $diasActivosNumeros = json_decode($agenda->dias_activos);
                $agenda->dias_activos = collect($diasActivosNumeros)->map(function($dia) use ($diasNombres) {
                    return $diasNombres[$dia] ?? 'Día desconocido';
                })->toArray();

                // Usar fecha específica si se proporciona, sino usar hoy
                $fechaParaSlots = $request->has('fecha') ? $request->get('fecha') : date('Y-m-d');
                
                // Verificar que la fecha esté dentro del rango de la agenda
                if ($fechaParaSlots >= $agenda->fecha_inicio && $fechaParaSlots <= $agenda->fecha_fin) {
                    $diaSemana = date('N', strtotime($fechaParaSlots));
                    
                    // Solo generar slots si es un día activo
                    if (in_array($diaSemana, $diasActivosNumeros)) {
                        $agenda->slots_horarios = $this->generarSlotsHorarios(
                            $agenda->hora_inicio_trabajo,
                            $agenda->hora_fin_trabajo,
                            $agenda->intervalo_minutos,
                            $agenda->descanso_inicio,
                            $agenda->descanso_fin,
                            $agenda->id,
                            $fechaParaSlots
                        );
                    } else {
                        $agenda->slots_horarios = [];
                        $agenda->mensaje_slots = 'Día no activo para esta agenda';
                    }
                    $agenda->fecha_slots = $fechaParaSlots;
                } else {
                    $agenda->slots_horarios = [];
                    $agenda->fecha_slots = $fechaParaSlots;
                    $agenda->mensaje_slots = 'Fecha fuera del rango de la agenda';
                }

                return $agenda;
            });

            return response()->json([
                'status' => 'success',
                'data' => $agendas,
                'total' => $agendas->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener las agendas: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método auxiliar para generar slots de horarios
    private function generarSlotsHorarios($horaInicio, $horaFin, $intervalo, $descansoInicio = null, $descansoFin = null, $agendaId = null, $fecha = null)
    {
        $slots = [];
        
        try {
            $inicio = \Carbon\Carbon::createFromFormat('H:i:s', $horaInicio);
            $fin = \Carbon\Carbon::createFromFormat('H:i:s', $horaFin);
            
            $descansoInicioCarbon = $descansoInicio ? \Carbon\Carbon::createFromFormat('H:i:s', $descansoInicio) : null;
            $descansoFinCarbon = $descansoFin ? \Carbon\Carbon::createFromFormat('H:i:s', $descansoFin) : null;
            
            $actual = $inicio->copy();
            
            while ($actual->lt($fin)) {
                $siguienteSlot = $actual->copy()->addMinutes($intervalo);
                
                // Verificar si el slot actual está en período de descanso
                $iniciaEnDescanso = false;
                if ($descansoInicioCarbon && $descansoFinCarbon) {
                    $iniciaEnDescanso = $actual->gte($descansoInicioCarbon) && $actual->lt($descansoFinCarbon);
                }
                
                // Si el slot actual está en descanso, saltar al final del descanso
                if ($iniciaEnDescanso) {
                    $actual = $descansoFinCarbon->copy();
                    continue;
                }
                
                // Verificar si el slot se extiende más allá del horario de trabajo
                if ($siguienteSlot->gt($fin)) {
                    break;
                }
                
                // Verificar si el slot se solapa con el período de descanso
                $seSolapaConDescanso = false;
                if ($descansoInicioCarbon && $descansoFinCarbon) {
                    $seSolapaConDescanso = $siguienteSlot->gt($descansoInicioCarbon) && $actual->lt($descansoInicioCarbon);
                }
                
                // Si se solapa con descanso, ajustar el final del slot al inicio del descanso
                if ($seSolapaConDescanso) {
                    $siguienteSlot = $descansoInicioCarbon->copy();
                }
                
                // Agregar el slot si es válido
                if ($actual->lt($siguienteSlot)) {
                    $disponible = true;
                    
                    // Verificar disponibilidad si se proporciona agendaId y fecha
                    if ($agendaId && $fecha) {
                        $disponible = $this->verificarDisponibilidadSlot(
                            $agendaId, 
                            $fecha, 
                            $actual->format('H:i:s'), 
                            $siguienteSlot->format('H:i:s')
                        );
                    }
                    
                    $slots[] = [
                        'hora_inicio' => $actual->format('H:i'),
                        'hora_fin' => $siguienteSlot->format('H:i'),
                        'disponible' => $disponible
                    ];
                }
                
                // Si terminamos justo antes del descanso, saltar al final del descanso
                if ($seSolapaConDescanso && $descansoFinCarbon) {
                    $actual = $descansoFinCarbon->copy();
                } else {
                    $actual = $siguienteSlot->copy();
                }
            }
            
        } catch (\Exception $e) {
            // En caso de error, retornar array vacío
            return [];
        }
        
        return $slots;
    }

    // Método auxiliar para verificar disponibilidad de un slot específico
    private function verificarDisponibilidadSlot($agendaId, $fecha, $horaInicio, $horaFin)
    {
        $conflicto = \DB::table('reservas')
            ->where('agenda_id', $agendaId)
            ->where('fecha_reserva', $fecha)
            ->where('estado', '!=', 'cancelada')
            ->where(function($query) use ($horaInicio, $horaFin) {
                $query->where(function($q) use ($horaInicio, $horaFin) {
                    // Verificar si hay solapamiento
                    $q->where('hora_inicio', '<', $horaFin)
                      ->where('hora_fin', '>', $horaInicio);
                });
            })
            ->exists();

        return !$conflicto;
    }

    // Método show para mostrar una agenda específica
    public function show($id, Request $request)
    {
        try {
            $agenda = \DB::table('agenda')
                ->select([
                    'agenda.*',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                    'servicios.duracion as servicio_duracion',
                    'negocios.nombre as negocio_nombre',
                    'negocios.descripcion as negocio_descripcion'
                ])
                ->join('servicios', 'agenda.servicio_id', '=', 'servicios.id')
                ->join('negocios', 'servicios.negocio_id', '=', 'negocios.id')
                ->where('agenda.id', $id)
                ->first();

            if (!$agenda) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Agenda no encontrada'
                ], 404);
            }

            // Mapeo de días a nombres
            $diasNombres = [
                1 => 'Lunes',
                2 => 'Martes', 
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
                7 => 'Domingo'
            ];

            // Decodificar dias_activos JSON y convertir a nombres
            $diasActivosNumeros = json_decode($agenda->dias_activos);
            $agenda->dias_activos = collect($diasActivosNumeros)->map(function($dia) use ($diasNombres) {
                return $diasNombres[$dia] ?? 'Día desconocido';
            })->toArray();

            // Usar fecha específica si se proporciona, sino usar hoy
            $fechaParaSlots = $request->has('fecha') ? $request->get('fecha') : date('Y-m-d');
            
            // Verificar que la fecha esté dentro del rango de la agenda
            if ($fechaParaSlots >= $agenda->fecha_inicio && $fechaParaSlots <= $agenda->fecha_fin) {
                $diaSemana = date('N', strtotime($fechaParaSlots));
                
                // Solo generar slots si es un día activo
                if (in_array($diaSemana, $diasActivosNumeros)) {
                    $agenda->slots_horarios = $this->generarSlotsHorarios(
                        $agenda->hora_inicio_trabajo,
                        $agenda->hora_fin_trabajo,
                        $agenda->intervalo_minutos,
                        $agenda->descanso_inicio,
                        $agenda->descanso_fin,
                        $agenda->id,
                        $fechaParaSlots
                    );
                } else {
                    $agenda->slots_horarios = [];
                    $agenda->mensaje_slots = 'Día no activo para esta agenda';
                }
                $agenda->fecha_slots = $fechaParaSlots;
            } else {
                $agenda->slots_horarios = [];
                $agenda->fecha_slots = $fechaParaSlots;
                $agenda->mensaje_slots = 'Fecha fuera del rango de la agenda';
            }

            return response()->json([
                'status' => 'success',
                'data' => $agenda
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener la agenda: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método update para actualizar una agenda
    public function update(Request $request, $id)
    {
        try {
            // Verificar que la agenda existe
            $agendaExistente = \DB::table('agenda')->where('id', $id)->first();
            if (!$agendaExistente) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Agenda no encontrada'
                ], 404);
            }

            // Validar los datos recibidos
            $validator = Validator::make($request->all(), [
                'servicio_id' => 'sometimes|exists:servicios,id',
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin' => 'sometimes|date|after_or_equal:fecha_inicio',
                'hora_inicio_trabajo' => 'sometimes',
                'hora_fin_trabajo' => 'sometimes',
                'intervalo_minutos' => 'sometimes|integer|min:1',
                'dias_activos' => 'sometimes|array',
                'descanso_inicio' => 'nullable',
                'descanso_fin' => 'nullable',
                'activo' => 'sometimes|boolean',
                'auto_generar_horarios' => 'sometimes|boolean',
                'notas' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar conflictos si se actualizan las fechas o servicio
            if ($request->has('fecha_inicio') || $request->has('fecha_fin') || $request->has('servicio_id')) {
                $servicioId = $request->servicio_id ?? $agendaExistente->servicio_id;
                $fechaInicio = $request->fecha_inicio ?? $agendaExistente->fecha_inicio;
                $fechaFin = $request->fecha_fin ?? $agendaExistente->fecha_fin;

                $conflicto = \DB::table('agenda')
                    ->where('servicio_id', $servicioId)
                    ->where('activo', true)
                    ->where('id', '!=', $id)
                    ->where(function($query) use ($fechaInicio, $fechaFin) {
                        $query->whereBetween('fecha_inicio', [$fechaInicio, $fechaFin])
                                ->orWhereBetween('fecha_fin', [$fechaInicio, $fechaFin])
                                ->orWhere(function($q) use ($fechaInicio, $fechaFin) {
                                    $q->where('fecha_inicio', '<=', $fechaInicio)
                                    ->where('fecha_fin', '>=', $fechaFin);
                                });
                    })
                    ->exists();

                if ($conflicto) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ya existe una agenda activa para este servicio en las fechas dadas'
                    ], 409);
                }
            }

            // Preparar datos para actualizar
            $updateData = [];
            
            if ($request->has('servicio_id')) $updateData['servicio_id'] = $request->servicio_id;
            if ($request->has('fecha_inicio')) $updateData['fecha_inicio'] = $request->fecha_inicio;
            if ($request->has('fecha_fin')) $updateData['fecha_fin'] = $request->fecha_fin;
            if ($request->has('hora_inicio_trabajo')) $updateData['hora_inicio_trabajo'] = $request->hora_inicio_trabajo;
            if ($request->has('hora_fin_trabajo')) $updateData['hora_fin_trabajo'] = $request->hora_fin_trabajo;
            if ($request->has('intervalo_minutos')) $updateData['intervalo_minutos'] = $request->intervalo_minutos;
            if ($request->has('dias_activos')) $updateData['dias_activos'] = json_encode($request->dias_activos);
            if ($request->has('descanso_inicio')) $updateData['descanso_inicio'] = $request->descanso_inicio;
            if ($request->has('descanso_fin')) $updateData['descanso_fin'] = $request->descanso_fin;
            if ($request->has('activo')) $updateData['activo'] = $request->activo;
            if ($request->has('auto_generar_horarios')) $updateData['auto_generar_horarios'] = $request->auto_generar_horarios;
            if ($request->has('notas')) $updateData['notas'] = $request->notas;

            $updateData['updated_at'] = now();

            // Actualizar la agenda
            \DB::table('agenda')->where('id', $id)->update($updateData);

            // Obtener la agenda actualizada
            $agenda = \DB::table('agenda')
                ->select([
                    'agenda.*',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                ])
                ->join('servicios', 'agenda.servicio_id', '=', 'servicios.id')
                ->where('agenda.id', $id)
                ->first();

            $agenda->dias_activos = json_decode($agenda->dias_activos);

            return response()->json([
                'status' => 'success',
                'message' => 'Agenda actualizada exitosamente',
                'data' => $agenda
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar la agenda: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método destroy para eliminar una agenda (soft delete)
    public function destroy($id)
    {
        try {
            $agenda = \DB::table('agenda')->where('id', $id)->first();
            
            if (!$agenda) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Agenda no encontrada'
                ], 404);
            }

            // Marcar como inactiva en lugar de eliminar físicamente
            \DB::table('agenda')
                ->where('id', $id)
                ->update([
                    'activo' => false,
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Agenda desactivada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar la agenda: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para reactivar una agenda
    public function activate($id)
    {
        try {
            $agenda = \DB::table('agenda')->where('id', $id)->first();
            
            if (!$agenda) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Agenda no encontrada'
                ], 404);
            }

            // Verificar conflictos antes de reactivar
            $conflicto = \DB::table('agenda')
                ->where('servicio_id', $agenda->servicio_id)
                ->where('activo', true)
                ->where('id', '!=', $id)
                ->where(function($query) use ($agenda) {
                    $query->whereBetween('fecha_inicio', [$agenda->fecha_inicio, $agenda->fecha_fin])
                            ->orWhereBetween('fecha_fin', [$agenda->fecha_inicio, $agenda->fecha_fin])
                            ->orWhere(function($q) use ($agenda) {
                                $q->where('fecha_inicio', '<=', $agenda->fecha_inicio)
                                ->where('fecha_fin', '>=', $agenda->fecha_fin);
                            });
                })
                ->exists();

            if ($conflicto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede reactivar: Ya existe una agenda activa para este servicio en las fechas dadas'
                ], 409);
            }

            \DB::table('agenda')
                ->where('id', $id)
                ->update([
                    'activo' => true,
                    'updated_at' => now()
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Agenda reactivada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al reactivar la agenda: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para obtener agendas por servicio
    public function getByServicio($servicioId)
    {
        try {
            $agendas = \DB::table('agenda')
                ->select([
                    'agenda.*',
                    'servicios.nombre as servicio_nombre',
                    'servicios.descripcion as servicio_descripcion',
                    'servicios.precio as servicio_precio',
                ])
                ->join('servicios', 'agenda.servicio_id', '=', 'servicios.id')
                ->where('agenda.servicio_id', $servicioId)
                ->where('agenda.activo', true)
                ->orderBy('agenda.fecha_inicio', 'asc')
                ->get();

            // Mapeo de días a nombres
            $diasNombres = [
                1 => 'Lunes',
                2 => 'Martes', 
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
                7 => 'Domingo'
            ];

            // Decodificar dias_activos JSON y convertir a nombres, generar slots
            $agendas = $agendas->map(function($agenda) use ($diasNombres) {
                // Convertir días a nombres
                $diasActivosNumeros = json_decode($agenda->dias_activos);
                $agenda->dias_activos = collect($diasActivosNumeros)->map(function($dia) use ($diasNombres) {
                    return $diasNombres[$dia] ?? 'Día desconocido';
                })->toArray();

                // Usar fecha específica si se proporciona, sino usar hoy
                $fechaParaSlots = request()->has('fecha') ? request()->get('fecha') : date('Y-m-d');
                
                // Verificar que la fecha esté dentro del rango de la agenda
                if ($fechaParaSlots >= $agenda->fecha_inicio && $fechaParaSlots <= $agenda->fecha_fin) {
                    $diaSemana = date('N', strtotime($fechaParaSlots));
                    
                    // Solo generar slots si es un día activo
                    if (in_array($diaSemana, $diasActivosNumeros)) {
                        $agenda->slots_horarios = $this->generarSlotsHorarios(
                            $agenda->hora_inicio_trabajo,
                            $agenda->hora_fin_trabajo,
                            $agenda->intervalo_minutos,
                            $agenda->descanso_inicio,
                            $agenda->descanso_fin,
                            $agenda->id,
                            $fechaParaSlots
                        );
                    } else {
                        $agenda->slots_horarios = [];
                        $agenda->mensaje_slots = 'Día no activo para esta agenda';
                    }
                    $agenda->fecha_slots = $fechaParaSlots;
                } else {
                    $agenda->slots_horarios = [];
                    $agenda->fecha_slots = $fechaParaSlots;
                    $agenda->mensaje_slots = 'Fecha fuera del rango de la agenda';
                }

                return $agenda;
            });

            return response()->json([
                'status' => 'success',
                'data' => $agendas,
                'total' => $agendas->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener las agendas del servicio: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para verificar disponibilidad en fechas específicas
    public function checkDisponibilidad(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'servicio_id' => 'required|exists:servicios,id',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $conflicto = \DB::table('agenda')
                ->where('servicio_id', $request->servicio_id)
                ->where('activo', true)
                ->where(function($query) use ($request) {
                    $query->whereBetween('fecha_inicio', [$request->fecha_inicio, $request->fecha_fin])
                            ->orWhereBetween('fecha_fin', [$request->fecha_inicio, $request->fecha_fin])
                            ->orWhere(function($q) use ($request) {
                                $q->where('fecha_inicio', '<=', $request->fecha_inicio)
                                ->where('fecha_fin', '>=', $request->fecha_fin);
                            });
                })
                ->exists();

            return response()->json([
                'status' => 'success',
                'disponible' => !$conflicto,
                'message' => $conflicto ? 'No disponible - Ya existe una agenda activa en estas fechas' : 'Disponible'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al verificar disponibilidad: ' . $e->getMessage()
            ], 500);
        }
    }
}
