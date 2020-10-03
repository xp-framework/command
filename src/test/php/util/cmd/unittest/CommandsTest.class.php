<?php namespace util\cmd\unittest;

use lang\reflect\Package;
use lang\{ClassLoader, ClassNotFoundException, IllegalArgumentException};
use unittest\{BeforeClass, Expect, Test, Values};
use util\cmd\{Command, Commands};

class CommandsTest extends \unittest\TestCase {
  private static $class, $global;

  /** @return void */
  #[BeforeClass]
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

  #[Test]
  public function packages_initially_empty() {
    $this->assertEquals([], Commands::allPackages());
  }

  #[Test]
  public function register_package() {
    self::withPackage('util.cmd.unittest', function($package) {
      $this->assertEquals([Package::forName($package)], Commands::allPackages());
    });
  }

  #[Test, Values(['BatchImport', 'util.cmd.unittest.BatchImport'])]
  public function named($name) {
    self::withPackage('util.cmd.unittest', function() use($name) {
      $this->assertEquals(self::$class, Commands::named($name));
    });
  }

  #[Test]
  public function unqualified_name_in_global_namespace() {
    $this->assertEquals(self::$global, Commands::named('BatchImport'));
  }

  #[Test, Expect(ClassNotFoundException::class), Values(['class.does.not.Exist', 'DoesNotExist'])]
  public function named_non_existant($name) {
    Commands::named($name);
  }

  #[Test, Expect(['class' => IllegalArgumentException::class, 'withMessage' => '/CommandsTest is not runnable/'])]
  public function name_non_runnable() {
    Commands::named(nameof($this));
  }

  #[Test, Expect(['class' => IllegalArgumentException::class, 'withMessage' => '/CommandsTest is not runnable/'])]
  public function file_not_runnable() {
    Commands::named(__FILE__);
  }

  #[Test]
  public function nameOf_qualified() {
    $this->assertEquals('util.cmd.unittest.BatchImport', Commands::nameOf(self::$class));
  }

  #[Test]
  public function nameOf_shortened_when_package_is_registered() {
    self::withPackage('util.cmd.unittest', function() {
      $this->assertEquals('BatchImport', Commands::nameOf(self::$class));
    });
  }
}