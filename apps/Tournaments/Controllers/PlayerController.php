<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
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
                'data' => $this->players->listar($this->request->query('q')),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los jugadores', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $jugador = $this->players->mostrar((int) $id);
            if (!$jugador) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $jugador]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $id = $this->players->crear($this->actorId(), $this->body());
            return $this->json(['success' => true, 'data' => ['id' => (int) $id], 'message' => 'Jugador creado'], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $jugador = $this->players->actualizar($this->actorId(), (int) $id, $this->body());
            if (!$jugador) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $jugador, 'message' => 'Jugador actualizado']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el jugador', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->players->eliminar($this->actorId(), (int) $id)) {
                return $this->json(['error' => 'Jugador no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Jugador eliminado']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el jugador', 'message' => $e->getMessage()], 500);
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