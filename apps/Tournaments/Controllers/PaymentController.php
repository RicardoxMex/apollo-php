<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\PaymentService;

class PaymentController extends Controller
{
    public function __construct(Container $container, private PaymentService $payments)
    {
        parent::__construct($container);
    }

    public function store($tournamentId)
    {
        try {
            $data = $this->validate($this->body(), [
                'registration_id' => 'nullable|integer|min:1',
                'participant_id'  => 'nullable|integer|min:1',
                'amount'          => 'nullable|numeric|min:0.01',
                'currency'        => 'nullable|string|size:3',
                'method'          => 'required|string',
                'reference'       => 'nullable|string|max:150',
                'status'          => 'nullable|in:pending,paid,refunded',
                'paid_at'         => 'nullable|date',
                'notes'           => 'nullable|string|max:1000',
            ]);
            $payment = $this->payments->register($this->actorId(), (int) $tournamentId, $data, $this->request);
            return $this->json(['success' => true, 'data' => $payment, 'message' => 'Pago registrado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo registrar el pago', 'message' => $e->getMessage()], 500);
        }
    }

    public function index($tournamentId)
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->payments->list($this->actorId(), (int) $tournamentId),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los pagos', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($tournamentId, $paymentId)
    {
        try {
            $this->payments->delete($this->actorId(), (int) $tournamentId, (int) $paymentId, $this->request);
            return $this->json(['success' => true, 'message' => 'Pago eliminado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el pago', 'message' => $e->getMessage()], 500);
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