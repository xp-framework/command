<?php namespace xp\command;

use util\cmd\ParamString;
use util\cmd\Config;
use io\File;
use io\streams\InputStream;
use io\streams\OutputStream;
use io\streams\StringReader;
use io\streams\StringWriter;
use io\streams\ConsoleInputStream;
use io\streams\ConsoleOutputStream;
use util\log\Logger;
use util\log\context\EnvironmentAware;
use util\PropertyManager;
use util\PropertyAccess;
use util\Properties;
use util\FilesystemPropertySource;
use util\ResourcePropertySource;
use rdbms\ConnectionManager;
use lang\XPClass;
use lang\System;
use lang\ClassLoader;
use lang\ClassNotFoundException;
use lang\reflect\TargetInvocationException;
use lang\Throwable;
use xp\runtime\Help;

/**
 * Command runner base class
 */
abstract class AbstractRunner {
  const DEFAULT_CONFIG_PATH = 'etc';

  protected static $in;
  protected static $out;
  protected static $err;
  protected $verbose = false;

  static function __static() {
    self::$in= new StringReader(new ConsoleInputStream(STDIN));
    self::$out= new StringWriter(new ConsoleOutputStream(STDOUT));
    self::$err= new StringWriter(new ConsoleOutputStream(STDERR));
  }

  /**
   * Displays usage of command
   *
   * @return void
   */
  protected abstract function commandUsage(XPClass $class);

  /**
   * Displays usage of runner
   *
   * @return void
   */
  protected abstract function selfUsage();

  /**
   * Run
   *
   * @param  util.cmd.ParamString $params
   * @param  util.cmd.Config $config
   * @return int
   */
  public abstract function run(ParamString $params, Config $config= null);

  /**
   * Main method
   *
   * @param   string[] args
   * @return  int
   */
  public static function main(array $args) {
    return (new static())->run(new ParamString($args));
  }

  /**
   * Reassigns standard input stream
   *
   * @param   io.streams.InputStream in
   * @return  io.streams.InputStream the given output stream
   */
  public function setIn(InputStream $in) {
    self::$in= new StringReader($in);
    return $in;
  }
  
  /**
   * Reassigns standard output stream
   *
   * @param   io.streams.OutputStream out
   * @return  io.streams.OutputStream the given output stream
   */
  public function setOut(OutputStream $out) {
    self::$out= new StringWriter($out);
    return $out;
  }

  /**
   * Reassigns standard error stream
   *
   * @param   io.streams.OutputStream error
   * @return  io.streams.OutputStream the given output stream
   */
  public function setErr(OutputStream $err) {
    self::$err= new StringWriter($err);
    return $err;
  }

  /**
   * Runs class
   *
   * @param  lang.XPClass $class
   * @param  util.cmd.ParamString $params
   * @param  util.cmd.Config $config
   * @return int
   */
  protected function runClass($class, $params, $config) {

    // Check whether class is runnable
    if (!$class->isSubclassOf('lang.Runnable')) {
      self::$err->writeLine('*** ', $class->getName(), ' is not runnable');
      return 1;
    }

    // Usage
    if ($params->exists('help', '?')) {
      $this->commandUsage($class);
      return 0;
    }

    // BC: PropertyManager, Logger, ConnectionManager instances
    $pm= PropertyManager::getInstance();
    $pm->setSources($config->sources());

    $l= Logger::getInstance();
    $pm->hasProperties('log') && $l->configure($pm->getProperties('log'));

    if (class_exists('rdbms\DBConnection')) {   // FIXME: Job of XPInjector?
      $cm= ConnectionManager::getInstance();
      $pm->hasProperties('database') && $cm->configure($pm->getProperties('database'));
    }

    // Setup logger context for all registered log categories
    foreach (Logger::getInstance()->getCategories() as $category) {
      if (null === ($context= $category->getContext()) || !($context instanceof EnvironmentAware)) continue;
      $context->setHostname(System::getProperty('host.name'));
      $context->setRunner(nameof($this));
      $context->setInstance($class->getName());
      $context->setResource(null);
      $context->setParams($params->string);
    }

    if ($class->hasMethod('newInstance')) {
      $instance= $class->getMethod('newInstance')->invoke(null, [$config]);
    } else if ($class->hasConstructor()) {
      $instance= $class->newInstance($config);
    } else {
      $instance= $class->newInstance();
    }
    $instance->in= self::$in;
    $instance->out= self::$out;
    $instance->err= self::$err;
    $methods= $class->getMethods();

    // Injection
    foreach ($methods as $method) {
      if (!$method->hasAnnotation('inject')) continue;

      $inject= $method->getAnnotation('inject');
      if (isset($inject['type'])) {
        $type= $inject['type'];
      } else if ($restriction= $method->getParameter(0)->getTypeRestriction()) {
        $type= $restriction->getName();
      } else {
        $type= $method->getParameter(0)->getType()->getName();
      }
      try {
        switch ($type) {
          case 'rdbms.DBConnection': {
            $args= [$cm->getByHost($inject['name'], 0)];
            break;
          }

          case 'util.Properties': {
            $p= $pm->getProperties($inject['name']);

            // If a PropertyAccess is retrieved which is not a util.Properties,
            // then, for BC sake, convert it into a util.Properties
            if ($p instanceof PropertyAccess && !$p instanceof Properties) {
              $convert= Properties::fromString('');
              $section= $p->getFirstSection();

              while ($section) {
                // HACK: Properties::writeSection() would first attempts to
                // read the whole file, we cannot make use of it.
                $convert->_data[$section]= $p->readSection($section);
                $section= $p->getNextSection();
              }
              $args= [$convert];
            } else {
              $args= [$p];
            }
            break;
          }

          case 'util.log.LogCategory': {
            $args= [$l->getCategory($inject['name'])];
            break;
          }

          default: {
            self::$err->writeLine('*** Unknown injection type "'.$type.'" at method "'.$method->getName().'"');
            return 2;
          }
        }

        $method->invoke($instance, $args);
      } catch (TargetInvocationException $e) {
        self::$err->writeLine('*** Error injecting '.$type.' '.$inject['name'].': '.$e->getCause()->compoundMessage());
        return 2;
      } catch (Throwable $e) {
        self::$err->writeLine('*** Error injecting '.$type.' '.$inject['name'].': '.$e->compoundMessage());
        return 2;
      }
    }
    
    // Arguments
    foreach ($methods as $method) {
      if ($method->hasAnnotation('args')) { // Pass all arguments
        if (!$method->hasAnnotation('args', 'select')) {
          $begin= 0;
          $end= $params->count;
          $pass= array_slice($params->list, 0, $end);
        } else {
          $pass= [];
          foreach (preg_split('/, ?/', $method->getAnnotation('args', 'select')) as $def) {
            if (is_numeric($def) || '-' == $def{0}) {
              $pass[]= $params->value((int)$def);
            } else {
              sscanf($def, '[%d..%d]', $begin, $end);
              isset($begin) || $begin= 0;
              isset($end) || $end= $params->count- 1;

              while ($begin <= $end) {
                $pass[]= $params->value($begin++);
              }
            }
          }
        }
        try {
          $method->invoke($instance, [$pass]);
        } catch (Throwable $e) {
          self::$err->writeLine('*** Error for arguments '.$begin.'..'.$end.': ', $this->verbose ? $e : $e->getMessage());
          return 2;
        }
      } else if ($method->hasAnnotation('arg')) {  // Pass arguments
        $arg= $method->getAnnotation('arg');
        if (isset($arg['position'])) {
          $name= '#'.($arg['position']+ 1);
          $select= intval($arg['position']);
          $short= null;
        } else if (isset($arg['name'])) {
          $name= $select= $arg['name'];
          $short= isset($arg['short']) ? $arg['short'] : null;
        } else {
          $name= $select= strtolower(preg_replace('/^set/', '', $method->getName()));
          $short= isset($arg['short']) ? $arg['short'] : null;
        }

        if (0 == $method->numParameters()) {
          if (!$params->exists($select, $short)) continue;
          $args= [];
        } else if (!$params->exists($select, $short)) {
          list($first, )= $method->getParameters();
          if (!$first->isOptional()) {
            self::$err->writeLine('*** Argument '.$name.' does not exist!');
            return 2;
          }

          $args= [];
        } else {
          $args= [$params->value($select, $short)];
        }

        try {
          $method->invoke($instance, $args);
        } catch (TargetInvocationException $e) {
          self::$err->writeLine('*** Error for argument '.$name.': ', $this->verbose ? $e->getCause() : $e->getCause()->compoundMessage());
          return 2;
        }
      }
    }

    try {
      return (int)$instance->run();
    } catch (Throwable $t) {
      self::$err->writeLine('*** ', $t->toString());
      return 70;    // EX_SOFTWARE according to sysexits.h
    }
  }
}
