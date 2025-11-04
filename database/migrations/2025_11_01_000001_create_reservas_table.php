<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('servicio_id')->constrained('servicios')->onDelete('cascade');
            $table->foreignId('agenda_id')->constrained('agenda')->onDelete('cascade'); // Cambio: ahora referencia directamente agenda
            $table->date('fecha_reserva');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->enum('estado', ['pendiente', 'confirmada', 'cancelada', 'completada'])->default('pendiente');
            $table->text('notas')->nullable();
            $table->decimal('precio_total', 10, 2);
            $table->timestamps();
            
            // Índices para optimizar consultas
            $table->index(['fecha_reserva', 'hora_inicio']);
            $table->index(['servicio_id', 'fecha_reserva']);
            $table->index(['agenda_id', 'fecha_reserva']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservas');
    }
};