<?php namespace xp\command;

use util\cmd\ParamString;
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
class CmdRunner {
  private static
    $in     = null,
    $out    = null,
    $err    = null;
  
  private
    $verbose= false;

  const DEFAULT_CONFIG_PATH = 'etc';

  static function __static() {
    self::$in= new StringReader(new ConsoleInputStream(STDIN));
    self::$out= new StringWriter(new ConsoleOutputStream(STDOUT));
    self::$err= new StringWriter(new ConsoleOutputStream(STDERR));
  }

  /**
   * Creates usage as markdown
   *
   * @param  lang.XPClass $class
   * @return string
   */
  private static function usageOf(XPClass $class) {
    $markdown= "- Usage\n  ```sh\n$ xp cmd ".$class->getName();

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

    return substr($markdown, 0, -1);
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

  /**
   * Displays usage
   *
   * @return  int exitcode
   */
  protected function usage() {
    self::$err->writeLine('Runs commands: `xp cmd [class]`. xp help cmd has the details!');
    return 1;
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
   * @param   util.cmd.ParamString params
   * @return  int
   */
  public function run(ParamString $params) {

    // No arguments given - show our own usage
    if ($params->count < 1) return self::usage();

    // Configure properties
    $pm= PropertyManager::getInstance();

    // Separate runner options from class options
    for ($offset= 0, $i= 0; $i < $params->count; $i++) switch ($params->list[$i]) {
      case '-c':
        if (0 == strncmp('res://', $params->list[$i+ 1], 6)) {
          $pm->appendSource(new ResourcePropertySource(substr($params->list[$i+ 1], 6)));
        } else {
          $pm->appendSource(new FilesystemPropertySource($params->list[$i+ 1]));
        }
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
    
    // Use default path for PropertyManager if no sources set
    if (!$pm->getSources()) {
      $pm->configure(self::DEFAULT_CONFIG_PATH);
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
    
    // Check whether class is runnable
    if (!$class->isSubclassOf('lang.Runnable')) {
      self::$err->writeLine('*** ', $class->getName(), ' is not runnable');
      return 1;
    }

    // Usage
    if ($classparams->exists('help', '?')) {
      $comment= $class->getComment();
      if ('' === (string)$comment) {
        $markdown= '# '.$class->getSimpleName()."\n\n".self::usageOf($class);
      } else {
        @list($headline, $text)= explode("\n", $comment, 2);
        $markdown= '# '.ltrim($headline, ' #')."\n\n".self::usageOf($class).$text;
      }

      Help::render(self::$err, $markdown, $class->getClassLoader());
      return 0;
    }

    // Load, instantiate and initialize
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
      $instance= $class->getMethod('newInstance')->invoke(null, []);
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
          $end= $classparams->count;
          $pass= array_slice($classparams->list, 0, $end);
        } else {
          $pass= [];
          foreach (preg_split('/, ?/', $method->getAnnotation('args', 'select')) as $def) {
            if (is_numeric($def) || '-' == $def{0}) {
              $pass[]= $classparams->value((int)$def);
            } else {
              sscanf($def, '[%d..%d]', $begin, $end);
              isset($begin) || $begin= 0;
              isset($end) || $end= $classparams->count- 1;

              while ($begin <= $end) {
                $pass[]= $classparams->value($begin++);
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
          if (!$classparams->exists($select, $short)) continue;
          $args= [];
        } else if (!$classparams->exists($select, $short)) {
          list($first, )= $method->getParameters();
          if (!$first->isOptional()) {
            self::$err->writeLine('*** Argument '.$name.' does not exist!');
            return 2;
          }

          $args= [];
        } else {
          $args= [$classparams->value($select, $short)];
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
