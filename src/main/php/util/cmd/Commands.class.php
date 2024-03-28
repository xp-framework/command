<?php namespace util\cmd;

use lang\reflect\Package;
use lang\{ClassLoader, ClassNotFoundException, IllegalArgumentException};

/**
 * Commands factory. Loads classes, files and named commands by using
 * a package-based search.
 *
 * @test  xp://util.cmd.unittest.CommandsTest
 */
final class Commands {
  private static $packages= [];

  /**
   * Prevent instantiation
   */
  private function __construct() { }

  /**
   * Register named commands
   *
   * @param  string $package
   * @return void
   */
  public static function registerPackage($package) {
    self::$packages[$package]= Package::forName($package);
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
   * @return lang.reflect.Package[]
   */
  public static function allPackages() {
    return array_values(self::$packages);
  }

  /**
   * Locates a named command
   *
   * @param  string $name
   * @return lang.reflect.Package or NULL if nothing is found
   */
  private static function locateNamed($name) {
    foreach (self::$packages as $package) {
      if ($package->providesClass($name)) return $package;
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
    } else if (strstr($name, '.')) {
      $class= $cl->loadClass($name);
    } else if ($package= self::locateNamed($name)) {
      $class= $package->loadClass($name);
    } else {
      $class= $cl->loadClass($name);
    }

    // Check whether class is runnable
    if (!$class->isSubclassOf('lang.Runnable')) {
      throw new IllegalArgumentException($class->getName().' is not runnable');
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