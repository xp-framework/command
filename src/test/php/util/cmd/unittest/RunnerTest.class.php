<?php namespace util\cmd\unittest;

use xp\command\Runner;
use util\cmd\Command;
use util\cmd\ParamString;
use util\log\Logger;
use util\PropertyManager;
use io\streams\MemoryInputStream;
use io\streams\MemoryOutputStream;
new import('lang.ResourceProvider');

class RunnerTest extends \unittest\TestCase {
  private
    $runner = null,
    $in     = null,
    $out    = null,
    $err    = null;

  /** @return void */
  public function setUp() {
    $this->runner= new Runner();
  }
  
  /**
   * Run with given args
   *
   * @param   string[] args
   * @param   string in
   * @param   util.PropertySource[] propertySources default []
   * @return  int
   */
  private function runWith(array $args, $in= '', $propertySources= []) {
    $pm= PropertyManager::getInstance();
    $sources= $pm->getSources();
    $pm->setSources($propertySources);

    $this->in= $this->runner->setIn(new MemoryInputStream($in));
    $this->out= $this->runner->setOut(new MemoryOutputStream());
    $this->err= $this->runner->setErr(new MemoryOutputStream());
    try {
      $res= $this->runner->run(new ParamString($args));
      $pm->setSources($sources);
      return $res;
    } catch (\lang\Throwable $t) {
      $pm->setSources($sources);
      throw $t;
    }
  }

  /**
   * Assertion helper for "args" annotation tests
   *
   * @param   string args
   * @param   util.cmd.Command command
   */
  private function assertAllArgs($args, Command $command) {
    $return= $this->runWith([nameof($command), 'a', 'b', 'c', 'd', 'e', 'f', 'g']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals($args, $this->out->getBytes());
  }

  /**
   * Asserts a given output stream contains the given bytes       
   *
   * @param   io.streams.MemoryOutputStream m
   * @param   string bytes
   * @throws  unittest.AssertionFailedError
   */
  private function assertOnStream(MemoryOutputStream $m, $bytes, $message= 'Not contained') {
    strstr($m->getBytes(), $bytes) || $this->fail($message, $m->getBytes(), $bytes);
  }
  
  /**
   * Returns a simple command instance
   *
   * @return  util.cmd.Command
   */
  private function newCommand() {
    return newinstance(Command::class, [], '{
      public static $wasRun= FALSE;
      public function __construct() { self::$wasRun= FALSE; }
      public function run() { self::$wasRun= TRUE; }
      public function wasRun() { return self::$wasRun; }
    }');
  }
  
  #[@test]
  public function selfUsage() {
    $return= $this->runWith([]);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, 'Usage:');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function nonExistantClass() {
    $return= $this->runWith(['@@NON-EXISTANT@@']);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, '*** Class "@@NON-EXISTANT@@" could not be found');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function nonExistantFile() {
    $return= $this->runWith(['@@NON-EXISTANT@@.'.\xp::CLASS_FILE_EXT]);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, '*** Cannot load class from non-existant file');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function notRunnableClass() {
    $return= $this->runWith([nameof($this)]);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, '*** '.nameof($this).' is not runnable');
    $this->assertEquals('', $this->out->getBytes());
  }
  
  #[@test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, 'Usage: $ xpcli '.nameof($command));
    $this->assertEquals('', $this->out->getBytes());
    $this->assertFalse($command->wasRun());
  }

  #[@test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, 'Usage: $ xpcli '.nameof($command));
    $this->assertEquals('', $this->out->getBytes());
    $this->assertFalse($command->wasRun());
  }

  #[@test]
  public function runCommand() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('', $this->out->getBytes());
    $this->assertTrue($command->wasRun());
  }

  #[@test]
  public function runWritingToStandardOutput() {
    $command= newinstance(Command::class, [], [
      'run' => function() { $this->out->write('UNITTEST'); }
    ]);

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function runWritingToStandardError() {
    $command= newinstance(Command::class, [], [
      'run' => function() { $this->err->write('UNITTEST'); }
    ]);

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(0, $return);
    $this->assertEquals('UNITTEST', $this->err->getBytes());
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function runEchoInput() {
    $command= newinstance(Command::class, [], [
      'run' => function() {
        while ($chunk= $this->in->read()) {
          $this->out->write($chunk);
        }
      }
    ]);

    $return= $this->runWith([nameof($command)], 'UNITTEST');
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function positionalArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg(position= 0)]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), 'UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function missingPositionalArgumentt() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg(position= 0)]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { throw new \unittest\AssertionFailedError("Should not be executed"); }
    }');

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(2, $return);
    $this->assertOnStream($this->err, '*** Argument #1 does not exist');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function shortNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '-a', 'UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function longNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '--arg=UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function shortRenamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg(name= "pass")]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '-p', 'UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function longRenamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg(name= "pass")]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '--pass=UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function missingNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= NULL;

      #[@arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { throw new \unittest\AssertionFailedError("Should not be executed"); }
    }');

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(2, $return);
    $this->assertOnStream($this->err, '*** Argument arg does not exist');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function existanceArgumentNotPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= FALSE;

      #[@arg]
      public function setVerbose() { $this->verbose= TRUE; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('false', $this->out->getBytes());
  }

  #[@test]
  public function optionalArgument() {
    $command= newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $name= NULL;

      #[@arg]
      public function setName($name= "unknown") { $this->name= $name; }
      public function run() { $this->out->write($this->name); }
    }');

    $return= $this->runWith([nameof($command), '-n', 'UNITTEST']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('UNITTEST', $this->out->getBytes());
  }

  #[@test]
  public function optionalArgumentNotPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $name= NULL;

      #[@arg]
      public function setName($name= "unknown") { $this->name= $name; }
      public function run() { $this->out->write($this->name); }
    }');

    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('unknown', $this->out->getBytes());
  }

  #[@test]
  public function shortExistanceArgumentPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= FALSE;

      #[@arg]
      public function setVerbose() { $this->verbose= TRUE; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command), '-v']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('true', $this->out->getBytes());
  }

  #[@test]
  public function longExistanceArgumentPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= FALSE;

      #[@arg]
      public function setVerbose() { $this->verbose= TRUE; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command), '--verbose']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('true', $this->out->getBytes());
  }

  #[@test]
  public function positionalArgumentException() {
    $command= newinstance(Command::class, [], '{
      
      #[@arg(position= 0)]
      public function setHost($host) { 
        throw new \lang\IllegalArgumentException("Connecting to ".$host." disallowed by policy");
      }
      
      public function run() { 
        // Not reached
      }
    }');
    $this->runWith([nameof($command), 'insecure.example.com']);
    $this->assertOnStream($this->err, '*** Error for argument #1');
    $this->assertOnStream($this->err, 'Connecting to insecure.example.com disallowed by policy');
  }

  #[@test]
  public function namedArgumentException() {
    $command= newinstance(Command::class, [], '{
      
      #[@arg]
      public function setHost($host) { 
        throw new \lang\IllegalArgumentException("Connecting to ".$host." disallowed by policy");
      }
      
      public function run() { 
        // Not reached
      }
    }');
    $this->runWith([nameof($command), '--host=insecure.example.com']);
    $this->assertOnStream($this->err, '*** Error for argument host');
    $this->assertOnStream($this->err, 'Connecting to insecure.example.com disallowed by policy');
  }

  #[@test]
  public function allArgs() {
    $this->assertAllArgs('a, b, c, d, e, f, g', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "[0..]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[@test]
  public function allArgsCompactNotation() {
    $this->assertAllArgs('a, b, c, d, e, f, g', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "*")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }
 
  #[@test]
  public function boundedArgs() {
    $this->assertAllArgs('a, b, c', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "[0..2]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[@test]
  public function boundedArgsFromOffset() {
    $this->assertAllArgs('c, d, e', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "[2..4]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[@test]
  public function positionalAndBoundedArgsFromOffset() {
    $this->assertAllArgs('a, c, d, e', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "0, [2..4]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[@test]
  public function boundedAndPositionalArgsWithOverlap() {
    $this->assertAllArgs('a, b, c, b', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "[0..2], 1")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }
 
  #[@test]
  public function positionalArgs() {
    $this->assertAllArgs('a, c, e, f', newinstance(Command::class, [], '{
      private $verbose= FALSE;
      private $args= [];

      #[@args(select= "0, 2, 4, 5")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[@test]
  public function configOption() {
    $command= newinstance(Command::class, [], '{
      private $choke= FALSE;

      #[@arg]
      public function setChoke() { 
        $this->choke= TRUE; 
      }
      
      public function run() { 
        $this->out->write($this->choke ? "true" : "false"); 
      }
    }');
    $return= $this->runWith(['-c', 'etc', nameof($command), '-c']);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('true', $this->out->getBytes());
  }

  #[@test]
  public function classPathOption() {
    $command= newinstance(Command::class, [], '{
      private $copy= NULL;
      
      #[@arg(short= "cp")]
      public function setCopy($copy) { 
        $this->copy= \lang\reflect\Package::forName("net.xp_forge.instructions")->loadClass($copy); 
      }
      
      public function run() { 
        $this->out->write($this->copy); 
      }
    }');
    $return= $this->runWith([
      '-cp', $this->getClass()->getPackage()->getResourceAsStream('instructions.xar')->getURI(), 
      nameof($command),
      '-cp', 'Copy'
    ]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('lang.XPClass<net.xp_forge.instructions.Copy>', $this->out->getBytes());
  }

  #[@test]
  public function unknownInjectionType() {
    $command= newinstance(Command::class, [], '{
      #[@inject(type= "io.Folder", name= "output")]
      public function setOutput($f) { 
      }
      
      public function run() { 
      }
    }');
    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(2, $return);
    $this->assertEquals('', $this->out->getBytes());
    $this->assertOnStream($this->err, '*** Unknown injection type "io.Folder" at method "setOutput"');
  }

  #[@test]
  public function noInjectionType() {
    $command= newinstance(Command::class, [], '{
      #[@inject(name= "output")]
      public function setOutput($f) { 
      }
      
      public function run() { 
      }
    }');
    $return= $this->runWith([nameof($command)]);
    $this->assertEquals(2, $return);
    $this->assertEquals('', $this->out->getBytes());
    $this->assertOnStream($this->err, '*** Unknown injection type "var" at method "setOutput"');
  }

  #[@test]
  public function loggerCategoryInjection() {
    $command= newinstance(Command::class, [], '{
      private $cat= NULL;
      
      #[@inject(type= "util.log.LogCategory", name= "debug")]
      public function setTrace($cat) { 
        $this->cat= $cat;
      }
      
      public function run() { 
        $this->out->write($this->cat ? $this->cat->getClass() : NULL); 
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertEquals('lang.XPClass<util.log.LogCategory>', $this->out->getBytes());
  }

  #[@test]
  public function loggerCategoryInjectionViaTypeRestriction() {
    $command= newinstance(Command::class, [], '{
      private $cat= NULL;
      
      #[@inject(name= "debug")]
      public function setTrace(\util\log\LogCategory $cat) { 
        $this->cat= $cat;
      }
      
      public function run() { 
        $this->out->write($this->cat ? $this->cat->getClass() : NULL); 
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertEquals('lang.XPClass<util.log.LogCategory>', $this->out->getBytes());
  }

  #[@test]
  public function loggerCategoryInjectionViaTypeDocumentation() {
    $command= newinstance(Command::class, [], '{
      private $cat= NULL;
      
      /**
       * @param   util.log.LogCategory cat
       */
      #[@inject(name= "debug")]
      public function setTrace($cat) { 
        $this->cat= $cat;
      }
      
      public function run() { 
        $this->out->write($this->cat ? $this->cat->getClass() : NULL); 
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertEquals('lang.XPClass<util.log.LogCategory>', $this->out->getBytes());
  }
 
  #[@test]
  public function injectionOccursBeforeArguments() {
    $command= newinstance(Command::class, [], '{
      private $cat= NULL;

      /**
       * @param   string name
       */
      #[@arg(position= 0)]
      public function setName($name) { 
        $this->out->write($this->cat ? $this->cat->getClass() : NULL); 
      }
      
      /**
       * @param   util.log.LogCategory cat
       */
      #[@inject(name= "debug")]
      public function setTrace($cat) { 
        $this->cat= $cat;
      }
      
      public function run() { 
      }
    }');
    $this->runWith([nameof($command), 'Test']);
    $this->assertEquals('lang.XPClass<util.log.LogCategory>', $this->out->getBytes());
  }

  #[@test]
  public function injectionException() {
    $command= newinstance(Command::class, [], '{
      
      #[@inject(name= "debug")]
      public function setTrace(\util\log\LogCategory $cat) { 
        throw new \lang\IllegalArgumentException("Logging disabled by policy");
      }
      
      public function run() { 
        // Not reached
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertOnStream($this->err, '*** Error injecting util.log.LogCategory debug');
    $this->assertOnStream($this->err, 'Logging disabled by policy');
  }

  #[@test]
  public function injectProperties() {
    $command= newinstance(Command::class, [], '{

      #[@inject(name= "debug")]
      public function setTrace(\util\Properties $prop) {
        $this->out->write("Have ", $prop->readString("section", "key"));
      }

      public function run() {
        // Not reached
      }
    }');
    $this->runWith(['-c', 'res://util/cmd/unittest', nameof($command)]);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('Have value', $this->out->getBytes());
  }

  #[@test]
  public function injectCompositeProperties() {
    $command= newinstance(Command::class, [], '{

      #[@inject(name= "debug")]
      public function setTrace(\util\Properties $prop) {
        $this->out->write("Have ", $prop->readString("section", "key"));
      }

      public function run() {
        // Intentionally empty
      }
    }');
    $this->runWith([nameof($command)], '', [new \util\RegisteredPropertySource('debug', \util\Properties::fromString('[section]
key=overwritten_value'
      )),
      new \util\FilesystemPropertySource(__DIR__)
    ]);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('Have overwritten_value', $this->out->getBytes());
  }

  #[@test]
  public function injectPropertiesMultipleSources() {
    $command= newinstance(Command::class, [], '{

      #[@inject(name= "debug")]
      public function setTrace(\util\Properties $prop) {
        $this->out->write("Have ", $prop->readString("section", "key"));
      }

      public function run() {
        // Not reached
      }
    }');
    $this->runWith(['-c', 'res://net/xp_framework/unittest/util/cmd/add_etc', '-c', 'res://net/xp_framework/unittest/util/cmd/', nameof($command)]);
    $this->assertEquals('', $this->err->getBytes());
    $this->assertEquals('Have overwritten_value', $this->out->getBytes());
  }

  #[@test]
  public function class_with_create() {
    $command= newinstance(Command::class, [], '{
      private $created= "constructor";

      public static function newInstance() {
        $self= new self();
        $self->created= "user-supplied creation method";
        return $self;
      }

      public function run() {
        $this->out->write("Created via ", $this->created);
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertEquals('Created via user-supplied creation method', $this->out->getBytes());
  }
}
