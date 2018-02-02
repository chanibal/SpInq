<?php

date_default_timezone_set('Europe/Warsaw');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	if((error_reporting() & $errno))
		throw new RuntimeException("$errstr in $errfile#$errline");
});



class Debug {
	protected $tag;


	protected static function ansi($c, $msg) { 
		return "\033[{$c}m{$msg}\033[0m"; 
	}


	public function info($msg) {
		error_log(self::ansi("1;32", "{$this->tag} info: ") . self::ansi(32, $msg)); 
	}


	public function warn($msg) { 
		error_log(self::ansi("1;33", "{$this->tag} warn: ") . self::ansi(33, $msg)); 
	}


	public function verbose($msg) { 
		error_log(self::ansi("0;37", "{$this->tag} verbose: ") . self::ansi("1;30", $msg)); 
	}


	public function error($msg) { 
		error_log(self::ansi("1;31", "{$this->tag} error: ") . self::ansi(31, $msg)); 
	}


	public function dump($obj, $label = null) { 
		ob_start();
		var_dump($obj);
		$msg = ob_get_clean();
		if($label) {
			$msg = "$label $msg";
		}
		error_log(self::ansi("1;34", "{$this->tag} dump: ") . self::ansi(34, $msg)); 
	}


	public function __construct($tag) {
		$this->tag = $tag;
	}

}


class ActionNotFoundException extends \RuntimeException {}


class Router {

	protected $baseuri;
	protected $actions;
	protected $debug;

	public function __construct($baseuri) {
		$this->baseuri = $baseuri;
		$this->debug = new Debug('Router');
	}

	public function link(array $actionAndArgs = [], $absolute = false) {
		$this->debug->dump($actionAndArgs, 'building link');
		$link = http_build_query($actionAndArgs);
		return ($absolute)
			?($this->baseuri . '?' . $link)
			:('?' . $link);
		// TODO: return class instance that has a toString for that?
	}

	public function register($actionName, $action) {
		// TODO: array $defaultArgs = []
		if(!is_callable($action))
			throw new \InvalidArgumentException('Action not callable');
		$this->debug->dump($actionName, 'registering action');
		$this->actions[$actionName] = $action;
	}

	public function execute($request) {
		if(!isset($request['action']))
            throw new ActionNotFoundException('No action requested');
        $actionName = $request['action'];

        if(!isset($this->actions[$actionName]))
            throw new ActionNotFoundException();
        $action = $this->actions[$actionName];

        $action($request);
	}

}


// TODO: needs flocking
class Entity {
	protected $path;
	protected $data;

	public function save() {
		$s = serialize($this->data);
		file_put_contents($this->path, $s);
	}

	protected function __construct(array $data, $path) {
		$this->data = $data;
		$this->path = $path;
	}

	public function __get($key) { return $this->data[$key]; }
	public function __set($key, $val) { $this->data[$key] = $val; }

	/**
	 * @return Entity
	 */
	public static function load($path) {
		if(!file_exists($path))
			return null;
		$s = file_get_contents($path);
		$d = unserialize($f);
		$e = new Entity($data, $path);
		return $e;
	}

	/**
	 * @return Entity
	 */
	public static function create($path) {
		if(file_exists($path))
			throw new RuntimeException("Entity $path already exists");
		return new Entity(array(), $path);
	}
}
