<?php namespace util\cmd;

use lang\XPClass;
use lang\ClassLoader;
use lang\IllegalArgumentException;

final class Commands {
  private static $named= [];

  /**
   * Prevent instantiation
   */
  private function __construct() { }

  /**
   * Register named commands
   *
   * @param  [:string] $named Map of names => classes
   * @return void
   */
  public static function register(array $named) {
    self::$named= array_merge(self::$named, $named);
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
    if (isset(self::$named[$name])) {
      $class= XPClass::forName(self::$named[$name]);
    } else if (is_file($name)) {
      $class= ClassLoader::getDefault()->loadUri($name);
    } else {
      $class= XPClass::forName($name);
    }

    // Check whether class is runnable
    if (!$class->isSubclassOf('lang.Runnable')) {
      throw new IllegalArgumentException($class->getName().' is not runnable');
    }

    return $class;
  }
}