<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\MatchService;

class MatchController extends Controller
{
    public function __construct(Container $container, private MatchService $matches)
    {
        parent::__construct($container);
    }

    public function index($tournamentId)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->matches->list((int) $tournamentId)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los partidos', 'message' => $e->getMessage()], 500);
        }
    }

    public function store($tournamentId)
    {
        try {
            $data = $this->validate($this->body(), [
                'round_number'      => 'sometimes|nullable|integer|min:1',
                'participant_a_id'  => 'sometimes|nullable|integer|min:1',
                'participant_b_id'  => 'sometimes|nullable|integer|min:1',
                'status'            => 'sometimes|in:pending,scheduled,live',
                'scheduled_at'      => 'sometimes|nullable|date',
                'label'             => 'sometimes|nullable|string|max:255',
            ]);
            $match = $this->matches->create($this->actorId(), (int) $tournamentId, $data);
            return $this->json(['success' => true, 'data' => $match, 'message' => 'Partido creado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el partido', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($tournamentId, $matchId)
    {
        try {
            $data = $this->validate($this->body(), [
                'status'                => 'sometimes|in:pending,scheduled,live,completed,cancelled',
                'scheduled_at'          => 'sometimes|nullable|date',
                'winner_participant_id' => 'sometimes|nullable|integer|min:1',
                'scores'                => 'nullable|array',
                'player_stats'          => 'nullable|array',
                'label'                 => 'sometimes|nullable|string|max:255',
            ]);
            $match = $this->matches->update($this->actorId(), (int) $tournamentId, (int) $matchId, $data, $this->request);
            return $this->json(['success' => true, 'data' => $match, 'message' => 'Partido actualizado']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el partido', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($tournamentId, $matchId)
    {
        try {
            $this->matches->delete($this->actorId(), (int) $tournamentId, (int) $matchId);
            return $this->json(['success' => true, 'message' => 'Partido eliminado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el partido', 'message' => $e->getMessage()], 500);
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