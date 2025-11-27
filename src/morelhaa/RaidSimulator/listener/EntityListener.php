<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\listener;

use morelhaa\RaidSimulator\RaidSimulator;
use morelhaa\RaidSimulator\entity\RaidMob;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\player\Player;
use pocketmine\item\VanillaItems;

class EntityListener implements Listener {

    private RaidSimulator $plugin;

    public function __construct(RaidSimulator $plugin) {
        $this->plugin = $plugin;
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();

        if (!$entity instanceof RaidMob) {
            return;
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();

            if ($damager instanceof Player) {
                $this->handlePlayerAttackMob($damager, $entity, $event);
            }
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof RaidMob) {
                $event->cancel();
            }
        }

        if ($entity->isAlive()) {
            $this->updateMobHealthDisplay($entity);
        }
    }

    private function handlePlayerAttackMob(Player $player, RaidMob $mob, EntityDamageByEntityEvent $event): void {
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            $event->cancel();
            $player->sendTip("§c¡No estás en un raid activo!");
            return;
        }

        if (!$session->getArena()->isInArena($mob->getPosition())) {
            $event->cancel();
            return;
        }

        $damage = $event->getFinalDamage();

        $player->sendTip("§c-" . round($damage, 1) . " <3");

        if ($mob->getHealth() - $damage <= 0) {
        }
    }

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();

        if (!$entity instanceof RaidMob) {
            return;
        }

        $cause = $entity->getLastDamageCause();

        if ($cause instanceof EntityDamageByEntityEvent) {
            $killer = $cause->getDamager();

            if ($killer instanceof Player) {
                $this->handleMobKilledByPlayer($killer, $entity, $event);
            }
        }

        $event->setDrops([]);
        $event->setXpDropAmount(0);
    }

    private function handleMobKilledByPlayer(Player $player, RaidMob $mob, EntityDeathEvent $event): void {
        $raidManager = $this->plugin->getRaidManager();
        $session = $raidManager->getPlayerSession($player);

        if ($session === null) {
            return;
        }

        $session->addKill($player);
        $session->decrementAliveMobs();

        $mobName = $mob->getName();
        $player->sendTip("§c+1 Kill | §7Mobs restantes: §f" . $session->getAliveMobs());

        $this->giveKillReward($player, $mob);

        if ($session->isWaveComplete() && $session->getCurrentWave() > 0) {
            $session->broadcastTitle("§a§lOLEADA COMPLETADA!");
        }
    }

    private function giveKillReward(Player $player, RaidMob $mob): void {
        $drops = [];

        $random = mt_rand(1, 100);

        if ($random <= 30) {
            $drops[] = VanillaItems::STEAK()->setCount(mt_rand(1, 3));
        }

        if ($random <= 15) {
            $drops[] = VanillaItems::ARROW()->setCount(mt_rand(5, 10));
        }

        if ($random <= 5) {
            $drops[] = VanillaItems::SPLASH_POTION()->setCount(1);
        }

        if ($mob->getIsBoss()) {
            $drops[] = VanillaItems::GOLDEN_APPLE()->setCount(mt_rand(1, 3));
            $drops[] = VanillaItems::DIAMOND()->setCount(mt_rand(2, 5));

            $player->sendMessage("§6§l¡BOSS DERROTADO! §r§e+" . count($drops) . " items especiales");
        }

        foreach ($drops as $item) {
            if ($player->getInventory()->canAddItem($item)) {
                $player->getInventory()->addItem($item);
            } else {
                $player->getWorld()->dropItem($player->getPosition(), $item);
            }
        }
    }

    private function updateMobHealthDisplay(RaidMob $mob): void {
    }
    public function onEntitySpawn(EntitySpawnEvent $event): void {
        $entity = $event->getEntity();

        if (!$entity instanceof RaidMob) {
            return;
        }

    }
    public function onEntityDespawn(EntityDespawnEvent $event): void {
        $entity = $event->getEntity();

        if (!$entity instanceof RaidMob) {
            return;
        }

        $raidManager = $this->plugin->getRaidManager();

        foreach ($raidManager->getAllSessions() as $session) {
            if ($session->getArena()->isInArena($entity->getPosition())) {
                if ($entity->isAlive()) {
                    $session->decrementAliveMobs();
                }
                break;
            }
        }
    }

    public function onEntityMove(EntitySpawnEvent $event): void {
        $entity = $event->getEntity();

        if ($entity instanceof RaidMob || $entity instanceof Player) {
            return;
        }

        $raidManager = $this->plugin->getRaidManager();

        foreach ($raidManager->getAllSessions() as $session) {
            if ($session->getArena()->isInArena($entity->getPosition())) {
                if (!$entity->isClosed()) {
                    $entity->flagForDespawn();
                }
                break;
            }
        }
    }
}
