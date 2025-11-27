<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\wave;

class Wave {

    private int $id;
    private array $mobs = [];
    private bool $hasBoss;
    private int $maxDuration;
    private float $difficulty;

    public function __construct(int $id, array $mobs, bool $hasBoss = false, int $maxDuration = 90, float $difficulty = 1.0) {
        $this->id = $id;
        $this->mobs = $mobs;
        $this->hasBoss = $hasBoss;
        $this->maxDuration = $maxDuration;
        $this->difficulty = $difficulty;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getMobs(): array {
        return $this->mobs;
    }
    public function getScaledMobs(): array {
        $scaled = [];

        foreach ($this->mobs as $mob) {
            $mobData = [
                "type" => $mob["type"],
                "amount" => $mob["amount"],
                "health" => $this->calculateHealth($mob["base_health"] ?? 20.0),
                "damage" => $this->calculateDamage($mob["base_damage"] ?? 2.0),
                "speed" => $this->calculateSpeed($mob["base_speed"] ?? 1.0),
                "is_boss" => $mob["is_boss"] ?? false
            ];

            $scaled[] = $mobData;
        }

        return $scaled;
    }

    private function calculateHealth(float $baseHealth): float {
        return $baseHealth * (1 + ($this->id * 0.15)) * $this->difficulty;
    }

    private function calculateDamage(float $baseDamage): float {
        return $baseDamage * (1 + ($this->id * 0.1)) * $this->difficulty;
    }

    private function calculateSpeed(float $baseSpeed): float {
        $speedBonus = 0.0;
        if ($this->id >= 30) {
            $speedBonus = 0.3;
        } elseif ($this->id >= 20) {
            $speedBonus = 0.2;
        } elseif ($this->id >= 10) {
            $speedBonus = 0.1;
        }

        return $baseSpeed * (1 + $speedBonus);
    }

    public function hasBoss(): bool {
        return $this->hasBoss;
    }

    public function getMaxDuration(): int {
        return $this->maxDuration;
    }

    public function getDifficulty(): float {
        return $this->difficulty;
    }

    public function getTotalMobCount(): int {
        $total = 0;
        foreach ($this->mobs as $mob) {
            $total += $mob["amount"];
        }
        return $total;
    }

    public static function fromArray(int $id, array $data): self {
        $mobs = [];

        if (isset($data["mobs"]) && is_array($data["mobs"])) {
            foreach ($data["mobs"] as $mobData) {
                $mobs[] = [
                    "type" => $mobData["type"] ?? "zombie",
                    "amount" => $mobData["amount"] ?? 10,
                    "base_health" => $mobData["health"] ?? 20.0,
                    "base_damage" => $mobData["damage"] ?? 2.0,
                    "base_speed" => $mobData["speed"] ?? 1.0,
                    "is_boss" => $mobData["boss"] ?? false
                ];
            }
        }

        return new self(
            $id,
            $mobs,
            $data["boss"] ?? false,
            $data["max_duration"] ?? 90,
            $data["difficulty"] ?? 1.0
        );
    }

    public function toArray(): array {
        return [
            "id" => $this->id,
            "mobs" => $this->mobs,
            "boss" => $this->hasBoss,
            "max_duration" => $this->maxDuration,
            "difficulty" => $this->difficulty
        ];
    }
}