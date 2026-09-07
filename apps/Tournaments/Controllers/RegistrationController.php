<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apps\Tournaments\Services\RegistrationService;

class RegistrationController extends Controller
{
    public function __construct(Container $container, private RegistrationService $inscripciones)
    {
        parent::__construct($container);
    }

    public function apply($torneoId)
    {
        try {
            $registro = $this->inscripciones->aplicar($this->actorId(), (int) $torneoId, $this->body(), $this->request);
            return $this->json(['success' => true, 'data' => $registro, 'message' => 'Solicitud enviada'], 201);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 409);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo enviar la solicitud', 'message' => $e->getMessage()], 500);
        }
    }

    public function decide($torneoId, $registroId)
    {
        try {
            $registro = $this->inscripciones->decidir($this->actorId(), (int) $torneoId, (int) $registroId, $this->body(), $this->request);
            return $this->json(['success' => true, 'data' => $registro, 'message' => 'Solicitud decidida']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo decidir la solicitud', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel($torneoId, $registroId)
    {
        try {
            $registro = $this->inscripciones->cancelar($this->actorId(), (int) $torneoId, (int) $registroId, $this->request);
            return $this->json(['success' => true, 'data' => $registro, 'message' => 'Solicitud cancelada']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: 403);
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