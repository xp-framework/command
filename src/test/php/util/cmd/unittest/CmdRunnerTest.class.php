<?php namespace util\cmd\unittest;

use io\streams\{MemoryInputStream, MemoryOutputStream};
use test\{Assert, Before, Test, Values};
use util\cmd\{Command, Config, ParamString};
use xp\command\CmdRunner;

class CmdRunnerTest {
  protected $runner, $in, $out, $err;

  #[Before]
  public function setUp() {
    $this->runner= new CmdRunner();
  }

  /**
   * Run with given args
   *
   * @param  string[] $args
   * @param  string $in
   * @param  util.cmd.Config $config
   * @return int
   */
  private function runWith(array $args, $in= '', $config= null) {
    $this->in= $this->runner->setIn(new MemoryInputStream($in));
    $this->out= $this->runner->setOut(new MemoryOutputStream());
    $this->err= $this->runner->setErr(new MemoryOutputStream());

    return $this->runner->run(new ParamString($args), $config);
  }

  /**
   * Assertion helper for "args" annotation tests
   *
   * @param   string args
   * @param   util.cmd.Command command
   */
  private function assertAllArgs($args, Command $command) {
    $return= $this->runWith([nameof($command), 'a', 'b', 'c', 'd', 'e', 'f', 'g']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals($args, $this->out->bytes());
  }

  /**
   * Asserts a given output stream contains the given bytes       
   *
   * @param   io.streams.MemoryOutputStream m
   * @param   string bytes
   * @throws  unittest.AssertionFailedError
   */
  private function assertOnStream(MemoryOutputStream $m, $bytes, $message= 'Not contained') {
    strstr($m->bytes(), $bytes) || Assert::false("{$message}: '{$bytes}' in '{$m->bytes()}'");
  }
  
  /**
   * Returns a simple command instance
   *
   * @return  util.cmd.Command
   */
  private function newCommand() {
    return newinstance(Command::class, [], '{
      public static $wasRun= false;
      public function __construct() { self::$wasRun= false; }
      public function run() { self::$wasRun= true; }
      public function wasRun() { return self::$wasRun; }
    }');
  }

  #[Test, Values([[[]], [['-?']]])]
  public function selfUsage($args) {
    $return= $this->runWith($args);
    Assert::equals(1, $return);
    $this->assertOnStream($this->err, 'xp cmd [class]');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    Assert::equals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    Assert::equals('', $this->out->bytes());
    Assert::false($command->wasRun());
  }

  #[Test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    Assert::equals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    Assert::equals('', $this->out->bytes());
    Assert::false($command->wasRun());
  }

  #[Test]
  public function nonExistant() {
    $return= $this->runWith(['@@NONEXISTANT@@']);
    Assert::equals(1, $return);
    $this->assertOnStream($this->err, '*** Class "@@NONEXISTANT@@" could not be found');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function notRunnableClass() {
    $return= $this->runWith([nameof($this)]);
    Assert::equals(1, $return);
    $this->assertOnStream($this->err, '*** '.nameof($this).' is not a command');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function notRunnableFile() {
    $return= $this->runWith([__FILE__]);
    Assert::equals(1, $return);
    $this->assertOnStream($this->err, '*** '.strtr(self::class, '\\', '.').' is not a command');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function runCommand() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command)]);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('', $this->out->bytes());
    Assert::true($command->wasRun());
  }

  #[Test]
  public function runWritingToStandardOutput() {
    $command= newinstance(Command::class, [], [
      'run' => function() { $this->out->write('UNITTEST'); }
    ]);

    $return= $this->runWith([nameof($command)]);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function runWritingToStandardError() {
    $command= newinstance(Command::class, [], [
      'run' => function() { $this->err->write('UNITTEST'); }
    ]);

    $return= $this->runWith([nameof($command)]);
    Assert::equals(0, $return);
    Assert::equals('UNITTEST', $this->err->bytes());
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function runEchoInput() {
    $command= newinstance(Command::class, [], [
      'run' => function() {
        while ($chunk= $this->in->read()) {
          $this->out->write($chunk);
        }
      }
    ]);

    $return= $this->runWith([nameof($command)], 'UNITTEST');
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function positionalArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg(position: 0)]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), 'UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function missingPositionalArgumentt() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg(position: 0)]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { throw new \unittest\AssertionFailedError("Should not be executed"); }
    }');

    $return= $this->runWith([nameof($command)]);
    Assert::equals(2, $return);
    $this->assertOnStream($this->err, '*** Argument #1 does not exist');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function shortNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '-a', 'UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function longNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '--arg=UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function shortRenamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg(name: "pass")]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '-p', 'UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function longRenamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg(name: "pass")]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { $this->out->write($this->arg); }
    }');

    $return= $this->runWith([nameof($command), '--pass=UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function missingNamedArgument() {
    $command= newinstance(Command::class, [], '{
      private $arg= null;

      #[Arg]
      public function setArg($arg) { $this->arg= $arg; }
      public function run() { throw new \unittest\AssertionFailedError("Should not be executed"); }
    }');

    $return= $this->runWith([nameof($command)]);
    Assert::equals(2, $return);
    $this->assertOnStream($this->err, '*** Argument arg does not exist');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function existanceArgumentNotPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= false;

      #[Arg]
      public function setVerbose() { $this->verbose= true; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command)]);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('false', $this->out->bytes());
  }

  #[Test]
  public function optionalArgument() {
    $command= newinstance(Command::class, [], '{
      private $verbose= false;
      private $name= null;

      #[Arg]
      public function setName($name= "unknown") { $this->name= $name; }
      public function run() { $this->out->write($this->name); }
    }');

    $return= $this->runWith([nameof($command), '-n', 'UNITTEST']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('UNITTEST', $this->out->bytes());
  }

  #[Test]
  public function optionalArgumentNotPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= false;
      private $name= null;

      #[Arg]
      public function setName($name= "unknown") { $this->name= $name; }
      public function run() { $this->out->write($this->name); }
    }');

    $return= $this->runWith([nameof($command)]);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('unknown', $this->out->bytes());
  }

  #[Test]
  public function shortExistanceArgumentPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= false;

      #[Arg]
      public function setVerbose() { $this->verbose= true; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command), '-v']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('true', $this->out->bytes());
  }

  #[Test]
  public function longExistanceArgumentPassed() {
    $command= newinstance(Command::class, [], '{
      private $verbose= false;

      #[Arg]
      public function setVerbose() { $this->verbose= true; }
      public function run() { $this->out->write($this->verbose ? "true" : "false"); }
    }');

    $return= $this->runWith([nameof($command), '--verbose']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('true', $this->out->bytes());
  }

  #[Test]
  public function positionalArgumentException() {
    $command= newinstance(Command::class, [], '{
      
      #[Arg(position: 0)]
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

  #[Test]
  public function namedArgumentException() {
    $command= newinstance(Command::class, [], '{
      
      #[Arg]
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

  #[Test]
  public function allArgs() {
    $this->assertAllArgs('a, b, c, d, e, f, g', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "[0..]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[Test]
  public function allArgsCompactNotation() {
    $this->assertAllArgs('a, b, c, d, e, f, g', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "*")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }
 
  #[Test]
  public function boundedArgs() {
    $this->assertAllArgs('a, b, c', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "[0..2]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[Test]
  public function boundedArgsFromOffset() {
    $this->assertAllArgs('c, d, e', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "[2..4]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[Test]
  public function positionalAndBoundedArgsFromOffset() {
    $this->assertAllArgs('a, c, d, e', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "0, [2..4]")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[Test]
  public function boundedAndPositionalArgsWithOverlap() {
    $this->assertAllArgs('a, b, c, b', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "[0..2], 1")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }
 
  #[Test]
  public function positionalArgs() {
    $this->assertAllArgs('a, c, e, f', newinstance(Command::class, [], '{
      private $verbose= false;
      private $args= [];

      #[Args(select: "0, 2, 4, 5")]
      public function setArgs($args) { $this->args= $args; }
      public function run() { $this->out->write(implode(", ", $this->args)); }
    }'));
  }

  #[Test]
  public function configOption() {
    $command= newinstance(Command::class, [], '{
      private $choke= false;

      #[Arg]
      public function setChoke() { 
        $this->choke= true; 
      }
      
      public function run() { 
        $this->out->write($this->choke ? "true" : "false"); 
      }
    }');
    $return= $this->runWith(['-c', 'etc', nameof($command), '-c']);
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('true', $this->out->bytes());
  }


  #[Test]
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
    Assert::equals('Created via user-supplied creation method', $this->out->bytes());
  }

  #[Test]
  public function config_passed_to_constructor() {
    $command= newinstance(Command::class, [], '{
      private $config= null;

      public function __construct($config= null) {
        $this->config= $config;
      }

      public function run() {
        $this->out->write("Created with ", $this->config);
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertOnStream($this->out, 'Created with util.cmd.Config');
  }

  #[Test]
  public function config_passed_to_create() {
    $command= newinstance(Command::class, [], '{
      private $config= null;

      public static function newInstance($config) {
        $self= new self();
        $self->config= $config;
        return $self;
      }

      public function run() {
        $this->out->write("Created with ", $this->config);
      }
    }');
    $this->runWith([nameof($command)]);
    $this->assertOnStream($this->out, 'Created with util.cmd.Config');
  }

  #[Test]
  public function can_be_invoked_via_main() {
    $command= newinstance(Command::class, [], [
      'arg' => 0,
      '#[Arg] setArg' => function($arg) { $this->arg= $arg; },
      'run' => function() { return $this->arg; }
    ]);

    Assert::equals(12, $command::main(['-a', 12]));
  }
}