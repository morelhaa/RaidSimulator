<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\command;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\arena\Arena;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;

class RaidCommand extends Command implements PluginOwned {

    private RaidSimulator $plugin;

    public function __construct(RaidSimulator $plugin) {
        parent::__construct("raid", "Comando principal del Raid Simulator", "/raid <join|leave|stats|ranking|help>", ["rs"]);
        $this->setPermission("raid.command");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cEste comando solo puede ser usado por jugadores");
            return false;
        }

        if (!$this->testPermission($sender)) {
            return false;
        }

        if (empty($args)) {
            $this->sendHelp($sender);
            return true;
        }

        $subCommand = strtolower($args[0]);

        switch ($subCommand) {
            case "join":
                $this->handleJoin($sender);
                break;

            case "leave":
            case "quit":
                $this->handleLeave($sender);
                break;

            case "stats":
                $this->handleStats($sender, $args);
                break;

            case "ranking":
            case "top":
            case "leaderboard":
                $this->handleRanking($sender, $args);
                break;

            case "help":
            case "ayuda":
                $this->sendHelp($sender);
                break;

            // Comandos de administración
            case "create":
                if (!$sender->hasPermission("raid.admin")) {
                    $sender->sendMessage("§cNo tienes permiso para usar este comando");
                    return false;
                }
                $this->handleCreate($sender, $args);
                break;

            case "delete":
                if (!$sender->hasPermission("raid.admin")) {
                    $sender->sendMessage("§cNo tienes permiso para usar este comando");
                    return false;
                }
                $this->handleDelete($sender, $args);
                break;

            case "list":
                if (!$sender->hasPermission("raid.admin")) {
                    $sender->sendMessage("§cNo tienes permiso para usar este comando");
                    return false;
                }
                $this->handleList($sender);
                break;

            case "reset":
                if (!$sender->hasPermission("raid.admin")) {
                    $sender->sendMessage("§cNo tienes permiso para usar este comando");
                    return false;
                }
                $this->handleReset($sender, $args);
                break;

            default:
                $this->sendHelp($sender);
                break;
        }

        return true;
    }

    private function handleJoin(Player $player): void {
        $raidManager = $this->plugin->getRaidManager();

        if ($raidManager->isInRaid($player)) {
            $player->sendMessage("§c¡Ya estás en un raid!");
            return;
        }

        $arena = $this->plugin->getArenaManager()->getAvailableArena();
        if ($arena === null) {
            $player->sendMessage("§cNo hay arenas disponibles en este momento");
            $player->sendMessage("§7Intenta de nuevo más tarde");
            return;
        }

        $players = [$player];
        $session = $raidManager->createSession($arena, $players);

        if ($session === null) {
            $player->sendMessage("§cError al crear la sesión del raid");
            return;
        }

        $player->sendMessage("§a§l¡Te has unido al Raid Simulator!");
        $player->sendMessage("§7Arena: §f" . $arena->getName());
        $player->sendMessage("§7Oleadas totales: §f" . $this->plugin->getWaveManager()->getTotalWaves());
        $player->sendMessage("§7¡Prepárate para la batalla!");
    }

    private function handleLeave(Player $player): void {
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            $player->sendMessage("§cNo estás en ningún raid");
            return;
        }

        $session->removePlayer($player);
        $player->sendMessage("§e¡Has salido del raid!");

        $spawn = $this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation();
        $player->teleport($spawn);

        if ($session->getPlayerCount() === 0) {
            $raidManager->endSession($session->getId(), "§cTodos los jugadores han salido");
        } else {
            $session->broadcastMessage("§e" . $player->getName() . " §7ha salido del raid");
        }
    }

    private function handleStats(Player $player, array $args): void {
        $scoreManager = $this->plugin->getScoreManager();

        // Si especifica un jugador (y tiene permiso)
        if (isset($args[1]) && $player->hasPermission("raid.admin")) {
            $targetName = $args[1];
        } else {
            $targetName = $player->getName();
        }

        $stats = $scoreManager->formatStats($targetName);

        foreach ($stats as $line) {
            $player->sendMessage($line);
        }
    }

    private function handleRanking(Player $player, array $args): void {
        $scoreManager = $this->plugin->getScoreManager();

        $type = $args[1] ?? "score";

        if (strtolower($type) === "elo") {
            $ranking = $scoreManager->formatELOLeaderboard();
        } else {
            $ranking = $scoreManager->formatLeaderboard();
        }

        foreach ($ranking as $line) {
            $player->sendMessage($line);
        }

        $player->sendMessage("§7Usa §f/raid ranking elo §7para ver el ranking de ELO");
    }

    private function handleCreate(Player $player, array $args): void {
        if (!isset($args[1])) {
            $player->sendMessage("§cUso: /raid create <nombre>");
            return;
        }

        $arenaName = $args[1];
        $arenaManager = $this->plugin->getArenaManager();

        if ($arenaManager->arenaExists($arenaName)) {
            $player->sendMessage("§cYa existe una arena con ese nombre");
            return;
        }

        $pos = $player->getPosition();
        $arena = new Arena(
            $arenaName,
            $pos->getWorld()->getFolderName(),
            $pos,
            $pos,
            [$pos],
            10
        );

        $arenaManager->addArena($arena);
        $player->sendMessage("§aArena §f{$arenaName} §acreada correctamente!");
        $player->sendMessage("§7Edita el archivo §farenas/{$arenaName}.yml §7para configurarla");
    }

    private function handleDelete(Player $player, array $args): void {
        if (!isset($args[1])) {
            $player->sendMessage("§cUso: /raid delete <nombre>");
            return;
        }

        $arenaName = $args[1];
        $arenaManager = $this->plugin->getArenaManager();

        if (!$arenaManager->arenaExists($arenaName)) {
            $player->sendMessage("§cNo existe una arena con ese nombre");
            return;
        }

        $arenaManager->removeArena($arenaName);
        $player->sendMessage("§aArena §f{$arenaName} §aeliminada correctamente!");
    }

    private function handleList(Player $player): void {
        $arenaManager = $this->plugin->getArenaManager();
        $arenas = $arenaManager->getAllArenas();

        if (empty($arenas)) {
            $player->sendMessage("§cNo hay arenas registradas");
            return;
        }

        $player->sendMessage("§7§m------------------------");
        $player->sendMessage("§e§lARENAS DISPONIBLES");
        $player->sendMessage("§7§m------------------------");

        foreach ($arenas as $arena) {
            $status = $arena->isAvailable() ? "§a✔ Disponible" : "§c✘ En uso";
            $player->sendMessage("§f" . $arena->getName() . " §7- " . $status);
            $player->sendMessage("§7  Mundo: §f" . $arena->getWorldName());
            $player->sendMessage("§7  Max jugadores: §f" . $arena->getMaxPlayers());
        }

        $player->sendMessage("§7§m------------------------");
        $player->sendMessage("§7Total: §f" . count($arenas) . " arenas");
    }

    private function handleReset(Player $player, array $args): void {
        if (!isset($args[1])) {
            $player->sendMessage("§cUso: /raid reset <all|player <nombre>>");
            return;
        }

        $scoreManager = $this->plugin->getScoreManager();

        if (strtolower($args[1]) === "all") {
            $player->sendMessage("§eReiniciando todas las estadísticas...");
            $scoreManager->resetAllStats();
            $player->sendMessage("§a¡Todas las estadísticas han sido reiniciadas!");
        } elseif (strtolower($args[1]) === "player" && isset($args[2])) {
            $targetName = $args[2];
            $scoreManager->resetPlayerStats($targetName);
            $player->sendMessage("§aEstadísticas de §f{$targetName} §areiniciadas!");
        } else {
            $player->sendMessage("§cUso: /raid reset <all|player <nombre>>");
        }
    }

    private function sendHelp(Player $player): void {
        $player->sendMessage("§7§m------------------------");
        $player->sendMessage("§e§lRAID SIMULATOR - AYUDA");
        $player->sendMessage("§7§m------------------------");
        $player->sendMessage("§f/raid join §7- Unirse a un raid");
        $player->sendMessage("§f/raid leave §7- Salir del raid actual");
        $player->sendMessage("§f/raid stats [jugador] §7- Ver estadísticas");
        $player->sendMessage("§f/raid ranking [score|elo] §7- Ver ranking");
        $player->sendMessage("§f/raid help §7- Mostrar esta ayuda");

        if ($player->hasPermission("raid.admin")) {
            $player->sendMessage("");
            $player->sendMessage("§c§lCOMANDOS DE ADMIN:");
            $player->sendMessage("§f/raid create <nombre> §7- Crear arena");
            $player->sendMessage("§f/raid delete <nombre> §7- Eliminar arena");
            $player->sendMessage("§f/raid list §7- Listar arenas");
            $player->sendMessage("§f/raid reset <all|player> §7- Resetear stats");
        }

        $player->sendMessage("§7§m------------------------");
    }

    public function getOwningPlugin(): Plugin {
        return $this->plugin;
    }
}