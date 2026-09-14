<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Validation\ValidationException;
use Apps\Tournaments\Services\AnnouncementService;

class AnnouncementController extends Controller
{
    public function __construct(Container $container, private AnnouncementService $announcements)
    {
        parent::__construct($container);
    }

    /** Lectura pública del tablón (GET /tournaments/{id}/announcements). */
    public function index($tournamentId)
    {
        try {
            return $this->json([
                'success' => true,
                'data' => $this->announcements->listPublic((int) $tournamentId),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudieron listar los anuncios', 'message' => $e->getMessage()], 500);
        }
    }

    /** Publicar un anuncio (POST /tournaments/{id}/announcements, organizador). */
    public function store($tournamentId)
    {
        try {
            $data = $this->validate($this->body(), [
                'title'       => 'required|string|max:200',
                'body'        => 'required|string|max:5000',
                'pinned'      => 'nullable|boolean',
                'notify_email'=> 'nullable|boolean',
            ]);
            $announcement = $this->announcements->create(
                $this->actorId(),
                (int) $tournamentId,
                $data,
                !empty($data['notify_email']),
                $this->request,
            );
            return $this->json(['success' => true, 'data' => $announcement, 'message' => 'Anuncio publicado'], 201);
        } catch (ValidationException $e) {
            return $this->json(['error' => 'Validación', 'errors' => $e->errors()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo publicar el anuncio', 'message' => $e->getMessage()], 500);
        }
    }

    /** Eliminar un anuncio (DELETE /tournaments/{id}/announcements/{aid}, organizador). */
    public function destroy($tournamentId, $announcementId)
    {
        try {
            $this->announcements->delete($this->actorId(), (int) $tournamentId, (int) $announcementId, $this->request);
            return $this->json(['success' => true, 'message' => 'Anuncio eliminado']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo eliminar el anuncio', 'message' => $e->getMessage()], 500);
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