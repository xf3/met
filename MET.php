<?php

namespace xf3;

/**
 * Class MET
 */
class MET {
	/**
	 * @var int Minimum remaining to max_execution_time time
	 */
	public $limit = 5;

	/**
	 * @var string Key used to store values in $_SESSION or file name
	 */
	protected $storageKey = 'MTE_storage';

	/**
	 * @var bool Whether run in CLI
	 */
	protected $cli;

	/**
	 * @var string Argument used to call `php script.php {key}` to check whether exec is accessible
	 */
	protected $execCheckKey = 'met_check_exec';

	/**
	 * @var string Answer used to responde to check call
	 */
	protected $execCheckAnswer = 'met_check_exec_ok';

	/**
	 * @var string CLI-call arguments string
	 */
	protected $cliReloadArgs;

	/**
	 * @var bool Whether exec() check is being performed
	 */
	protected $execCheck = false;

	/**
	 * @var array Stored values to be used among runs
	 */

	protected $values;
	/**
	 * @var int ini 'max_execution_time' value
	 */
	protected $max_time;

	/**
	 * @var bool Whether script reload is being performed
	 */
	protected $reloading = false;

	/**
	 * Checks whether data storage is available
	 *
	 * @param string $storageKey Optional key to storage filename (CLI mode) or $_SESSION key
	 *
	 * @throws Exception
	 */
	public function __construct($storageKey = null)
	{
		$this->cli = php_sapi_name() === 'cli';

		/**
		 * If exec() check is being performed, print right answer and exit
		 */
		if ($this->cli && !empty($GLOBALS['argv']) && in_array($this->execCheckKey, $GLOBALS['argv'])) {
			$this->execCheck = true;
			print $this->execCheckAnswer;
			exit;
		}

		if ($storageKey !== null) {
			$this->storageKey = $storageKey;
		}

		if ($this->cli) {
			$this->cliReloadArgs = join(' ', $GLOBALS['argv']);

			if (exec('php '.$this->cliReloadArgs.' '.$this->execCheckKey) !== $this->execCheckAnswer) {
				throw new Exception('MET: exec() not accessible');
			}

			$fExists = file_exists($this->storageKey);

			if (
				(!$fExists && !is_writable(dirname($this->storageKey) . '/'))
				|| ($fExists && !is_writable($this->storageKey))
			) {
				throw new Exception('MET: ' . $this->storageKey . ' is not writable, can\'t save data');
			}
		} else {
			/**
			 * IDE warning fix
			 */
			$fExists = false;

			if (!session_id()) {
				session_start();
			}

			if (!session_id()) {
				throw new Exception('MET: can\'t start session');
			}
		}

		$this->max_time = ini_get('max_execution_time');

		if ($this->max_time === '') {
			throw new Exception('MET: can\'t retrive `max_execution_time`');
		}

		$this->max_time = intval($this->max_time);

		$this->values = (
			$this->cli
				? (
					$fExists ? unserialize(file_get_contents($this->storageKey)) : array()
				)
				: (
					isset($_SESSION[$this->storageKey]) ? $_SESSION[$this->storageKey] : array()
				)
		);
	}

	/**
	 * Saves data if it exists or cleans via $this::clean()
	 */
	public function __destruct()
	{
		if ($this->execCheck || $this->reloading) return;

		$this->clean();
	}

	/**
	 * Returns parameter value by key
	 *
	 * @param string $key Multilevel key (e.g. 'firstLevel.second')
	 * @param mixed $default Default value to be used, if no data currently stored for given key
	 *
	 * @return mixed
	 */
	public function value($key, $default = null)
	{
		$return = $this->getPath($this->values, $key);

		return $return !== null ? $return : $default;
	}

	/**
	 * Performs time check and script reload if critical value is near
	 */
	public function check()
	{
		$args = func_get_args();

		$argsN = func_num_args();

		if ($argsN && is_callable($args[$argsN-1])) {
			$callback = $args[$argsN-1];
			unset($args[$argsN-1]);
		} else {
			$callback = false;
		}

		if ($args) {
			call_user_func_array(array($this, 'update'), $args);
		}

		if ($this->max_time > 0 && $this->max_time - time() + $_SERVER['REQUEST_TIME'] < $this->limit) {
			if ($this->values) $this->save();

			$this->reloading = true;

			/**
			 * @var callable $callback
			 */
			if ($callback) $callback();

			if ($this->cli) {
				exec('php '.$this->cliReloadArgs);
			} else {
				header('Location: '.$_SERVER['REQUEST_URI']);
			}

			exit;
		}
	}

	/**
	 * Updates one or several keys with new data.<br>
	 * If two arguments passed, first will be used as key, second as value
	 * If one array passed, values will be updated in accordance to it's key=>value
	 *
	 * @return bool True on good arguments, false otherwise
	 */
	public function update()
	{
		$args = func_get_args();

		switch (func_num_args()) {
			case 1:
				foreach ($args[0] as $key => $value) {
					$this->setPath($this->values, $key, $value);
				}

				return true;
			break;

			case 2:
				$this->setPath($this->values, $args[0], $args[1]);
				return true;
			break;
		}

		return false;
	}

	/**
	 * Removes data in store by key
	 * @param string $key Data's key to be removed
	 */
	public function delete($key)
	{
		$this->setPath($this->values, $key, null);
	}

	/**
	 * Unlinks storage file or empties $_SESSION's storage key
	 */
	public function clean() {
		if ($this->cli) {
			if (is_file($this->storageKey)) unlink($this->storageKey);
		} else {
			unset($_SESSION[$this->storageKey]);
		}
	}

	/**
	 * Saves current values to storage
	 */
	protected function save() {
		if ($this->cli) {
			file_put_contents($this->storageKey, serialize($this->values));
		} else {
			$this->setPath($_SESSION, $this->storageKey, $this->values);
		}
	}

	/**
	 * Gets array value by multilevel key
	 *
	 * @param array $array Array to get value from
	 * @param string $path Multilevel array key (e.g. 'firstLevel.second')
	 *
	 * @return mixed null if specified key not found, value otherwise
	 */
	protected function getPath($array, $path)
	{
		$path = explode('.', $path);

		$n = sizeof($path);

		for ($i = 0; $i < $n; $i++) {
			if (!isset($array[$path[$i]]) || ($i !== $n - 1 && (!is_array($array[$path[$i]]) && !is_string($array[$path[$i]])))) return null;

			$array = $array[$path[$i]];
		}

		return $array;
	}

	/**
	 * Updates array value by multilevel key
	 *
	 * @param array $array Array reference to update
	 * @param string $path Multilevel array key (e.g. 'firstLevel.second')
	 * @param mixed $value New value to set
	 */
	protected function setPath(&$array, $path, $value)
	{
		$path = explode('.', $path);

		$n = sizeof($path);

		$sub = & $array;

		for ($i = 0; $i < $n; $i++) {
			if ($path[$i] === '') continue;

			if (!isset($sub[$path[$i]])) {
				if ($i === $n - 1) {
					$sub[$path[$i]] = $value;

					return;
				} else {
					$sub[$path[$i]] = array();
				}
			} else {
				if (!is_array($sub[$path[$i]]) && $i !== $n - 1) {
					$sub[$path[$i]] = array();
				}
			}

			$subParent = & $sub;
			$sub = & $sub[$path[$i]];
		}

		if ($value === null) {
			if (isset($subParent)) {
				unset($subParent[$path[$n - 1]]);
			} else {
				unset($sub);
			}
		} else {
			$sub = $value;
		}
	}
}
