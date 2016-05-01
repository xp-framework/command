<?php namespace xp\command;

use util\cmd\ParamString;
use util\cmd\Config;
use util\log\Logger;
use util\log\context\EnvironmentAware;
use util\PropertyManager;
use util\FilesystemPropertySource;
use util\ResourcePropertySource;
use rdbms\ConnectionManager;
use lang\XPClass;

/**
 * Runs util.cmd.Command subclasses on the command line.
 *
 * Usage:
 * ```sh
 * $ xpcli [options] fully.qualified.class.Name [classoptions]
 * ```
 *
 * Options includes one of the following:
 * ```
 * -c:
 *   Add the path to the PropertyManager sources. The PropertyManager
 *   is used for dependency injection. If files called log.ini exists
 *   in this paths, the Logger will be configured with. If any
 *   database.ini are present there, the ConnectionManager will be
 *   configured with it. (If not given etc is used as default path)
 * 
 * -cp:
 *   Add the path value to the class path.
 *
 * -v:
 *   Enable verbosity (show complete stack trace when exceptions
 *   occurred)
 *
 * -?:
 *   Shows this help text.
 * ```
 *
 * If the class options contain `-?`, the help text supplied via the
 * class' api documentation is shown. All other class options are
 * dependant on the class.
 *
 * @test  xp://net.xp_framework.unittest.util.cmd.RunnerTest
 * @see   xp://util.cmd.Command
 */
class Runner extends AbstractRunner {
  
  static function __static() { }

  /**
   * Converts api-doc markdown to plain text w/ ASCII "art"
   *
   * @param   string markup
   * @return  string text
   */
  protected static function textOf($markup) {
    $line= str_repeat('=', 72);
    return strip_tags(preg_replace(
      ['#```([a-z]*)#', '#```#', '#^\- #'],
      [$line, $line, '* '],
      trim($markup)
    ));
  }
  
  /**
   * Show usage
   *
   * @param  lang.XPClass class
   * @return void
   */
  protected function commandUsage(XPClass $class) {

    // Description
    if (null !== ($comment= $class->getComment())) {
      self::$err->writeLine(self::textOf($comment));
      self::$err->writeLine(str_repeat('=', 72));
    }

    $extra= $details= $positional= [];
    foreach ($class->getMethods() as $method) {
      if (!$method->hasAnnotation('arg')) continue;

      $arg= $method->getAnnotation('arg');
      $name= strtolower(preg_replace('/^set/', '', $method->getName()));;
      $comment= self::textOf($method->getComment());

      if (0 == $method->numParameters()) {
        $optional= true;
      } else {
        list($first, )= $method->getParameters();
        $optional= $first->isOptional();
      }

      if (isset($arg['position'])) {
        $details['#'.($arg['position'] + 1)]= $comment;
        $positional[$arg['position']]= $name;
      } else if (isset($arg['name'])) {
        $details['--'.$arg['name'].' | -'.(isset($arg['short']) ? $arg['short'] : $arg['name']{0})]= $comment;
        $extra[$arg['name']]= $optional;
      } else {
        $details['--'.$name.' | -'.(isset($arg['short']) ? $arg['short'] : $name{0})]= $comment;
        $extra[$name]= $optional;
      }
    }

    // Usage
    asort($positional);
    self::$err->write('Usage: $ xpcli ', $class->getName(), ' ');
    foreach ($positional as $name) {
      self::$err->write('<', $name, '> ');
    }
    foreach ($extra as $name => $optional) {
      self::$err->write(($optional ? '[' : ''), '--', $name, ($optional ? '] ' : ' '));
    }
    self::$err->writeLine();

    // Argument details
    self::$err->writeLine('Arguments:');
    foreach ($details as $which => $comment) {
      self::$err->writeLine('* ', $which, "\n  ", str_replace("\n", "\n  ", $comment), "\n");
    }
  }

  /**
   * Displays usage
   *
   * @return void
   */
  protected function selfUsage() {
    self::$err->writeLine($this->textOf((new XPClass(__CLASS__))->getComment()));
  }
  
  /**
   * Main method
   *
   * @param   util.cmd.ParamString params
   * @return  int
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
      case '-cp':
        \lang\ClassLoader::registerPath($params->list[$i+ 1], null);
        $offset+= 2; $i++;
        break;
      case '-v':
        $this->verbose= true;
        $offset+= 1; $i++;
        break;
      case '-?':
        return self::usage();
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
    if (strstr($classname, \xp::CLASS_FILE_EXT)) {
      $file= new \io\File($classname);
      if (!$file->exists()) {
        self::$err->writeLine('*** Cannot load class from non-existant file ', $classname);
        return 1;
      }

      try {
        $class= \lang\ClassLoader::getDefault()->loadUri($file->getURI());
      } catch (\lang\ClassNotFoundException $e) {
        self::$err->writeLine('*** ', $this->verbose ? $e : $e->getMessage());
        return 1;
      }
    } else {
      try {
        $class= \lang\XPClass::forName($classname);
      } catch (\lang\ClassNotFoundException $e) {
        self::$err->writeLine('*** ', $this->verbose ? $e : $e->getMessage());
        return 1;
      }
    }

    return $this->runClass($class, $classparams, $config);
  }
}