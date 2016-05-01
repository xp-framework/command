<?php namespace util\cmd;

use lang\ClassLoader;
use lang\IllegalArgumentException;

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
    self::$packages[]= $package;
  }

  /**
   * Loads a named command
   *
   * @param  lang.CLassLoader $cl
   * @param  string $name
   * @return lang.XPClass
   * @throws lang.IllegalArgumentException if no class can be found by the given name
   */
  private static function loadNamed($cl, $name) {
    $class= implode('', array_map('ucfirst', explode('-', $name)));
    foreach (self::$packages as $package) {
      if ($cl->providesClass($qualified= $package.'.'.$class)) return $cl->loadClass($qualified);
    }
    throw new IllegalArgumentException('No command named "'.$name.'"');
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
    } else {
      $class= self::loadNamed($cl, $name);
    }

    // Check whether class is runnable
    if (!$class->isSubclassOf('lang.Runnable')) {
      throw new IllegalArgumentException($class->getName().' is not runnable');
    }

    return $class;
  }
}