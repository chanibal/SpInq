<?php

namespace SpInq;

if(version_compare(PHP_VERSION, '5.3.0') < 0)
	die("SpInq requires PHP 5.3+");

date_default_timezone_set('Europe/Warsaw');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	if((error_reporting() & $errno))
		throw new \RuntimeException("$errstr in $errfile#$errline");
});
set_exception_handler(function($ex) {
	Debug::main()->error($ex);
});




class Debug {
	protected $tag;


	private static $mainInstance;


	/**
	 * Global instance debug, to be used only when no tags are required
	 */
	static public function main() 
	{
		if(self::$mainInstance == null) self::$mainInstance = new Debug("main");
		return self::$mainInstance;
	}


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
		$this->log(self::ansi("1;32", "{$this->tag} info: ") . self::ansi(32, $msg)); 
	}


	public function warn($msg) { 
		$this->log(self::ansi("1;33", "{$this->tag} warn: ") . self::ansi(33, $msg)); 
	}


	public function verbose($msg) { 
		$this->log(self::ansi("0;37", "{$this->tag} verbose: ") . self::ansi("1;30", $msg)); 
	}


	public function error($msg) { 
		$this->log(self::ansi("1;31", "{$this->tag} error: ") . self::ansi(31, $msg)); 
	}


	public function dump($obj, $label = null) { 
		$msg = var_export($obj, true);
		if($label) {
			$msg = "$label $msg";
		}
		$this->log(self::ansi("1;34", "{$this->tag} dump: ") . self::ansi(34, $msg)); 
	}


	protected function log($msg) {
		error_log($msg);
		error_log($msg, 3, 'spinq-log.txt');
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
	 * @var callable[]
	 */
	protected $actionProblemMatcher;

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
	 * @param callable? $actionProblemMatcher A Callable that will translate any exceptions from calling this action to a View instance, defaults to rfc7807 problem+json.
	 * @return void
	 */
	public function register($actionName, $action, $actionProblemMatcher = null) {
		// TODO: array $defaultArgs = []
		if(!is_callable($action))
			throw new \InvalidArgumentException('Action not callable');
		if(isset($this->actions[$actionName]))
			throw new \InvalidArgumentException('Action name already used: '.$actionName);
		$this->debug->dump($actionName, 'registering action');
		$this->actions[$actionName] = $action;
		if($actionProblemMatcher != null)
			$this->actionProblemMatcher[$actionName] = $actionProblemMatcher;
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

	public function getMatchedProblemViewForException($request, \Exception $ex) {
		$actionName = @$request['action'];
		
		if(!isset($this->actionProblemMatcher[$actionName])) {
			$problemView = ProblemDetailsView::fromException($ex);
		}
		else {
			$problemView = $this->actionProblemMatcher[$actionName]($ex);
		}

		if ($problemView == null || !($problemView instanceof View))
			return new ProblemDetailsView("problem-matcher-problem", "Problem matcher did not return a view");
		return $problemView;
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
	public function httpCode() { return $this->httpCode; }
	public abstract function render();
	public function __toString() { return $this->render(); }
}

class JSONView extends View {
	public function __construct(protected mixed $object, int $httpCode = 200, protected ?string $jsonp = null) { $this->httpCode = $httpCode; }
	public function headers() { return [ 'Content-Type' => ($this->jsonp !== null) ? 'application/javascript; charset=utf-8' : 'application/json; charset=utf-8' ]; }
	public function render() { $j = json_encode($this->object, JSON_UNESCAPED_SLASHES); return ($this->jsonp !== null) ? "{$this->jsonp}($j);" : $j;}
}


class ProblemDetailsView extends JSONView {
	public function __construct($type, $title, $httpCode = 500, $extended = []) {
		$object = ["type" => $type, "title" => $title] + $extended;
		parent::__construct($object, $httpCode);
	}

	public static function fromException(\Exception $ex, $httpCode = 500) {
		return new ProblemDetailsView('https://github.com/chanibal/spinq/problems/' . get_class($ex), $ex->getMessage(), $httpCode, [ "trace" => $ex->getTrace() ]);
	}

	public function headers() { return [ 'Content-Type' => 'application/problem+json; charset=utf-8' ]; }
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

	public function escape($text) {
		return htmlentities($text);
	}
}


class RedirectView extends View {

	protected $uri;
	protected $httpCode;
	protected $message;

	public function __construct($uri, $httpCode = 302, $message = null) {
		$this->uri = $uri;
		$this->httpCode = $httpCode;
		$this->message = $message;
	}

	public function render() {
		$debug = new Debug('RedirectView');
		$debug->info("Redirecting to '{$this->uri}' with HTTP code {$this->httpCode} and message = '{$this->message}'");
		header("location: {$this->uri}", $this->httpCode);
		if($this->message) {
			print $this->message;
		}
	}

}


/**
 * Helper for executing a current action from HTTP or CLI
 * @return View
 */
function executeCurrentAction(Router $router, $defaultArgs = []) {
	$debug = new Debug(__FUNCTION__);

	$headers = [];
	$contents = null;

	try {
		if(isset($GLOBALS['argv'])) {
			$argv = $GLOBALS['argv']; 
			$debug->dump($argv, "argv");
			if(!isset($argv[1]))
				throw new \RuntimeException('CLI syntax <script> <action> [<arugments in json form>]');
			if(isset($argv[2])) {
				$args = json_decode($argv[2], true);
				$errmsg = json_last_error_msg();
				if(json_last_error())
					throw new \RuntimeException("Could not parse argument json string: {$errmsg}, json='{$argv[2]}'.");
			}
			else {
				$args = [];
			}
			$debug->dump($args, "parsed args");
			$args['action'] = $argv[1];
			$debug->dump($args, 'executing from cli');
		}
		else {
			$args=$_GET;
			$debug->dump($args, 'executing from web');
		}

		if(empty($args))
			$args = $defaultArgs;

		$view = $router->execute($args, $router);
		if(!($view instanceof View))
			throw new \RuntimeException('Action did not return view');
		$headers = $view->headers();
		$contents = $view->render();
	}
	catch(\Exception $ex) {
		$view = $router->getMatchedProblemViewForException($args, $ex);
		$headers = $view->headers();
		$contents = $view->render();
	}

	foreach ($headers as $key => $value)
		header("$key: $value", true, $view->httpCode());
	echo($contents);
}

/**
 * Database abstraction with schema migrations and helpers.
 * Usage: extend from class, add custom helpers for data
 * Add methods for migrations like `migrateTo1`, `migrateTo2` etc.
 * Database will always be migrated to newest version.
 */
abstract class Database {
    protected $db;

    protected $logger;

    // overload to false to disable
    protected $automigrate = true;

    public function __construct($dsn = 'sqlite:database.db', $user = null, $pass = null) {
        $this->logger = new Debug('database');

        $this->db = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Check current DB schema
        $dbSchema = null;
        try {
            $result = $this->select("select max(version) as version from Schema");
            if(count($result) > 0)
                $dbSchema = $result[0]["version"];
            else
                $dbSchema = 0;
        }
        catch(\PDOException $ex) {
            // will fail at preparing if there is no Schema table, reset it
            $this->logger->warn("Checking schema failed, resetting db. Reason: $ex");
            $dbSchema = 0;
            $this->execute("drop table if exists Schema");
            $this->execute("create table Schema(version INTEGER primary key, updated INTEGER)");
        }

        // Check code DB schema
        for($codeSchema = 0; method_exists($this, "migrateTo".($codeSchema + 1)); $codeSchema++)
        { ; }

        $this->logger->info("Schema: db = $dbSchema, code = $codeSchema");

        if($codeSchema < $dbSchema)
            throw new \RuntimeException("Database schema version ($dbSchema) is newer than code schema ($codeSchema)");

        if($dbSchema < $codeSchema) {
            $this->logger->info("Migrating from $dbSchema to $codeSchema...");
            if (!$this->automigrate)
                throw new \RuntimeException("Automatic migrations are disabled");
            for ($schema = $dbSchema + 1; $schema <= $codeSchema; $schema++) {
                $this->logger->info("Migrating to $schema");
                $this->insert("insert into Schema(version, updated) values(?, ?)", [$schema, time()]);
                $migrationFunc = "migrateTo$schema";
                $this->$migrationFunc();
            }
            $this->logger->info("Migration succeeded");
        }
    }

    /**
     * Executes SELECT SQL query
     * @returns array of assoc array of data
     */
    protected function select($sql, array $params = [], ?string $into = null) {
        $stmt = $this->db->prepare($sql);
        if(!$stmt->execute($params))
			throw new \RuntimeException("Could not execute sql '$sql'");
		if($into) {
			$stmt->setFetchMode(\PDO::FETCH_CLASS, $into);
		}
        $result = $stmt->fetchAll();
        $this->logger->verbose("SQL select '$sql' with args=".var_export($params, true)." returned ".count($result)." rows into='$into'");
        return $result;
    }

    protected function selectOne(string $sql, array $params = [], ?string $into = null) {
        $results = $this->select($sql, $params, $into);

        if(count($results) != 1)
            throw new \RuntimeException("Could not find row");
        else
            return $results[0];
    }

    /**
     * Executes CREATE, UPDATE etc. SQL query
     * @returns affected rows
     */
    protected function execute($sql, array $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $affectedRows = $stmt->rowCount();
        $this->logger->verbose("SQL execute '$sql' with args=".var_export($params, true)." affected $affectedRows rows");
        return $affectedRows;
    }

    /**
     * Executes INSERT SQL query
     * @returns last insert id
     */
    protected function insert($sql, array $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $lastInsertId = $this->db->lastInsertId();
        $this->logger->verbose("SQL insert '$sql' with args=".var_export($params, true)." last insert id = $lastInsertId");
        return $lastInsertId;
    }

	/**
	 * Executes INSERT SQL query on table with column names in keys and values in values of the array
	 * Values will be escaped automatically unless key is prepended with '!'
	 * @example insertAutomatic('Tab', [v=>1, s=>"asdf", "!updated"=>"datetime(now)"])
	 * @returns last insert id
	 */
	protected function insertAutomatic($table, array $values) {
		$columns=[];
		$bindings=[];
		$params=[];
		foreach ($values as $key => $value) {
			if($key[0] == '!') 
			{
				$columns[] = substr($key, 1);
				$bindings[] = $value;
			}
			else
			{
				$columns[] = $key;
				$bindings[] = ":$key";
				$params[] = $value;
			}
		}
		
		$sql = "INSERT INTO $table(" . join(', ', $columns) . ') values('. join(', ', $bindings) . ')';
		$this->logger->verbose("Generated SQL $sql from: ".json_encode($values));
		return $this->insert($sql, $params);
	}
}

