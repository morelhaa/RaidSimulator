<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\manager;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\wave\Wave;
use pocketmine\utils\Config;

class WaveManager {

    private RaidSimulator $plugin;
    /** @var Wave[] */
    private array $waves = [];
    private int $totalWaves = 0;

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
        $this->loadWaves();
    }

    private function loadWaves(): void {
        $config = new Config($this->plugin->getDataFolder() . "waves.yml", Config::YAML);
        $wavesData = $config->get("waves", []);

        if (empty($wavesData)) {
            $this->plugin->getLogger()->warning("§eNo hay oleadas configuradas. Creando ejemplo...");
            $this->createDefaultWaves();
            return;
        }

        foreach ($wavesData as $waveData) {
            $id = $waveData["id"] ?? 0;
            if ($id > 0) {
                $wave = Wave::fromArray($id, $waveData);
                $this->waves[$id] = $wave;
            }
        }

        $this->totalWaves = count($this->waves);
        $this->plugin->getLogger()->info("§aCargadas " . $this->totalWaves . " oleadas");
    }

    private function createDefaultWaves(): void {
        $defaultWaves = [
            ["id" => 1, "mobs" => [
                ["type" => "zombie", "amount" => 10, "health" => 20, "damage" => 2, "speed" => 1.0]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.0],

            ["id" => 2, "mobs" => [
                ["type" => "zombie", "amount" => 15, "health" => 20, "damage" => 2, "speed" => 1.0]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.0],

            ["id" => 3, "mobs" => [
                ["type" => "zombie", "amount" => 12, "health" => 20, "damage" => 2, "speed" => 1.0],
                ["type" => "skeleton", "amount" => 5, "health" => 15, "damage" => 2.5, "speed" => 1.0]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.1],

            ["id" => 4, "mobs" => [
                ["type" => "zombie", "amount" => 20, "health" => 20, "damage" => 2, "speed" => 1.0],
                ["type" => "spider", "amount" => 3, "health" => 18, "damage" => 2.0, "speed" => 1.2]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.2],

            ["id" => 5, "mobs" => [
                ["type" => "zombie", "amount" => 15, "health" => 25, "damage" => 3, "speed" => 1.0],
                ["type" => "zombie_boss", "amount" => 1, "health" => 100, "damage" => 5, "speed" => 0.8, "boss" => true]
            ], "boss" => true, "max_duration" => 120, "difficulty" => 1.5],

            ["id" => 6, "mobs" => [
                ["type" => "zombie", "amount" => 25, "health" => 22, "damage" => 2.5, "speed" => 1.0],
                ["type" => "skeleton", "amount" => 8, "health" => 16, "damage" => 3, "speed" => 1.0]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.3],

            ["id" => 7, "mobs" => [
                ["type" => "zombie", "amount" => 20, "health" => 24, "damage" => 3, "speed" => 1.1],
                ["type" => "spider", "amount" => 6, "health" => 20, "damage" => 2.5, "speed" => 1.3],
                ["type" => "creeper", "amount" => 3, "health" => 15, "damage" => 4, "speed" => 1.0]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.4],

            ["id" => 8, "mobs" => [
                ["type" => "skeleton", "amount" => 15, "health" => 18, "damage" => 3.5, "speed" => 1.0],
                ["type" => "zombie", "amount" => 15, "health" => 25, "damage" => 3, "speed" => 1.1]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.5],

            ["id" => 9, "mobs" => [
                ["type" => "zombie", "amount" => 30, "health" => 26, "damage" => 3.5, "speed" => 1.2],
                ["type" => "spider", "amount" => 8, "health" => 22, "damage" => 3, "speed" => 1.4]
            ], "boss" => false, "max_duration" => 90, "difficulty" => 1.6],

            ["id" => 10, "mobs" => [
                ["type" => "skeleton", "amount" => 20, "health" => 20, "damage" => 4, "speed" => 1.0],
                ["type" => "skeleton_boss", "amount" => 1, "health" => 150, "damage" => 6, "speed" => 0.9, "boss" => true]
            ], "boss" => true, "max_duration" => 120, "difficulty" => 1.8]
        ];

        $config = new Config($this->plugin->getDataFolder() . "waves.yml", Config::YAML);
        $config->set("waves", $defaultWaves);
        $config->save();

        $this->loadWaves();
    }

    public function getWave(int $id): ?Wave {
        return $this->waves[$id] ?? null;
    }

    public function getAllWaves(): array {
        return $this->waves;
    }

    public function getTotalWaves(): int {
        return $this->totalWaves;
    }

    public function waveExists(int $id): bool {
        return isset($this->waves[$id]);
    }

    public function getNextWave(int $currentWave): ?Wave {
        $nextId = $currentWave + 1;
        return $this->waves[$nextId] ?? null;
    }

    public function isLastWave(int $waveId): bool {
        return $waveId >= $this->totalWaves;
    }

    public function getBossWaves(): array {
        $bossWaves = [];
        foreach ($this->waves as $wave) {
            if ($wave->hasBoss()) {
                $bossWaves[] = $wave;
            }
        }
        return $bossWaves;
    }
}