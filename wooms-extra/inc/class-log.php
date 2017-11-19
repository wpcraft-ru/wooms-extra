<?php

/**
 *
 */
class WooMS_Log
{

  function __construct()
  {
    // add_action('woomss_tool_actions_btns', array($this, 'display_log'), 111);

  }

  function display_log(){
    echo '<hr>';
    $data = get_transient('wooms_log');
    if(is_array($data)){
      $data = array_reverse($data);
      echo "<pre>";
      foreach($data as $item){
        var_dump($item);
      }
      echo "</pre>";
    }
  }

  function add($string = ''){
    if(empty($string)){
      return false;
    }

    $data = get_transient('wooms_log');
    if( ! is_array($data)){
      $data = array();
    }
    $data[] = array(date('Y-m-d H:i:s'), $string);
    $data = array_slice($data, -33, 33);

    set_transient('wooms_log', $data);
  }
}
new WooMS_Log;
