<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\wave;

use morelhaa\RaidSimulator\entity\RaidMob;
use morelhaa\RaidSimulator\arena\Arena;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\entity\Location;
use pocketmine\scheduler\ClosureTask;

class MobSpawner {

    public static function spawnMobs(Arena $arena, Wave $wave): array {
        $spawnedMobs = [];
        $scaledMobs = $wave->getScaledMobs();
        foreach ($scaledMobs as $mobData) {
            $type = $mobData["type"];
            $amount = $mobData["amount"];
            $health = $mobData["health"];
            $damage = $mobData["damage"];
            $isBoss = $mobData["is_boss"] ?? false;
            for ($i = 0; $i < $amount; $i++) {
                $spawnPos = self::getSpawnPosition($arena, $i, $amount);
                $mob = self::createMob($type, $spawnPos, $health, $damage, $isBoss);
                if ($mob !== null) {
                    $mob->spawnToAll();
                    $spawnedMobs[] = $mob;
                    self::spawnEffect($spawnPos);
                }
            }
        }
        return $spawnedMobs;
    }

    private static function createMob(string $type, Position $position, float $health, float $damage, bool $isBoss): ?RaidMob {
        $world = $position->getWorld();
        if ($world === null) return null;

        $nbt = CompoundTag::create()
            ->setString("mobType", $type)
            ->setFloat("CustomHealth", $health)
            ->setFloat("CustomDamage", $damage)
            ->setByte("IsBoss", $isBoss ? 1 : 0);

        $nbt->setString("id", "RaidMob");
        $nbt->setTag("Pos", new ListTag([
            new DoubleTag($position->x),
            new DoubleTag($position->y),
            new DoubleTag($position->z)
        ]));
        $nbt->setTag("Rotation", new ListTag([
            new FloatTag(0.0),
            new FloatTag(0.0)
        ]));

        try {
            $location = Location::fromObject(
                $position,
                $world,
                0.0,
                0.0
            );

            $entity = new RaidMob($location, $nbt);

            return $entity instanceof RaidMob ? $entity : null;
        } catch (\Exception $e) {
            Server::getInstance()->getLogger()->error("Error creando RaidMob: " . $e->getMessage());
            return null;
        }
    }

    private static function getSpawnPosition(Arena $arena, int $mobIndex, int $totalMobs): Position {
        $spawnPoints = $arena->getMobSpawnPoints();

        if (empty($spawnPoints)) {
            return $arena->getCenterPoint();
        }

        if (count($spawnPoints) === 1) {
            $spawnPoint = $spawnPoints[0];
            return new Position(
                $spawnPoint->getX(),
                $spawnPoint->getY(),
                $spawnPoint->getZ(),
                $spawnPoint->getWorld()
            );
        }

        $spawnPoint = $spawnPoints[$mobIndex % count($spawnPoints)];

        $x = $spawnPoint->getX() + mt_rand(-1, 1) * 0.5;
        $y = $spawnPoint->getY();
        $z = $spawnPoint->getZ() + mt_rand(-1, 1) * 0.5;

        return new Position($x, $y, $z, $spawnPoint->getWorld());
    }

    private static function getRandomSpawnPosition(Arena $arena): Position {
        return self::getSpawnPosition($arena, 0, 1);
    }

    public static function spawnSingleMob(string $type, Position $position, float $health, float $damage, bool $isBoss = false): ?RaidMob {
        $mob = self::createMob($type, $position, $health, $damage, $isBoss);
        if ($mob !== null) {
            $mob->spawnToAll();
            self::spawnEffect($position);
        }
        return $mob;
    }

    public static function spawnBoss(Arena $arena, string $bossType, float $health, float $damage): ?RaidMob {
        $spawnPos = $arena->getCenterPoint();
        $boss = self::createMob($bossType, $spawnPos, $health, $damage, true);
        if ($boss !== null) {
            $boss->spawnToAll();
            self::bossSpawnEffect($spawnPos);
            self::announceBossSpawn($arena, $bossType);
        }
        return $boss;
    }

    private static function spawnEffect(Position $position): void {
        $world = $position->getWorld();
        if ($world === null) return;
        for ($i = 0; $i < 10; $i++) {
            $world->addParticle(
                $position->add(mt_rand(-10, 10) / 10, mt_rand(0, 20) / 10, mt_rand(-10, 10) / 10),
                new \pocketmine\world\particle\FlameParticle()
            );
        }
    }

    private static function bossSpawnEffect(Position $position): void {
        $world = $position->getWorld();
        if ($world === null) return;
        for ($i = 0; $i < 50; $i++) {
            $angle = ($i / 50) * M_PI * 2;
            $radius = 3;
            $x = $position->getX() + ($radius * cos($angle));
            $z = $position->getZ() + ($radius * sin($angle));
            $world->addParticle(new Vector3($x, $position->getY() + 1, $z), new \pocketmine\world\particle\LavaParticle());
        }
        $world->addParticle($position, new \pocketmine\world\particle\HugeExplodeSeedParticle());
    }

    private static function announceBossSpawn(Arena $arena, string $bossType): void {
        $world = $arena->getWorld();
        if ($world === null) return;
        $bossName = self::getBossDisplayName($bossType);
        foreach ($world->getPlayers() as $player) {
            $player->sendTitle("§c§lBOSS APARECIÓ", "§f{$bossName}", 10, 40, 10);
            $player->sendMessage("§c§l[!] §r§cUn boss poderoso ha aparecido en la arena!");
        }
    }

    private static function getBossDisplayName(string $bossType): string {
        return match($bossType) {
            "zombie_boss" => "§c§lZOMBIE GIGANTE",
            "skeleton_boss" => "§7§lSKELETON REY",
            "brute" => "§4§lBRUTO SALVAJE",
            default => "§e§lBOSS DESCONOCIDO"
        };
    }

    public static function spawnWaveProgressive(Arena $arena, Wave $wave, int $delayTicks = 20): void {
        $scaledMobs = $wave->getScaledMobs();
        $currentDelay = 0;
        $server = Server::getInstance();
        $mobIndexCounter = 0;

        foreach ($scaledMobs as $mobData) {
            $type = $mobData["type"];
            $amount = $mobData["amount"];
            $health = $mobData["health"];
            $damage = $mobData["damage"];
            $isBoss = $mobData["is_boss"] ?? false;
            $groupSize = $isBoss ? 1 : 3;
            $groups = (int)ceil($amount / $groupSize);

            for ($g = 0; $g < $groups; $g++) {
                $mobsInGroup = min($groupSize, $amount - ($g * $groupSize));
                $startIndex = $mobIndexCounter;

                $server->getScheduler()->scheduleDelayedTask(
                    new ClosureTask(function() use ($arena, $type, $mobsInGroup, $health, $damage, $isBoss, $startIndex, $amount): void {
                        for ($i = 0; $i < $mobsInGroup; $i++) {
                            $spawnPos = self::getSpawnPosition($arena, $startIndex + $i, $amount);
                            self::spawnSingleMob($type, $spawnPos, $health, $damage, $isBoss);
                        }
                    }),
                    $currentDelay
                );

                $mobIndexCounter += $mobsInGroup;
                $currentDelay += $delayTicks;
            }
        }
    }

    public static function clearArenaMobs(Arena $arena): int {
        $world = $arena->getWorld();
        if ($world === null) return 0;
        $cleared = 0;
        foreach ($world->getEntities() as $entity) {
            if ($entity instanceof RaidMob && $arena->isInArena($entity->getPosition())) {
                $entity->flagForDespawn();
                $cleared++;
            }
        }
        return $cleared;
    }

    public static function countArenaMobs(Arena $arena): int {
        $world = $arena->getWorld();
        if ($world === null) return 0;
        $count = 0;
        foreach ($world->getEntities() as $entity) {
            if ($entity instanceof RaidMob && $arena->isInArena($entity->getPosition()) && $entity->isAlive()) $count++;
        }
        return $count;
    }

    public static function getArenaMobs(Arena $arena): array {
        $world = $arena->getWorld();
        if ($world === null) return [];
        $mobs = [];
        foreach ($world->getEntities() as $entity) {
            if ($entity instanceof RaidMob && $arena->isInArena($entity->getPosition())) $mobs[] = $entity;
        }
        return $mobs;
    }
}
