<?php namespace util\cmd\unittest;

use util\cmd\Command;
use util\cmd\Commands;
use lang\reflect\Package;
use lang\ClassLoader;
use lang\IllegalArgumentException;
use lang\ClassNotFoundException;

class CommandsTest extends \unittest\TestCase {
  private static $class, $global;

  /** @return void */
  #[@beforeClass]
  public static function defineCommandClass() {
    self::$class= ClassLoader::defineClass('util.cmd.unittest.BatchImport', Command::class, [], [
      'run' => function() { }
    ]);
    self::$global= ClassLoader::defineClass('BatchImport', Command::class, [], [
      'run' => function() { }
    ]);
  }

  /**
   * Register package, calls block, removing the package before returning or throwing
   *
   * @param  string $package
   * @param  function(string): void $block
   */
  private static function withPackage($package, $block) {
    Commands::registerPackage($package);
    try {
      $block($package);
    } finally {
      Commands::removePackage($package);
    }
  }

  #[@test]
  public function packages_initially_empty() {
    $this->assertEquals([], Commands::allPackages());
  }

  #[@test]
  public function register_package() {
    self::withPackage('util.cmd.unittest', function($package) {
      $this->assertEquals([Package::forName($package)], Commands::allPackages());
    });
  }

  #[@test, @values(['BatchImport', 'util.cmd.unittest.BatchImport'])]
  public function named($name) {
    self::withPackage('util.cmd.unittest', function() use($name) {
      $this->assertEquals(self::$class, Commands::named($name));
    });
  }

  #[@test]
  public function unqualified_name_in_global_namespace() {
    $this->assertEquals(self::$global, Commands::named('BatchImport'));
  }

  #[@test, @expect(ClassNotFoundException::class), @values(['class.does.not.Exist', 'DoesNotExist'])]
  public function named_non_existant($name) {
    Commands::named($name);
  }

  #[@test, @expect(class= IllegalArgumentException::class, withMessage= '/CommandsTest is not runnable/')]
  public function name_non_runnable() {
    Commands::named(nameof($this));
  }

  #[@test, @expect(class= IllegalArgumentException::class, withMessage= '/CommandsTest is not runnable/')]
  public function file_not_runnable() {
    Commands::named(__FILE__);
  }

  #[@test]
  public function nameOf_qualified() {
    $this->assertEquals('util.cmd.unittest.BatchImport', Commands::nameOf(self::$class));
  }

  #[@test]
  public function nameOf_shortened_when_package_is_registered() {
    self::withPackage('util.cmd.unittest', function() {
      $this->assertEquals('BatchImport', Commands::nameOf(self::$class));
    });
  }
}