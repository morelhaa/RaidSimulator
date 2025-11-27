<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\listener;

use morelhaa\RaidSimulator\RaidSimulator;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;

class PlayerListener implements Listener {

    private RaidSimulator $plugin;

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        // Remover del raid
        $session->removePlayer($player);
        $session->broadcastMessage("§e" . $player->getName() . " §7se ha desconectado");

        // Si no quedan jugadores, terminar sesión
        if ($session->getPlayerCount() === 0) {
            $raidManager->endSession($session->getId(), "§cTodos los jugadores se desconectaron");
        }
    }

    public function onDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        $session->addDeath($player);

        $cause = $player->getLastDamageCause();
        $killer = "desconocido";

        if ($cause instanceof EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager instanceof Player) {
                $killer = $damager->getName();
            } else {
                $killer = "un mob";
            }
        }

        $session->broadcastMessage("§cX " . $player->getName() . " §7fue asesinado por §f{$killer}");

        $event->setDrops([]);
        $event->setXpDropAmount(0);

        $player->sendMessage("§7Reaparecerás en §e5 segundos§7...");
    }

    public function onRespawn(PlayerRespawnEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        // Establecer spawn en la arena
        $event->setRespawnPosition($session->getArena()->getSpawnPoint());
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        if ($session->getArena()->isInArena($player->getPosition())) {
            $event->cancel();
            $player->sendTip("§c¡No puedes romper bloques en el raid!");
        }
    }
    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        if ($session->getArena()->isInArena($player->getPosition())) {
            $event->cancel();
            $player->sendTip("§c¡No puedes colocar bloques en el raid!");
        }
    }
    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $to = $event->getTo();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        $arena = $session->getArena();

        if (!$arena->isInArena($to)) {
            $event->cancel();
            $player->teleport($arena->getCenterPoint());
            $player->sendTip("§c¡No puedes salir de la arena durante el raid!");
        }
    }
    public function onDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();

        if (!$entity instanceof Player) {
            return;
        }

        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($entity);

        if ($session === null) {
            return;
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();

            if ($damager instanceof Player && $session->hasPlayer($damager)) {
                $event->cancel();
                $damager->sendTip("§c¡No puedes atacar a tus compañeros de raid!");
                return;
            }
        }

        if ($event->getCause() === EntityDamageEvent::CAUSE_FALL) {
            $event->cancel();
        }

        if ($event->getCause() === EntityDamageEvent::CAUSE_VOID) {
            $event->cancel();
            $entity->teleport($session->getArena()->getSpawnPoint());
            $entity->sendMessage("§c¡Has caído al vacío! Regresando al spawn...");
        }
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        $block = $event->getBlock();

        $blockedBlocks = [
            "chest",
            "furnace",
            "crafting_table",
            "enchanting_table",
            "anvil",
            "bed"
        ];

        $blockName = $block->getName();

        foreach ($blockedBlocks as $blocked) {
            if (stripos($blockName, $blocked) !== false) {
                $event->cancel();
                $player->sendTip("§c¡No puedes usar ese bloque en el raid!");
                return;
            }
        }
    }
}