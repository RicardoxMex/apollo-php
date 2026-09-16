<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\PlayerService;

class PlayerController extends Controller
{
    public function __construct(Container $container, private PlayerService $players)
    {
        parent::__construct($container);
    }

    public function index()
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->players->list($this->request->query('q')),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los jugadores', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $player = $this->players->show((int) $id);
            if (!$player) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $player]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $data = $this->validate($this->body(), [
                'name'          => 'required|string|max:100',
                'user_id'       => 'nullable|integer|min:1',
                'jersey_number' => 'nullable|integer|min:0',
                'birth_date'    => 'nullable|date',
            ]);
            $id = $this->players->create($this->actorId(), $data);
            return $this->json(['success' => true, 'data' => ['id' => (int) $id], 'message' => 'Jugador creado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $player = $this->players->show((int) $id);
            if (!$player) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }

            if (!$this->canManage($player)) {
                return $this->json([
                    'error' => 'Forbidden',
                    'message' => 'Solo el dueño, un capitán de un equipo que lo contiene, el organizador de un torneo donde participa o un administrador puede actualizarlo',
                ], 403);
            }

            $data = $this->validate($this->body(), [
                'name'          => 'sometimes|string|max:100',
                'user_id'       => 'sometimes|nullable|integer|min:1',
                'jersey_number' => 'sometimes|nullable|integer|min:0',
                'birth_date'    => 'sometimes|nullable|date',
            ]);
            $player = $this->players->update($this->actorId(), (int) $id, $data);
            if (!$player) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $player, 'message' => 'Jugador actualizado']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $player = $this->players->show((int) $id);
            if (!$player) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }

            if (!$this->canManage($player)) {
                return $this->json([
                    'error' => 'Forbidden',
                    'message' => 'Solo el dueño, un capitán de un equipo que lo contiene, el organizador de un torneo donde participa o un administrador puede eliminarlo',
                ], 403);
            }

            if (!$this->players->delete($this->actorId(), (int) $id)) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Jugador eliminado']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ownership (R-PERIM-02): user_id dueño, capitán de un equipo que lo
     * contiene, organizador de un torneo donde participa, o admin.
     */
    protected function canManage(array $player): bool
    {
        return $this->isAdmin() || $this->players->canManage($this->actorId(), $player);
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