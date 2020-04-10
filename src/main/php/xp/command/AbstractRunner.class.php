<?php namespace xp\command;

use io\streams\{ConsoleInputStream, ConsoleOutputStream, InputStream, OutputStream, StringReader, StringWriter};
use lang\{ClassLoader, ClassNotFoundException, System, Throwable, XPClass};
use lang\reflect\TargetInvocationException;
use util\cmd\{Commands, Config, Console, ParamString};
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
    self::$in= Console::$in;
    self::$out= Console::$out;
    self::$err= Console::$err;
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
   * @return  io.streams.InputStream the given input stream
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
   * @param  string $command
   * @param  util.cmd.ParamString $params
   * @param  util.cmd.Config $config
   * @return int
   */
  protected function runCommand($command, $params, $config) {
    try {
      $class= Commands::named($command);
    } catch (Throwable $e) {
      self::$err->writeLine('*** ', $this->verbose ? $e : $e->getMessage());
      return 1;
    }

    // Usage
    if ($params->exists('help', '?')) {
      $this->commandUsage($class);
      return 0;
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
    
    // Arguments
    foreach ($class->getMethods() as $method) {
      if ($method->hasAnnotation('args')) { // Pass all arguments
        if (!$method->hasAnnotation('args', 'select')) {
          $begin= 0;
          $end= $params->count;
          $pass= array_slice($params->list, 0, $end);
        } else {
          $pass= [];
          foreach (preg_split('/, ?/', $method->getAnnotation('args', 'select')) as $def) {
            if (is_numeric($def) || '-' == $def[0]) {
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