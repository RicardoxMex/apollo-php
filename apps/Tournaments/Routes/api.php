<?php
// apps/Tournaments/Routes/api.php
//
// REST API del dominio de torneos (prefix: api)
//
// Públicas (explore + detalle):          GET    /api/tournaments...
// Con auth (JWT del ApolloAuth):         POST/PUT/DELETE /api/tournaments...
//                                        /api/teams, /api/players, /api/seasons, /api/audit-logs
//
// Ciclo de estados: POST /publish (draft→open), /start (open→live), /finish (live→finished)
// Inscripciones:    POST /{id}/registrations (aplicar), /{rid}/decide (moderación), /{rid}/cancel
// Sorteo:           POST /{id}/draw (bracket | groups | manual)
// Partidos:         PUT /{id}/matches/{mid} (estado, marcadores, ganador; propaga al bracket)

use Apps\Tournaments\Controllers\AuditLogController;
use Apps\Tournaments\Controllers\MatchController;
use Apps\Tournaments\Controllers\PlayerController;
use Apps\Tournaments\Controllers\RegistrationController;
use Apps\Tournaments\Controllers\SeasonController;
use Apps\Tournaments\Controllers\TeamController;
use Apps\Tournaments\Controllers\TournamentController;

/** @var \Apollo\Core\Router\Router $router */

// ─── Lecturas públicas (explore, detalle, bracket, partidos) ───
$router->get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
$router->get('/tournaments/{id}', [TournamentController::class, 'show'])->where(['id' => '\d+'])->name('tournaments.show');
$router->get('/tournaments/{id}/participants', [TournamentController::class, 'participants'])->where(['id' => '\d+'])->name('tournaments.participants');
$router->get('/tournaments/{id}/draws', [TournamentController::class, 'draws'])->where(['id' => '\d+'])->name('tournaments.draws');
$router->get('/tournaments/{id}/matches', [MatchController::class, 'index'])->where(['id' => '\d+'])->name('tournaments.matches');

// ─── Escrituras con auth (JWT) ───
$router->group(['middleware' => ['auth']], function ($router) {
    // Torneos: CRUD + ciclo + sorteo + moderación
    $router->post('/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    $router->put('/tournaments/{id}', [TournamentController::class, 'update'])->where(['id' => '\d+'])->name('tournaments.update');
    $router->delete('/tournaments/{id}', [TournamentController::class, 'destroy'])->where(['id' => '\d+'])->name('tournaments.destroy');
    $router->post('/tournaments/{id}/publish', [TournamentController::class, 'publish'])->where(['id' => '\d+'])->name('tournaments.publish');
    $router->post('/tournaments/{id}/start', [TournamentController::class, 'start'])->where(['id' => '\d+'])->name('tournaments.start');
    $router->post('/tournaments/{id}/finish', [TournamentController::class, 'finish'])->where(['id' => '\d+'])->name('tournaments.finish');
    $router->post('/tournaments/{id}/draw', [TournamentController::class, 'generateDraw'])->where(['id' => '\d+'])->name('tournaments.draw.generate');

    // Solicitudes de inscripción (aplicar cualquiera; decidir solo organizador)
    $router->get('/tournaments/{id}/registrations', [TournamentController::class, 'registrations'])->where(['id' => '\d+'])->name('tournaments.registrations');
    $router->post('/tournaments/{id}/registrations', [RegistrationController::class, 'apply'])->where(['id' => '\d+'])->name('registrations.apply');
    $router->post('/tournaments/{id}/registrations/{rid}/decide', [RegistrationController::class, 'decide'])->where(['id' => '\d+', 'rid' => '\d+'])->name('registrations.decide');
    $router->post('/tournaments/{id}/registrations/{rid}/cancel', [RegistrationController::class, 'cancel'])->where(['id' => '\d+', 'rid' => '\d+'])->name('registrations.cancel');

    // Partidos (solo organizador; torneo en live)
    $router->put('/tournaments/{id}/matches/{mid}', [MatchController::class, 'update'])->where(['id' => '\d+', 'mid' => '\d+'])->name('matches.update');

    // Equipos, jugadores, temporadas
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

    // Auditoría (solo lectura)
    $router->get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});