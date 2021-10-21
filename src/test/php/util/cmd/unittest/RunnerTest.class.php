<?php namespace util\cmd\unittest;

use unittest\{Arg, Test, Values};
use util\cmd\Command;
use xp\command\Runner;

class RunnerTest extends AbstractRunnerTest {

  /** @return xp.command.AbstractRunner */
  protected function runner() { return new Runner(); }

  #[Test, Values([[[]], [['-?']]])]
  public function selfUsage($args) {
    $return= $this->runWith($args);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, '$ xpcli [options]');
    $this->assertEquals('', $this->out->bytes());
  }

  #[Test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xpcli '.nameof($command));
    $this->assertEquals('', $this->out->bytes());
    $this->assertFalse($command->wasRun());
  }

  #[Test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xpcli '.nameof($command));
    $this->assertEquals('', $this->out->bytes());
    $this->assertFalse($command->wasRun());
  }

  #[Test]
  public function classPathOption() {
    $command= newinstance(Command::class, [], '{
      private $copy= NULL;
      
      #[Arg(["short" => "cp"])]
      public function setCopy($copy) { 
        $this->copy= \lang\reflect\Package::forName("net.xp_forge.instructions")->loadClass($copy); 
      }
      
      public function run() { 
        $this->out->write($this->copy); 
      }
    }');
    $return= $this->runWith([
      '-cp', typeof($this)->getPackage()->getResourceAsStream('instructions.xar')->getURI(), 
      nameof($command),
      '-cp', 'Copy'
    ]);
    $this->assertEquals(0, $return);
    $this->assertEquals('', $this->err->bytes());
    $this->assertEquals('lang.XPClass<net.xp_forge.instructions.Copy>', $this->out->bytes());
  }
}