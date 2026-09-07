<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apps\Tournaments\Services\DrawService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TournamentService;

class TournamentController extends Controller
{
    public function __construct(
        Container $container,
        private TournamentService $torneos,
        private RegistrationService $inscripciones,
        private DrawService $sorteos,
    ) {
        parent::__construct($container);
    }

    public function index()
    {
        try {
            $filtros = [
                'q' => $this->request->query('q'),
                'sport' => $this->request->query('sport'),
                'status' => $this->request->query('status'),
                'visibility' => $this->request->query('visibility'),
                'organizer_id' => $this->request->query('organizer_id'),
            ];
            $result = $this->torneos->index(
                array_filter($filtros, fn($v) => $v !== null && $v !== ''),
                (int) $this->request->query('perPage', 20),
                (int) $this->request->query('page', 1),
            );
            return $this->json(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los torneos', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $torneo = $this->torneos->mostrar((int) $id);
            if (!$torneo) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $torneo]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $torneo = $this->torneos->crear($this->actorId(), $this->body(), $this->request);
            return $this->json(['success' => true, 'data' => $torneo, 'message' => 'Torneo creado'], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $torneo = $this->torneos->actualizar($this->actorId(), (int) $id, $this->body(), $this->request);
            if (!$torneo) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $torneo, 'message' => 'Torneo actualizado']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->torneos->eliminar($this->actorId(), (int) $id, $this->request)) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Torneo eliminado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function publish($id)
    {
        return $this->transicion((int) $id, 'publish');
    }

    public function start($id)
    {
        return $this->transicion((int) $id, 'start');
    }

    public function finish($id)
    {
        return $this->transicion((int) $id, 'finish');
    }

    private function transicion(int $id, string $accion)
    {
        try {
            $torneo = $this->torneos->transicion($this->actorId(), $id, $accion, $this->request);
            if (!$torneo) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $torneo, 'message' => "Torneo {$accion}ado"]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo cambiar el estado del torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function participants($id)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->torneos->participantes((int) $id)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los participantes', 'message' => $e->getMessage()], 500);
        }
    }

    public function registrations($id)
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->inscripciones->listar((int) $id, $this->actorId(), $this->request->query('status')),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar las solicitudes', 'message' => $e->getMessage()], 500);
        }
    }

    public function draws($id)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->sorteos->mostrar((int) $id)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el sorteo', 'message' => $e->getMessage()], 500);
        }
    }

    public function generateDraw($id)
    {
        try {
            $sorteo = $this->sorteos->generar($this->actorId(), (int) $id, $this->body(), $this->request);
            return $this->json(['success' => true, 'data' => $sorteo, 'message' => 'Sorteo generado']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo generar el sorteo', 'message' => $e->getMessage()], 500);
        }
    }

    private function actorId(): int
    {
        return (int) $this->request->user()->id;
    }

    private function body(): array
    {
        return $this->request->json() ?? [];
    }
}