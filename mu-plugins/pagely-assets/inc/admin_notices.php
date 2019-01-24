<?php
/* Class for Pagely Admin Notices 
 * use it to set/get/display notices on actions
 * Version: .01
 * Author: Strebel
 */

class Pagely_Alert
{
    public $alerts = array();
    public static $instance = null;

    function __construct()
    {
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX))
        {

            $this->registerHooks();
        }  
    }

    public static function instance()
    {
        if (empty(self::$instance))
            self::$instance = new Pagely_Alert();

        return self::$instance;
    }

    public function registerHooks()
    {

        //add_action('all_admin_notices',array($this,'displayAlert'),20);
        add_action('admin_head',array($this,'displayAlert'),20);


    }
    public function setAlert($text,$status = true, $key = null )
    {
        if (empty($key))
            $this->alerts[] = array('msg' => $text, 'status' => $status);
        else
            $this->alerts[$key] = array('msg' => $text, 'status' => $status);

    }

    public function getAlerts() {
        return $this->alerts;
    }

    public function test() {
        echo 'Class loaded';
    }

    public function displayAlert() {

        $success_str =  false;
        $error_str = false;

        //print_r($this->alerts);
        foreach ($this->alerts as $k => $v) {
            if (!empty($v)) { 
                //success vs error
                if ($v['status']) {
                    $success_str .= "<p>{$v['msg']}</p>";
                } else {
                    $error_str .= "<p>{$v['msg']}</p>";
                }
            }
        }

        // or not
        // this will echo only 1 success, and 1 error.. with multple lines if needed.
        if ($error_str) {
            echo "<div class='error'>$error_str</div>";
        }

        if ($success_str) {
            echo "<div class='updated fade'>$success_str</div>";
        }


    }

}	
// LOAD THE PLUGIN AT INIT
Pagely_Alert::instance(); 
