<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\arena;

use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\Server;

class Arena {

    private string $name;
    private string $worldName;
    private Position $spawnPoint;
    private Position $centerPoint;
    private array $mobSpawnPoints = [];
    private int $maxPlayers;
    private bool $available = true;

    public function __construct(
        string $name,
        string $worldName,
        Position $spawnPoint,
        Position $centerPoint,
        array $mobSpawnPoints,
        int $maxPlayers = 10
    ) {
        $this->name = $name;
        $this->worldName = $worldName;
        $this->spawnPoint = $spawnPoint;
        $this->centerPoint = $centerPoint;
        $this->mobSpawnPoints = $mobSpawnPoints;
        $this->maxPlayers = $maxPlayers;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getWorldName(): string {
        return $this->worldName;
    }

    public function getWorld(): ?World {
        return Server::getInstance()->getWorldManager()->getWorldByName($this->worldName);
    }

    public function getSpawnPoint(): Position {
        return $this->spawnPoint;
    }

    public function getCenterPoint(): Position {
        return $this->centerPoint;
    }

    public function getMobSpawnPoints(): array {
        return $this->mobSpawnPoints;
    }

    public function getRandomMobSpawnPoint(): Position {
        return $this->mobSpawnPoints[array_rand($this->mobSpawnPoints)];
    }

    public function getMaxPlayers(): int {
        return $this->maxPlayers;
    }

    public function isAvailable(): bool {
        return $this->available;
    }

    public function setAvailable(bool $available): void {
        $this->available = $available;
    }

    public function isInArena(Position $position): bool {
        if ($position->getWorld() === null) {
            return false;
        }

        if ($position->getWorld()->getFolderName() !== $this->worldName) {
            return false;
        }

        $radius = 100;
        $distance = $this->centerPoint->distance($position);

        return $distance <= $radius;
    }

    public static function fromArray(string $name, array $data): ?self {
        if (!isset($data["world"], $data["spawn"], $data["center"], $data["mob-spawns"])) {
            return null;
        }

        $worldName = $data["world"];

        $spawn = explode(":", $data["spawn"]);
        $spawnPoint = new Position((float)$spawn[0], (float)$spawn[1], (float)$spawn[2],
            Server::getInstance()->getWorldManager()->getWorldByName($worldName));

        $center = explode(":", $data["center"]);
        $centerPoint = new Position((float)$center[0], (float)$center[1], (float)$center[2],
            Server::getInstance()->getWorldManager()->getWorldByName($worldName));

        $mobSpawns = [];
        foreach ($data["mob-spawns"] as $mobSpawnStr) {
            $coords = explode(":", $mobSpawnStr);
            $mobSpawns[] = new Position((float)$coords[0], (float)$coords[1], (float)$coords[2],
                Server::getInstance()->getWorldManager()->getWorldByName($worldName));
        }

        $maxPlayers = $data["max-players"] ?? 10;

        return new self($name, $worldName, $spawnPoint, $centerPoint, $mobSpawns, $maxPlayers);
    }

    public function toArray(): array {
        $mobSpawnsArray = [];
        foreach ($this->mobSpawnPoints as $pos) {
            $mobSpawnsArray[] = $pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ();
        }

        return [
            "world" => $this->worldName,
            "spawn" => $this->spawnPoint->getX() . ":" . $this->spawnPoint->getY() . ":" . $this->spawnPoint->getZ(),
            "center" => $this->centerPoint->getX() . ":" . $this->centerPoint->getY() . ":" . $this->centerPoint->getZ(),
            "mob-spawns" => $mobSpawnsArray,
            "max-players" => $this->maxPlayers
        ];
    }
}