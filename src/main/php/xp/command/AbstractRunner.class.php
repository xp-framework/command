<?php namespace xp\command;

use io\streams\{ConsoleInputStream, ConsoleOutputStream, InputStream, OutputStream, StringReader, StringWriter};
use lang\reflection\{InvocationFailed, Type};
use lang\{ClassLoader, ClassNotFoundException, System, Throwable, XPClass, Reflection};
use util\cmd\{Arg, Args, Commands, Config, Console, ParamString};
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
   * @param  lang.reflection.Type $type
   * @return void
   */
  protected abstract function commandUsage(Type $type);

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
      $type= Reflection::type(Commands::named($command));
    } catch (Throwable $e) {
      self::$err->writeLine('*** ', $this->verbose ? $e : $e->getMessage());
      return 1;
    }

    // Usage
    if ($params->exists('help', '?')) {
      $this->commandUsage($type);
      return 0;
    }

    if ($method= $type->method('newInstance')) {
      $instance= $method->invoke(null, [$config]);
    } else {
      $instance= $type->newInstance($config);
    }

    $instance->in= self::$in;
    $instance->out= self::$out;
    $instance->err= self::$err;
    
    // Arguments
    foreach ($type->methods() as $method) {
      if ($args= $method->annotation(Args::class)) {
        if ($select= $args->argument('select')) {
          $pass= [];
          foreach (preg_split('/, ?/', $select) as $def) {
            if (is_numeric($def) || '-' === $def[0]) {
              $pass[]= $params->value((int)$def);
            } else {
              sscanf($def, '[%d..%d]', $begin, $end);
              $begin ?? $begin= 0;
              $end ?? $end= $params->count - 1;

              while ($begin <= $end) {
                $pass[]= $params->value($begin++);
              }
            }
          }
        } else {
          $begin= 0;
          $end= $params->count;
          $pass= array_slice($params->list, 0, $end);
        }

        try {
          $method->invoke($instance, [$pass]);
        } catch (InvocationFailed $e) {
          self::$err->writeLine("*** Error for arguments {$begin}..{$end}: ", $this->verbose ? $e : $e->getMessage());
          return 2;
        }
      } else if ($arg= $method->annotation(Arg::class)) {
        if (null !== ($position= $arg->argument('position'))) {
          $select= (int)$position;
          $name= '#'.($position + 1);
          $short= null;
        } else {
          $select= $name= $arg->argument('name') ?? strtolower(preg_replace('/^set/', '', $method->name()));
          $short= $arg->argument('short');
        }

        $first= $method->parameter(0);
        if (null === $first) {
          if (!$params->exists($select, $short)) continue;
          $args= [];
        } else if (!$params->exists($select, $short)) {
          if (!$first->optional()) {
            self::$err->writeLine("*** Argument {$name} does not exist!");
            return 2;
          }

          $args= [];
        } else {
          $args= [$params->value($select, $short)];
        }

        try {
          $method->invoke($instance, $args);
        } catch (InvocationFailed $e) {
          self::$err->writeLine("*** Error for argument {$name}: ", $this->verbose ? $e->getCause() : $e->getCause()->compoundMessage());
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