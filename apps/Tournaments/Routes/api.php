<?php
// apps/Tournaments/Routes/api.php
//
// REST API of the tournaments domain (prefix: api)
//
// Public (explore + detail):   GET    /api/tournaments...
// With auth (ApolloAuth JWT):  POST/PUT/DELETE /api/tournaments...
//                              /api/teams, /api/players, /api/seasons, /api/audit-logs
// Uploads:                     POST   /api/uploads (auth, servicio nativo core/Uploads)
//                              GET    /api/uploads/{path} (público, sirve el archivo)
//
// Status cycle: POST /publish (draft→open), /start (open→live), /finish (live→finished)
// Registrations: POST /{id}/registrations (apply), /{rid}/decide (moderation), /{rid}/cancel
// Draw:          POST /{id}/draw (bracket | groups | manual)
// Matches:       PUT /{id}/matches/{mid} (status, scores, winner; propagates to the bracket)

use Apps\Tournaments\Controllers\AuditLogController;
use Apps\Tournaments\Controllers\AnnouncementController;
use Apps\Tournaments\Controllers\MatchController;
use Apps\Tournaments\Controllers\PaymentController;
use Apps\Tournaments\Controllers\PlayerController;
use Apps\Tournaments\Controllers\RegistrationController;
use Apps\Tournaments\Controllers\SeasonController;
use Apps\Tournaments\Controllers\TeamController;
use Apps\Tournaments\Controllers\TemplateController;
use Apps\Tournaments\Controllers\TournamentController;
use Apps\Tournaments\Controllers\UploadController;

/** @var \Apollo\Core\Router\Router $router */

// ─── Public reads (explore, detail, bracket, matches) ───
$router->group(['middleware' => ['cors']], function ($router) {
    $router->get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    $router->get('/tournaments/{id}', [TournamentController::class, 'show'])->where(['id' => '\d+'])->name('tournaments.show');
    $router->get('/tournaments/{id}/participants', [TournamentController::class, 'participants'])->where(['id' => '\d+'])->name('tournaments.participants');
    $router->get('/tournaments/{id}/draws', [TournamentController::class, 'draws'])->where(['id' => '\d+'])->name('tournaments.draws');
    $router->get('/tournaments/{tournamentId}/matches', [MatchController::class, 'index'])->where(['tournamentId' => '\d+'])->name('tournaments.matches');
    // Clasificación (M2): tabla de posiciones calculada en servidor (fuente única)
    $router->get('/tournaments/{id}/standings', [TournamentController::class, 'standings'])->where(['id' => '\d+'])->name('tournaments.standings');
    // Tablón de anuncios (M6): lectura pública
    $router->get('/tournaments/{tournamentId}/announcements', [AnnouncementController::class, 'index'])->where(['tournamentId' => '\d+'])->name('announcements.index');
    // Plantilla Excel (.xlsx) para inscribir equipos/participantes
    $router->get('/plantillas/inscripcion', [TemplateController::class, 'plantillaInscripcion'])->name('plantillas.inscripcion');
    // Archivos subidos (imágenes de equipos): lectura pública, path seguro
    $router->get('/uploads/{path}', [UploadController::class, 'show'])->where(['path' => '[A-Za-z0-9._/-]+'])->name('uploads.show');
});

// ─── Writes with auth (JWT) ───
$router->group(['middleware' => ['auth', 'cors']], function ($router) {
    // Tournaments: CRUD + cycle + draw + moderation
    $router->post('/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    $router->put('/tournaments/{id}', [TournamentController::class, 'update'])->where(['id' => '\d+'])->name('tournaments.update');
    $router->delete('/tournaments/{id}', [TournamentController::class, 'destroy'])->where(['id' => '\d+'])->name('tournaments.destroy');
    $router->post('/tournaments/{id}/publish', [TournamentController::class, 'publish'])->where(['id' => '\d+'])->name('tournaments.publish');
    $router->post('/tournaments/{id}/start', [TournamentController::class, 'start'])->where(['id' => '\d+'])->name('tournaments.start');
    $router->post('/tournaments/{id}/finish', [TournamentController::class, 'finish'])->where(['id' => '\d+'])->name('tournaments.finish');
    $router->post('/tournaments/{tournamentId}/draw', [TournamentController::class, 'generateDraw'])->where(['tournamentId' => '\d+'])->name('tournaments.draw.generate');
    $router->delete('/tournaments/{tournamentId}/draw', [TournamentController::class, 'clearDraw'])->where(['tournamentId' => '\d+'])->name('tournaments.draw.delete');
    $router->post('/tournaments/{tournamentId}/duplicate', [TournamentController::class, 'duplicate'])->where(['tournamentId' => '\d+'])->name('tournaments.duplicate');

    // Registration requests (anyone can apply; only the organizer decides)
    $router->get('/tournaments/{tournamentId}/registrations', [TournamentController::class, 'registrations'])->where(['tournamentId' => '\d+'])->name('tournaments.registrations');
    $router->post('/tournaments/{tournamentId}/registrations', [RegistrationController::class, 'apply'])->where(['tournamentId' => '\d+'])->name('registrations.apply');
    $router->post('/tournaments/{tournamentId}/registrations/{registrationId}/decide', [RegistrationController::class, 'decide'])->where(['tournamentId' => '\d+', 'registrationId' => '\d+'])->name('registrations.decide');
    $router->post('/tournaments/{tournamentId}/registrations/{registrationId}/cancel', [RegistrationController::class, 'cancel'])->where(['tournamentId' => '\d+', 'registrationId' => '\d+'])->name('registrations.cancel');

    // Matches (organizer only): create (jornadas/scheduling) and update
    // (status, scores, winner; propagates to the bracket)
    $router->post('/tournaments/{tournamentId}/matches', [MatchController::class, 'store'])->where(['tournamentId' => '\d+'])->name('matches.store');
    $router->put('/tournaments/{tournamentId}/matches/{matchId}', [MatchController::class, 'update'])->where(['tournamentId' => '\d+', 'matchId' => '\d+'])->name('matches.update');
    $router->delete('/tournaments/{tournamentId}/matches/{matchId}', [MatchController::class, 'destroy'])->where(['tournamentId' => '\d+', 'matchId' => '\d+'])->name('matches.destroy');

    // Pagos manuales de inscripción (M4, organizador)
    $router->post('/tournaments/{tournamentId}/payments', [PaymentController::class, 'store'])->where(['tournamentId' => '\d+'])->name('payments.store');
    $router->get('/tournaments/{tournamentId}/payments', [PaymentController::class, 'index'])->where(['tournamentId' => '\d+'])->name('payments.index');
    $router->delete('/tournaments/{tournamentId}/payments/{paymentId}', [PaymentController::class, 'destroy'])->where(['tournamentId' => '\d+', 'paymentId' => '\d+'])->name('payments.destroy');

    // Tablón de anuncios (M6, organizador)
    $router->post('/tournaments/{tournamentId}/announcements', [AnnouncementController::class, 'store'])->where(['tournamentId' => '\d+'])->name('announcements.store');
    $router->delete('/tournaments/{tournamentId}/announcements/{announcementId}', [AnnouncementController::class, 'destroy'])->where(['tournamentId' => '\d+', 'announcementId' => '\d+'])->name('announcements.destroy');

    // Teams, players, seasons
    $router->get('/teams', [TeamController::class, 'index'])->name('teams.index');
    $router->post('/teams', [TeamController::class, 'store'])->name('teams.store');
    $router->get('/teams/{id}', [TeamController::class, 'show'])->where(['id' => '\d+'])->name('teams.show');
    $router->put('/teams/{id}', [TeamController::class, 'update'])->where(['id' => '\d+'])->name('teams.update');
    $router->delete('/teams/{id}', [TeamController::class, 'destroy'])->where(['id' => '\d+'])->name('teams.destroy');

    $router->get('/players', [PlayerController::class, 'index'])->name('players.index');
    $router->post('/players', [PlayerController::class, 'store'])->name('players.store');
    $router->get('/players/{id}', [PlayerController::class, 'show'])->where(['id' => '\d+'])->name('players.show');
    $router->put('/players/{id}', [PlayerController::class, 'update'])->where(['id' => '\d+'])->name('players.update');
    $router->delete('/players/{id}', [PlayerController::class, 'destroy'])->where(['id' => '\d+'])->name('players.destroy');

    $router->get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
    $router->post('/seasons', [SeasonController::class, 'store'])->name('seasons.store');
    $router->get('/seasons/{id}', [SeasonController::class, 'show'])->where(['id' => '\d+'])->name('seasons.show');
    $router->put('/seasons/{id}', [SeasonController::class, 'update'])->where(['id' => '\d+'])->name('seasons.update');
    $router->delete('/seasons/{id}', [SeasonController::class, 'destroy'])->where(['id' => '\d+'])->name('seasons.destroy');

    // Audit (read only)
    $router->get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    // Historial del perfil (M3): organizados + participados + equipos
    $router->get('/profile/history', [TournamentController::class, 'history'])->name('profile.history');

    // Uploads: subida de archivos (imágenes) con el servicio nativo de Uploads
    $router->post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
});