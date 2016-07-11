<?php namespace util\cmd;

use xp\command\CmdRunner;

/**
 * Base class for all commands
 */
abstract class Command extends \lang\Object implements \lang\Runnable {
  public
    #[@type('io.streams.StringReader')]
    $in  = null,
    #[@type('io.streams.StringWriter')]
    $out = null,
    #[@type('io.streams.StringWriter')]
    $err = null;

  /**
   * Make Commands runnable via `xp`.
   *
   * @param  string[] $args
   * @return int
   */
  public static function main($args) {
    array_unshift($args, strtr(get_called_class(), '\\', '.'));
    return CmdRunner::main($args);
  }
}
