<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
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
                'data' => $this->teams->list($this->request->query('q')),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los equipos', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $team = $this->teams->show((int) $id);
            if (!$team) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $team]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $data = $this->validate($this->body(), [
                'name'     => 'required|string|max:100',
                'contact'  => 'nullable|string|max:255',
                'image'    => 'nullable|string|max:500',
                'captains' => 'nullable|array',
                'players'  => 'nullable|array',
            ]);
            $team = $this->teams->create($this->actorId(), $data);
            return $this->json(['success' => true, 'data' => $team, 'message' => 'Equipo creado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            if (!$this->canManage((int) $id)) {
                return $this->json([
                    'error' => 'Forbidden',
                    'message' => 'Solo el capitán, el organizador de un torneo con el equipo inscrito o un administrador puede actualizarlo',
                ], 403);
            }

            $data = $this->validate($this->body(), [
                'name'     => 'sometimes|string|max:100',
                'contact'  => 'sometimes|nullable|string|max:255',
                'image'    => 'sometimes|nullable|string|max:500',
                'captains' => 'sometimes|nullable|array',
                'players'  => 'sometimes|nullable|array',
            ]);
            $team = $this->teams->update($this->actorId(), (int) $id, $data);
            if (!$team) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $team, 'message' => 'Equipo actualizado']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->canManage((int) $id)) {
                return $this->json([
                    'error' => 'Forbidden',
                    'message' => 'Solo el capitán, el organizador de un torneo con el equipo inscrito o un administrador puede eliminarlo',
                ], 403);
            }

            if (!$this->teams->delete($this->actorId(), (int) $id)) {
                return $this->json(['error' => 'Equipo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Equipo eliminado']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el equipo', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ownership (R-PERIM-02): capitán del equipo, organizador de un torneo
     * donde el equipo está inscrito/participa, o admin.
     */
    protected function canManage(int $teamId): bool
    {
        return $this->isAdmin() || $this->teams->canManage($teamId, $this->actorId());
    }

    protected function isAdmin(): bool
    {
        return $this->request->user()?->isAdmin() === true;
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