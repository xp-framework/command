<?php namespace util\cmd\unittest;

use unittest\{Test, Values};
use xp\command\CmdRunner;

class CmdRunnerTest extends AbstractRunnerTest {

  /** @return xp.command.AbstractRunner */
  protected function runner() { return new CmdRunner(); }

  #[Test, Values([[[]], [['-?']]])]
  public function selfUsage($args) {
    $return= $this->runWith($args);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, 'xp cmd [class]');
    $this->assertEquals('', $this->out->bytes());
  }

  #[Test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    $this->assertEquals('', $this->out->bytes());
    $this->assertFalse($command->wasRun());
  }

  #[Test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    $this->assertEquals('', $this->out->bytes());
    $this->assertFalse($command->wasRun());
  }
}