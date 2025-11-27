<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\manager;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\arena\Arena;
use pocketmine\utils\Config;

class ArenaManager {

    private RaidSimulator $plugin;
    /** @var Arena[] */
    private array $arenas = [];

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
        $this->loadArenas();
    }

    private function loadArenas(): void {
        $arenaFolder = $this->plugin->getDataFolder() . "arenas/";

        if (!is_dir($arenaFolder)) {
            $this->plugin->getLogger()->warning("No se encontró la carpeta de arenas");
            return;
        }

        $files = scandir($arenaFolder);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === "yml") {
                $arenaName = pathinfo($file, PATHINFO_FILENAME);
                $config = new Config($arenaFolder . $file, Config::YAML);

                $arena = Arena::fromArray($arenaName, $config->getAll());
                if ($arena !== null) {
                    $this->arenas[$arenaName] = $arena;
                    $this->plugin->getLogger()->info("§aArena cargada: " . $arenaName);
                } else {
                    $this->plugin->getLogger()->error("§cError al cargar arena: " . $arenaName);
                }
            }
        }

        if (empty($this->arenas)) {
            $this->plugin->getLogger()->warning("§eNo se cargaron arenas. Creando ejemplo...");
            $this->createExampleArena();
        }
    }

    private function createExampleArena(): void {
        $exampleData = [
            "world" => "world",
            "spawn" => "100:64:100",
            "center" => "100:64:100",
            "mob-spawns" => [
                "110:64:100",
                "90:64:100",
                "100:64:110",
                "100:64:90",
                "105:64:105",
                "95:64:95"
            ],
            "max-players" => 10
        ];

        $config = new Config($this->plugin->getDataFolder() . "arenas/example.yml", Config::YAML, $exampleData);
        $config->save();

        $arena = Arena::fromArray("example", $exampleData);
        if ($arena !== null) {
            $this->arenas["example"] = $arena;
            $this->plugin->getLogger()->info("§aArena de ejemplo creada!");
        }
    }

    public function getArena(string $name): ?Arena {
        return $this->arenas[$name] ?? null;
    }

    public function getAvailableArena(): ?Arena {
        foreach ($this->arenas as $arena) {
            if ($arena->isAvailable()) {
                return $arena;
            }
        }
        return null;
    }

    public function getAllArenas(): array {
        return $this->arenas;
    }

    public function arenaExists(string $name): bool {
        return isset($this->arenas[$name]);
    }

    public function addArena(Arena $arena): void {
        $this->arenas[$arena->getName()] = $arena;
        $this->saveArena($arena);
    }

    public function removeArena(string $name): bool {
        if (!isset($this->arenas[$name])) {
            return false;
        }

        unset($this->arenas[$name]);
        $file = $this->plugin->getDataFolder() . "arenas/" . $name . ".yml";
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }

    private function saveArena(Arena $arena): void {
        $config = new Config(
            $this->plugin->getDataFolder() . "arenas/" . $arena->getName() . ".yml",
            Config::YAML,
            $arena->toArray()
        );
        $config->save();
    }

    public function getArenaCount(): int {
        return count($this->arenas);
    }

    public function getAvailableArenaCount(): int {
        $count = 0;
        foreach ($this->arenas as $arena) {
            if ($arena->isAvailable()) {
                $count++;
            }
        }
        return $count;
    }
}