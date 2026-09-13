<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\RegistrationService;

class RegistrationController extends Controller
{
    public function __construct(Container $container, private RegistrationService $registrations)
    {
        parent::__construct($container);
    }

    public function apply($tournamentId)
    {
        try {
            $data = $this->validate($this->body(), [
                'team_id'  => 'nullable|integer|min:1',
                'player_id'=> 'nullable|integer|min:1',
                'message'  => 'nullable|string|max:500',
            ]);
            $registration = $this->registrations->apply($this->actorId(), (int) $tournamentId, $data, $this->request);
            return $this->json(['success' => true, 'data' => $registration, 'message' => 'Solicitud enviada'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo enviar la solicitud', 'message' => $e->getMessage()], 500);
        }
    }

    public function decide($tournamentId, $registrationId)
    {
        try {
            $data = $this->validate($this->body(), [
                'action' => 'required|in:accepted,rejected',
            ]);
            $registration = $this->registrations->decide($this->actorId(), (int) $tournamentId, (int) $registrationId, $data, $this->request);
            return $this->json(['success' => true, 'data' => $registration, 'message' => 'Solicitud decidida']);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo decidir la solicitud', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel($tournamentId, $registrationId)
    {
        try {
            $registration = $this->registrations->cancel($this->actorId(), (int) $tournamentId, (int) $registrationId, $this->request);
            return $this->json(['success' => true, 'data' => $registration, 'message' => 'Solicitud cancelada']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo cancelar la solicitud', 'message' => $e->getMessage()], 500);
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