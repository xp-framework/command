<?php namespace util\cmd;

use lang\reflection\{Package, Type};
use lang\{ClassLoader, ClassNotFoundException, IllegalArgumentException, Reflection};

/**
 * Commands factory. Loads classes, files and named commands by using
 * a package-based search.
 *
 * @test  xp://util.cmd.unittest.CommandsTest
 */
final class Commands {
  private static $packages= [];

  /** Prevent instantiation */
  private function __construct() { }

  /**
   * Register named commands
   *
   * @param  string $package
   * @return void
   */
  public static function registerPackage($package) {
    self::$packages[$package]= new Package($package);
  }

  /**
   * Remove package
   *
   * @param  string $package
   * @return void
   */
  public static function removePackage($package) {
    unset(self::$packages[$package]);
  }

  /**
   * Gets all registered packages
   *
   * @return [:lang.reflection.Package]
   */
  public static function allPackages() {
    return self::$packages;
  }

  /**
   * Locates a named command
   *
   * @param  lang.IClassLoader $cl
   * @param  string $name
   * @return ?string
   */
  private static function locateNamed($cl, $name) {
    foreach (self::$packages as $package) {
      $class= $package->name().'.'.$name;
      if ($cl->providesClass($class)) return $class;
    }
    return null;
  }

  /**
   * Find a command by a given name
   *
   * @throws lang.ClassNotFoundException
   * @throws lang.IllegalArgumentException if class is not runnable
   */
  public static function named(string $name): Type {
    $cl= ClassLoader::getDefault();
    if (is_file($name)) {
      $type= Reflection::type($cl->loadUri($name));
    } else if (strpos($name, '.')) {
      $type= Reflection::type($cl->loadClass($name));
    } else if ($named= self::locateNamed($cl, $name)) {
      $type= Reflection::type($cl->loadClass($named));
    } else {
      $type= Reflection::type($cl->loadClass($name));
    }

    if (!$type->is(Command::class)) {
      throw new IllegalArgumentException($type->name().' is not a command');
    }

    return $type;
  }

  /** Return name of a given class - shortened if inside a registered package */
  public static function nameOf(Type $type): string {
    if (isset(self::$packages[$type->package()->name()])) {
      return $type->declaredName();
    } else {
      return $type->name();
    }
  }
}