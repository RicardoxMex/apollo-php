<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apps\Tournaments\Services\MatchService;

class MatchController extends Controller
{
    public function __construct(Container $container, private MatchService $partidos)
    {
        parent::__construct($container);
    }

    public function index($torneoId)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->partidos->listar((int) $torneoId)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los partidos', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($torneoId, $matchId)
    {
        try {
            $partido = $this->partidos->actualizar($this->actorId(), (int) $torneoId, (int) $matchId, $this->body(), $this->request);
            return $this->json(['success' => true, 'data' => $partido, 'message' => 'Partido actualizado']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el partido', 'message' => $e->getMessage()], 500);
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