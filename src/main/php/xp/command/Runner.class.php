<?php namespace xp\command;

use lang\reflect\{Modifiers, Package};
use lang\reflection\Type;
use lang\{ClassLoader, ClassNotFoundException, XPClass, Reflection};
use util\cmd\{Commands, Config, ParamString};

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
 *   is used for dependency injection. (If not given etc is used as 
 *   default path)
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
 *
 * -l:
 *   Lists named commands
 * ```
 *
 * If the class options contain `-?`, the help text supplied via the
 * class' api documentation is shown. All other class options are
 * dependant on the class.
 *
 * @test  xp://util.cmd.unittest.RunnerTest
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
   * Shows usage
   *
   * @param  lang.reflection.Type $type
   * @return void
   */
  protected function commandUsage(Type $type) {

    // Description
    if (null !== ($comment= $type->comment())) {
      self::$err->writeLine(self::textOf($comment));
      self::$err->writeLine(str_repeat('=', 72));
    }

    $extra= $details= $positional= [];
    foreach ($type->methods()->annotated(Arg::class) as $method) {
      $arg= $method->annotation('arg')->arguments();
      $name= strtolower(preg_replace('/^set/', '', $method->name()));;
      $first= $method->parameter(0);
      $optional= $first ? $first->optional() : true;
      $comment= self::textOf($method->comment());

      if (isset($arg['position'])) {
        $details['#'.($arg['position'] + 1)]= $comment;
        $positional[$arg['position']]= $name;
      } else if (isset($arg['name'])) {
        $details['--'.$arg['name'].' | -'.($arg['short'] ?? $arg['name'][0])]= $comment;
        $extra[$arg['name']]= $optional;
      } else {
        $details['--'.$name.' | -'.($arg['short'] ?? $name[0])]= $comment;
        $extra[$name]= $optional;
      }
    }

    // Usage
    asort($positional);
    self::$err->write('Usage: $ xpcli ', Commands::nameOf($type), ' ');
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
    self::$err->writeLine($this->textOf(Reflection::type(self::class)->comment()));
  }

  /**
   * Lists commands
   *
   * @return void
   */
  protected function listCommands() {
    $commandsIn= function($package) {
      $text= '';
      foreach ($package->getClasses() as $class) {
        if ($class->isSubclassOf('util.cmd.Command') && !Modifiers::isAbstract($class->getModifiers())) {
          $text.= '  $ xpcli '.$class->getSimpleName()."\n";
        }
      }
      return $text ?: '  (no commands)';
    };

    self::$err->writeLine('Named commands');
    self::$err->writeLine();

    if ($packages= Commands::allPackages()) {
      foreach (Commands::allPackages() as $package) {
        self::$err->writeLine('* ', $package);
        self::$err->writeLine($commandsIn($package));
      }
      self::$err->writeLine();
    }

    self::$err->writeLine('* Global package');
    self::$err->writeLine($commandsIn(Package::forName(null)));
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
        ClassLoader::registerPath($params->list[$i+ 1], null);
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