<?php namespace xp\command;

use io\streams\{ConsoleInputStream, ConsoleOutputStream, InputStream, OutputStream, StringReader, StringWriter};
use lang\reflection\{InvocationFailed, Type, Package};
use lang\{ClassLoader, ClassNotFoundException, Throwable, Reflection};
use util\cmd\{Arg, Args, Command, Commands, Config, Console, ParamString};
use util\{Properties, PropertyAccess, PropertyManager};
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
 * Pass `-v` to see more verbose output from argument handling.
 *
 * @test  util.cmd.unittest.CmdRunnerTest
 * @see   util.cmd.Command
 */
class CmdRunner {
  const DEFAULT_CONFIG_PATH= 'etc';

  private static $in;
  private static $out;
  private static $err;
  private $verbose= false;

  static function __static() {
    self::$in= Console::$in;
    self::$out= Console::$out;
    self::$err= Console::$err;
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
   * Shows usage
   *
   * @param  lang.reflection.Type $type
   * @return void
   */
  protected function commandUsage(Type $type) {
    $comment= $type->comment();
    if ('' === (string)$comment) {
      $markdown= '# '.$type->name()."\n\n";
      $text= '';
    } else if (false === ($p= strpos($comment, "\n"))) {
      $markdown= '# '.$comment."\n\n";
      $text= '';
    } else {
      $markdown= '# '.ltrim(substr($comment, 0, $p), ' #')."\n\n";
      $text= substr($comment, $p + 1);
    }

    $markdown.= "- Usage\n  ```sh\n$ xp cmd ".Commands::nameOf($type->class());

    $extra= $details= $positional= [];
    foreach ($type->methods()->annotated(Arg::class) as $method) {
      $arg= $method->annotation(Arg::class)->arguments();
      $name= strtolower(preg_replace('/^(use|set)/', '', $method->name()));
      $first= $method->parameter(0);
      $optional= $first ? $first->optional() : true;
      $comment= $method->comment();

      if (isset($arg['position'])) {
        $details[$name]= [$comment, null];
        $positional[$arg['position']]= $name;
      } else if (isset($arg['name'])) {
        $details['--'.$arg['name']]= [$comment, $arg['short'] ?? $arg['name'][0]];
        $extra[$arg['name']]= $optional;
      } else {
        $details['--'.$name]= [$comment, $arg['short'] ?? $name[0]];
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

    Help::render(self::$err, substr($markdown, 0, -1).$text, $type->classLoader());
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
      foreach ($package->types() as $type) {
        if ($type->is(Command::class) && $type->instantiable()) {
          $markdown.= '  $ xp cmd '.$type->declaredName()."\n";
        }
      }
      return $markdown ?: '  *(no commands)*';
    };

    $markdown= "# Named commands\n\n";

    if ($packages= Commands::allPackages()) {
      foreach ($packages as $name => $package) {
        $markdown.= '* In package **'.$name."**\n\n".$commandsIn($package);
      }
      $markdown.= "\n";
    }

    $markdown.= "* In global package\n\n".$commandsIn(new Package());

    Help::render(self::$err, $markdown, []);
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
              $begin ??= 0;
              $end ??= $params->count - 1;

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
          $select= $name= $arg->argument('name') ?? strtolower(preg_replace('/^(use|set)/', '', $method->name()));
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
    $classparams= new ParamString(array_slice($params->list, $offset + 1));
    return $this->runCommand($params->value($offset), $classparams, $config);
  }

  /**
   * Main method
   *
   * @param   string[] args
   * @return  int
   */
  public static function main(array $args) {
    return (new self())->run(new ParamString($args));
  }
}