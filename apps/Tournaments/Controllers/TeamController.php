<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apps\Tournaments\Services\TeamService;

class TeamController extends Controller
{
    public function __construct(Container $container, private TeamService $teams)
    {
        parent::__construct($container);
    }

    public function index()
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->teams->listar($this->request->query('q')),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los equipos', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $equipo = $this->teams->mostrar((int) $id);
            if (!$equipo) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $equipo]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $equipo = $this->teams->crear($this->actorId(), $this->body());
            return $this->json(['success' => true, 'data' => $equipo, 'message' => 'Equipo creado'], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $equipo = $this->teams->actualizar($this->actorId(), (int) $id, $this->body());
            if (!$equipo) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $equipo, 'message' => 'Equipo actualizado']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->teams->eliminar($this->actorId(), (int) $id)) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Equipo eliminado']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    protected function actorId(): int
    {
        return (int) $this->request->user()->id;
    }

    protected function body(): array
    {
        return $this->request->json() ?? [];
    }
}