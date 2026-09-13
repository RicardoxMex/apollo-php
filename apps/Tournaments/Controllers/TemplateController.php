<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plantillas Excel (.xlsx) generadas por el servidor (PhpSpreadsheet).
 * El frontend las descarga para inscribir equipos/participantes por hoja de
 * cálculo: cada campo vive en SU propia celda (nada de "todo en una celda").
 */
class TemplateController extends Controller
{
    /**
     * GET /api/plantillas/inscripcion?individual=0|1&jugadores_por_equipo=N
     * N = jugadores por equipo configurados en el torneo (0 = sin límite → sugerencia).
     */
    public function plantillaInscripcion(Request $request): Response
    {
        $individual = (bool) $request->query('individual', false);
        $jugadoresPorEquipo = (int) $request->query('jugadores_por_equipo', 0);

        $hoja = new Spreadsheet();
        $sheet = $hoja->getActiveSheet();

        if ($individual) {
            $sheet->setTitle('Participantes');
            $headers = ['Nombre', 'Contacto (opcional)'];
            $filas = [
                ['Marta López', 'marta@mail.com'],
                ['Luis García', ''],
            ];
        } else {
            $sheet->setTitle('Equipos');
            $jugadores = $jugadoresPorEquipo > 0 ? $jugadoresPorEquipo : 8; // sin límite → 8 sugeridos
            $headers = ['Equipos', 'Contacto (opcional)'];
            for ($i = 1; $i <= $jugadores; $i++) {
                $headers[] = "Jugador {$i}";
            }

            $nombres = ['Carlos Pérez', 'Luis Ruiz', 'Ana Torres', 'Marco Díaz', 'Elena Ríos', 'Sofía Vega', 'Javier Cruz', 'Lucía Mora'];
            $fila1 = ['Alpha FC', 'capitan@alpha.com'];
            $fila2 = ['Bravo FC', ''];
            for ($i = 1; $i <= $jugadores; $i++) {
                $fila1[] = $nombres[($i - 1) % count($nombres)];
                $fila2[] = '';
            }
            $filas = [$fila1, $fila2];
        }

        $sheet->fromArray([$headers, ...$filas], null, 'A1');

        foreach ($headers as $i => $_) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($i === 0 ? 24 : 20);
        }
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $tmp = tempnam(sys_get_temp_dir(), 'tm_') . '.xlsx';
        (new Xlsx($hoja))->save($tmp);
        $hoja->disconnectWorksheets();

        register_shutdown_function(static fn() => @unlink($tmp));

        return Response::download($tmp, $individual ? 'plantilla-participantes.xlsx' : 'plantilla-equipos.xlsx');
    }
}