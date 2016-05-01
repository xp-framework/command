<?php namespace xp\command;

use util\cmd\ParamString;
use util\cmd\Config;
use util\cmd\Commands;
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
use lang\reflect\Modifiers;
use lang\reflect\Package;
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
 * - Lists named commands
 *   ```sh
 *   $ xp cmd -l
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
 * @test  xp://util.cmd.unittest.CmdRunnerTest
 * @see   xp://util.cmd.Command
 */
class CmdRunner extends AbstractRunner {

  static function __static() { }

  /**
   * Shows usage
   *
   * @param  lang.XPClass $class
   * @return void
   */
  protected function commandUsage(XPClass $class) {
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

    Help::render(self::$err, substr($markdown, 0, -1).$text, $class->getClassLoader());
  }

  /**
   * Displays usage
   *
   * @return  int exitcode
   */
  protected function selfUsage() {
    self::$err->writeLine('Runs commands: `xp cmd [class]`. xp help cmd has the details!');
    return 1;
  }

  /**
   * Lists commands
   *
   * @return void
   */
  protected function listCommands() {
    $commandsIn= function($package) {
      $markdown= '';
      foreach ($package->getClasses() as $class) {
        if ($class->isSubclassOf('util.cmd.Command') && !Modifiers::isAbstract($class->getModifiers())) {
          $markdown.= '  $ xp cmd '.$class->getSimpleName()."\n";
        }
      }
      return $markdown ?: '  *(no commands)*';
    };

    $markdown= "# Named commands\n\n";

    if ($packages= Commands::allPackages()) {
      foreach ($packages as $package) {
        $markdown.= '* In package **'.$package->getName()."**\n\n".$commandsIn($package);
      }
      $markdown.= "\n";
    }

    $markdown.= "* In global package\n\n".$commandsIn(Package::forName(null));

    Help::render(self::$err, $markdown, []);
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
    if ($params->count < 1) {
      $this->selfUsage();
      return 1;
    }

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
        $this->selfUsage();
        return 1;
      case '-l':
        $this->listCommands();
        return 1;
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
    $classparams= new ParamString(array_slice($params->list, $offset+ 1));
    return $this->runCommand($params->value($offset), $classparams, $config);
  }
}
