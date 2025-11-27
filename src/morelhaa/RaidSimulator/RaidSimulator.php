<?php

declare(strict_types=1);

namespace morelhaa\RaidSimulator;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use morelhaa\RaidSimulator\manager\RaidManager;
use morelhaa\RaidSimulator\manager\ArenaManager;
use morelhaa\RaidSimulator\manager\WaveManager;
use morelhaa\RaidSimulator\manager\ScoreManager;
use morelhaa\RaidSimulator\command\RaidCommand;
use morelhaa\RaidSimulator\listener\PlayerListener;
use morelhaa\RaidSimulator\listener\EntityListener;
use morelhaa\RaidSimulator\entity\RaidMob;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

class RaidSimulator extends PluginBase {
    use SingletonTrait;

    private RaidManager $raidManager;
    private ArenaManager $arenaManager;
    private WaveManager $waveManager;
    private ScoreManager $scoreManager;

    protected function onLoad(): void {
        self::setInstance($this);
    }

    protected function onEnable(): void {
        @mkdir($this->getDataFolder() . "arenas");
        @mkdir($this->getDataFolder() . "waves");

        $this->saveDefaultConfig();
        $this->saveResource("waves.yml");
        $this->saveResource("rewards.yml");
        $this->saveResource("arenas/example.yml");

        $this->arenaManager = new ArenaManager($this);
        $this->waveManager = new WaveManager($this);
        $this->scoreManager = new ScoreManager($this);
        $this->raidManager = new RaidManager($this);

        $this->getServer()->getCommandMap()->register("raid", new RaidCommand($this));

        $pm = $this->getServer()->getPluginManager();
        $pm->registerEvents(new PlayerListener($this), $this);
        $pm->registerEvents(new EntityListener($this), $this);

        $this->registerEntity();

        $this->getLogger()->info("§aRaidSimulator cargado correctamente!");
    }

    private function registerEntity(): void {
        try {
            if (!class_exists(RaidMob::class)) {
                $this->getLogger()->warning("§eClase RaidMob no encontrada. Verifica que el archivo existe en:");
                $this->getLogger()->warning("§esrc/morelhaa/RaidSimulator/entity/RaidMob.php");
                return;
            }

            EntityFactory::getInstance()->register(
                RaidMob::class,
                function(World $world, CompoundTag $nbt): RaidMob {
                    return new RaidMob(
                        EntityDataHelper::parseLocation($nbt, $world),
                        $nbt
                    );
                },
                ['RaidMob', 'raidsimulator:mob']
            );

            $this->getLogger()->info("§aEntidad RaidMob registrada correctamente");
        } catch (\Exception $e) {
            $this->getLogger()->error("§cError al registrar RaidMob: " . $e->getMessage());
            $this->getLogger()->error("§cStack trace: " . $e->getTraceAsString());
        }
    }

    protected function onDisable(): void {
        $this->raidManager->endAllSessions();
        $this->getLogger()->info("§cRaidSimulator deshabilitado!");
    }

    public function getRaidManager(): RaidManager {
        return $this->raidManager;
    }

    public function getArenaManager(): ArenaManager {
        return $this->arenaManager;
    }

    public function getWaveManager(): WaveManager {
        return $this->waveManager;
    }

    public function getScoreManager(): ScoreManager {
        return $this->scoreManager;
    }
}