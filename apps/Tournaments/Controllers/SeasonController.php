<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\SeasonService;

class SeasonController extends Controller
{
    public function __construct(Container $container, private SeasonService $seasons)
    {
        parent::__construct($container);
    }

    public function index()
    {
        try {
            return $this->json(['success' => true, 'data' => $this->seasons->list()]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar las temporadas', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $season = $this->seasons->show((int) $id);
            if (!$season) {
                return $this->json(['error' => 'Temporada no encontrada'], 404);
            }
            return $this->json(['success' => true, 'data' => $season]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $data = $this->validate($this->body(), [
                'name'      => 'required|string|max:120',
                'starts_at' => 'nullable|date',
                'ends_at'   => 'nullable|date',
            ]);
            $id = $this->seasons->create($this->actorId(), $data);
            return $this->json(['success' => true, 'data' => ['id' => (int) $id], 'message' => 'Temporada creada'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $data = $this->validate($this->body(), [
                'name'      => 'sometimes|string|max:120',
                'starts_at' => 'sometimes|nullable|date',
                'ends_at'   => 'sometimes|nullable|date',
            ]);
            $season = $this->seasons->update($this->actorId(), (int) $id, $data);
            if (!$season) {
                return $this->json(['error' => 'Temporada no encontrada'], 404);
            }
            return $this->json(['success' => true, 'data' => $season, 'message' => 'Temporada actualizada']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->seasons->delete($this->actorId(), (int) $id)) {
                return $this->json(['error' => 'Temporada no encontrada'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Temporada eliminada']);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar la temporada', 'message' => $e->getMessage()], 500);
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