<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\manager;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\session\RaidSession;
use pocketmine\utils\Config;
use pocketmine\player\Player;

class ScoreManager {

    private RaidSimulator $plugin;
    private Config $scoresConfig;
    private Config $rankingConfig;

    private const BASE_ELO = 1000;
    private const ELO_FIRST_PLACE = 20;
    private const ELO_SECOND_PLACE = 10;
    private const ELO_THIRD_PLACE = 5;

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
        $this->scoresConfig = new Config($this->plugin->getDataFolder() . "scores.yml", Config::YAML);
        $this->rankingConfig = new Config($this->plugin->getDataFolder() . "ranking.yml", Config::YAML);
    }

    public function saveSessionScore(RaidSession $session): void {
        $stats = $session->getStats();
        $players = $session->getPlayers();

        $sessionData = [
            "date" => date("Y-m-d H:i:s"),
            "wave" => $stats["wave"],
            "kills" => $stats["kills"],
            "deaths" => $stats["deaths"],
            "score" => $stats["score"],
            "elapsed_time" => $stats["elapsed_time"],
            "players" => []
        ];

        foreach ($players as $player) {
            $playerName = $player->getName();

            $playerStats = [
                "kills" => $session->getPlayerKills($playerName),
                "deaths" => $session->getPlayerDeaths($playerName)
            ];

            $sessionData["players"][$playerName] = $playerStats;
            $this->updatePlayerStats($playerName, $stats["wave"], $stats["score"], $playerStats["kills"], $playerStats["deaths"]);
        }

        $sessions = $this->scoresConfig->get("sessions", []);
        $sessions[] = $sessionData;
        $this->scoresConfig->set("sessions", $sessions);
        $this->scoresConfig->save();

        $this->updateRanking($session);
    }

    private function updatePlayerStats(string $playerName, int $wave, int $score, int $kills, int $deaths): void {
        $playerData = $this->scoresConfig->get("players." . $playerName, [
            "total_raids" => 0,
            "best_wave" => 0,
            "best_score" => 0,
            "total_kills" => 0,
            "total_deaths" => 0,
            "elo" => self::BASE_ELO
        ]);

        $playerData["total_raids"]++;
        $playerData["total_kills"] += $kills;
        $playerData["total_deaths"] += $deaths;

        if ($wave > ($playerData["best_wave"] ?? 0)) {
            $playerData["best_wave"] = $wave;
        }

        if ($score > ($playerData["best_score"] ?? 0)) {
            $playerData["best_score"] = $score;
        }

        $this->scoresConfig->set("players." . $playerName, $playerData);
        $this->scoresConfig->save();
    }

    private function updateRanking(RaidSession $session): void {
        $stats = $session->getStats();
        $score = $stats["score"];
        $wave = $stats["wave"];

        $ranking = $this->rankingConfig->get("leaderboard", []);

        $entry = [
            "score" => $score,
            "wave" => $wave,
            "date" => date("Y-m-d H:i:s"),
            "players" => []
        ];

        foreach ($session->getPlayers() as $player) {
            $entry["players"][] = $player->getName();
        }

        $ranking[] = $entry;

        usort($ranking, function($a, $b) {
            if ($a["score"] === $b["score"]) {
                return $b["wave"] <=> $a["wave"];
            }
            return $b["score"] <=> $a["score"];
        });

        $ranking = array_slice($ranking, 0, 100);

        $this->updateELO($ranking);

        $this->rankingConfig->set("leaderboard", $ranking);
        $this->rankingConfig->set("last_update", date("Y-m-d H:i:s"));
        $this->rankingConfig->save();
    }

    private function updateELO(array $ranking): void {
        for ($i = 0; $i < min(3, count($ranking)); $i++) {
            $entry = $ranking[$i];
            $eloGain = 0;

            switch ($i) {
                case 0:
                    $eloGain = self::ELO_FIRST_PLACE;
                    break;
                case 1:
                    $eloGain = self::ELO_SECOND_PLACE;
                    break;
                case 2:
                    $eloGain = self::ELO_THIRD_PLACE;
                    break;
            }

            foreach ($entry["players"] as $playerName) {
                $this->addELO($playerName, $eloGain);
            }
        }
    }

    private function addELO(string $playerName, int $amount): void {
        $playerData = $this->scoresConfig->get("players." . $playerName, [
            "elo" => self::BASE_ELO
        ]);

        $playerData["elo"] = ($playerData["elo"] ?? self::BASE_ELO) + $amount;

        $this->scoresConfig->set("players." . $playerName, $playerData);
        $this->scoresConfig->save();
    }

    public function getPlayerStats(string $playerName): ?array {
        $stats = $this->scoresConfig->get("players." . $playerName);
        return is_array($stats) ? $stats : null;
    }

    public function getPlayerELO(string $playerName): int {
        $stats = $this->getPlayerStats($playerName);
        if ($stats === null) {
            return self::BASE_ELO;
        }
        return $stats["elo"] ?? self::BASE_ELO;
    }

    public function getLeaderboard(int $limit = 10): array {
        $ranking = $this->rankingConfig->get("leaderboard", []);
        return array_slice($ranking, 0, $limit);
    }

    public function getELOLeaderboard(int $limit = 10): array {
        $players = $this->scoresConfig->get("players", []);

        $eloList = [];
        foreach ($players as $name => $data) {
            $eloList[] = [
                "name" => $name,
                "elo" => $data["elo"] ?? self::BASE_ELO,
                "best_wave" => $data["best_wave"] ?? 0,
                "total_raids" => $data["total_raids"] ?? 0
            ];
        }

        usort($eloList, function($a, $b) {
            return $b["elo"] <=> $a["elo"];
        });

        return array_slice($eloList, 0, $limit);
    }

    public function getPlayerRank(string $playerName): int {
        $eloList = $this->getELOLeaderboard(999);

        foreach ($eloList as $index => $player) {
            if ($player["name"] === $playerName) {
                return $index + 1;
            }
        }

        return 0;
    }

    public function formatStats(string $playerName): array {
        $stats = $this->getPlayerStats($playerName);

        if ($stats === null) {
            return [
                "§cNo se encontraron estadísticas para este jugador"
            ];
        }

        $rank = $this->getPlayerRank($playerName);
        $rankText = $rank > 0 ? "#" . $rank : "Sin ranking";

        return [
            "§7§m------------------------",
            "§e§lESTADÍSTICAS DE §f" . strtoupper($playerName),
            "§7§m------------------------",
            "§7Ranking: §f{$rankText}",
            "§7ELO: §f{$stats['elo']}",
            "§7Raids completados: §f{$stats['total_raids']}",
            "§7Mejor oleada: §f{$stats['best_wave']}",
            "§7Mejor score: §f{$stats['best_score']}",
            "§7Total kills: §f{$stats['total_kills']}",
            "§7Total muertes: §f{$stats['total_deaths']}",
            "§7K/D Ratio: §f" . $this->calculateKD($stats['total_kills'], $stats['total_deaths']),
            "§7§m------------------------"
        ];
    }

    public function formatLeaderboard(): array {
        $leaderboard = $this->getLeaderboard(10);

        if (empty($leaderboard)) {
            return ["§cNo hay datos en el ranking aún"];
        }

        $lines = [
            "§7§m------------------------",
            "§e§lTOP 10 RAIDS - SCORE",
            "§7§m------------------------"
        ];

        foreach ($leaderboard as $index => $entry) {
            $position = $index + 1;
            $medal = $this->getMedal($position);
            $players = implode(", ", $entry["players"]);

            $lines[] = "§f{$medal} §7Score: §f{$entry['score']} §7| Oleada: §f{$entry['wave']}";
            $lines[] = "§7   Jugadores: §f{$players}";
        }

        $lines[] = "§7§m------------------------";
        return $lines;
    }

    public function formatELOLeaderboard(): array {
        $leaderboard = $this->getELOLeaderboard(10);

        if (empty($leaderboard)) {
            return ["§cNo hay datos en el ranking aún"];
        }

        $lines = [
            "§7§m------------------------",
            "§e§lTOP 10 JUGADORES - ELO",
            "§7§m------------------------"
        ];

        foreach ($leaderboard as $index => $player) {
            $position = $index + 1;
            $medal = $this->getMedal($position);

            $lines[] = "§f{$medal} {$player['name']} §7- ELO: §f{$player['elo']}";
            $lines[] = "§7   Raids: §f{$player['total_raids']} §7| Mejor oleada: §f{$player['best_wave']}";
        }

        $lines[] = "§7§m------------------------";
        return $lines;
    }

    private function getMedal(int $position): string {
        return match($position) {
            1 => "§6#1",
            2 => "§7#2",
            3 => "§c#3",
            default => "§f#{$position}"
        };
    }

    private function calculateKD(int $kills, int $deaths): string {
        if ($deaths === 0) {
            return $kills > 0 ? "∞" : "0.00";
        }
        return number_format($kills / $deaths, 2);
    }

    public function resetPlayerStats(string $playerName): void {
        $this->scoresConfig->remove("players." . $playerName);
        $this->scoresConfig->save();
    }

    public function resetAllStats(): void {
        $this->scoresConfig->set("players", []);
        $this->scoresConfig->set("sessions", []);
        $this->scoresConfig->save();

        $this->rankingConfig->set("leaderboard", []);
        $this->rankingConfig->save();
    }
}
