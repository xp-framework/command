<?php namespace util\cmd\unittest;

use lang\ElementNotFoundException;
use test\Assert;
use test\{Expect, Test};
use util\cmd\Config;
use util\{ClassPathPropertySource, FilesystemPropertySource, PropertyAccess};

class ConfigTest {
  
  #[Test]
  public function can_create() {
    new Config();
  }

  #[Test]
  public function initially_empty() {
    Assert::true((new Config())->isEmpty());
  }

  #[Test]
  public function not_empty_if_created_with_source() {
    Assert::false((new Config('.'))->isEmpty());
  }

  #[Test]
  public function not_empty_if_created_with_sources() {
    Assert::false((new Config('.', 'util/cmd/unittest'))->isEmpty());
  }

  #[Test]
  public function no_longer_empty_after_appending_source() {
    $config= new Config();
    $config->append('.');
    Assert::false($config->isEmpty());
  }

  #[Test]
  public function append_dir() {
    $config= new Config();
    $config->append('.');
    Assert::equals([new FilesystemPropertySource('.')], $config->sources());
  }

  #[Test]
  public function append_resource() {
    $config= new Config();
    $config->append('live');
    Assert::equals([new ClassPathPropertySource('live')], $config->sources());
  }

  #[Test, Expect(ElementNotFoundException::class)]
  public function properties_raises_exception_when_nothing_found() {
    (new Config())->properties('test');
  }

  #[Test]
  public function properties() {
    $config= new Config();
    $config->append('util/cmd/unittest');
    Assert::equals('value', $config->properties('debug')->readString('section', 'key'));
  }

  #[Test]
  public function composite_properties() {
    $config= new Config();
    $config->append('util/cmd/unittest/add_etc');
    $config->append('util/cmd/unittest');
    Assert::equals('overwritten_value', $config->properties('debug')->readString('section', 'key'));
  }
}