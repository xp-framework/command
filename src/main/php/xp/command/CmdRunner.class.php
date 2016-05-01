<?php namespace xp\command;

use util\cmd\ParamString;
use util\cmd\Config;
use io\File;
use util\log\Logger;
use util\log\context\EnvironmentAware;
use util\PropertyManager;
use util\PropertyAccess;
use util\Properties;
use rdbms\ConnectionManager;
use lang\XPClass;
use lang\System;
use lang\ClassLoader;
use lang\ClassNotFoundException;
use lang\reflect\TargetInvocationException;
use lang\Throwable;
use xp\runtime\Help;

/**
 * Runs util.cmd.Command subclasses on the command line.
 * ========================================================================
 *
 * - Run a command class from the classpath
 *   ```sh
 *   $ xp cmd com.example.Query
 *   ```
 * - Show usage for a given command
 *   ```sh
 *   $ xp cmd com.example.Query -?
 *   ```
 * - Run a command class from a file
 *   ```sh
 *   $ xp cmd Query.class.php
 *   ```
 * - Pass configuration directories other than `./etc`:
 *   ```sh
 *   $ xp cmd -c etc/default -c etc/prod com.example.Query
 *   ```
 *
 * Supports the convention that *log.ini* will contain the logger
 * configuration, and *database.ini* will contain database connections.
 *
 * Pass `-v` to see more verbose output from argument handling.
 *
 * @test  xp://net.xp_framework.unittest.util.cmd.RunnerTest
 * @see   xp://util.cmd.Command
 */
class CmdRunner extends AbstractRunner {

  static function __static() { }

  /**
   * Shows usage
   *
   * @param  lang.XPClass $class
   * @return int
   */
  protected function showUsage(XPClass $class) {
    $comment= $class->getComment();
    if ('' === (string)$comment) {
      $markdown= '# '.$class->getSimpleName()."\n\n";
      $text= '';
    } else {
      @list($headline, $text)= explode("\n", $comment, 2);
      $markdown= '# '.ltrim($headline, ' #')."\n\n";
    }

    $markdown.= "- Usage\n  ```sh\n$ xp cmd ".$class->getName();

    $extra= $details= $positional= [];
    foreach ($class->getMethods() as $method) {
      if (!$method->hasAnnotation('arg')) continue;

      $arg= $method->getAnnotation('arg');
      $name= strtolower(preg_replace('/^set/', '', $method->getName()));
      $optional= 0 === $method->numParameters() || $method->getParameters()[0]->isOptional();
      $comment= $method->getComment();

      if (isset($arg['position'])) {
        $details[$name]= [$comment, null];
        $positional[$arg['position']]= $name;
      } else if (isset($arg['name'])) {
        $details['--'.$arg['name']]= [$comment, isset($arg['short']) ? $arg['short'] : $arg['name']{0}];
        $extra[$arg['name']]= $optional;
      } else {
        $details['--'.$name]= [$comment, isset($arg['short']) ? $arg['short'] : $name{0}];
        $extra[$name]= $optional;
      }
    }

    // Usage
    asort($positional);
    foreach ($positional as $name) {
      $markdown.= ' '.$name;
    }
    foreach ($extra as $name => $optional) {
      $markdown.= ' '.(($optional ? '[' : '').'--'.$name.($optional ? '] ' : ' '));
    }
    $markdown.= "\n  ```\n";

    // Argument details
    foreach ($details as $which => $detail) {
      $markdown.= sprintf(
        "  **%s**: %s%s\n\n",
        $which,
        str_replace("\n", "\n  ", $detail[0]),
        $detail[1] ? ' *(also: -'.$detail[1].')*' : ''
      );
    }

    Help::render(self::$err, substr($markdown, 0, -1), $class->getClassLoader());
  }

  /**
   * Displays usage
   *
   * @return  int exitcode
   */
  protected function usage() {
    self::$err->writeLine('Runs commands: `xp cmd [class]`. xp help cmd has the details!');
    return 1;
  }

  private function findCommand($cl, $name, $package) {
    $file= $name.\xp::CLASS_FILE_EXT;
    foreach ($cl->packageContents($package) as $resource) {
      if ($file === $resource) {
        return ($package ? $package.'.' : '').'.'.$name;
      } else if ('/' === $resource{strlen($resource) - 1}) {
        if ($uri= $this->findCommand($cl, $name, ($package ? $package.'.' : '').substr($resource, 0, -1))) return $uri;
      }
    }
    return null;
  }

  private function findClass($name) {
    $class= implode('', array_map('ucfirst', explode('-', $name)));
    foreach (ClassLoader::getLoaders() as $cl) {
      if ($command= $this->findCommand($cl, $class, null)) return $cl->loadClass($command);
    }
    throw new ClassNotFoundException($class);
  }

  /**
   * Main method
   *
   * @param  util.cmd.ParamString $params
   * @param  util.cmd.Config $config
   * @return int
   */
  public function run(ParamString $params, Config $config= null) {

    // No arguments given - show our own usage
    if ($params->count < 1) return $this->usage();

    // Configure properties
    $config || $config= new Config();

    // Separate runner options from class options
    for ($offset= 0, $i= 0; $i < $params->count; $i++) switch ($params->list[$i]) {
      case '-c':
        $config->append($params->list[$i+ 1]);
        $offset+= 2; $i++;
        break;
      case '-v':
        $this->verbose= true;
        $offset+= 1; $i++;
        break;
      case '-?':
        return $this->usage();
      default:
        break 2;
    }
    
    // Sanity check
    if (!$params->exists($offset)) {
      self::$err->writeLine('*** Missing classname');
      return 1;
    }

    // Use default path for config if no sources set
    if ($config->isEmpty()) {
      $config->append(is_dir(self::DEFAULT_CONFIG_PATH) ? self::DEFAULT_CONFIG_PATH : '.');
    }

    unset($params->list[-1]);
    $classname= $params->value($offset);
    $classparams= new ParamString(array_slice($params->list, $offset+ 1));

    // Class file or class name
    $cl= ClassLoader::getDefault();
    if (strstr($classname, \xp::CLASS_FILE_EXT)) {
      $file= new File($classname);
      if (!$file->exists()) {
        self::$err->writeLine('*** Cannot load class from non-existant file ', $classname);
        return 1;
      }

      $class= $cl->loadUri($file->getURI());
    } else if ($cl->providesClass($classname)) {
      $class= $cl->loadClass($classname);
    } else {
      try {
        $class= $this->findClass($classname);
      } catch (ClassNotFoundException $e) {
        self::$err->writeLine('*** ', $this->verbose ? $e : $e->getMessage());
        return 1;
      }
    }
    
    return $this->runClass($class, $classparams, $config);
  }
}
