<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator\entity;

use pocketmine\entity\Human;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\EntityDataHelper;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Server;
use pocketmine\entity\Skin;

class RaidMob extends Human {

    private const NETWORK_ID = EntityIds::PLAYER;

    private string $mobType = "zombie";
    private float $customHealth = 20.0;
    private float $customDamage = 2.0;
    private float $moveSpeed = 0.35;
    private bool $isBoss = false;

    private ?Player $target = null;
    private int $attackCooldown = 0;
    private int $strafeDirection = 0;
    private int $strafeTicks = 0;
    private int $jumpCooldown = 0;
    private int $criticalHitChance = 15;

    private bool $isRetreating = false;
    private int $retreatTicks = 0;
    private int $comboPotDelay = 0;
    private int $comboHits = 0;
    private int $lastHitTime = 0;

    private ?Player $lastDamager = null;

    private array $mobConfigs = [
        "zombie" => ["name" => "§cZombie", "skin" => "zombie", "attack_speed" => 10, "strafe_enabled" => false, "can_critical" => true, "armor_level" => 0],
        "skeleton" => ["name" => "§7Skeleton", "skin" => "skeleton", "attack_speed" => 15, "strafe_enabled" => true, "can_critical" => true, "armor_level" => 1],
        "brute" => ["name" => "§4Brute Zombie", "skin" => "zombie_brute", "attack_speed" => 8, "strafe_enabled" => false, "can_critical" => true, "armor_level" => 2],
        "spider" => ["name" => "§8Spider Warrior", "skin" => "spider_warrior", "attack_speed" => 7, "strafe_enabled" => true, "can_critical" => true, "armor_level" => 0],
        "zombie_boss" => ["name" => "§c§lZOMBIE BOSS", "skin" => "zombie_boss", "attack_speed" => 8, "strafe_enabled" => true, "can_critical" => true, "armor_level" => 3],
        "skeleton_boss" => ["name" => "§7§lSKELETON BOSS", "skin" => "skeleton_boss", "attack_speed" => 10, "strafe_enabled" => true, "can_critical" => true, "armor_level" => 3]
    ];

    public function __construct(Location $location, CompoundTag $nbt) {
        $mobType = $nbt->getString("mobType", "zombie");
        $skin = self::createSkinFromType($mobType);
        parent::__construct($location, $skin, $nbt);
    }

    private static function createSkinFromType(string $mobType): Skin {
        $data = str_repeat("\x00", 8192);
        return new Skin("Standard_Custom", $data);
    }

    protected function initEntity(CompoundTag $nbt): void {
        parent::initEntity($nbt);
        $this->mobType = $nbt->getString("mobType", "zombie");
        $this->customHealth = $nbt->getFloat("CustomHealth", $nbt->getFloat("health", 20.0));
        $this->customDamage = $nbt->getFloat("CustomDamage", $nbt->getFloat("damage", 2.0));
        $this->isBoss = ($nbt->getByte("IsBoss", $nbt->getByte("isBoss", 0)) === 1) || ($nbt->getByte("isBoss", 0) === 1);
        $this->moveSpeed = $this->isBoss ? 0.4 : 0.35;
        $this->setMaxHealth((int) $this->customHealth);
        $this->setHealth((int) $this->customHealth);
        $this->setNameTagAlwaysVisible(true);
        $this->setNameTag($this->getMobName());
        $this->equipMob();
    }

    private function getMobName(): string {
        $config = $this->mobConfigs[$this->mobType] ?? $this->mobConfigs["zombie"];
        $percentage = ($this->getHealth() / max(1, $this->getMaxHealth())) * 100;
        $bars = (int)($percentage / 10);
        $color = "§a";
        if ($percentage < 30) $color = "§c";
        elseif ($percentage < 60) $color = "§e";
        return $config["name"] . " " . $color . str_repeat("█", $bars) . "§7" . str_repeat("█", 10 - $bars);
    }

    private function equipMob(): void {
        $config = $this->mobConfigs[$this->mobType] ?? $this->mobConfigs["zombie"];
        $sword = VanillaItems::DIAMOND_SWORD();
        if ($this->isBoss) {
            $sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2));
            $sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::KNOCKBACK(), 1));
        } else {
            $sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1));
        }
        $this->getInventory()->setItem(0, $sword);
        $this->getInventory()->setHeldItemIndex(0);
        $armorLevel = $config["armor_level"] ?? 0;
        if ($armorLevel > 0) {
            $armorInv = $this->getArmorInventory();
            switch ($armorLevel) {
                case 1:
                    $armorInv->setHelmet(VanillaItems::IRON_HELMET());
                    $armorInv->setChestplate(VanillaItems::IRON_CHESTPLATE());
                    break;
                case 2:
                    $armorInv->setHelmet(VanillaItems::DIAMOND_HELMET());
                    $armorInv->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
                    $armorInv->setLeggings(VanillaItems::DIAMOND_LEGGINGS());
                    break;
                case 3:
                    $helmet = VanillaItems::DIAMOND_HELMET();
                    $helmet->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 2));
                    $chestplate = VanillaItems::DIAMOND_CHESTPLATE();
                    $chestplate->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 2));
                    $leggings = VanillaItems::DIAMOND_LEGGINGS();
                    $leggings->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 2));
                    $boots = VanillaItems::DIAMOND_BOOTS();
                    $boots->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 2));
                    $armorInv->setHelmet($helmet);
                    $armorInv->setChestplate($chestplate);
                    $armorInv->setLeggings($leggings);
                    $armorInv->setBoots($boots);
                    break;
            }
        }
    }

    protected function entityBaseTick(int $tickDiff = 1): bool {
        $hasUpdate = parent::entityBaseTick($tickDiff);
        if ($this->isClosed() || !$this->isAlive()) return false;
        $this->setNameTag($this->getMobName());
        if ($this->attackCooldown > 0) $this->attackCooldown--;
        if ($this->jumpCooldown > 0) $this->jumpCooldown--;
        if ($this->comboPotDelay > 0) $this->comboPotDelay--;
        $this->updateTarget();
        if ($this->target !== null && $this->target->isAlive()) $this->combatAI();
        else $this->idleAI();
        return $hasUpdate;
    }

    private function updateTarget(): void {
        if ($this->target !== null && $this->target->isOnline() && $this->target->isAlive()) {
            $distance = $this->location->distance($this->target->getLocation());
            if ($distance > 30) $this->target = null;
            return;
        }
        $this->target = $this->findNearestPlayer();
    }

    private function findNearestPlayer(): ?Player {
        $nearest = null;
        $minDistance = 30.0;
        foreach ($this->getWorld()->getPlayers() as $player) {
            if (!$player->isAlive() || $player->isSpectator()) continue;
            $distance = $this->location->distance($player->getLocation());
            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $player;
            }
        }
        return $nearest;
    }

    private function combatAI(): void {
        if ($this->target === null) return;
        $config = $this->mobConfigs[$this->mobType] ?? $this->mobConfigs["zombie"];
        $targetPos = $this->target->getLocation();
        $distance = $this->location->distance($targetPos);
        if ($this->getHealth() < $this->getMaxHealth() * 0.3 && !$this->isBoss) {
            if (!$this->isRetreating) {
                $this->isRetreating = true;
                $this->retreatTicks = 40;
            }
        }
        if ($this->isRetreating) {
            $this->retreatTicks--;
            if ($this->retreatTicks <= 0) $this->isRetreating = false;
            else {
                $this->moveAwayFrom($targetPos);
                return;
            }
        }
        if ($distance <= 3.5) {
            if ($config["strafe_enabled"]) $this->performStrafe($targetPos);
            else $this->lookAt($targetPos);
            if ($this->attackCooldown <= 0) {
                $this->attackTarget();
                $this->attackCooldown = $config["attack_speed"];
            }
            if ($this->jumpCooldown <= 0 && mt_rand(1, 100) <= 30) {
                $this->motion->y = 0.42;
                $this->jumpCooldown = 20;
            }
        } else if ($distance <= 20) {
            $this->moveTowards($targetPos);
            if ($distance > 8) $this->setSprinting(true);
            else $this->setSprinting(false);
        }
        if ($this->lastHitTime > 0 && (time() - $this->lastHitTime) > 3) $this->comboHits = 0;
    }

    private function performStrafe(Vector3 $targetPos): void {
        $this->strafeTicks++;
        if ($this->strafeTicks >= 20) {
            $this->strafeDirection = mt_rand(-1, 1);
            $this->strafeTicks = 0;
        }
        $this->lookAt($targetPos);
        if ($this->strafeDirection !== 0) {
            $angle = $this->location->yaw + ($this->strafeDirection * 90);
            $radians = deg2rad($angle);
            $x = -sin($radians) * $this->moveSpeed;
            $z = cos($radians) * $this->moveSpeed;
            $this->motion->x = $x;
            $this->motion->z = $z;
        }
    }

    private function moveTowards(Vector3 $target): void {
        $this->lookAt($target);
        $diffX = $target->x - $this->location->x;
        $diffZ = $target->z - $this->location->z;
        $distance = sqrt($diffX ** 2 + $diffZ ** 2);
        if ($distance > 0) {
            $this->motion->x = ($diffX / $distance) * $this->moveSpeed;
            $this->motion->z = ($diffZ / $distance) * $this->moveSpeed;
        }
    }

    private function moveAwayFrom(Vector3 $target): void {
        $diffX = $this->location->x - $target->x;
        $diffZ = $this->location->z - $target->z;
        $distance = sqrt($diffX ** 2 + $diffZ ** 2);
        if ($distance > 0) {
            $this->motion->x = ($diffX / $distance) * $this->moveSpeed * 0.7;
            $this->motion->z = ($diffZ / $distance) * $this->moveSpeed * 0.7;
        }
        $this->lookAt($target);
    }

    private function attackTarget(): void {
        if ($this->target === null || !$this->target->isAlive()) return;
        $config = $this->mobConfigs[$this->mobType] ?? $this->mobConfigs["zombie"];
        $damage = $this->customDamage;
        $isCritical = false;
        if ($config["can_critical"] && mt_rand(1, 100) <= $this->criticalHitChance) {
            $damage *= 1.5;
            $isCritical = true;
        }
        if ($this->comboHits > 2) $damage *= 1.1;
        $ev = new EntityDamageByEntityEvent($this, $this->target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
        $this->target->attack($ev);
        if (!$ev->isCancelled()) {
            $this->comboHits++;
            $this->lastHitTime = time();
            if ($isCritical) $this->target->sendTip("§c§lCRÍTICO!");
            $directionVector = $this->target->getPosition()->subtractVector($this->getPosition())->normalize();
            $horizontalKb = 0.4;
            $verticalKb = 0.35;
            $motion = $directionVector->multiply($horizontalKb);
            $motion->y = $verticalKb;
            $this->target->setMotion($motion);
        }
    }

    private function idleAI(): void {
        if (mt_rand(1, 100) <= 5) {
            $angle = mt_rand(0, 360);
            $radians = deg2rad($angle);
            $this->motion->x = -sin($radians) * 0.1;
            $this->motion->z = cos($radians) * 0.1;
        }
    }

    public function attack(EntityDamageEvent $source): void {
        parent::attack($source);

        // Guardar quién hizo daño al mob
        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Player) {
                $this->target = $damager;
                $this->lastDamager = $damager;
            }
        }
    }

    protected function onDeath(): void {
        parent::onDeath();

        if ($this->lastDamager instanceof Player && $this->lastDamager->isOnline()) {
            $plugin = \morelhaa\RaidSimulator\RaidSimulator::getInstance();
            $session = $plugin->getRaidManager()->getPlayerSession($this->lastDamager);

            if ($session !== null) {
                $session->addKill($this->lastDamager);
                $session->decrementAliveMobs();

                $remaining = $session->getAliveMobs();
                if ($remaining > 0) {
                    $this->lastDamager->sendTip("§aMob eliminado! §7Quedan: §f" . $remaining);
                } else {
                    $this->lastDamager->sendTip("§a§l¡OLEADA COMPLETADA!");
                }
            }
        }
    }

    public function lookAt(Vector3 $target): void {
        $horizontal = sqrt(($target->x - $this->location->x) ** 2 + ($target->z - $this->location->z) ** 2);
        $vertical = $target->y - $this->location->y;
        $this->location->pitch = rad2deg(-atan2($vertical, $horizontal));
        $this->location->yaw = rad2deg(atan2($target->z - $this->location->z, $target->x - $this->location->x)) - 90;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(1.8, 0.6);
    }

    public static function getNetworkTypeId(): string {
        return self::NETWORK_ID;
    }

    public function getName(): string {
        return $this->getMobName();
    }

    public function getIsBoss(): bool {
        return $this->isBoss;
    }

    public static function nbtDeserialize(CompoundTag $nbt): Entity {
        return new self(
            EntityDataHelper::parseLocation($nbt, Server::getInstance()->getWorldManager()->getDefaultWorld()),
            $nbt
        );
    }

    public function nbtSerialize(): CompoundTag {
        $tag = parent::nbtSerialize();
        $tag->setString("mobType", $this->mobType);
        $tag->setFloat("CustomHealth", $this->customHealth);
        $tag->setFloat("CustomDamage", $this->customDamage);
        $tag->setByte("IsBoss", $this->isBoss ? 1 : 0);
        return $tag;
    }

    protected function syncNetworkData(EntityMetadataCollection $properties): void {
        parent::syncNetworkData($properties);
        $properties->setString(EntityMetadataProperties::NAMETAG, $this->getNameTag());
    }
}