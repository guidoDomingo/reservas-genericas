<?php

use App\Http\Controllers\Api\AgendaController;
use App\Http\Controllers\Api\NegocioController;
use App\Http\Controllers\Api\ReservasController;
use App\Http\Controllers\Api\ServiciosController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*    Metodos para Usuarios
*/
Route::post('/usuarios/store', [UserController::class, 'store']);
Route::get('/usuarios/index', [UserController::class, 'index']);
Route::get('/usuarios/show/{id}', [UserController::class, 'show']);
Route::post('/usuarios/update/{id}', [UserController::class, 'update']);
Route::get('/usuarios/delete/{id}', [UserController::class, 'destroy']);
Route::get('/usuarios/activate/{id}', [UserController::class, 'activate']);
Route::get('/usuarios/verificar-email/{id}', [UserController::class, 'verificarEmail']);
Route::post('/usuarios/cambiar-password/{id}', [UserController::class, 'cambiarPassword']);
Route::get('/usuarios/historial-reservas/{id}', [UserController::class, 'historialReservas']);

Route::post('/negocios/store', [NegocioController::class, 'store']);
Route::get('/negocios/index', [NegocioController::class, 'index']);
Route::get('/negocios/show/{id}', [NegocioController::class, 'show']);
Route::post('/negocios/updated/{id}', [NegocioController::class, 'update']);
Route::get('/negocios/delete/{id}', [NegocioController::class, 'destroy']);
Route::get('/negocios/activar/{id}', [NegocioController::class, 'reactivate']);


/*
    Metodos para Servicios
*/

Route::post('/servicios/store', [ServiciosController::class, 'store']);
Route::get('/servicios/show/{id}', [ServiciosController::class, 'show']);



/*    Metodos para Agenda
*/
Route::post('/agenda/store', [AgendaController::class, 'store']);
Route::get('/agenda/index', [AgendaController::class, 'index']);
Route::get('/agenda/show/{id}', [AgendaController::class, 'show']);
Route::post('/agenda/update/{id}', [AgendaController::class, 'update']);
Route::get('/agenda/delete/{id}', [AgendaController::class, 'destroy']);
Route::get('/agenda/activate/{id}', [AgendaController::class, 'activate']);
Route::get('/agenda/servicio/{servicioId}', [AgendaController::class, 'getByServicio']);
Route::post('/agenda/check-disponibilidad', [AgendaController::class, 'checkDisponibilidad']);

/*    Metodos para Reservas
*/
Route::post('/reservas/store', [ReservasController::class, 'store']);
Route::get('/reservas/index', [ReservasController::class, 'index']);
Route::get('/reservas/show/{id}', [ReservasController::class, 'show']);
Route::post('/reservas/update/{id}', [ReservasController::class, 'update']);
Route::get('/reservas/delete/{id}', [ReservasController::class, 'destroy']);
Route::get('/reservas/confirmar/{id}', [ReservasController::class, 'confirmar']);
Route::get('/reservas/completar/{id}', [ReservasController::class, 'completar']);
Route::get('/reservas/usuario/{usuarioId}', [ReservasController::class, 'getByUsuario']);
Route::post('/reservas/verificar-disponibilidad', [ReservasController::class, 'verificarDisponibilidad']);
