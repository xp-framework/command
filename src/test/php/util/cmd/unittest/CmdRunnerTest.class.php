<?php namespace util\cmd\unittest;

use unittest\Assert;
use unittest\{Test, Values};
use xp\command\CmdRunner;

class CmdRunnerTest extends AbstractRunnerTest {

  /** @return xp.command.AbstractRunner */
  protected function runner() { return new CmdRunner(); }

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
}