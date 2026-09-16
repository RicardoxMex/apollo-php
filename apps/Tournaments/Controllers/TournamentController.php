<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\DrawService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TournamentService;

class TournamentController extends Controller
{
    private const RULES = [
        'title'                 => 'required|string|min:3|max:120',
        'format'                => 'required|in:eliminacion-directa,doble-eliminacion,round-robin,grupos,liga',
        'max_participants'      => 'required|integer|min:2',
        'clasificados_eliminacion' => 'nullable|integer|min:0',
        'ida_vuelta'            => 'nullable|boolean',
        'players_per_team'      => 'nullable|integer|min:1',
        'sport'                 => 'nullable|string|max:60',
        'description'           => 'nullable|string|max:2000',
        'location'              => 'nullable|string|max:255',
        'image'                 => 'nullable|string|max:500',
        'is_online'             => 'nullable|boolean',
        'is_individual'         => 'nullable|boolean',
        'start_date'            => 'nullable|date',
        'end_date'              => 'nullable|date',
        'registration_deadline' => 'nullable|date',
        'registration_fee'      => 'nullable|numeric|min:0',
        'currency'              => 'nullable|string|size:3',
        'visibility'            => 'nullable|in:publico,privado',
        'minimum_age'           => 'nullable|integer|min:0',
        'rules'                 => 'nullable|string',
        'max_substitutes'       => 'nullable|integer|min:0',
        'season_id'             => 'nullable|integer',
        'prizes'                => 'nullable|array',
        'stats'                 => 'nullable|array',
    ];

    private function reglasActualizar(): array
    {
        $rules = [];

        foreach (self::RULES as $campo => $regla) {
            $rules[$campo] = 'sometimes|' . $regla;
        }

        return $rules;
    }
    public function __construct(
        Container $container,
        private TournamentService $tournaments,
        private RegistrationService $registrations,
        private DrawService $draws,
    ) {
        parent::__construct($container);
    }

    public function index()
    {
        try {
            $filters = [
                'q' => $this->request->query('q'),
                'sport' => $this->request->query('sport'),
                'status' => $this->request->query('status'),
                'visibility' => $this->request->query('visibility'),
                'organizer_id' => $this->request->query('organizer_id'),
            ];

            // D-F0-4: ver los torneos de un organizador exige sesión; `me`
            // se resuelve al actor y un tercero no puede pedir otro id (salvo admin).
            if (($filters['organizer_id'] ?? null) !== null && $filters['organizer_id'] !== '') {
                $user = $this->resolveUser();
                if (!$user) {
                    return $this->json([
                        'error' => 'Unauthorized',
                        'message' => 'Inicia sesión para ver los torneos de un organizador',
                    ], 401);
                }
                if ($filters['organizer_id'] === 'me') {
                    $filters['organizer_id'] = (int) $user->id;
                } elseif ((int) $filters['organizer_id'] !== (int) $user->id && !$user->hasAnyRole(['admin'])) {
                    return $this->json([
                        'error' => 'Forbidden',
                        'message' => 'Solo puedes ver los torneos que organizas',
                    ], 403);
                }
            }

            // perPage acotado a 1..100 (D-F0-4).
            $perPage = max(1, min(100, (int) $this->request->query('perPage', 20)));

            $result = $this->tournaments->index(
                array_filter($filters, fn($v) => $v !== null && $v !== ''),
                $perPage,
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
            $tournament = $this->tournaments->show((int) $id);
            if (!$tournament) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $tournament]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function store()
    {
        try {
            $data = $this->validate($this->body(), self::RULES);
            $tournament = $this->tournaments->create($this->actorId(), $data, $this->request);
            return $this->json(['success' => true, 'data' => $tournament, 'message' => 'Torneo creado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo crear el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function update($id)
    {
        try {
            $data = $this->validate($this->body(), $this->reglasActualizar());
            $tournament = $this->tournaments->update($this->actorId(), (int) $id, $data, $this->request);
            if (!$tournament) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $tournament, 'message' => 'Torneo actualizado']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo actualizar el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!$this->tournaments->delete($this->actorId(), (int) $id, $this->request)) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'message' => 'Torneo eliminado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function publish($id)
    {
        return $this->transition((int) $id, 'publish');
    }

    public function start($id)
    {
        return $this->transition((int) $id, 'start');
    }

    public function finish($id)
    {
        return $this->transition((int) $id, 'finish');
    }

    public function pause($id)
    {
        return $this->transition((int) $id, 'pause');
    }

    public function resume($id)
    {
        return $this->transition((int) $id, 'resume');
    }

    private function transition(int $id, string $action)
    {
        try {
            $tournament = $this->tournaments->transition($this->actorId(), $id, $action, $this->request);
            if (!$tournament) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            $message = match ($action) {
                'pause' => 'Torneo pausado',
                'resume' => 'Torneo reanudado',
                default => "Torneo {$action}ado",
            };
            return $this->json(['success' => true, 'data' => $tournament, 'message' => $message]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\Apps\ApolloAuth\Exceptions\EmailNotVerifiedException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => $e->businessCode()], 403);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo cambiar el estado del torneo', 'message' => $e->getMessage()], 500);
        }
    }

    public function participants($id)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->tournaments->participants((int) $id)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los participantes', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Clasificación en servidor (M2): GET /tournaments/{id}/standings (público).
     */
    public function standings($id)
    {
        try {
            $standings = (new \Apps\Tournaments\Services\StandingsService())->compute((int) $id);
            return $this->json(['success' => true, 'data' => $standings]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 404);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo calcular la clasificación', 'message' => $e->getMessage()], 500);
        }
    }

    public function registrations($tournamentId)
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->registrations->list((int) $tournamentId, $this->actorId(), $this->request->query('status')),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar las solicitudes', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Historial del perfil (M3): torneos organizados, participados y equipos.
     * GET /profile/history (auth).
     */
    public function history()
    {
        try {
            $history = (new \Apps\Tournaments\Services\ProfileHistoryService())->history($this->actorId());
            return $this->json(['success' => true, 'data' => $history]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo cargar el historial', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Inscripciones del participante (PORTAL-01, REQ-01): GET /profile/registrations (auth).
     * Actor-scoped: solo las propias (applicant o jugador vinculado).
     */
    public function myRegistrations()
    {
        try {
            $rows = (new \Apps\Tournaments\Services\ProfileRegistrationsService())->list($this->actorId());
            return $this->json(['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron cargar las inscripciones', 'message' => $e->getMessage()], 500);
        }
    }

    public function draws($id)
    {
        try {
            return $this->json(['success' => true, 'data' => $this->draws->show((int) $id)]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo obtener el sorteo', 'message' => $e->getMessage()], 500);
        }
    }

    public function generateDraw($tournamentId)
    {
        try {
            $data = $this->validate($this->body(), [
                'type'            => 'required|in:groups,bracket,manual',
                'num_groups'      => 'nullable|integer|min:2',
                'groups'          => 'nullable|array',
                'participant_ids' => 'nullable|array',
            ]);
            $draw = $this->draws->generate($this->actorId(), (int) $tournamentId, $data, $this->request);
            return $this->json(['success' => true, 'data' => $draw, 'message' => 'Sorteo generado']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo generar el sorteo', 'message' => $e->getMessage()], 500);
        }
    }

    public function clearDraw($tournamentId)
    {
        try {
            $draw = $this->draws->delete($this->actorId(), (int) $tournamentId, $this->request);
            return $this->json(['success' => true, 'data' => $draw, 'message' => 'Sorteo limpiado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo limpiar el sorteo', 'message' => $e->getMessage()], 500);
        }
    }

    public function duplicate($tournamentId)
    {
        try {
            $tournament = $this->tournaments->duplicate($this->actorId(), (int) $tournamentId, $this->request);
            if (!$tournament) {
                return $this->json(['error' => 'Torneo no encontrado'], 404);
            }
            return $this->json(['success' => true, 'data' => $tournament, 'message' => 'Torneo duplicado'], 201);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo duplicar el torneo', 'message' => $e->getMessage()], 500);
        }
    }

    private function actorId(): int
    {
        return (int) $this->request->user()->id;
    }

    /**
     * Resuelve el usuario autenticado en rutas públicas (D-F0-4): si el
     * request ya lo trae (middleware auth) lo reutiliza; si no, valida un
     * Bearer token opcional sin rechazar al anónimo.
     */
    private function resolveUser(): ?\Apps\ApolloAuth\Models\User
    {
        if ($this->request->hasUser()) {
            return $this->request->user();
        }

        $header = (string) $this->request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $user = app(\Apps\ApolloAuth\Services\AuthService::class)
            ->authenticateFromToken(substr($header, 7));

        if ($user) {
            $this->request->setUser($user);
        }

        return $user;
    }

    private function body(): array
    {
        return $this->request->json() ?? [];
    }
}