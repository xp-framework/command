<?php namespace util\cmd\unittest;
 
use lang\IllegalArgumentException;
use unittest\{Expect, Test};
use util\cmd\ParamString;

class ParamStringTest extends \unittest\TestCase {
  
  #[Test]
  public function shortFlag() {
    $p= new ParamString(['-k']);

    $this->assertTrue($p->exists('k'));
    $this->assertNull($p->value('k'));
  }

  #[Test]
  public function shortValue() {
    $p= new ParamString(['-d', 'sql']);

    $this->assertTrue($p->exists('d'));
    $this->assertEquals('sql', $p->value('d'));
  }

  #[Test]
  public function longFlag() {
    $p= new ParamString(['--verbose']);

    $this->assertTrue($p->exists('verbose'));
    $this->assertNull($p->value('verbose'));
  }

  #[Test]
  public function longValue() {
    $p= new ParamString(['--level=3']);

    $this->assertTrue($p->exists('level'));
    $this->assertEquals('3', $p->value('level'));
  }

  #[Test]
  public function longValueShortGivenDefault() {
    $p= new ParamString(['-l', '3']);

    $this->assertTrue($p->exists('level'));
    $this->assertEquals('3', $p->value('level'));
  }

  #[Test]
  public function longValueShortGiven() {
    $p= new ParamString(['-L', '3', '-l', 'FAIL']);

    $this->assertTrue($p->exists('level', 'L'));
    $this->assertEquals('3', $p->value('level', 'L'));
  }

  #[Test]
  public function positional() {
    $p= new ParamString(['That is a realm']);
    
    $this->assertTrue($p->exists(0));
    $this->assertEquals('That is a realm', $p->value(0));
  }

  #[Test]
  public function existance() {
    $p= new ParamString(['a', 'b']);
    
    $this->assertTrue($p->exists(0));
    $this->assertTrue($p->exists(1));
    $this->assertFalse($p->exists(2));
  }

  #[Test, Expect(IllegalArgumentException::class)]
  public function nonExistantPositional() {
    (new ParamString(['a']))->value(1);
  }

  #[Test]
  public function nonExistantPositionalWithDefault() {
    $this->assertEquals(
      'Default', 
      (new ParamString(['--verbose']))->value(1, null, 'Default')
    );
  }

  #[Test, Expect(IllegalArgumentException::class)]
  public function nonExistantNamed() {
    (new ParamString(['--verbose']))->value('name');
  }

  #[Test]
  public function nonExistantNamedWithDefault() {
    $this->assertEquals(
      'Default', 
      (new ParamString(['--verbose']))->value('name', 'n', 'Default')
    );
  }
  
  #[Test]
  public function whitespaceInParameter() {
    $p= new ParamString(['--realm=That is a realm']);
    
    $this->assertTrue($p->exists('realm'));
    $this->assertEquals('That is a realm', $p->value('realm'));
  }
}