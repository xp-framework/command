<?php namespace util\cmd\unittest;
 
use lang\ElementNotFoundException;
use unittest\{Expect, Test};
use util\cmd\Config;
use util\{FilesystemPropertySource, PropertyAccess, ResourcePropertySource};

class ConfigTest extends \unittest\TestCase {
  
  #[Test]
  public function can_create() {
    new Config();
  }

  #[Test]
  public function initially_empty() {
    $this->assertTrue((new Config())->isEmpty());
  }

  #[Test]
  public function not_empty_if_created_with_source() {
    $this->assertFalse((new Config('.'))->isEmpty());
  }

  #[Test]
  public function not_empty_if_created_with_sources() {
    $this->assertFalse((new Config('.', 'util/cmd/unittest'))->isEmpty());
  }

  #[Test]
  public function no_longer_empty_after_appending_source() {
    $config= new Config();
    $config->append('.');
    $this->assertFalse($config->isEmpty());
  }

  #[Test]
  public function append_dir() {
    $config= new Config();
    $config->append('.');
    $this->assertEquals([new FilesystemPropertySource('.')], $config->sources());
  }

  #[Test]
  public function append_resource() {
    $config= new Config();
    $config->append('live');
    $this->assertEquals([new ResourcePropertySource('live')], $config->sources());
  }

  #[Test]
  public function append_resource_with_explicit_res_prefix() {
    $config= new Config();
    $config->append('res://live');
    $this->assertEquals([new ResourcePropertySource('live')], $config->sources());
  }

  #[Test, Expect(ElementNotFoundException::class)]
  public function properties_raises_exception_when_nothing_found() {
    (new Config())->properties('test');
  }

  #[Test]
  public function properties() {
    $config= new Config();
    $config->append('util/cmd/unittest');
    $this->assertEquals('value', $config->properties('debug')->readString('section', 'key'));
  }

  #[Test]
  public function composite_properties() {
    $config= new Config();
    $config->append('util/cmd/unittest/add_etc');
    $config->append('util/cmd/unittest');
    $this->assertEquals('overwritten_value', $config->properties('debug')->readString('section', 'key'));
  }
}