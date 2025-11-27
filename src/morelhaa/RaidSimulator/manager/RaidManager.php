<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\manager;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\session\RaidSession;
use morelhaa\RaidSimulator\arena\Arena;
use morelhaa\RaidSimulator\wave\Wave;
use morelhaa\RaidSimulator\wave\MobSpawner;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\nbt\tag\CompoundTag;

class RaidManager {

    private RaidSimulator $plugin;
    /** @var RaidSession[] */
    private array $sessions = [];
    /** @var array<string, string> */
    private array $playerSessions = [];

    private const WAVE_BREAK_TIME = 10;

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
        $this->startMainTask();
    }

    private function startMainTask(): void {
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->tick();
        }), 20);
    }

    private function tick(): void {
        foreach ($this->sessions as $session) {
            if (!$session->isActive() || $session->isPaused()) continue;
            if ($session->hasTimeExpired()) {
                $this->endSession($session->getId(), "§cTiempo agotado!");
                continue;
            }
            if ($session->isWaveComplete() && $session->getCurrentWave() > 0) {
                $this->handleWaveComplete($session);
            }
            $this->handleRespawnQueue($session);
            $this->updatePlayerDisplay($session);
        }
    }

    public function createSession(Arena $arena, array $players): ?RaidSession {
        if (!$arena->isAvailable()) return null;
        $sessionId = uniqid("raid_");
        $session = new RaidSession($sessionId, $arena, $players);
        $this->sessions[$sessionId] = $session;
        $arena->setAvailable(false);
        foreach ($players as $player) {
            $this->playerSessions[$player->getName()] = $sessionId;
            $player->teleport($arena->getSpawnPoint());
        }
        $session->broadcastMessage("§a§l¡RAID INICIADO!");
        $session->broadcastMessage("§7Prepárense para la primera oleada...");
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sessionId): void {
            $session = $this->getSession($sessionId);
            if ($session !== null) $this->startNextWave($session);
        }), 100);
        return $session;
    }

    public function startNextWave(RaidSession $session): void {
        $session->nextWave();
        $currentWave = $session->getCurrentWave();
        $wave = $this->plugin->getWaveManager()->getWave($currentWave);
        if ($wave === null) {
            $this->endSession($session->getId(), "§a§l¡RAID COMPLETADO!");
            return;
        }
        $session->setActive(true);
        $message = $wave->hasBoss() ? "§c§l¡OLEADA DE BOSS #{$currentWave}!" : "§e§lOLEADA #{$currentWave}";
        $session->broadcastTitle($message);
        $session->broadcastMessage("§7Mobs: §f" . $wave->getTotalMobCount());
        $this->spawnWaveMobs($session, $wave);
    }

    private function spawnWaveMobs(RaidSession $session, Wave $wave): void {
        $arena = $session->getArena();
        $scaledMobs = $wave->getScaledMobs();
        $totalSpawned = 0;
        foreach ($scaledMobs as $mobData) {
            $type = $mobData["type"] ?? "zombie";
            $amount = (int)($mobData["amount"] ?? 0);
            $health = (float)($mobData["health"] ?? 20.0);
            $damage = (float)($mobData["damage"] ?? 2.0);
            $isBoss = (bool)($mobData["is_boss"] ?? false);
            for ($i = 0; $i < $amount; $i++) {
                $spawnPos = $arena->getRandomMobSpawnPoint();
                if ($spawnPos === null) continue;
                $world = $spawnPos->getWorld();
                if ($world === null) continue;
                $chunkX = ((int)floor($spawnPos->getX())) >> 4;
                $chunkZ = ((int)floor($spawnPos->getZ())) >> 4;
                $world->loadChunk($chunkX, $chunkZ);
                $mob = MobSpawner::spawnSingleMob($type, $spawnPos, $health, $damage, $isBoss);
                if ($mob !== null) {
                    $totalSpawned++;
                    $session->incrementAliveMobs();
                }
            }
        }
        $session->broadcastMessage("§a{$totalSpawned} mobs spawneados!");
    }

    private function handleWaveComplete(RaidSession $session): void {
        $currentWave = $session->getCurrentWave();
        $session->setPaused(true);
        $session->broadcastTitle("§a§lOLEADA COMPLETADA!");
        $session->broadcastMessage("§7Siguiente oleada en §e" . self::WAVE_BREAK_TIME . "s§7...");
        $session->calculateScore();
        $waveManager = $this->plugin->getWaveManager();
        if ($waveManager->isLastWave($currentWave)) {
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($session): void {
                $this->endSession($session->getId(), "§a§l¡RAID COMPLETADO CON ÉXITO!");
            }), self::WAVE_BREAK_TIME * 20);
            return;
        }
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($session): void {
            $currentSession = $this->getSession($session->getId());
            if ($currentSession !== null) {
                $currentSession->setPaused(false);
                $this->startNextWave($currentSession);
            }
        }), self::WAVE_BREAK_TIME * 20);
    }

    private function handleRespawnQueue(RaidSession $session): void {
        foreach ($session->getPlayers() as $player) {
            if (!$player->isOnline() || $player->isAlive()) continue;
            if ($session->canRespawn($player)) {
                $session->respawnPlayer($player);
                $player->sendMessage("§a¡Has reaparecido!");
            } else {
                $time = $session->getRespawnTime($player);
                $player->sendTip("§cReapareciendo en: §e{$time}s");
            }
        }
    }

    private function updatePlayerDisplay(RaidSession $session): void {
        $stats = $session->getStats();
        $wave = $session->getCurrentWave();
        $alive = $session->getAliveMobs();
        $time = $session->getRemainingTime();
        foreach ($session->getPlayers() as $player) {
            $player->sendActionBarMessage("§eWave: §f$wave §7| §eVivos: §f$alive §7| §eTiempo: §f{$time}s");
        }
    }

    public function endSession(string $sessionId, string $reason = ""): void {
        $session = $this->getSession($sessionId);
        if ($session === null) return;
        $session->setActive(false);
        $session->calculateScore();
        if ($reason !== "") $session->broadcastTitle($reason);
        $stats = $session->getStats();
        $session->broadcastMessage("§7§m------------------------");
        $session->broadcastMessage("§e§lESTADÍSTICAS FINALES");
        $session->broadcastMessage("§7Oleadas alcanzadas: §f{$stats['wave']}");
        $session->broadcastMessage("§7Total kills: §f{$stats['kills']}");
        $session->broadcastMessage("§7Total muertes: §f{$stats['deaths']}");
        $session->broadcastMessage("§7Score final: §f{$stats['score']}");
        $session->broadcastMessage("§7§m------------------------");
        $this->plugin->getScoreManager()->saveSessionScore($session);
        $spawn = $this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation();
        foreach ($session->getPlayers() as $player) {
            if ($player->isOnline()) {
                $player->teleport($spawn);
                unset($this->playerSessions[$player->getName()]);
            }
        }
        $session->getArena()->setAvailable(true);
        unset($this->sessions[$sessionId]);
    }

    public function getSession(string $id): ?RaidSession {
        return $this->sessions[$id] ?? null;
    }

    public function getPlayerSession(Player $player): ?RaidSession {
        $sessionId = $this->playerSessions[$player->getName()] ?? null;
        return $sessionId !== null ? ($this->sessions[$sessionId] ?? null) : null;
    }

    public function isInRaid(Player $player): bool {
        return isset($this->playerSessions[$player->getName()]);
    }

    public function getAllSessions(): array {
        return $this->sessions;
    }

    public function getActiveSessionCount(): int {
        return count($this->sessions);
    }
}
