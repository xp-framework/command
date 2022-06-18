<?php namespace xp\command;

use lang\reflect\{Modifiers, Package, TargetInvocationException};
use lang\{ClassLoader, ClassNotFoundException, Throwable, XPClass};
use rdbms\ConnectionManager;
use util\cmd\{Command, Commands, Config, ParamString};
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
      $p= strcspn($comment, ".\n");
      $markdown= '# '.ltrim(substr($comment, 0, $p), ' #')."\n\n";
      $text= substr($comment, $p);
    }

    $markdown.= "- Usage\n  ```sh\n$ xp cmd ".Commands::nameOf($class);

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
        if ($class->isSubclassOf(Command::class) && !Modifiers::isAbstract($class->getModifiers())) {
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
   * Starts repl
   *
   * @param  util.cmd.Config $config
   * @return void
   */
  protected function startRepl($config) {
    foreach (Commands::allPackages() as $package) {
      self::$out->writeLine("\e[33m@", $package, "\e[0m");
    }
    self::$out->writeLine('XP Command REPL. Use "ls" to list commands, "exit" to exit');
    self::$out->writeLine("\e[36m", str_repeat('═', 72), "\e[0m");
    foreach ($config->sources() as $source) {
      self::$out->writeLine('Config: ', $source);
    }

    $prompt= (getenv('USERNAME') ?: getenv('USER') ?: posix_getpwuid(posix_geteuid())['name']).'@'.gethostname();
    $exit= 0;
    do {
      self::$out->write("\n\e[", 0 === $exit ? '44' : '41', ";1;37m", $prompt, " cmd \$\e[0m ");

      $command= $args= null;
      sscanf(self::$in->readLine(), "%[^ ] %[^\r]", $command, $args);
      switch ($command) {
        case null: break;
        case 'exit': return;
        case 'ls':
          foreach (array_merge(Commands::allPackages(), [Package::forName(null)]) as $package) {
            foreach ($package->getClasses() as $class) {
              if ($class->isSubclassOf(Command::class) && !Modifiers::isAbstract($class->getModifiers())) {
                self::$out->write("* \e[37m", $class->getSimpleName(), "\e[0m");
                if ($comment= $class->getComment()) {
                  self::$out->writeLine(" - \e[3m", substr($comment, 0, strcspn($comment, ".\n")), "\e[0m");
                } else {
                  self::$out->writeLine();
                }
              }
            }
          }
          $exit= 0;
          break;

        // Treat any other input as a command name
        default:
          try {
            $exit= $this->runCommand($command, ParamString::parse($args), $config);
          } catch (\Throwable $e) {
            self::$err->writeLine(Throwable::wrap($e));
            $exit= 255;
          }
          break;
      }
    } while (true);
  }

  /**
   * Main method
   *
   * @param  util.cmd.ParamString $params
   * @param  util.cmd.Config $config
   * @return int
   */
  public function run(ParamString $params, Config $config= null) {

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
    
    // Without class: Start REPL
    if (!$params->exists($offset)) {
      $this->startRepl($config);
      return 0;
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