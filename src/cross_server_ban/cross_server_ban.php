<?php

namespace cross_server_ban;

use pocketmine\level\sound\LaunchSound;
use pocketmine\level\sound\ClickSound;
use pocketmine\level\sound\FizzSound;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\entity\Arrow;
use pocketmine\level\Location;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\nbt\tag\Compound;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\inventory\PlayerInventory;
use pocketmine\Server;
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\OfflinePlayer;
use pocketmine\utils\Config;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\math\Vector3;
use pocketmine\scheduler\PluginTask;
use pocketmine\block\Block;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\level\LevelUnloadEvent;
use pocketmine\inventory\Inventory;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\block\BlockFactory;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\utils\Binary\NetworkBinaryStream;

class cross_server_ban extends PluginBase implements Listener{
	const API_VERSION = 0;
	const CLOSE_PLAYER_MESSAGE = 'GG 你已被封鎖  :)';
	
	private static $instance;
	
	public static function getInstance(){
		return static::$instance;
	}
	
	function onEnable () {
		if(!static::$instance instanceof \cross_server_ban\cross_server_ban ){
			static::$instance = $this;
		}
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		@mkdir($this->getDataFolder());
		
		$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML, []);
		if ( !isset($this->config->{'console_log'}) ) {
			$this->config->{'console_log'} = true;
		}
		$this->console_log = (bool)$this->config->{'console_log'};
		if ( !isset($this->config->{'server_name'}) ) {
			$this->config->{'server_name'} = 'wtf';
		}
		if ( !isset($this->config->{'api_url'}) ) {
			$this->config->{'api_url'} = 'https://lstw.top/api/v1.php';
		}
		if ( !isset($this->config->{'ignore_server'}) ) {
			$this->config->{'ignore_server'} = [
				'ignore_1',
				'ignore_2',
			];
		}
		if ( !isset($this->config->{'ignore_reason_type'}) ) {
			$this->config->{'ignore_reason_type'} = [
				-2,
				-1,
			];
		}
		if ( !isset($this->config->{'___server_api_password'}) ) {
			$this->config->{'___server_api_password'} = 'pwd';// length=43
		}
		if ( !isset($this->config->{'___dev_api_mode'}) ) {
			$this->config->{'___dev_api_mode'} = false;
		}
		if ( !isset($this->config->{'___dev_api_url'}) ) {
			$this->config->{'___dev_api_url'} = 'https://lstw.top/api/__dev/v1.php';
		}
		if ( $this->config->hasChanged() ) {
			$this->config->save();
		}
		if ( $this->config->{'___dev_api_mode'} === true ) {
			$this->apiurl = $this->config->{'___dev_api_url'};
			$devprefix = '___';
		} else {
			$this->apiurl = $this->config->{'api_url'};
			$devprefix = '';
		}
		
		$this->listtosend = new Config($this->getDataFolder().$devprefix."listtosend.yml", Config::YAML, []);
		$this->data = new Config($this->getDataFolder().$devprefix."data.yml", Config::YAML, []);
		if ( !isset($this->data->counter) ) {
			$all = $this->listtosend->getAll();
			if ( count($all) > 0 ) {
				$key = key(array_slice($all, -1, null, true));
				$this->data->counter = $key+1;
			} else {
				$this->data->counter = 0;
			}
		}
		if ( !isset($this->data->submitcounter) ) {
			$this->data->submitcounter = 0;
		}
		if ( $this->data->hasChanged() ) {
			$this->data->save();
		}
		
		$this->stopsend = false;
		
		$this->xuidmap = new Config($this->getDataFolder().$devprefix."id_xuid_map.yml", Config::YAML, []);
		$this->banlist = new Config($this->getDataFolder().$devprefix."banlist.yml", Config::YAML, []);
		$this->cachedxuid = new Config($this->getDataFolder().$devprefix."cachedxuid.yml", Config::YAML, []);
		
		$this->getScheduler()->scheduleRepeatingTask(new callbackTask([$this,"sync_banlist"]),310);// ~15s
	}
	
	function onDisable () {
		if ( $this->xuidmap->hasChanged() ) {
			$this->xuidmap->save();
		}
	}
	
	function login ( PlayerLoginEvent $e ) {
		$p = $e->getPlayer();
		$nn = $p->getLowerCaseName();
		
		$xuid = $p->getXuid();
		if ( $this->getServer()->requiresAuthentication() and $xuid !== '' ) {
			$this->xuidmap->{$nn} = $xuid;
			
			if ( $this->checkbanlist($p) ) {
				$e->setKickMessage(self::CLOSE_PLAYER_MESSAGE);
				$e->setCancelled(true);
			}
		}
	}
	
	function checkbanlist ( Player $p ) : bool{
		$xuid = $p->getXuid();
		if ( !$this->checkwhitelist($p) ) {
			foreach ( $this->banlist->getAll() as $v ) {
				if ( $v['x'] === $xuid ) {
					return true;
				}
			}
			foreach ( array_reverse($this->listtosend->getAll()) as $v ) {
				if ( $v['xuid'] === $xuid ) {
					if ( $v['ban'] === true ) {
						return true;
					} else {
						return false;
					}
				}
			}
		}
		return false;
	}
	
	function checkwhitelist ( Player $p ) : bool{
		return ($p->isOp() or !$this->getServer()->requiresAuthentication());
	}
	
	function sync_banlist () {
		if ( $this->xuidmap->hasChanged() ) {
			$this->xuidmap->save();
		}
		if ( $this->listtosend->hasChanged() ) {
			$this->listtosend->save();
		}
		
		$sendlist = '';
		if ( $this->stopsend === false ) {
			foreach ( $this->listtosend->getAll() as $smid=>$v ) {
				$sendlist = '&p='.$this->config->{'___server_api_password'}.'&smid='.$smid.'&ban='.($v['ban']===true?1:0);
				array_map(function ( $v ) {
					return urlencode($v);
				}, $v);
				$sendlist .= '&x='.$v['xuid'].'&t='.$v['time'].'&n='.$v['nn'].'&ri='.$v['reasonid'].'&rs='.$v['reason'];
				break;
			}
			
		}
		$this->getServer()->getAsyncPool()->submitTask(new getURL_Task($this->apiurl.'?v='.self::API_VERSION.'&c='.$this->data->counter.'&sn='.$this->config->{'server_name'}.$sendlist));
	}
	
	function sync_banlist_callback ( $rt ) {
		try {
			$data = json_decode($rt, true);
			if ( !is_array($data) ) {
				throw new \Exception('數據解讀錯誤');
			}
			if ( isset($data['ssend']) and $data['ssend'] === true ) {
				$this->stopsend = true;
			}
			if ( isset($data['smid']) ) {
				$smid = $data['smid'];
				if ( isset($this->listtosend->{$smid}) ) {
					unset($this->listtosend->{$smid});
				}
			}
			if ( isset($data['suc']) and $data['suc'] === true and isset($data['data']) and is_array($data['data']) ) {
				if ( (int)($data['counter']??-1) > $this->data->counter ) {
					foreach ( $data['data'] as $counter=>$entry ) {
						// b: ban|unban(bool), x: xuid(string)
						if ( is_array($entry) and isset($entry['b']) and isset($entry['x']) and isset($entry['sn']) ) {
							if ( $entry['b'] === true ) {//ban
								if ( !in_array($entry['sn'], $this->config->{'ignore_server'}, true) ) {
									if ( !in_array($entry['ri'], $this->config->{'ignore_reason_type'}, true) ) {
										$this->banlist->{$counter} = [
											'x'=>$entry['x'],//xuid
											## api unsend 't'=>$entry['t']??time(),//time
											## api unsend 'rs'=>$entry['rs']??'Unknown Reason',
											'ri'=>$entry['ri']??0,//原因類型ID，預設0
											'nn'=>$entry['nn']??'Unknown',//username參考用
											## unsave 'sn'=>$entry['sn'],//伺服器名稱
										];
										if ( $this->console_log ) {
											$this->getLogger()->info('[封鎖] XUID: '.$entry['x'].' 已被封鎖，ID (參考用): '.($entry['nn']??'Unknown'));
										}
									} else {
										if ( $this->console_log ) {
											$this->getLogger()->info('[封鎖] XUID: '.$entry['x'].' 被使用忽略的原因類型ID封鎖，ID (參考用): '.($entry['nn']??'Unknown'));
										}
									}
								} else {
									if ( $this->console_log ) {
										$this->getLogger()->info('[封鎖] XUID: '.$entry['x'].' 被忽略的伺服器封鎖，ID (參考用): '.($entry['nn']??'Unknown'));
									}
								}
							} else {//unban
								foreach ( $this->banlist->getAll() as $k=>$v ) {
									if ( $entry['x'] === $v['x'] ) {
										unset($this->banlist->{$k});
										if ( $this->console_log ) {
											$this->getLogger()->info('[解封] XUID: '.$v['x'].' 已被解鎖');
										}
										//no break
									}
								}
							}
						}
					}
					if ( $this->banlist->hasChanged() ) {
						$this->banlist->save();
					}
					
					$this->data->counter = $data['counter'];
					$this->data->save();
				}
			} else {
				if ( $this->console_log ) {
					$this->getLogger()->warning('數據提交失敗，原因: '.($data['errmsg']??'未知'));
				}
			}
			if ( isset($data['msg']) and $data['msg'] !== '' ) {
				$this->getLogger()->info((string)$data['msg']);
			}
		} catch ( \Exception $e ) {
			$this->getLogger()->error('錯誤: '.$e->getMessage());
		}
	}
	
	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if ( !$this->getServer()->requiresAuthentication() ) {
			$sender->sendMessage(TF::RED.'!!! 伺服器未有啟用XBOX驗證 (xbox-auth)，你無法使用跨服封禁系統 !!!');
			return true;
		}
		if ( $this->stopsend !== false ) {
			$sender->sendMessage(TF::RED.'!!! 本伺服器的API密碼不在數據庫中 !!!');
			$sender->sendMessage(TF::RED.'!!! 請嘗試向插件作者Leo申請 !!!');
			return true;
		}
		switch ( $command->getName() ) {
			case 'csban';
				$n = $nn = strtolower((string)array_shift($args));
				if ( $nn !== '' ) {
					if ( !Player::isValidUserName($nn) ) {
						$sender->sendMessage(TF::RED.'!!! 玩家ID無效 !!!');
						return true;
					}
					$reasonid = array_shift($args);
					$reason = join(' ', $args);
					if ( !is_numeric($reasonid) ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因類型ID不合法，無法封鎖 !!!');
						return true;
					}
					if ( $reason === '' ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因不得留空 !!!');
						return true;
					}
					if ( strlen($reason) > 100 ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因長度不得超過100位 (每個全形字佔3位) !!!');
						return true;
					}
					$reasonid = (int)$reasonid;
					if ( isset($this->xuidmap->{$nn}) ) {
						$xuid = $this->xuidmap->{$nn};
						if ( $xuid === '' or !is_numeric($xuid) ) {
							$sender->sendMessage(TF::RED.'!!! 此玩家的XUID不合法，無法封鎖 !!!');
							return true;
						}
						$p = $this->getServer()->getPlayerExact($nn);
						if ( $p instanceof Player ) {
							$n = $p->getName();
							if ( !$this->checkwhitelist($p) ) {
								$p->close('', self::CLOSE_PLAYER_MESSAGE);
							}
						}
						$smid = $this->data->submitcounter;
						$this->data->submitcounter = $smid+1;
						$this->data->save();
						$this->listtosend->{$smid} = [
							'xuid'=>$xuid,
							'nn'=>$nn,
							'reasonid'=>$reasonid,
							'reason'=>$reason,
							'time'=>time(),
							'ban'=>true,
						];
						$this->listtosend->save();
						$sender->sendMessage(TF::GREEN.'> '.$n.' (XUID: '.$xuid.') 已被封鎖，原因 (ID:'.$reasonid.')：'.$reason);
					} else {
						$sender->sendMessage(TF::RED.'!!! 玩家數據不存在，無法封鎖 !!!');
					}
				} else {
					$sender->sendMessage(TF::RED.'!!! 請使用 /csban <玩家ID> <原因類型ID> <原因> !!!');
				}
				break;
			case 'csunban';
				$n = $nn = strtolower((string)array_shift($args));
				if ( $nn !== '' ) {
					if ( !Player::isValidUserName($nn) ) {
						$sender->sendMessage(TF::RED.'!!! 玩家ID無效 !!!');
						return true;
					}
					$reason = join(' ', $args);
					if ( $reason === '' ) {
						$sender->sendMessage(TF::RED.'!!! 解封原因不得留空 !!!');
						return true;
					}
					if ( strlen($reason) > 100 ) {
						$sender->sendMessage(TF::RED.'!!! 解封原因長度不得超過100位 (每個全形字佔3位) !!!');
						return true;
					}
					if ( isset($this->xuidmap->{$nn}) ) {
						$xuid = $this->xuidmap->{$nn};
						if ( $xuid === '' or !is_numeric($xuid) ) {
							$sender->sendMessage(TF::RED.'!!! 此玩家的XUID不合法，無法解封 !!!');
							return true;
						}
						$p = $this->getServer()->getPlayerExact($nn);
						if ( $p instanceof Player ) {
							$n = $p->getName();
							if ( !$this->checkwhitelist($p) ) {
								$p->close('', self::CLOSE_PLAYER_MESSAGE);
							}
						}
						$smid = $this->data->submitcounter;
						$this->data->submitcounter = $smid+1;
						$this->data->save();
						$this->listtosend->{$smid} = [
							'xuid'=>$xuid,
							'nn'=>$nn,
							'reasonid'=>99999,
							'reason'=>$reason,
							'time'=>time(),
							'ban'=>false,
						];
						$this->listtosend->save();
						$sender->sendMessage(TF::GREEN.'> '.$n.' (XUID: '.$xuid.') 已被解封，原因：'.$reason);
					} else {
						$sender->sendMessage(TF::RED.'!!! 玩家數據不存在，無法解封 !!!');
					}
				} else {
					$sender->sendMessage(TF::RED.'!!! 請使用 /csunban <玩家ID> <原因> !!!');
				}
				break;
			case 'csbanxuid';
				$xuid = (string)array_shift($args);
				if ( $xuid !== '' ) {
					if ( !is_numeric($xuid) ) {
						$sender->sendMessage(TF::RED.'!!! XUID不合法，無法封鎖 !!!');
						return true;
					}
					$reasonid = array_shift($args);
					$reason = join(' ', $args);
					if ( !is_numeric($reasonid) ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因類型ID不合法，無法封鎖 !!!');
						return true;
					}
					if ( $reason === '' ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因不得留空 !!!');
						return true;
					}
					if ( strlen($reason) > 100 ) {
						$sender->sendMessage(TF::RED.'!!! 封鎖原因長度不得超過100位 (每個全形字佔3位) !!!');
						return true;
					}
					$reasonid = (int)$reasonid;
					
					$smid = $this->data->submitcounter;
					$this->data->submitcounter = $smid+1;
					$this->data->save();
					$this->listtosend->{$smid} = [
						'xuid'=>$xuid,
						'nn'=>'Unknown',
						'reasonid'=>$reasonid,
						'reason'=>$reason,
						'time'=>time(),
						'ban'=>true,
					];
					$this->listtosend->save();
					$sender->sendMessage(TF::GREEN.'> XUID: '.$xuid.' 已被封鎖，原因 (ID:'.$reasonid.')：'.$reason);
				} else {
					$sender->sendMessage(TF::RED.'!!! 請使用 /csbanxuid <XUID> <原因類型ID> <原因> !!!');
				}
				break;
			case 'csunbanxuid';
				$xuid = (string)array_shift($args);
				if ( $xuid !== '' ) {
					if ( !is_numeric($xuid) ) {
						$sender->sendMessage(TF::RED.'!!! XUID不合法，無法封鎖 !!!');
						return true;
					}
					$reason = join(' ', $args);
					if ( $reason === '' ) {
						$sender->sendMessage(TF::RED.'!!! 解封原因不得留空 !!!');
						return true;
					}
					if ( strlen($reason) > 100 ) {
						$sender->sendMessage(TF::RED.'!!! 解封原因長度不得超過100位 (每個全形字佔3位) !!!');
						return true;
					}
					$smid = $this->data->submitcounter;
					$this->data->submitcounter = $smid+1;
					$this->data->save();
					$this->listtosend->{$smid} = [
						'xuid'=>$xuid,
						'nn'=>'Unknown',
						'reasonid'=>99999,
						'reason'=>$reason,
						'time'=>time(),
						'ban'=>false,
					];
					$this->listtosend->save();
					$sender->sendMessage(TF::GREEN.'> XUID: '.$xuid.' 已被解封，原因：'.$reason);
				} else {
					$sender->sendMessage(TF::RED.'!!! 請使用 /csunbanxuid <XUID> <原因> !!!');
				}
				break;
		}
		return true;
	}
	
}
class getURL_Task extends \pocketmine\scheduler\AsyncTask {
	private $url;
	private $rt = false;
	
	function __construct ( string $url ) {
		$this->url = $url;
	}
	
	function onRun () {
		try {
			$this->rt = \pocketmine\utils\Internet::getURL($this->url);
		} catch ( \Exception $e ) {}
	}
	
	function onCompletion ( Server $server ) {
		if ( $this->rt !== false and $this->rt !== '' ) {
			$plugin = $server->getPluginManager()->getPlugin('cross_server_ban');
			$plugin->sync_banlist_callback($this->rt);
		}
	}
}

class callbackTask extends \pocketmine\scheduler\Task {
	private $callable;
	private $args;
	
	public function __construct(callable $c, $args = []){
		$this->callable = $c;
		$this->args = $args;
	}
	
	public function onRun(int $currentTick){
		call_user_func_array($this->callable, $this->args);
	}
}