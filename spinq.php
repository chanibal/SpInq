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
		$logger=ini_get('error_log');
		if(empty($logger))
		{
			// color only console output
			return "\033[{$c}m{$msg}\033[0m";
		}
		else
		{
			// this probably gets written to a file or syslog
			return $msg;
		}
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

	/**
	 * @var string
	 */
	protected $baseuri;

	/**
	 * @var callable[] 
	 */
	protected $actions;

	/**
	 * @var Debug
	 */
	protected $debug;

	/**
	 * Guessing a base uri when none was provided.
	 * Use only when desperate.
	 */
	protected function guessBaseURI() {
		if(!isset($_SERVER['HTTP_HOST'])) {
			$this->debug->info("Looks like this is a non-web script, returning a dummy address.");
			return 'http://localhost/';
		}

		$protocol = (@$_SERVER['HTTPS'] == 'on' || @$_SERVER['REQUEST_SCHEME'] == 'https') ? 'https' : 'http';
		$this->debug->info("Guessed protocol $protocol");

		$hostAndPort = $_SERVER['HTTP_HOST'] ?: (
			(isset($_SERVER['SERVER_PORT']) && @$_SERVER['SERVER_PORT'] != 80) 
				? "localhost:{$_SERVER['SERVER_PORT']}" : 'localhost');
		$this->debug->info("Guessed host and port: $hostAndPort");

		$path = parse_url(@$_SERVER['REQUEST_URI'] ?: '/', PHP_URL_PATH);
		$this->debug->info("Guessed path: $path");

		if($path[strlen($path) - 1] != '/') {
			$path = dirname($path);
			$this->debug->info("Guessed path with removed last element: $path");
		}
		if($path[strlen($path) - 1] != '/') {
			$path .= '/';
		}

		return "{$protocol}://{$hostAndPort}{$path}";
	}

	public function __construct($baseuri = null) {
		$this->debug = new Debug('Router');
		if ($baseuri) {
			$this->baseuri = $baseuri;
		}
		else {
			$this->baseuri = $this->guessBaseURI();
			$this->debug->warn("No baseuri provided, had to guess: '{$this->baseuri}'");
		}



	}

	public function link(array $actionAndArgs = [], $absolute = false) {
		$this->debug->dump($actionAndArgs, 'building link');
		$link = http_build_query($actionAndArgs);
		return ($absolute)
			?($this->baseuri . '?' . $link)
			:('?' . $link);
		// TODO: return class instance that has a toString for that?
	}

	/**
	 * Registers an action to this router
	 * @param string $actionName The name of the action that will be registered, must be unique
	 * @param callable $action A callable with arguments: array $args, Router $router
	 * @return void
	 */
	public function register($actionName, $action) {
		// TODO: array $defaultArgs = []
		if(!is_callable($action))
			throw new \InvalidArgumentException('Action not callable');
		if(isset($this->actions[$actionName]))
			throw new \InvalidArgumentException('Action name already used: '.$actionName);
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
	protected $httpCode = 200;
	public function httpCode() { $this->httpCode; }
	public abstract function render();
	public function __toString() { return $this->render(); }
}

class JSONView extends View {
	protected $object;
	public function __construct($object, $httpCode = 200) {
		$this->object = $object;
		$this->httpCode = $httpCode;
	}

	public function headers() { return [ 'Content-Type' => 'application/json; charset=utf-8' ]; }
	public function render() { return json_encode($this->object, JSON_UNESCAPED_SLASHES); }
}

class HTMLView extends View {

	protected $layouts = [];
	protected $arguments;

	public function __construct($template, $arguments, $httpCode = 200) {
		$this->wrapIn($template);
		$this->arguments = $arguments;
		$this->httpCode = $httpCode;
	}

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
		$debug->dump($argv, "argv");
		if(!isset($argv[1]))
			throw new RuntimeException('CLI syntax <script> <action> [<arugments in json form>]');
		if(isset($argv[2])) {
			$args = json_decode($argv[2], true);
			$errmsg = json_last_error_msg();
			if(json_last_error())
				throw new RuntimeException("Could not parse argument json string: {$err}, json='{$argv[2]}'.");
		}
		else {
			$args = [];
		}
		$debug->dump($args, "parsed args");
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
