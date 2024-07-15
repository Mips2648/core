<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../core/php/core.inc.php';

class cache {
	/*     * *************************Attributs****************************** */

	private static $cache = null;

	private $key;
	private $value = null;
	private $lifetime = 0;
	private $datetime;
	private $options = null;

	/*     * ***********************Methode static*************************** */

	public static function getFolder() {
		return jeedom::getTmpFolder('cache');
	}

	public static function set($_key, $_value, $_lifetime = 0, $_options = null) {
		if ($_lifetime < 0) {
			$_lifetime = 0;
		}
		$cache = (new self())
			->setKey($_key)
			->setValue($_value)
			->setLifetime($_lifetime);
		if ($_options != null) {
			$cache->options = json_encode($_options, JSON_UNESCAPED_UNICODE);
		}
		return $cache->save();
	}

	public static function delete($_key) {
		$cache = cache::byKey($_key);
		if (is_object($cache)) {
			$cache->remove();
		}
	}

	/**
	 * @name getCache()
	 * @access public
	 * @static
	 * @param string $_engine to override the current cache defined in configuration
	 * @return \Doctrine\Common\Cache\CacheProvider
	 */
	public static function getCache($_engine = null) {
		if ($_engine === null && self::$cache !== null) {
			return self::$cache;
		}
		if( $_engine === null){
			// get current cache
			$engine = config::byKey('cache::engine');
		}else{
			// override existing configuration
			$engine = $_engine;
			config::save('cache::engine', $_engine);
		}
		switch ($engine) {
			case 'PhpFileCache':
				self::$cache = new \Doctrine\Common\Cache\FilesystemCache(self::getFolder());
				break;
			case 'MemcachedCache':
				// check if memcached extention is available
				if (!class_exists('memcached')) {
					log::add( __CLASS__, 'error', 'memcached extension not installed, fall back to FilesystemCache.');
					return self::getCache( 'FilesystemCache');
				}
				$memcached = new Memcached();
				$memcached->addServer(config::byKey('cache::memcacheaddr'), config::byKey('cache::memcacheport'));
				self::$cache = new \Doctrine\Common\Cache\MemcachedCache();
				self::$cache->setMemcached($memcached);
				break;
			case 'RedisCache':
				// check if redis extension is available
				if (!class_exists('redis')) {
					log::add( __CLASS__, 'error', 'redis extension not installed, fall back to FilesystemCache.');
					return self::getCache( 'FilesystemCache');
				}
				$redis = new Redis();
				$redisAddr = config::byKey('cache::redisaddr');
				try{
					// try to connect to redis
					if (strncmp($redisAddr, '/', 1) === 0) {
						$redis->connect($redisAddr);
					} else {
						$redis->connect($redisAddr, config::byKey('cache::redisport'));
					}	
				}catch( Exception $e){
					// error : fall back to FilesystemCache
					log::add( __CLASS__, 'error', 'Unable to connect to redis instance, fall back to FilesystemCache.'."\n".$e->getMessage());
					return self::getCache( 'FilesystemCache');
				}
				self::$cache = new \Doctrine\Common\Cache\RedisCache();
				self::$cache->setRedis($redis);
				break;
			case 'MariadbCache':
				self::$cache = new MariadbCache();
			default: // default is FilesystemCache
				self::$cache = new \Doctrine\Common\Cache\FilesystemCache(self::getFolder());
				break;
		}
		return self::$cache;
	}

	/**
	 *
	 * @param string $_key
	 * @return object
	 */
	public static function byKey($_key) {
        if(config::byKey('cache::engine') == 'MariadbCache'){
		  $cache =  MariadbCache::fetch($_key);
          if (!is_object($cache)) {
			$cache = (new self())
				->setKey($_key)
				->setDatetime(date('Y-m-d H:i:s'));
			}
          return $cache;
		}
		// Try/catch/debug to address issue https://github.com/jeedom/core/issues/2426
		try {
			$cache = self::getCache()->fetch($_key);
		} catch (Error $e) {
			log::add(__CLASS__, 'debug', 'Error in ' . __FUNCTION__ . '(): ' . $e->getMessage() . ', trace: ' . $e->getTraceAsString());
			$cache = null;
		}
		if (!is_object($cache)) {
			$cache = (new self())
				->setKey($_key)
				->setDatetime(date('Y-m-d H:i:s'));
		}
		return $cache;
	}

	public static function exist($_key) {
		// Try/catch/debug to address issue https://github.com/jeedom/core/issues/2426
		try {
			return is_object(self::getCache()->fetch($_key));
		} catch (Error $e) {
			log::add(__CLASS__, 'debug', 'Error in ' . __FUNCTION__ . '(): ' . $e->getMessage() . ', trace: ' . $e->getTraceAsString());
			return false;
		}
	}

	public static function flush() {
		switch (config::byKey('cache::engine')) {
			case 'FilesystemCache':
			case 'PhpFileCache':
				self::getCache()->deleteAll();
				shell_exec('rm -rf ' . self::getFolder() . ' 2>&1 > /dev/null');
				break;
			case 'MariadbCache':
				self::getCache()->deleteAll();
			default:
				return;
		}
		
	}

	public static function persist() {
		switch (config::byKey('cache::engine')) {
			case 'FilesystemCache':
				$cache_dir = self::getFolder();
				break;
			case 'PhpFileCache':
				$cache_dir = self::getFolder();
				break;
			default:
				return;
		}
		try {
			$cmd = system::getCmdSudo() . 'rm -rf ' . __DIR__ . '/../../cache.tar.gz;cd ' . $cache_dir . ';';
			$cmd .= system::getCmdSudo() . 'tar cfz ' . __DIR__ . '/../../cache.tar.gz * 2>&1 > /dev/null;';
			$cmd .= system::getCmdSudo() . 'chmod 774 ' . __DIR__ . '/../../cache.tar.gz;';
			$cmd .= system::getCmdSudo() . 'chown ' . system::get('www-uid') . ':' . system::get('www-gid') . ' ' . __DIR__ . '/../../cache.tar.gz;';
			$cmd .= system::getCmdSudo() . 'chown -R ' . system::get('www-uid') . ':' . system::get('www-gid') . ' ' . $cache_dir . ';';
			$cmd .= system::getCmdSudo() . 'chmod 774 -R ' . $cache_dir . ' 2>&1 > /dev/null';
			com_shell::execute($cmd);
		} catch (Exception $e) {
		}
	}

	public static function isPersistOk(): bool {
		if (config::byKey('cache::engine') != 'FilesystemCache' && config::byKey('cache::engine') != 'PhpFileCache') {
			return true;
		}
		$filename = __DIR__ . '/../../cache.tar.gz';
		if (!file_exists($filename)) {
			return false;
		}
		if (filemtime($filename) < strtotime('-65min')) {
			return false;
		}
		return true;
	}

	public static function restore() {
		switch (config::byKey('cache::engine')) {
			case 'FilesystemCache':
				$cache_dir = self::getFolder();
				break;
			case 'PhpFileCache':
				$cache_dir = self::getFolder();
				break;
			default:
				return;
		}
		if (!file_exists(__DIR__ . '/../../cache.tar.gz')) {
			$cmd = 'mkdir ' . $cache_dir . ';';
			$cmd .= 'chmod -R 777 ' . $cache_dir . ';';
			com_shell::execute($cmd);
			return;
		}
		$cmd = 'rm -rf ' . $cache_dir . ';';
		$cmd .= 'mkdir ' . $cache_dir . ';';
		$cmd .= 'cd ' . $cache_dir . ';';
		$cmd .= 'tar xfz ' . __DIR__ . '/../../cache.tar.gz;';
		$cmd .= 'chmod -R 777 ' . $cache_dir . ' 2>&1 > /dev/null;';
		com_shell::execute($cmd);
	}

	public static function clean() {
		if (config::byKey('cache::engine') == 'MariadbCache') {
			return MariadbCache::clean();
		}
		if (config::byKey('cache::engine') != 'FilesystemCache') {
			return;
		}
		$re = '/s:\d*:(.*?);s:\d*:"(.*?)";s/';
		$result = array();
		foreach (ls(self::getFolder()) as $folder) {
			foreach (ls(self::getFolder() . '/' . $folder) as $file) {
				$path = self::getFolder() . '/' . $folder . '/' . $file;
				if (strpos($file, 'swap') !== false) {
					unlink($path);
					continue;
				}
				$str = (string) str_replace("\n", '', file_get_contents($path));
				preg_match_all($re, $str, $matches);
				if (!isset($matches[2]) || !isset($matches[2][0]) || trim($matches[2][0]) == '') {
					continue;
				}
				$result[] = $matches[2][0];
			}
		}
		$cleanCache = array(
			'cmdCacheAttr' => 'cmd',
			'cmd' => 'cmd',
			'eqLogicCacheAttr' => 'eqLogic',
			'eqLogicStatusAttr' => 'eqLogic',
			'scenarioCacheAttr' => 'scenario',
			'cronCacheAttr' => 'cron',
			'cron' => 'cron',
		);
		foreach ($result as $key) {
			$matches = null;
			if (strpos($key, '::lastCommunication') !== false) {
				cache::delete($key);
				continue;
			}
			if (strpos($key, '::state') !== false) {
				cache::delete($key);
				continue;
			}
			if (strpos($key, '::numberTryWithoutSuccess') !== false) {
				cache::delete($key);
				continue;
			}
			foreach ($cleanCache as $kClean => $value) {
				if (strpos($key, $kClean) !== false) {
					$id = str_replace($kClean, '', $key);
					if (!is_numeric($id)) {
						continue;
					}
					$object = $value::byId($id);
					if (!is_object($object)) {
						cache::delete($key);
					}
					continue;
				}
			}
			preg_match_all('/widgetHtml(\d*)(.*?)/', $key, $matches);
			if (isset($matches[1][0])) {
				if (!is_numeric($matches[1][0])) {
					continue;
				}
				$object = eqLogic::byId($matches[1][0]);
				if (!is_object($object)) {
					cache::delete($key);
				}
			}
			preg_match_all('/camera(\d*)(.*?)/', $key, $matches);
			if (isset($matches[1][0])) {
				if (!is_numeric($matches[1][0])) {
					continue;
				}
				$object = eqLogic::byId($matches[1][0]);
				if (!is_object($object)) {
					cache::delete($key);
				}
			}
			preg_match_all('/scenarioHtml(.*?)(\d*)/', $key, $matches);
			if (isset($matches[1][0])) {
				if (!is_numeric($matches[1][0])) {
					continue;
				}
				$object = scenario::byId($matches[1][0]);
				if (!is_object($object)) {
					cache::delete($key);
				}
			}
			if (strpos($key, 'widgetHtmlmobile') !== false) {
				$id = str_replace('widgetHtmlmobile', '', $key);
				if (is_numeric($id)) {
					cache::delete($key);
				}
				continue;
			}
			if (strpos($key, 'widgetHtmldashboard') !== false) {
				$id = str_replace('widgetHtmldashboard', '', $key);
				if (is_numeric($id)) {
					cache::delete($key);
				}
				continue;
			}
			if (strpos($key, 'widgetHtmldplan') !== false) {
				$id = str_replace('widgetHtmldplan', '', $key);
				if (is_numeric($id)) {
					cache::delete($key);
				}
				continue;
			}
			if (strpos($key, 'widgetHtml') !== false) {
				$id = str_replace('widgetHtml', '', $key);
				if (is_numeric($id)) {
					cache::delete($key);
				}
				continue;
			}
			if (strpos($key, 'cmd') !== false) {
				$id = str_replace('cmd', '', $key);
				if (is_numeric($id)) {
					cache::delete($key);
				}
				continue;
			}
			preg_match_all('/dependancy(.*)/', $key, $matches);
			if (isset($matches[1][0])) {
				try {
					$plugin = plugin::byId($matches[1][0]);
					if (!is_object($plugin)) {
						cache::delete($key);
					}
				} catch (Exception $e) {
					cache::delete($key);
				}
			}
		}
	}

	/*     * *********************Methode d'instance************************* */

	public function save() {
		if(config::byKey('cache::engine') == 'MariadbCache'){
			return MariadbCache::save($this->getKey(),$this->getValue(),$this->getLifetime(),$this->getOptions());
		}
		$this->setDatetime(date('Y-m-d H:i:s'));
		if ($this->getLifetime() == 0) {
			return self::getCache()->save($this->getKey(), $this);
		} else {
			return self::getCache()->save($this->getKey(), $this, $this->getLifetime());
		}
	}

	public function remove() {
		try {
			self::getCache()->delete($this->getKey());
		} catch (Exception $e) {
		}
	}

	/*     * **********************Getteur Setteur*************************** */

	public function getKey() {
		return $this->key;
	}

	public function setKey($key): self {
		$this->key = $key;
		return $this;
	}

	public function getValue($_default = '') {
		return ($this->value === null || (is_string($this->value) && trim($this->value) === '')) ? $_default : $this->value;
	}

	public function setValue($value): self {
		$this->value = $value;
		return $this;
	}

	public function getLifetime() {
		return $this->lifetime;
	}

	public function setLifetime($lifetime): self {
		$this->lifetime = $lifetime;
		return $this;
	}

	public function getDatetime() {
		return $this->datetime;
	}

	public function setDatetime($datetime): self {
		$this->datetime = $datetime;
		return $this;
	}

	public function getOptions($_key = '', $_default = '') {
		return utils::getJsonAttr($this->options, $_key, $_default);
	}

	public function setOptions($_key, $_value = null): self {
		$this->options = utils::setJsonAttr($this->options, $_key, $_value);
		return $this;
	}
}


class MariadbCache {

	public static function clean(){
		$sql = 'DELETE 
		FROM cache
		WHERE (UNIX_TIMESTAMP(`datetime`)+`lifetime`) < UNIX_TIMESTAMP()';
		return  DB::Prepare($sql,array(), DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS);
	}

	public static function fetch($_key){
		$sql = 'SELECT *
		FROM cache
		WHERE `key`=:key';
		$return = DB::Prepare($sql,array('key' => $_key), DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS,'cache');
		if($return === false){
			return null;
		}
		if($return->getLifetime() > 0 && (strtotime($return->getDatetime()) + $return->getLifetime()) < strtotime('now')){
			return null;
		}
		return $return;
	}

	public static function delete($_key){
		$sql = 'DELETE 
		FROM cache
		WHERE `key`=:key';
		return  DB::Prepare($sql,array('key' => $_key), DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS);
	}

	public static function deleteAll(){
		return  DB::Prepare('TRUNCATE cache',array(), DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS);
	}

	public static function save($_key, $_value, $_lifetime = -1, $_options = null){
		$options = null;
		if(is_array($_value)){
			$_value = json_encode($_value, JSON_UNESCAPED_UNICODE);
		}
		if(is_array($_options)){
			if(count($_options) == 0){
				$_options = null;
			}else{
				$_options = json_encode($_options, JSON_UNESCAPED_UNICODE);
			}
		}
		$value = array(
			'key' => $_key,
			'value' => $_value,
			'options' => $_options,
			'lifetime' =>$_lifetime,
			'datetime' => date('Y-m-d H:i:s')
		);
		$sql = 'REPLACE INTO cache SET `key`=:key, `value`=:value,`datetime`=:datetime,`lifetime`=:lifetime,`options`=:options';
		return  DB::Prepare($sql,$value, DB::FETCH_TYPE_ROW);
	}

}
