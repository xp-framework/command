<?php namespace util\cmd\unittest;

use lang\IllegalArgumentException;
use unittest\Assert;
use unittest\{Expect, Test};
use util\cmd\ParamString;

class ParamStringTest {
  
  #[Test]
  public function shortFlag() {
    $p= new ParamString(['-k']);

    Assert::true($p->exists('k'));
    Assert::null($p->value('k'));
  }

  #[Test]
  public function shortValue() {
    $p= new ParamString(['-d', 'sql']);

    Assert::true($p->exists('d'));
    Assert::equals('sql', $p->value('d'));
  }

  #[Test]
  public function longFlag() {
    $p= new ParamString(['--verbose']);

    Assert::true($p->exists('verbose'));
    Assert::null($p->value('verbose'));
  }

  #[Test]
  public function longValue() {
    $p= new ParamString(['--level=3']);

    Assert::true($p->exists('level'));
    Assert::equals('3', $p->value('level'));
  }

  #[Test]
  public function longValueShortGivenDefault() {
    $p= new ParamString(['-l', '3']);

    Assert::true($p->exists('level'));
    Assert::equals('3', $p->value('level'));
  }

  #[Test]
  public function longValueShortGiven() {
    $p= new ParamString(['-L', '3', '-l', 'FAIL']);

    Assert::true($p->exists('level', 'L'));
    Assert::equals('3', $p->value('level', 'L'));
  }

  #[Test]
  public function positional() {
    $p= new ParamString(['That is a realm']);
    
    Assert::true($p->exists(0));
    Assert::equals('That is a realm', $p->value(0));
  }

  #[Test]
  public function existance() {
    $p= new ParamString(['a', 'b']);
    
    Assert::true($p->exists(0));
    Assert::true($p->exists(1));
    Assert::false($p->exists(2));
  }

  #[Test, Expect(IllegalArgumentException::class)]
  public function nonExistantPositional() {
    (new ParamString(['a']))->value(1);
  }

  #[Test]
  public function nonExistantPositionalWithDefault() {
    Assert::equals(
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
    Assert::equals(
      'Default', 
      (new ParamString(['--verbose']))->value('name', 'n', 'Default')
    );
  }
  
  #[Test]
  public function whitespaceInParameter() {
    $p= new ParamString(['--realm=That is a realm']);
    
    Assert::true($p->exists('realm'));
    Assert::equals('That is a realm', $p->value('realm'));
  }
}