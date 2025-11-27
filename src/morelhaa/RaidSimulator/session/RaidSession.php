<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\session;

use morelhaa\RaidSimulator\arena\Arena;
use morelhaa\RaidSimulator\wave\Wave;
use pocketmine\player\Player;

class RaidSession {

    private string $id;
    private Arena $arena;
    /** @var Player[] */
    private array $players = [];
    private int $currentWave = 0;
    private int $startTime;
    private int $totalTime;
    private int $waveStartTime = 0;
    private bool $active = false;
    private bool $paused = false;

    // Estadísticas
    private int $totalKills = 0;
    private int $totalDeaths = 0;
    private array $playerDeaths = [];
    private array $playerKills = [];
    private int $score = 0;
    private int $aliveMobs = 0;

    private int $respawnCooldown = 5;
    private array $respawnQueue = [];

    public function __construct(string $id, Arena $arena, array $players, int $totalTime = 1800) {
        $this->id = $id;
        $this->arena = $arena;
        $this->players = $players;
        $this->startTime = time();
        $this->totalTime = $totalTime;

        foreach ($players as $player) {
            $playerName = $player->getName();
            $this->playerDeaths[$playerName] = 0;
            $this->playerKills[$playerName] = 0;
        }
    }

    public function getId(): string {
        return $this->id;
    }

    public function getArena(): Arena {
        return $this->arena;
    }

    public function getPlayers(): array {
        return $this->players;
    }

    public function hasPlayer(Player $player): bool {
        return in_array($player, $this->players, true);
    }

    public function addPlayer(Player $player): void {
        if (!$this->hasPlayer($player)) {
            $this->players[] = $player;
            $playerName = $player->getName();
            $this->playerDeaths[$playerName] = 0;
            $this->playerKills[$playerName] = 0;
        }
    }

    public function removePlayer(Player $player): void {
        $key = array_search($player, $this->players, true);
        if ($key !== false) {
            unset($this->players[$key]);
            $this->players = array_values($this->players);
        }
    }

    public function getPlayerCount(): int {
        return count($this->players);
    }

    public function getCurrentWave(): int {
        return $this->currentWave;
    }

    public function setCurrentWave(int $wave): void {
        $this->currentWave = $wave;
        $this->waveStartTime = time();
    }

    public function nextWave(): void {
        $this->currentWave++;
        $this->waveStartTime = time();
        $this->aliveMobs = 0;
    }

    public function getWaveStartTime(): int {
        return $this->waveStartTime;
    }

    public function getWaveElapsedTime(): int {
        return time() - $this->waveStartTime;
    }

    public function isActive(): bool {
        return $this->active;
    }

    public function setActive(bool $active): void {
        $this->active = $active;
    }

    public function isPaused(): bool {
        return $this->paused;
    }

    public function setPaused(bool $paused): void {
        $this->paused = $paused;
    }

    public function getElapsedTime(): int {
        return time() - $this->startTime;
    }

    public function getRemainingTime(): int {
        return max(0, $this->totalTime - $this->getElapsedTime());
    }

    public function hasTimeExpired(): bool {
        return $this->getRemainingTime() <= 0;
    }

    public function addKill(Player $player): void {
        $this->totalKills++;
        $playerName = $player->getName();
        if (isset($this->playerKills[$playerName])) {
            $this->playerKills[$playerName]++;
        }
    }

    public function addDeath(Player $player): void {
        $this->totalDeaths++;
        $playerName = $player->getName();
        if (isset($this->playerDeaths[$playerName])) {
            $this->playerDeaths[$playerName]++;
        }

        $this->respawnQueue[$playerName] = time() + $this->respawnCooldown;
    }

    public function getTotalKills(): int {
        return $this->totalKills;
    }

    public function getTotalDeaths(): int {
        return $this->totalDeaths;
    }

    public function getPlayerKills(string $playerName): int {
        return $this->playerKills[$playerName] ?? 0;
    }

    public function getPlayerDeaths(string $playerName): int {
        return $this->playerDeaths[$playerName] ?? 0;
    }

    public function getScore(): int {
        return $this->score;
    }

    public function calculateScore(): void {
        $this->score = ($this->currentWave * 100)
            + ($this->totalKills * 5)
            + ($this->getRemainingTime() * 2)
            - ($this->totalDeaths * 20);

        if ($this->score < 0) {
            $this->score = 0;
        }
    }

    public function getAliveMobs(): int {
        return $this->aliveMobs;
    }

    public function setAliveMobs(int $count): void {
        $this->aliveMobs = $count;
    }

    public function incrementAliveMobs(): void {
        $this->aliveMobs++;
    }

    public function decrementAliveMobs(): void {
        $this->aliveMobs--;
        if ($this->aliveMobs < 0) {
            $this->aliveMobs = 0;
        }
    }

    public function isWaveComplete(): bool {
        return $this->aliveMobs <= 0;
    }

    public function canRespawn(Player $player): bool {
        $playerName = $player->getName();
        if (!isset($this->respawnQueue[$playerName])) {
            return true;
        }
        return time() >= $this->respawnQueue[$playerName];
    }

    public function getRespawnTime(Player $player): int {
        $playerName = $player->getName();
        if (!isset($this->respawnQueue[$playerName])) {
            return 0;
        }
        return max(0, $this->respawnQueue[$playerName] - time());
    }

    public function respawnPlayer(Player $player): void {
        $playerName = $player->getName();
        if (isset($this->respawnQueue[$playerName])) {
            unset($this->respawnQueue[$playerName]);
        }

        // Teleportar al spawn
        $player->teleport($this->arena->getSpawnPoint());
        $player->setHealth($player->getMaxHealth());
        $player->getHungerManager()->setFood($player->getHungerManager()->getMaxFood());
    }

    public function getStats(): array {
        return [
            "wave" => $this->currentWave,
            "kills" => $this->totalKills,
            "deaths" => $this->totalDeaths,
            "score" => $this->score,
            "elapsed_time" => $this->getElapsedTime(),
            "remaining_time" => $this->getRemainingTime(),
            "players" => count($this->players),
            "alive_mobs" => $this->aliveMobs
        ];
    }

    public function broadcastMessage(string $message): void {
        foreach ($this->players as $player) {
            if ($player->isOnline()) {
                $player->sendMessage($message);
            }
        }
    }

    public function broadcastTitle(string $title, string $subtitle = ""): void {
        foreach ($this->players as $player) {
            if ($player->isOnline()) {
                $player->sendTitle($title, $subtitle);
            }
        }
    }
}
