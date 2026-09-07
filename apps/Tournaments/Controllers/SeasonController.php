<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
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
            return $this->json(['success' => true, 'data' => $this->seasons->listar()]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar las temporadas', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $temporada = $this->seasons->mostrar((int) $id);
            if (!$temporada) {
                return $this->json(['error' => 'Temporada no encontrada'], 404);
            }
            return $this->json(['success' => true, 'data' => $temporada]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $id = $this->seasons->crear($this->actorId(), $this->body());
            return $this->json(['success' => true, 'data' => ['id' => (int) $id], 'message' => 'Temporada creada'], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $temporada = $this->seasons->actualizar($this->actorId(), (int) $id, $this->body());
            if (!$temporada) {
                return $this->json(['error' => 'Temporada no encontrada'], 404);
            }
            return $this->json(['success' => true, 'data' => $temporada, 'message' => 'Temporada actualizada']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar la temporada', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->seasons->eliminar($this->actorId(), (int) $id)) {
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