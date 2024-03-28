<?php namespace util\cmd;

use lang\reflection\Package;
use lang\{ClassLoader, ClassNotFoundException, IllegalArgumentException, Runnable};

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
   * @param  string $name
   * @return lang.XPClass
   * @throws lang.ClassNotFoundException
   * @throws lang.IllegalArgumentException if class is not runnable
   */
  public static function named($name) {
    $cl= ClassLoader::getDefault();
    if (is_file($name)) {
      $class= $cl->loadUri($name);
    } else if (strpos($name, '.')) {
      $class= $cl->loadClass($name);
    } else if ($named= self::locateNamed($cl, $name)) {
      $class= $cl->loadClass($named);
    } else {
      $class= $cl->loadClass($name);
    }

    // Check whether class is runnable
    if (!$class->isSubclassOf(Command::class)) {
      throw new IllegalArgumentException($class->getName().' is not a command');
    }

    return $class;
  }

  /**
   * Return name of a given class - shortened if inside a registered package
   *
   * @param  lang.XPClass $class
   * @return string
   */
  public static function nameOf($class) {
    if (isset(self::$packages[$class->getPackage()->getName()])) {
      return $class->getSimpleName();
    } else {
      return $class->getName();
    }
  }
}