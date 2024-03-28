<?php namespace util\cmd\unittest;

use test\Assert;
use test\{Arg, Test, Values};
use util\cmd\Command;
use xp\command\Runner;

class RunnerTest extends AbstractRunnerTest {

  /** @return xp.command.AbstractRunner */
  protected function runner() { return new Runner(); }

  #[Test, Values([[[]], [['-?']]])]
  public function selfUsage($args) {
    $return= $this->runWith($args);
    Assert::equals(1, $return);
    $this->assertOnStream($this->err, '$ xpcli [options]');
    Assert::equals('', $this->out->bytes());
  }

  #[Test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    Assert::equals(0, $return);
    $this->assertOnStream($this->err, '$ xpcli '.nameof($command));
    Assert::equals('', $this->out->bytes());
    Assert::false($command->wasRun());
  }

  #[Test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    Assert::equals(0, $return);
    $this->assertOnStream($this->err, '$ xpcli '.nameof($command));
    Assert::equals('', $this->out->bytes());
    Assert::false($command->wasRun());
  }

  #[Test]
  public function classPathOption() {
    $command= newinstance(Command::class, [], '{
      private $copy= NULL;
      
      #[Arg(short: "cp")]
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
    Assert::equals(0, $return);
    Assert::equals('', $this->err->bytes());
    Assert::equals('lang.XPClass<net.xp_forge.instructions.Copy>', $this->out->bytes());
  }
}