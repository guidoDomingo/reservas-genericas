<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->constrained('servicios')->onDelete('cascade');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->time('hora_inicio_trabajo'); // 9:00 AM
            $table->time('hora_fin_trabajo'); // 5:00 PM
            $table->integer('intervalo_minutos')->default(30);
            $table->json('dias_activos');
            $table->time('descanso_inicio')->nullable();
            $table->time('descanso_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('auto_generar_horarios')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agenda');
    }
};
