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
            throw new ActionNotFoundException("No action of name $actionName found");
        $action = $this->actions[$actionName];

        return $action($request, $this);
	}

}


// TODO: needs flocking
class Entity {
	protected $path;
	protected $data;

	public function save() {
		$serialized = serialize($this->data);
		file_put_contents($this->path, $serialized);
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
		$serialized = file_get_contents($path);
		$data = unserialize($serialized);
		$entity = new Entity($data, $path);
		return $entity;
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



function guid() {
	return sprintf(
		'%04X%04X-%04X-%04X-%04X-%04X%04X%04X', 
		mt_rand(0, 0xFFFF),
		mt_rand(0, 0xFFFF),
		mt_rand(0, 0xFFFF),
		mt_rand(0x4000, 0x4FFF),
		mt_rand(0, 0xFFFF),
		mt_rand(0, 0xFFFF),
		mt_rand(0, 0xFFFF),
		mt_rand(0, 0xFFFF)
	);
}


abstract class View {
	public function headers() { return []; }
	public function httpCode() { return 200; }
	public abstract function render();
	public function __toString() { return $this->render(); }
}

class JSONView extends View {
	protected $object;
	public function __construct($object) {
		$this->object = $object;
	}

	public function headers() { return [ 'Content-Type' => 'application/json; charset=utf-8' ]; }
	public function render() { return json_encode($this->object); }
}

class HTMLView extends View {

	protected $layouts = [];
	protected $arguments;
	protected $httpCode;

	public function __construct($template, $arguments, $httpCode = 200) {
		$this->wrapIn($template);
		$this->arguments = $arguments;
		$this->httpCode = $httpCode;
	}

	public function httpCode() { return $this->httpCode; }

	public function headers() { return [ 'Content-Type' => 'text/html; charset=utf-8' ]; }

	public function render() {
		$debug=new Debug('HTMLView');
		extract($this->arguments);
		while($template = array_pop($this->layouts)) {
			$debug->info("Rendering template $template");
			ob_start();
			try { include $template; }
			finally { $contents = ob_get_clean(); }
		}
		return $contents;
	}


	public function wrapIn($template) {
		array_push($this->layouts, $template);
	}

	public function anchor($url, $text) {
		$urlEscaped = htmlspecialchars($url);
		$textEscaped = htmlentities($text);
		return "<a href=\"{$urlEscaped}\">{$textEscaped}</a>";
	}
}

/**
 * @return View
 */
function executeCurrentAction(Router $router) {
	$debug = new Debug(__FUNCTION__);

	if(isset($GLOBALS['argv'])) {
		$argv = $GLOBALS['argv']; 
		$debug->dump($argv);
		if(count($argv) < 2)
			throw new RuntimeException('CLI syntax <script> <action> [<arugments in json form>]');
		$args = count($argv > 2) ? json_decode($argv[2], true) : [];
		var_dump($args);
		$args['action'] = $argv[1];
		$debug->dump($args, 'executing from cli');
	}
	else {
		$args=$_GET + $_POST;
		$debug->dump($args, 'executing from web');
	}

	$view = $router->execute($args, $router);
	if($view instanceof View)
		return $view;
	throw new RuntimeException('Action did not return view');
}
