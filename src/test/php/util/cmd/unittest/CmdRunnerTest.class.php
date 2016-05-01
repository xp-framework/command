<?php namespace util\cmd\unittest;

use xp\command\CmdRunner;

class CmdRunnerTest extends AbstractRunnerTest {

  /** @return var */
  protected function runner() { return new CmdRunner(); }

  #[@test]
  public function selfUsage() {
    $return= $this->runWith([]);
    $this->assertEquals(1, $return);
    $this->assertOnStream($this->err, 'xp cmd [class]');
    $this->assertEquals('', $this->out->getBytes());
  }

  #[@test]
  public function shortClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '-?']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    $this->assertEquals('', $this->out->getBytes());
    $this->assertFalse($command->wasRun());
  }

  #[@test]
  public function longClassUsage() {
    $command= $this->newCommand();
    $return= $this->runWith([nameof($command), '--help']);
    $this->assertEquals(0, $return);
    $this->assertOnStream($this->err, '$ xp cmd '.nameof($command));
    $this->assertEquals('', $this->out->getBytes());
    $this->assertFalse($command->wasRun());
  }
}
